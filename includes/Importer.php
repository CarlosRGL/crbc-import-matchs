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
     * Import calendrier file (future matches, no scores).
     */
    public function import_calendrier( string $filepath ): array {
        $stats = [ 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => [] ];

        try {
            $spreadsheet = IOFactory::load( $filepath );
            $sheet = $this->find_calendrier_sheet( $spreadsheet );
            if ( ! $sheet ) {
                $stats['errors'][] = 'Feuille "rechercherRencontre" introuvable dans le fichier.';
                return $stats;
            }
        } catch ( \Exception $e ) {
            $stats['errors'][] = 'Impossible de lire le fichier : ' . $e->getMessage();
            return $stats;
        }

        $rows = $sheet->toArray( null, true, true, true );
        $header = null;

        foreach ( $rows as $row_num => $row ) {
            if ( ! $header ) {
                $header = $this->map_header( $row );
                continue;
            }

            $result = $this->process_row( $row, $header, 'calendrier', $row_num );

            if ( $result === 'created' ) {
                $stats['created']++;
            } elseif ( $result === 'skipped' ) {
                $stats['skipped']++;
            } elseif ( $result === 'skip_exempt' ) {
                // silently skip exempt rows
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
            $spreadsheet = IOFactory::load( $filepath );
            $sheet = $spreadsheet->getSheet( 0 );
        } catch ( \Exception $e ) {
            $stats['errors'][] = 'Impossible de lire le fichier : ' . $e->getMessage();
            return $stats;
        }

        $rows = $sheet->toArray( null, true, true, true );
        $header = null;

        foreach ( $rows as $row_num => $row ) {
            if ( ! $header ) {
                $header = $this->map_header( $row );
                continue;
            }

            $result = $this->process_row( $row, $header, 'resultats', $row_num );

            if ( $result === 'created' ) {
                $stats['created']++;
            } elseif ( $result === 'updated' ) {
                $stats['updated']++;
            } elseif ( $result === 'skipped' ) {
                $stats['skipped']++;
            } elseif ( $result === 'skip_exempt' ) {
                // silently skip exempt rows
            } elseif ( is_string( $result ) ) {
                $stats['errors'][] = $result;
            }
        }

        return $stats;
    }

    /**
     * Process a single row from either file type.
     *
     * @return string 'created'|'updated'|'skipped'|'skip_exempt' or error message
     */
    private function process_row( array $row, array $header, string $type, int $row_num ): string {
        $division = trim( $row[ $header['Division'] ] ?? '' );
        $equipe1  = trim( $row[ $header['Equipe 1'] ] ?? '' );
        $equipe2  = trim( $row[ $header['Equipe 2'] ] ?? '' );
        $date_raw = $row[ $header['Date de rencontre'] ] ?? '';
        $heure_raw = $row[ $header['Heure'] ] ?? '';

        // Skip empty rows
        if ( empty( $equipe1 ) && empty( $equipe2 ) ) {
            return 'skip_exempt';
        }

        // Skip exempt
        if ( strcasecmp( $equipe1, 'Exempt' ) === 0 || strcasecmp( $equipe2, 'Exempt' ) === 0 ) {
            return 'skip_exempt';
        }

        // Detect home/away
        if ( stripos( $equipe1, self::TEAM_NAME ) !== false ) {
            $is_away = false;
            $opponent_raw = $equipe2;
        } elseif ( stripos( $equipe2, self::TEAM_NAME ) !== false ) {
            $is_away = true;
            $opponent_raw = $equipe1;
        } else {
            return "Ligne $row_num : ni Equipe 1 ni Equipe 2 ne contient « " . self::TEAM_NAME . " ».";
        }

        // Clean opponent name
        $opponent = $this->clean_opponent_name( $opponent_raw );
        if ( empty( $opponent ) ) {
            return "Ligne $row_num : nom d'adversaire vide après nettoyage.";
        }

        // Parse date
        $match_date = $this->parse_date( $date_raw, $heure_raw );
        if ( ! $match_date ) {
            return "Ligne $row_num : date invalide ($date_raw $heure_raw).";
        }

        // Find or create opponent (equipes-externes)
        $opponent_id = $this->find_or_create_opponent( $opponent );
        if ( is_wp_error( $opponent_id ) ) {
            return "Ligne $row_num : " . $opponent_id->get_error_message();
        }

        // Find or create division taxonomy term
        $this->find_or_create_division( $division );

        // Build title
        $title = $is_away
            ? $opponent . ' - CRBC'
            : 'CRBC - ' . $opponent;

        // Duplicate check
        $existing = $this->find_existing_match( $match_date, $opponent_id );

        if ( $type === 'calendrier' ) {
            if ( $existing ) {
                return 'skipped';
            }

            $post_id = $this->create_match_post( $title, $match_date, $opponent_id, $is_away, $division );
            if ( is_wp_error( $post_id ) ) {
                return "Ligne $row_num : " . $post_id->get_error_message();
            }

            return 'created';
        }

        // Résultats
        $score1 = trim( $row[ $header['Score 1'] ] ?? '' );
        $score2 = trim( $row[ $header['Score 2'] ] ?? '' );

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
            return 'updated';
        }

        $post_id = $this->create_match_post( $title, $match_date, $opponent_id, $is_away, $division, $score_crbc, $score_adv );
        if ( is_wp_error( $post_id ) ) {
            return "Ligne $row_num : " . $post_id->get_error_message();
        }

        return 'created';
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
     * Find equipes-externes post by name (case-insensitive), or create one.
     */
    private function find_or_create_opponent( string $name ): int|\WP_Error {
        $query = new \WP_Query( [
            'post_type'      => 'equipes-externes',
            'posts_per_page' => 1,
            'post_status'    => 'any',
            'title'          => $name,
            'no_found_rows'  => true,
        ] );

        // WP_Query 'title' is exact but case-sensitive; fallback to manual search
        if ( $query->have_posts() ) {
            return $query->posts[0]->ID;
        }

        // Case-insensitive search
        global $wpdb;
        $post_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'equipes-externes' AND post_status IN ('publish','draft') AND LOWER(TRIM(post_title)) = LOWER(TRIM(%s)) LIMIT 1",
                $name
            )
        );

        if ( $post_id ) {
            return (int) $post_id;
        }

        // Create new
        $new_id = wp_insert_post( [
            'post_type'   => 'equipes-externes',
            'post_title'  => $name,
            'post_status' => 'publish',
        ], true );

        return $new_id;
    }

    /**
     * Find or create the equipes-crbc taxonomy term.
     */
    private function find_or_create_division( string $division ): void {
        if ( empty( $division ) ) {
            return;
        }

        $term = get_term_by( 'name', $division, 'equipes-crbc' );
        if ( $term ) {
            return;
        }

        $term = get_term_by( 'slug', sanitize_title( $division ), 'equipes-crbc' );
        if ( $term ) {
            return;
        }

        wp_insert_term( $division, 'equipes-crbc' );
    }

    /**
     * Check for an existing match by date + opponent.
     */
    private function find_existing_match( string $date, int $opponent_id ): ?int {
        $query = new \WP_Query( [
            'post_type'      => 'matchs',
            'posts_per_page' => 1,
            'post_status'    => 'any',
            'no_found_rows'  => true,
            'meta_query'     => [
                'relation' => 'AND',
                [
                    'key'   => 'date_du_match',
                    'value' => $date,
                ],
                [
                    'key'   => 'adversaire',
                    'value' => $opponent_id,
                ],
            ],
        ] );

        if ( $query->have_posts() ) {
            return $query->posts[0]->ID;
        }

        return null;
    }

    /**
     * Create a match post with all meta fields.
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

        update_post_meta( $post_id, 'date_du_match', $date );
        update_post_meta( $post_id, 'adversaire', $opponent_id );
        update_post_meta( $post_id, 'match_a_lexterieur', $is_away ? '1' : '0' );

        if ( $score_crbc !== '' ) {
            update_post_meta( $post_id, 'score_crbc', $score_crbc );
        }
        if ( $score_adv !== '' ) {
            update_post_meta( $post_id, 'score_adversaire', $score_adv );
        }

        // Set taxonomy term
        if ( ! empty( $division ) ) {
            wp_set_object_terms( $post_id, $division, 'equipes-crbc' );
        }

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
