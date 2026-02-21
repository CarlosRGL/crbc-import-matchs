<?php
/**
 * Plugin Name: CRBC Import Matchs
 * Description: Importer les matchs du CRBC66 depuis des fichiers Excel (.xlsx) — Calendrier et Résultats.
 * Version: 1.0.0
 * Author: CarlosRGL
 * Text Domain: crbc-import-matchs
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'CRBC_IMPORT_MATCHS_VERSION', '1.0.0' );
define( 'CRBC_IMPORT_MATCHS_PATH', plugin_dir_path( __FILE__ ) );
define( 'CRBC_IMPORT_MATCHS_URL', plugin_dir_url( __FILE__ ) );

// Autoload Composer dependencies
$autoload = CRBC_IMPORT_MATCHS_PATH . 'vendor/autoload.php';
if ( file_exists( $autoload ) ) {
    require_once $autoload;
}

// Boot admin page
add_action( 'plugins_loaded', function () {
    if ( is_admin() ) {
        new \CRBC\ImportMatchs\Admin();
    }
} );
