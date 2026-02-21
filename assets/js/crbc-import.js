(function () {
    'use strict';

    var form = document.getElementById('crbc-import-form');
    var uploadSection = document.getElementById('crbc-upload-section');
    var progressSection = document.getElementById('crbc-progress-section');
    var summarySection = document.getElementById('crbc-summary-section');
    var loadingOverlay = document.getElementById('crbc-loading');
    var calProgress = document.getElementById('crbc-cal-progress');
    var resProgress = document.getElementById('crbc-res-progress');
    var logList = document.getElementById('crbc-log-list');

    var BATCH_SIZE = 5;

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        startImport();
    });

    function startImport() {
        loadingOverlay.style.display = 'flex';

        var formData = new FormData(form);
        formData.append('action', 'crbc_parse_files');
        formData.append('nonce', crbcImport.nonce);

        fetch(crbcImport.ajaxUrl, {
            method: 'POST',
            body: formData,
        })
            .then(function (r) { return r.json(); })
            .then(function (resp) {
                loadingOverlay.style.display = 'none';

                if (!resp.success) {
                    alert(resp.data && resp.data.message ? resp.data.message : 'Erreur inconnue.');
                    return;
                }

                var data = resp.data;
                uploadSection.style.display = 'none';
                progressSection.style.display = 'block';

                // Setup progress bars
                if (data.total_cal > 0) {
                    calProgress.style.display = 'block';
                    updateProgressBar('cal', 0, data.total_cal);
                }
                if (data.total_res > 0) {
                    resProgress.style.display = 'block';
                    updateProgressBar('res', 0, data.total_res);
                }

                // Process sequentially: calendrier first, then resultats
                var queue = [];
                if (data.total_cal > 0) queue.push({ type: 'calendrier', total: data.total_cal, prefix: 'cal' });
                if (data.total_res > 0) queue.push({ type: 'resultats', total: data.total_res, prefix: 'res' });

                processQueue(data.job_id, queue, 0);
            })
            .catch(function (err) {
                loadingOverlay.style.display = 'none';
                alert('Erreur réseau : ' + err.message);
            });
    }

    function processQueue(jobId, queue, index) {
        if (index >= queue.length) {
            showFinalSummary();
            return;
        }

        var item = queue[index];
        addLogHeading(item.type === 'calendrier' ? 'Calendrier' : 'Résultats');
        processBatches(jobId, item.type, item.total, item.prefix, 0, function () {
            processQueue(jobId, queue, index + 1);
        });
    }

    function processBatches(jobId, fileType, total, prefix, offset, onDone) {
        var body = new URLSearchParams();
        body.append('action', 'crbc_process_batch');
        body.append('nonce', crbcImport.nonce);
        body.append('job_id', jobId);
        body.append('file_type', fileType);
        body.append('offset', offset);
        body.append('batch_size', BATCH_SIZE);

        fetch(crbcImport.ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
        })
            .then(function (r) { return r.json(); })
            .then(function (resp) {
                if (!resp.success) {
                    addLogItem('Erreur', 'error', resp.data && resp.data.message ? resp.data.message : 'Erreur batch');
                    onDone();
                    return;
                }

                var d = resp.data;
                updateProgressBar(prefix, d.processed, d.total);

                // Append batch results to log
                if (d.batch_results) {
                    d.batch_results.forEach(function (item) {
                        var label = item.title || 'Match';
                        if (item.matched_as) {
                            label += ' (fuzzy: ' + item.matched_as + ')';
                        }
                        addLogItem(label, item.action, item.message || '');
                    });
                }

                // Store latest stats on the progress bar element for summary
                var barEl = document.getElementById('crbc-' + prefix + '-progress');
                if (barEl) barEl.dataset.stats = JSON.stringify(d.stats);

                if (d.done) {
                    onDone();
                } else {
                    processBatches(jobId, fileType, total, prefix, d.processed, onDone);
                }
            })
            .catch(function (err) {
                addLogItem('Erreur réseau', 'error', err.message);
                onDone();
            });
    }

    function updateProgressBar(prefix, current, total) {
        var bar = document.getElementById('crbc-' + prefix + '-bar');
        var label = document.getElementById('crbc-' + prefix + '-label');
        var pct = total > 0 ? Math.round((current / total) * 100) : 0;
        bar.style.width = pct + '%';
        label.textContent = current + ' / ' + total + ' matchs';
    }

    function addLogHeading(text) {
        var el = document.createElement('li');
        el.className = 'crbc-log-heading';
        el.textContent = text;
        logList.appendChild(el);
        logList.scrollTop = logList.scrollHeight;
    }

    function addLogItem(title, action, message) {
        var icons = { created: '\u2705', updated: '\uD83D\uDD04', skipped: '\u23ED\uFE0F', error: '\u274C' };
        var labels = { created: 'créé', updated: 'mis à jour', skipped: 'ignoré', error: 'erreur' };

        var el = document.createElement('li');
        el.className = 'crbc-log-item crbc-log-' + action;

        var icon = document.createElement('span');
        icon.className = 'crbc-log-icon';
        icon.textContent = icons[action] || '\u2754';

        var titleSpan = document.createElement('span');
        titleSpan.className = 'crbc-log-title';
        titleSpan.textContent = title;

        var badge = document.createElement('span');
        badge.className = 'crbc-log-badge crbc-badge-' + action;
        badge.textContent = labels[action] || action;

        el.appendChild(icon);
        el.appendChild(titleSpan);
        el.appendChild(badge);

        if (message && action === 'error') {
            var msg = document.createElement('span');
            msg.className = 'crbc-log-message';
            msg.textContent = message;
            el.appendChild(msg);
        }

        logList.appendChild(el);
        logList.scrollTop = logList.scrollHeight;
    }

    function showFinalSummary() {
        summarySection.style.display = 'block';
        var html = '';

        ['cal', 'res'].forEach(function (prefix) {
            var barEl = document.getElementById('crbc-' + prefix + '-progress');
            if (!barEl || barEl.style.display === 'none') return;

            var stats;
            try { stats = JSON.parse(barEl.dataset.stats || '{}'); } catch (e) { stats = {}; }
            var label = prefix === 'cal' ? 'Calendrier' : 'Résultats';

            html += '<div class="crbc-summary-card">';
            html += '<h3>' + label + '</h3>';
            html += '<div class="crbc-summary-row crbc-s-created">\u2705 Créés : <strong>' + (stats.created || 0) + '</strong></div>';
            html += '<div class="crbc-summary-row crbc-s-updated">\uD83D\uDD04 Mis à jour : <strong>' + (stats.updated || 0) + '</strong></div>';
            html += '<div class="crbc-summary-row crbc-s-skipped">\u23ED\uFE0F Ignorés : <strong>' + (stats.skipped || 0) + '</strong></div>';
            html += '<div class="crbc-summary-row crbc-s-errors">\u274C Erreurs : <strong>' + (stats.errors ? stats.errors.length : 0) + '</strong></div>';
            html += '</div>';
        });

        summarySection.innerHTML = '<h2>Résumé final</h2><div class="crbc-summary-grid">' + html + '</div>';
    }
})();
