<?php
/**
 * Plugin Name: CRBC Import Matchs
 * Description: Importer les matchs du CRBC66 depuis des fichiers Excel (.xlsx) — Calendrier et Résultats.
 * Version: 1.3.1
 * Author: CarlosRGL
 * Text Domain: crbc-import-matchs
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'CRBC_IMPORT_MATCHS_VERSION', '1.3.1' );
define( 'CRBC_IMPORT_MATCHS_PATH', plugin_dir_path( __FILE__ ) );
define( 'CRBC_IMPORT_MATCHS_URL', plugin_dir_url( __FILE__ ) );

// Autoload Composer dependencies
$autoload = CRBC_IMPORT_MATCHS_PATH . 'vendor/autoload.php';
if ( file_exists( $autoload ) ) {
    require_once $autoload;
}

// ─── Auto-updates via GitHub ───────────────────────────────────────────────
// Vérifie les nouvelles releases sur https://github.com/CarlosRGL/crbc-import-matchs
// Pour un repo privé, définir CRBC_GITHUB_TOKEN dans wp-config.php :
//   define( 'CRBC_GITHUB_TOKEN', 'ghp_...' );
add_action( 'plugins_loaded', function () {
    if ( ! class_exists( 'YahnisElsts\PluginUpdateChecker\v5\PucFactory' ) ) {
        return;
    }

    $updateChecker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        'https://github.com/CarlosRGL/crbc-import-matchs/',
        __FILE__,
        'crbc-import-matchs'
    );

    // Utiliser les release assets (le .zip de la release) plutôt que le source code
    $updateChecker->getVcsApi()->enableReleaseAssets();

    // Authentification pour repo privé (optionnel si le repo est public)
    if ( defined( 'CRBC_GITHUB_TOKEN' ) && CRBC_GITHUB_TOKEN ) {
        $updateChecker->setAuthentication( CRBC_GITHUB_TOKEN );
    }
} );

// Boot admin page
add_action( 'plugins_loaded', function () {
    if ( is_admin() ) {
        new \CRBC\ImportMatchs\Admin();
    }
} );

// Enqueue admin JS on plugin page only
add_action( 'admin_enqueue_scripts', function ( $hook ) {
    if ( $hook !== 'matchs_page_crbc-import-matchs' ) {
        return;
    }

    wp_enqueue_script(
        'crbc-import-js',
        CRBC_IMPORT_MATCHS_URL . 'assets/js/crbc-import.js',
        [],
        CRBC_IMPORT_MATCHS_VERSION,
        true
    );

    wp_localize_script( 'crbc-import-js', 'crbcImport', [
        'ajaxUrl' => admin_url( 'admin-ajax.php' ),
        'nonce'   => wp_create_nonce( 'crbc_import_matchs_action' ),
    ] );
} );
