<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="wrap">
    <h1>Importer les matchs</h1>

    <?php if ( ! empty( $results ) ) : ?>
        <div class="crbc-import-results">
            <?php foreach ( $results as $key => $data ) : ?>
                <div class="crbc-result-section">
                    <h2><?php echo esc_html( $data['label'] ); ?></h2>
                    <table class="widefat striped">
                        <tbody>
                            <tr>
                                <td>&#9989; Créés</td>
                                <td><strong><?php echo (int) $data['created']; ?></strong></td>
                            </tr>
                            <?php if ( $key === 'resultats' ) : ?>
                            <tr>
                                <td>&#128260; Mis à jour</td>
                                <td><strong><?php echo (int) $data['updated']; ?></strong></td>
                            </tr>
                            <?php endif; ?>
                            <tr>
                                <td>&#9193; Ignorés — doublons</td>
                                <td><strong><?php echo (int) $data['skipped']; ?></strong></td>
                            </tr>
                            <tr>
                                <td>&#10060; Erreurs</td>
                                <td><strong><?php echo count( $data['errors'] ); ?></strong></td>
                            </tr>
                        </tbody>
                    </table>

                    <?php if ( ! empty( $data['errors'] ) ) : ?>
                        <div class="notice notice-error" style="margin-top: 10px;">
                            <ul style="margin: 0.5em 0; padding-left: 1.5em;">
                                <?php foreach ( $data['errors'] as $error ) : ?>
                                    <li><?php echo esc_html( $error ); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <hr>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data">
        <?php wp_nonce_field( 'crbc_import_matchs_action', 'crbc_import_matchs_nonce' ); ?>

        <div class="crbc-upload-zones">
            <div class="crbc-upload-zone">
                <h2>Calendrier (prochains matchs)</h2>
                <p class="description">Fichier Excel (.xlsx) contenant les rencontres à venir, sans scores.</p>
                <input type="file" name="calendrier" accept=".xlsx">
            </div>

            <div class="crbc-upload-zone">
                <h2>Résultats (matchs joués)</h2>
                <p class="description">Fichier Excel (.xlsx) contenant les résultats avec scores.</p>
                <input type="file" name="resultats" accept=".xlsx">
            </div>
        </div>

        <?php submit_button( 'Importer', 'primary', 'submit', true ); ?>
    </form>
</div>

<style>
    .crbc-upload-zones {
        display: flex;
        gap: 30px;
        margin-top: 20px;
    }
    .crbc-upload-zone {
        flex: 1;
        background: #fff;
        border: 1px solid #c3c4c7;
        padding: 20px;
        border-radius: 4px;
    }
    .crbc-upload-zone h2 {
        margin-top: 0;
    }
    .crbc-upload-zone input[type="file"] {
        margin-top: 10px;
    }
    .crbc-import-results {
        display: flex;
        gap: 30px;
        margin: 20px 0;
    }
    .crbc-result-section {
        flex: 1;
        background: #fff;
        border: 1px solid #c3c4c7;
        padding: 20px;
        border-radius: 4px;
    }
    .crbc-result-section h2 {
        margin-top: 0;
    }
</style>
