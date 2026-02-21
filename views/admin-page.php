<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="wrap">
    <h1>Importer les matchs</h1>

    <!-- Upload Section -->
    <div id="crbc-upload-section">
        <form id="crbc-import-form" enctype="multipart/form-data">
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

            <p class="submit">
                <button type="submit" class="button button-primary" id="crbc-submit-btn">Analyser les fichiers</button>
            </p>
        </form>
    </div>

    <!-- Loading Overlay -->
    <div id="crbc-loading" style="display:none;">
        <div class="crbc-spinner"></div>
        <p>Analyse des fichiers en cours…</p>
    </div>

    <!-- Progress Section -->
    <div id="crbc-progress-section" style="display:none;">

        <div id="crbc-cal-progress" class="crbc-progress-block" style="display:none;">
            <h3>Calendrier</h3>
            <div class="crbc-progress-bar-wrap">
                <div class="crbc-progress-bar" id="crbc-cal-bar"></div>
            </div>
            <span class="crbc-progress-label" id="crbc-cal-label">0 / 0 matchs</span>
        </div>

        <div id="crbc-res-progress" class="crbc-progress-block" style="display:none;">
            <h3>Résultats</h3>
            <div class="crbc-progress-bar-wrap">
                <div class="crbc-progress-bar" id="crbc-res-bar"></div>
            </div>
            <span class="crbc-progress-label" id="crbc-res-label">0 / 0 matchs</span>
        </div>

        <h3>Journal d'import</h3>
        <ul id="crbc-log-list" class="crbc-log"></ul>
    </div>

    <!-- Final Summary -->
    <div id="crbc-summary-section" style="display:none;"></div>
</div>

<style>
    /* Upload zones */
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
    .crbc-upload-zone h2 { margin-top: 0; }
    .crbc-upload-zone input[type="file"] { margin-top: 10px; }

    /* Loading */
    #crbc-loading {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        padding: 60px 0;
    }
    .crbc-spinner {
        width: 40px;
        height: 40px;
        border: 4px solid #c3c4c7;
        border-top-color: #2271b1;
        border-radius: 50%;
        animation: crbc-spin 0.8s linear infinite;
    }
    @keyframes crbc-spin {
        to { transform: rotate(360deg); }
    }
    #crbc-loading p {
        margin-top: 15px;
        color: #50575e;
        font-size: 14px;
    }

    /* Progress */
    .crbc-progress-block {
        background: #fff;
        border: 1px solid #c3c4c7;
        border-radius: 4px;
        padding: 15px 20px;
        margin-bottom: 15px;
    }
    .crbc-progress-block h3 { margin: 0 0 10px; }
    .crbc-progress-bar-wrap {
        background: #e5e5e5;
        border-radius: 4px;
        height: 24px;
        overflow: hidden;
    }
    .crbc-progress-bar {
        background: #2271b1;
        height: 100%;
        width: 0%;
        border-radius: 4px;
        transition: width 0.3s ease;
    }
    .crbc-progress-label {
        display: inline-block;
        margin-top: 6px;
        font-size: 13px;
        color: #50575e;
    }

    /* Log */
    .crbc-log {
        background: #fff;
        border: 1px solid #c3c4c7;
        border-radius: 4px;
        max-height: 400px;
        overflow-y: auto;
        margin: 0;
        padding: 0;
        list-style: none;
        font-family: SFMono-Regular, Consolas, "Liberation Mono", Menlo, monospace;
        font-size: 12px;
    }
    .crbc-log-heading {
        padding: 10px 15px;
        background: #f0f0f1;
        font-weight: 600;
        font-size: 13px;
        border-bottom: 1px solid #c3c4c7;
    }
    .crbc-log-item {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 6px 15px;
        border-bottom: 1px solid #f0f0f1;
    }
    .crbc-log-icon { flex-shrink: 0; }
    .crbc-log-title { flex: 1; }
    .crbc-log-badge {
        font-size: 11px;
        padding: 2px 8px;
        border-radius: 3px;
        font-weight: 500;
        white-space: nowrap;
    }
    .crbc-badge-created { background: #d1fae5; color: #065f46; }
    .crbc-badge-updated { background: #dbeafe; color: #1e40af; }
    .crbc-badge-skipped { background: #f3f4f6; color: #6b7280; }
    .crbc-badge-error   { background: #fee2e2; color: #991b1b; }
    .crbc-log-message {
        display: block;
        width: 100%;
        font-size: 11px;
        color: #991b1b;
        padding-left: 28px;
    }

    /* Summary */
    .crbc-summary-grid {
        display: flex;
        gap: 20px;
        margin-top: 10px;
    }
    .crbc-summary-card {
        flex: 1;
        background: #fff;
        border: 1px solid #c3c4c7;
        border-radius: 4px;
        padding: 20px;
    }
    .crbc-summary-card h3 { margin: 0 0 12px; }
    .crbc-summary-row {
        padding: 4px 0;
        font-size: 14px;
    }
</style>
