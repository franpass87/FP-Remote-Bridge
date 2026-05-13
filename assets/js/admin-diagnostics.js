(function () {
    'use strict';

    var config = window.fpRemoteBridgeDiagnostics;
    if (!config || typeof config !== 'object') {
        return;
    }

    var refreshButton = document.getElementById('fpbridge-diagnostics-refresh');
    var root = document.getElementById('fpbridge-diagnostics-root');
    var updatedLabel = document.querySelector('.fpbridge-diagnostics-updated');

    if (!refreshButton || !root) {
        return;
    }

    refreshButton.addEventListener('click', function () {
        var originalText = refreshButton.textContent;
        refreshButton.disabled = true;
        refreshButton.textContent = config.i18n.refreshing || 'Aggiornamento in corso…';

        var body = new URLSearchParams();
        body.set('action', config.action);
        body.set('nonce', config.nonce);

        window.fetch(config.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: body.toString()
        })
            .then(function (response) {
                return response.json();
            })
            .then(function (payload) {
                if (!payload || !payload.success || !payload.data || !payload.data.html) {
                    throw new Error('invalid_payload');
                }

                root.innerHTML = payload.data.html;
                if (updatedLabel && payload.data.generated_at) {
                    updatedLabel.textContent = (config.i18n.updatedPrefix || 'Ultimo aggiornamento:') + ' ' + payload.data.generated_at;
                }
            })
            .catch(function () {
                window.alert(config.i18n.error || 'Impossibile aggiornare la panoramica. Riprova.');
            })
            .finally(function () {
                refreshButton.disabled = false;
                refreshButton.textContent = originalText;
            });
    });
}());
