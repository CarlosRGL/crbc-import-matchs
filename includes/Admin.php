<?php

namespace CRBC\ImportMatchs;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Admin {

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_menu_page' ] );

        // AJAX endpoints
        add_action( 'wp_ajax_crbc_parse_files', [ $this, 'ajax_parse_files' ] );
        add_action( 'wp_ajax_crbc_process_batch', [ $this, 'ajax_process_batch' ] );
    }

    public function add_menu_page(): void {
        add_management_page(
            'Importer les matchs',
            'Importer les matchs',
            'manage_options',
            'crbc-import-matchs',
            [ $this, 'render_page' ]
        );
    }

    /**
     * Phase 1 — Upload files, parse to rows, store in transient.
     */
    public function ajax_parse_files(): void {
        check_ajax_referer( 'crbc_import_matchs_action', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permissions insuffisantes.' ] );
        }

        $job_id   = wp_generate_uuid4();
        $job_data = [];
        $importer = new Importer();

        // Ensure temp directory exists
        $tmp_dir = wp_upload_dir()['basedir'] . '/crbc-import-tmp/';
        if ( ! is_dir( $tmp_dir ) ) {
            wp_mkdir_p( $tmp_dir );
        }

        $total_cal = 0;
        $total_res = 0;

        foreach ( [ 'calendrier', 'resultats' ] as $type ) {
            if ( empty( $_FILES[ $type ]['tmp_name'] ) || $_FILES[ $type ]['error'] !== UPLOAD_ERR_OK ) {
                continue;
            }

            $ext = strtolower( pathinfo( $_FILES[ $type ]['name'], PATHINFO_EXTENSION ) );
            if ( $ext !== 'xlsx' ) {
                wp_send_json_error( [ 'message' => "Fichier $type : seuls les fichiers .xlsx sont acceptés." ] );
            }

            // Move to persistent temp location
            $dest = $tmp_dir . $job_id . '-' . $type . '.xlsx';
            if ( ! move_uploaded_file( $_FILES[ $type ]['tmp_name'], $dest ) ) {
                wp_send_json_error( [ 'message' => "Impossible de déplacer le fichier $type." ] );
            }

            try {
                $rows = $importer->parse_file_to_rows( $dest, $type );
            } catch ( \Exception $e ) {
                @unlink( $dest );
                wp_send_json_error( [ 'message' => "Erreur parsing $type : " . $e->getMessage() ] );
            }

            $job_data[ $type ] = [
                'rows'      => $rows,
                'total'     => count( $rows ),
                'processed' => 0,
                'file_path' => $dest,
                'stats'     => [
                    'created' => 0,
                    'updated' => 0,
                    'skipped' => 0,
                    'errors'  => [],
                ],
            ];

            if ( $type === 'calendrier' ) {
                $total_cal = count( $rows );
            } else {
                $total_res = count( $rows );
            }
        }

        if ( empty( $job_data ) ) {
            wp_send_json_error( [ 'message' => 'Aucun fichier valide envoyé.' ] );
        }

        set_transient( 'crbc_import_job_' . $job_id, $job_data, 30 * MINUTE_IN_SECONDS );

        wp_send_json_success( [
            'job_id'    => $job_id,
            'total_cal' => $total_cal,
            'total_res' => $total_res,
        ] );
    }

    /**
     * Phase 2 — Process a batch of rows from the stored transient.
     */
    public function ajax_process_batch(): void {
        check_ajax_referer( 'crbc_import_matchs_action', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permissions insuffisantes.' ] );
        }

        $job_id     = sanitize_text_field( $_POST['job_id'] ?? '' );
        $file_type  = sanitize_text_field( $_POST['file_type'] ?? '' );
        $offset     = absint( $_POST['offset'] ?? 0 );
        $batch_size = absint( $_POST['batch_size'] ?? 5 );

        if ( ! $job_id || ! in_array( $file_type, [ 'calendrier', 'resultats' ], true ) ) {
            wp_send_json_error( [ 'message' => 'Paramètres invalides.' ] );
        }

        $job_data = get_transient( 'crbc_import_job_' . $job_id );
        if ( ! $job_data || ! isset( $job_data[ $file_type ] ) ) {
            wp_send_json_error( [ 'message' => 'Job introuvable ou expiré.' ] );
        }

        $file_data = $job_data[ $file_type ];
        $rows      = $file_data['rows'];
        $total     = $file_data['total'];
        $batch     = array_slice( $rows, $offset, $batch_size );

        $importer = new Importer();
        $importer->ensure_opponents_loaded();

        $batch_results = [];

        foreach ( $batch as $entry ) {
            $result = $importer->process_row_data( $entry, $file_type );

            // Handle legacy string returns (shouldn't happen but be safe)
            if ( is_string( $result ) ) {
                $result = [
                    'action'  => 'error',
                    'title'   => 'Ligne ' . ( $entry['row_num'] ?? '?' ),
                    'message' => $result,
                ];
            }

            $batch_results[] = $result;

            // Accumulate stats
            $action = $result['action'];
            if ( in_array( $action, [ 'created', 'updated', 'skipped' ], true ) ) {
                $file_data['stats'][ $action ]++;
            } elseif ( $action === 'error' ) {
                $file_data['stats']['errors'][] = $result['message'] ?? 'Erreur inconnue';
            }
        }

        $new_processed     = $offset + count( $batch );
        $file_data['processed'] = $new_processed;
        $done              = $new_processed >= $total;

        // Update transient
        $job_data[ $file_type ] = $file_data;

        if ( $done ) {
            // Clean up temp file
            if ( ! empty( $file_data['file_path'] ) && file_exists( $file_data['file_path'] ) ) {
                @unlink( $file_data['file_path'] );
            }

            // If both files are done, delete transient entirely
            $all_done = true;
            foreach ( $job_data as $key => $fdata ) {
                if ( isset( $fdata['total'] ) && $fdata['processed'] < $fdata['total'] ) {
                    $all_done = false;
                    break;
                }
            }
            if ( $all_done ) {
                delete_transient( 'crbc_import_job_' . $job_id );
            } else {
                set_transient( 'crbc_import_job_' . $job_id, $job_data, 30 * MINUTE_IN_SECONDS );
            }
        } else {
            set_transient( 'crbc_import_job_' . $job_id, $job_data, 30 * MINUTE_IN_SECONDS );
        }

        wp_send_json_success( [
            'processed'     => $new_processed,
            'total'         => $total,
            'done'          => $done,
            'batch_results' => $batch_results,
            'stats'         => $file_data['stats'],
        ] );
    }

    public function render_page(): void {
        include CRBC_IMPORT_MATCHS_PATH . 'views/admin-page.php';
    }
}
