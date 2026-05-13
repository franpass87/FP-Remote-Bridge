(function () {
    'use strict';

    if (typeof window.fpRemoteBridgeDiag !== 'object' || window.fpRemoteBridgeDiag === null) {
        return;
    }

    var config = window.fpRemoteBridgeDiag;
    var queue = [];
    var flushTimer = null;
    var isSending = false;

    function safeString(value) {
        if (value === null || value === undefined) {
            return '';
        }

        if (typeof value === 'string') {
            return value;
        }

        try {
            return String(value);
        } catch (error) {
            return '';
        }
    }

    function scheduleFlush() {
        if (flushTimer !== null) {
            return;
        }

        flushTimer = window.setTimeout(function () {
            flushTimer = null;
            flushQueue();
        }, Number(config.flushMs) || 4000);
    }

    function pushEvent(event) {
        queue.push(event);

        var batchSize = Number(config.batchSize) || 5;
        if (queue.length >= batchSize) {
            flushQueue();
            return;
        }

        scheduleFlush();
    }

    function flushQueue() {
        if (isSending || queue.length === 0) {
            return;
        }

        var batch = queue.splice(0, Number(config.batchSize) || 5);
        isSending = true;

        var body = new URLSearchParams();
        body.set('action', safeString(config.action));
        body.set('nonce', safeString(config.nonce));
        body.set('fp_bridge_hp', '');
        body.set('events', JSON.stringify(batch));

        window.fetch(safeString(config.ajaxUrl), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: body.toString()
        }).catch(function () {
            // Ignora errori di rete per non generare loop.
        }).finally(function () {
            isSending = false;
            if (queue.length > 0) {
                scheduleFlush();
            }
        });
    }

    function buildBaseEvent(type, message, extra) {
        var event = {
            type: type,
            message: safeString(message).slice(0, 2000),
            context: safeString(config.context) || 'frontend',
            page_url: safeString(window.location.href).slice(0, 500),
            user_agent: safeString(window.navigator.userAgent).slice(0, 255)
        };

        if (extra && typeof extra === 'object') {
            Object.keys(extra).forEach(function (key) {
                event[key] = extra[key];
            });
        }

        return event;
    }

    window.addEventListener('error', function (event) {
        if (!event) {
            return;
        }

        pushEvent(buildBaseEvent('javascript', event.message || 'JavaScript error', {
            source: safeString(event.filename),
            line: Number(event.lineno) || 0,
            column: Number(event.colno) || 0,
            stack: safeString(event.error && event.error.stack).slice(0, 4000)
        }));
    });

    window.addEventListener('unhandledrejection', function (event) {
        var reason = event ? event.reason : '';
        var message = safeString(reason && reason.message ? reason.message : reason);
        var stack = safeString(reason && reason.stack ? reason.stack : '').slice(0, 4000);

        pushEvent(buildBaseEvent('promise', message || 'Unhandled promise rejection', {
            stack: stack
        }));
    });

    if (config.captureConsole && window.console && typeof window.console.error === 'function') {
        var originalError = window.console.error.bind(window.console);

        window.console.error = function () {
            var parts = [];
            for (var i = 0; i < arguments.length; i += 1) {
                parts.push(safeString(arguments[i]));
            }

            pushEvent(buildBaseEvent('console', parts.join(' ').slice(0, 2000), {
                source: 'console.error'
            }));

            return originalError.apply(window.console, arguments);
        };
    }

    window.addEventListener('pagehide', function () {
        if (queue.length === 0) {
            return;
        }

        var body = new URLSearchParams();
        body.set('action', safeString(config.action));
        body.set('nonce', safeString(config.nonce));
        body.set('fp_bridge_hp', '');
        body.set('events', JSON.stringify(queue.splice(0, queue.length)));

        if (typeof navigator.sendBeacon === 'function') {
            navigator.sendBeacon(safeString(config.ajaxUrl), body);
        }
    });
}());
