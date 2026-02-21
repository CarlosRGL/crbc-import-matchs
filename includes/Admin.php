<?php

namespace CRBC\ImportMatchs;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Admin {

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_menu_page' ] );
        add_action( 'admin_init', [ $this, 'handle_upload' ] );
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

    public function handle_upload(): void {
        if ( ! isset( $_POST['crbc_import_matchs_nonce'] ) ) {
            return;
        }

        if ( ! wp_verify_nonce( $_POST['crbc_import_matchs_nonce'], 'crbc_import_matchs_action' ) ) {
            wp_die( 'Nonce invalide.' );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Permissions insuffisantes.' );
        }

        $results = [];
        $importer = new Importer();

        // Calendrier file
        if ( ! empty( $_FILES['calendrier']['tmp_name'] ) ) {
            $file = $_FILES['calendrier'];
            $validation = $this->validate_file( $file );
            if ( is_wp_error( $validation ) ) {
                $results['calendrier'] = [
                    'label'   => 'Calendrier',
                    'created' => 0,
                    'updated' => 0,
                    'skipped' => 0,
                    'errors'  => [ $validation->get_error_message() ],
                ];
            } else {
                $results['calendrier'] = $importer->import_calendrier( $file['tmp_name'] );
                $results['calendrier']['label'] = 'Calendrier';
            }
        }

        // Résultats file
        if ( ! empty( $_FILES['resultats']['tmp_name'] ) ) {
            $file = $_FILES['resultats'];
            $validation = $this->validate_file( $file );
            if ( is_wp_error( $validation ) ) {
                $results['resultats'] = [
                    'label'   => 'Résultats',
                    'created' => 0,
                    'updated' => 0,
                    'skipped' => 0,
                    'errors'  => [ $validation->get_error_message() ],
                ];
            } else {
                $results['resultats'] = $importer->import_resultats( $file['tmp_name'] );
                $results['resultats']['label'] = 'Résultats';
            }
        }

        if ( ! empty( $results ) ) {
            set_transient( 'crbc_import_results', $results, 60 );
        }

        wp_safe_redirect( admin_url( 'tools.php?page=crbc-import-matchs&imported=1' ) );
        exit;
    }

    private function validate_file( array $file ): true|\WP_Error {
        if ( $file['error'] !== UPLOAD_ERR_OK ) {
            return new \WP_Error( 'upload_error', 'Erreur lors de l\'upload du fichier.' );
        }

        $ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
        if ( $ext !== 'xlsx' ) {
            return new \WP_Error( 'invalid_type', 'Seuls les fichiers .xlsx sont acceptés.' );
        }

        return true;
    }

    public function render_page(): void {
        $results = get_transient( 'crbc_import_results' );
        if ( $results ) {
            delete_transient( 'crbc_import_results' );
        }

        include CRBC_IMPORT_MATCHS_PATH . 'views/admin-page.php';
    }
}
