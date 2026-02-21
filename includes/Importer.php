<?php

namespace CRBC\ImportMatchs;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Importer {

    private const TEAM_NAME = 'CANET RBC';

    /**
     * In-memory cache of all equipes-externes: [ID => post_title].
     * Populated once per import run, updated when new opponents are created.
     */
    private array $all_opponents = [];

    /**
     * In-memory cache of existing matches: ["date_opponent_id" => post_id].
     * Populated once per import run via a single SQL query.
     */
    private array $existing_matches = [];

    /**
     * In-memory cache of equipes-crbc terms: [name_lower_or_slug => term_id].
     */
    private array $divisions = [];

    /**
     * Load all equipes-externes into memory.
     */
    private function get_all_opponents(): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type='equipes-externes' AND post_status IN ('publish','draft') ORDER BY ID"
        );
        $result = [];
        foreach ( $rows as $r ) {
            $result[ (int) $r->ID ] = trim( $r->post_title );
        }
        return $result;
    }

    /**
     * Ensure opponents are loaded (call once at start of batch).
     */
    public function ensure_opponents_loaded(): void {
        if ( empty( $this->all_opponents ) ) {
            $this->all_opponents = $this->get_all_opponents();
        }
    }

    /**
     * Preload all existing matches into memory with a single SQL query.
     */
    private function preload_existing_matches(): void {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT p.ID,
                    MAX(CASE WHEN pm.meta_key = 'date_du_match' THEN pm.meta_value END) AS match_date,
                    MAX(CASE WHEN pm.meta_key = 'adversaire'    THEN pm.meta_value END) AS adversaire
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
             WHERE p.post_type = 'matchs'
               AND p.post_status NOT IN ('trash','auto-draft')
               AND pm.meta_key IN ('date_du_match','adversaire')
             GROUP BY p.ID
             HAVING match_date IS NOT NULL AND adversaire IS NOT NULL"
        );
        $this->existing_matches = [];
        foreach ( $rows as $r ) {
            $key = $r->match_date . '_' . $r->adversaire;
            $this->existing_matches[ $key ] = (int) $r->ID;
        }
    }

    /**
     * Ensure existing matches are preloaded.
     */
    public function ensure_matches_preloaded(): void {
        if ( empty( $this->existing_matches ) ) {
            $this->preload_existing_matches();
        }
    }

    /**
     * Preload all equipes-crbc taxonomy terms into memory.
     */
    private function preload_divisions(): void {
        $terms = get_terms( [
            'taxonomy'   => 'equipes-crbc',
            'hide_empty' => false,
            'fields'     => 'all',
        ] );
        $this->divisions = [];
        if ( ! is_wp_error( $terms ) ) {
            foreach ( $terms as $term ) {
                $this->divisions[ strtolower( $term->name ) ] = $term->term_id;
                $this->divisions[ $term->slug ]               = $term->term_id;
            }
        }
    }

    /**
     * Ensure divisions are loaded.
     */
    public function ensure_divisions_loaded(): void {
        if ( empty( $this->divisions ) ) {
            $this->preload_divisions();
        }
    }

    /**
     * Ensure all caches are preloaded (opponents, matches, divisions).
     */
    public function ensure_all_preloaded(): void {
        $this->ensure_opponents_loaded();
        $this->ensure_matches_preloaded();
        $this->ensure_divisions_loaded();
    }

    public function set_opponents_cache( array $opponents ): void {
        $this->all_opponents = $opponents;
    }

    public function get_opponents_cache(): array {
        return $this->all_opponents;
    }

    public function set_matches_cache( array $matches ): void {
        $this->existing_matches = $matches;
    }

    public function get_matches_cache(): array {
        return $this->existing_matches;
    }

    public function set_divisions_cache( array $divisions ): void {
        $this->divisions = $divisions;
    }

    public function get_divisions_cache(): array {
        return $this->divisions;
    }

    /**
     * Parse an Excel file into normalized row data without performing any WP operations.
     *
     * @param string $filepath Path to the .xlsx file.
     * @param string $type     'calendrier' or 'resultats'.
     * @return array Array of assoc arrays with keys: division, equipe1, equipe2, date_raw, heure_raw, score1, score2.
     * @throws \Exception If the file cannot be read.
     */
    public function parse_file_to_rows( string $filepath, string $type ): array {
        $spreadsheet = IOFactory::load( $filepath );

        if ( $type === 'calendrier' ) {
            $sheet = $this->find_calendrier_sheet( $spreadsheet );
            if ( ! $sheet ) {
                throw new \Exception( 'Feuille "rechercherRencontre" introuvable dans le fichier.' );
            }
        } else {
            $sheet = $spreadsheet->getSheet( 0 );
        }

        $rows   = $sheet->toArray( null, true, true, true );
        $header = null;
        $result = [];

        foreach ( $rows as $row_num => $row ) {
            if ( ! $header ) {
                $header = $this->map_header( $row );
                continue;
            }

            $equipe1 = trim( $row[ $header['Equipe 1'] ] ?? '' );
            $equipe2 = trim( $row[ $header['Equipe 2'] ] ?? '' );

            // Skip empty rows
            if ( empty( $equipe1 ) && empty( $equipe2 ) ) {
                continue;
            }

            // Skip exempt
            if ( strcasecmp( $equipe1, 'Exempt' ) === 0 || strcasecmp( $equipe2, 'Exempt' ) === 0 ) {
                continue;
            }

            $entry = [
                'division'  => trim( $row[ $header['Division'] ] ?? '' ),
                'equipe1'   => $equipe1,
                'equipe2'   => $equipe2,
                'date_raw'  => $row[ $header['Date de rencontre'] ] ?? '',
                'heure_raw' => $row[ $header['Heure'] ] ?? '',
                'score1'    => '',
                'score2'    => '',
                'row_num'   => $row_num,
            ];

            if ( $type === 'resultats' && isset( $header['Score 1'], $header['Score 2'] ) ) {
                $entry['score1'] = trim( $row[ $header['Score 1'] ] ?? '' );
                $entry['score2'] = trim( $row[ $header['Score 2'] ] ?? '' );
            }

            $result[] = $entry;
        }

        return $result;
    }

    /**
     * Import calendrier file (future matches, no scores).
     */
    public function import_calendrier( string $filepath ): array {
        $stats = [ 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => [] ];

        try {
            $rows = $this->parse_file_to_rows( $filepath, 'calendrier' );
        } catch ( \Exception $e ) {
            $stats['errors'][] = $e->getMessage();
            return $stats;
        }

        $this->ensure_all_preloaded();

        foreach ( $rows as $entry ) {
            $result = $this->process_row_data( $entry, 'calendrier' );

            if ( $result === 'created' ) {
                $stats['created']++;
            } elseif ( $result === 'skipped' ) {
                $stats['skipped']++;
            } elseif ( is_string( $result ) ) {
                $stats['errors'][] = $result;
            }
        }

        return $stats;
    }

    /**
     * Import résultats file (past matches with scores).
     */
    public function import_resultats( string $filepath ): array {
        $stats = [ 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => [] ];

        try {
            $rows = $this->parse_file_to_rows( $filepath, 'resultats' );
        } catch ( \Exception $e ) {
            $stats['errors'][] = $e->getMessage();
            return $stats;
        }

        $this->ensure_all_preloaded();

        foreach ( $rows as $entry ) {
            $result = $this->process_row_data( $entry, 'resultats' );

            if ( $result === 'created' ) {
                $stats['created']++;
            } elseif ( $result === 'updated' ) {
                $stats['updated']++;
            } elseif ( $result === 'skipped' ) {
                $stats['skipped']++;
            } elseif ( is_string( $result ) ) {
                $stats['errors'][] = $result;
            }
        }

        return $stats;
    }

    /**
     * Process a single normalized row from either file type.
     * Used by both the legacy full-import methods and the new batch processor.
     *
     * @param array  $entry Assoc array from parse_file_to_rows().
     * @param string $type  'calendrier' or 'resultats'.
     * @return array ['action' => 'created'|'updated'|'skipped'|'error', 'title' => '...', 'message' => '...', 'matched_as' => '...']
     */
    public function process_row_data( array $entry, string $type ): array|string {
        $division  = $entry['division'];
        $equipe1   = $entry['equipe1'];
        $equipe2   = $entry['equipe2'];
        $date_raw  = $entry['date_raw'];
        $heure_raw = $entry['heure_raw'];
        $row_num   = $entry['row_num'] ?? '?';

        // Detect home/away
        if ( stripos( $equipe1, self::TEAM_NAME ) !== false ) {
            $is_away      = false;
            $opponent_raw = $equipe2;
        } elseif ( stripos( $equipe2, self::TEAM_NAME ) !== false ) {
            $is_away      = true;
            $opponent_raw = $equipe1;
        } else {
            return [
                'action'  => 'error',
                'title'   => "$equipe1 - $equipe2",
                'message' => "Ligne $row_num : ni Equipe 1 ni Equipe 2 ne contient « " . self::TEAM_NAME . ' ».',
            ];
        }

        // Clean opponent name
        $opponent = $this->clean_opponent_name( $opponent_raw );
        if ( empty( $opponent ) ) {
            return [
                'action'  => 'error',
                'title'   => "$equipe1 - $equipe2",
                'message' => "Ligne $row_num : nom d'adversaire vide après nettoyage.",
            ];
        }

        // Build title
        $title = $is_away
            ? $opponent . ' - CRBC'
            : 'CRBC - ' . $opponent;

        // Parse date
        $match_date = $this->parse_date( $date_raw, $heure_raw );
        if ( ! $match_date ) {
            return [
                'action'  => 'error',
                'title'   => $title,
                'message' => "Ligne $row_num : date invalide ($date_raw $heure_raw).",
            ];
        }

        // Find or create opponent (equipes-externes) with fuzzy matching
        $match_info   = $this->find_or_create_opponent( $opponent );
        $opponent_id  = $match_info['id'];
        $matched_as   = $match_info['matched_as'] ?? null;

        if ( is_wp_error( $opponent_id ) ) {
            return [
                'action'  => 'error',
                'title'   => $title,
                'message' => "Ligne $row_num : " . $opponent_id->get_error_message(),
            ];
        }

        // Find or create division taxonomy term
        $this->find_or_create_division( $division );

        // Duplicate check
        $existing = $this->find_existing_match( $match_date, $opponent_id );

        $result = [
            'title'      => $title,
            'matched_as' => $matched_as,
        ];

        if ( $type === 'calendrier' ) {
            if ( $existing ) {
                $result['action'] = 'skipped';
                return $result;
            }

            $post_id = $this->create_match_post( $title, $match_date, $opponent_id, $is_away, $division );
            if ( is_wp_error( $post_id ) ) {
                $result['action']  = 'error';
                $result['message'] = "Ligne $row_num : " . $post_id->get_error_message();
                return $result;
            }

            $result['action'] = 'created';
            return $result;
        }

        // Résultats
        $score1 = $entry['score1'];
        $score2 = $entry['score2'];

        if ( ! $is_away ) {
            $score_crbc = $score1;
            $score_adv  = $score2;
        } else {
            $score_crbc = $score2;
            $score_adv  = $score1;
        }

        if ( $existing ) {
            update_post_meta( $existing, 'score_crbc', $score_crbc );
            update_post_meta( $existing, 'score_adversaire', $score_adv );
            $result['action'] = 'updated';
            return $result;
        }

        $post_id = $this->create_match_post( $title, $match_date, $opponent_id, $is_away, $division, $score_crbc, $score_adv );
        if ( is_wp_error( $post_id ) ) {
            $result['action']  = 'error';
            $result['message'] = "Ligne $row_num : " . $post_id->get_error_message();
            return $result;
        }

        $result['action'] = 'created';
        return $result;
    }

    /**
     * Clean the opponent name: strip IE prefix and trailing suffixes.
     */
    private function clean_opponent_name( string $name ): string {
        $name = trim( $name );

        // Strip leading "IE - "
        if ( str_starts_with( $name, 'IE - ' ) ) {
            $name = substr( $name, 5 );
        }

        // Strip trailing " - N (X)" pattern e.g. " - 1 (7)" or " - 2 (11)"
        $name = preg_replace( '/ - \d+ \(\d+\)$/', '', $name );

        // Strip trailing " (X)" pattern e.g. " (7)"
        $name = preg_replace( '/ \(\d+\)$/', '', $name );

        return trim( $name );
    }

    /**
     * Parse date and time from Excel row values.
     * Handles both string dates (DD/MM/YYYY) and Excel serial dates.
     * Handles both string times (HH:MM) and Excel time floats.
     */
    private function parse_date( $date_raw, $heure_raw ): ?string {
        // Handle Excel serial date (float)
        if ( is_numeric( $date_raw ) && (float) $date_raw > 1000 ) {
            try {
                $date_obj = ExcelDate::excelToDateTimeObject( (float) $date_raw );
                $date_str = $date_obj->format( 'd/m/Y' );
            } catch ( \Exception $e ) {
                return null;
            }
        } else {
            $date_str = trim( (string) $date_raw );
        }

        if ( empty( $date_str ) ) {
            return null;
        }

        // Parse DD/MM/YYYY
        $parts = preg_split( '/[\/\-.]/', $date_str );
        if ( count( $parts ) !== 3 ) {
            return null;
        }

        $day   = str_pad( $parts[0], 2, '0', STR_PAD_LEFT );
        $month = str_pad( $parts[1], 2, '0', STR_PAD_LEFT );
        $year  = $parts[2];

        // Handle Excel time float (0.0-1.0)
        if ( is_numeric( $heure_raw ) && (float) $heure_raw < 1 ) {
            $total_seconds = round( (float) $heure_raw * 86400 );
            $hours   = (int) floor( $total_seconds / 3600 );
            $minutes = (int) floor( ( $total_seconds % 3600 ) / 60 );
            $time_str = sprintf( '%02d:%02d', $hours, $minutes );
        } else {
            $time_str = trim( (string) $heure_raw );
        }

        if ( empty( $time_str ) ) {
            $time_str = '00:00';
        }

        // Ensure HH:MM format
        if ( preg_match( '/^(\d{1,2}):(\d{2})/', $time_str, $m ) ) {
            $time_str = sprintf( '%02d:%02d', (int) $m[1], (int) $m[2] );
        }

        return "$year-$month-$day $time_str:00";
    }

    /**
     * Find equipes-externes post by name using cascade fuzzy matching, or create one.
     *
     * Cascade:
     * 1. Exact match (case-insensitive, trimmed)
     * 2. WP title contained in opponent name (longest wins)
     * 3. Opponent name contained in WP title
     * 4. similar_text() score > 60%
     * 5. Create new post
     *
     * @return array ['id' => int|WP_Error, 'matched_as' => string|null]
     */
    private function find_or_create_opponent( string $name ): array {
        $name_lower = strtolower( trim( $name ) );

        // Step 1 — Exact match
        foreach ( $this->all_opponents as $id => $existing_title ) {
            if ( strtolower( trim( $existing_title ) ) === $name_lower ) {
                return [ 'id' => $id, 'matched_as' => null ];
            }
        }

        // Step 2 — WP title contained in opponent name (longest wins)
        $best_id     = null;
        $best_length = 0;
        foreach ( $this->all_opponents as $id => $existing_title ) {
            $existing_lower = strtolower( trim( $existing_title ) );
            if ( $existing_lower !== '' && stripos( $name, $existing_title ) !== false ) {
                $len = mb_strlen( $existing_title );
                if ( $len > $best_length ) {
                    $best_length = $len;
                    $best_id     = $id;
                }
            }
        }
        if ( $best_id !== null ) {
            return [ 'id' => $best_id, 'matched_as' => $this->all_opponents[ $best_id ] ];
        }

        // Step 3 — Opponent name contained in WP title
        foreach ( $this->all_opponents as $id => $existing_title ) {
            if ( $name_lower !== '' && stripos( $existing_title, $name ) !== false ) {
                return [ 'id' => $id, 'matched_as' => $existing_title ];
            }
        }

        // Step 4 — similar_text() score > 60%
        $best_id  = null;
        $best_pct = 0.0;
        foreach ( $this->all_opponents as $id => $existing_title ) {
            $existing_lower = strtolower( trim( $existing_title ) );
            similar_text( $name_lower, $existing_lower, $pct );
            if ( $pct > $best_pct ) {
                $best_pct = $pct;
                $best_id  = $id;
            }
        }
        if ( $best_pct > 60 && $best_id !== null ) {
            return [ 'id' => $best_id, 'matched_as' => $this->all_opponents[ $best_id ] ];
        }

        // Step 5 — Create new
        $new_id = wp_insert_post( [
            'post_type'   => 'equipes-externes',
            'post_title'  => $name,
            'post_status' => 'publish',
        ], true );

        if ( ! is_wp_error( $new_id ) ) {
            // Add to in-memory cache
            $this->all_opponents[ $new_id ] = $name;
        }

        return [ 'id' => $new_id, 'matched_as' => null ];
    }

    /**
     * Find or create the equipes-crbc taxonomy term (in-memory cache).
     */
    private function find_or_create_division( string $division ): void {
        if ( empty( $division ) ) {
            return;
        }

        $key = strtolower( $division );
        if ( isset( $this->divisions[ $key ] ) || isset( $this->divisions[ sanitize_title( $division ) ] ) ) {
            return;
        }

        $result = wp_insert_term( $division, 'equipes-crbc' );
        if ( ! is_wp_error( $result ) ) {
            $this->divisions[ $key ]                        = $result['term_id'];
            $this->divisions[ sanitize_title( $division ) ] = $result['term_id'];
        }
    }

    /**
     * Check for an existing match by date + opponent (O(1) array lookup).
     */
    private function find_existing_match( string $date, int $opponent_id ): ?int {
        $key = $date . '_' . $opponent_id;
        return $this->existing_matches[ $key ] ?? null;
    }

    /**
     * Create a match post with all meta fields.
     * Uses add_post_meta() instead of update_post_meta() on new posts to skip
     * the unnecessary SELECT check.
     */
    private function create_match_post(
        string $title,
        string $date,
        int $opponent_id,
        bool $is_away,
        string $division,
        string $score_crbc = '',
        string $score_adv = ''
    ): int|\WP_Error {
        $post_id = wp_insert_post( [
            'post_type'   => 'matchs',
            'post_title'  => $title,
            'post_status' => 'publish',
        ], true );

        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }

        add_post_meta( $post_id, 'date_du_match', $date, true );
        add_post_meta( $post_id, 'adversaire', $opponent_id, true );
        add_post_meta( $post_id, 'match_a_lexterieur', $is_away ? '1' : '0', true );

        if ( $score_crbc !== '' ) {
            add_post_meta( $post_id, 'score_crbc', $score_crbc, true );
        }
        if ( $score_adv !== '' ) {
            add_post_meta( $post_id, 'score_adversaire', $score_adv, true );
        }

        // Set taxonomy term
        if ( ! empty( $division ) ) {
            wp_set_object_terms( $post_id, $division, 'equipes-crbc' );
        }

        // Add to in-memory cache so subsequent rows don't create duplicates
        $this->existing_matches[ $date . '_' . $opponent_id ] = $post_id;

        return $post_id;
    }

    /**
     * Map header row to column letters.
     */
    private function map_header( array $row ): array {
        $map = [];
        foreach ( $row as $col => $value ) {
            $clean = trim( (string) $value );
            if ( $clean !== '' ) {
                $map[ $clean ] = $col;
            }
        }
        return $map;
    }

    /**
     * Find the last sheet matching "rechercherRencontre" pattern for calendrier files.
     */
    private function find_calendrier_sheet( Spreadsheet $spreadsheet ): ?Worksheet {
        $match = null;

        foreach ( $spreadsheet->getSheetNames() as $name ) {
            if ( stripos( $name, 'rechercherRencontre' ) !== false ) {
                $match = $spreadsheet->getSheetByName( $name );
            }
        }

        return $match;
    }
}
