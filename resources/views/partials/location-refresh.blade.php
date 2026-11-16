@if (\\App\\Http\\Middleware\\EnsureWelcomeAccepted::isAccepted(request()))
<script id="location-refresh">
(function () {
    'use strict';

    if (!('geolocation' in navigator) || !('fetch' in window)) {
        return;
    }

    var ENDPOINT = @json(route('location.refresh'));
    var CSRF_TOKEN = @json(csrf_token());
    var MAX_ACCURACY_METERS = @json(
        \\App\\Services\\Geo\\VisitorLocationResolver::MAX_ACCURACY_METERS
    );

    var MIN_INTERVAL_MS = 30 * 60 * 1000;
    var ACQUISITION_WINDOW_MS = 12000;
    var STORAGE_KEY = 'ti_vagas_location_refresh';
    var LOCATION_MODE = @json(session('location_mode', 'limited'));

    var running = false;
    var attemptedThisPage = false;
    var memoryState = {};
    var statusElement = null;
    var retryButton = null;

    function readState() {
        try {
            return JSON.parse(window.localStorage.getItem(STORAGE_KEY) || '{}') || {};
        } catch (error) {
            return memoryState;
        }
    }

    function writeState(state) {
        memoryState = state;
        try {
            window.localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
        } catch (error) {
            // O fluxo continua funcionando sem armazenamento local.
        }
    }

    function clearStatus() {
        if (statusElement && statusElement.parentNode) {
            statusElement.parentNode.removeChild(statusElement);
        }
        statusElement = null;
        retryButton = null;
    }

    function showStatus(message, actionLabel, action) {
        if (!statusElement) {
            statusElement = document.createElement('div');
            statusElement.id = 'location-refresh-status';
            statusElement.setAttribute('role', 'status');
            statusElement.setAttribute('aria-live', 'polite');
            statusElement.style.cssText =
                'position:fixed;left:16px;right:16px;bottom:16px;'
                + 'z-index:9998;margin:0 auto;max-width:620px;'
                + 'padding:12px 14px;background:#fff;color:#202938;'
                + 'border:1px solid #cbd5e1;border-radius:8px;'
                + 'box-shadow:0 4px 16px rgba(0,0,0,.12);'
                + 'font-size:14px;display:flex;gap:12px;'
                + 'align-items:center;justify-content:space-between;';
            document.body.appendChild(statusElement);
        }

        statusElement.textContent = message;

        if (retryButton && retryButton.parentNode) {
            retryButton.parentNode.removeChild(retryButton);
        }
        retryButton = null;

        if (actionLabel && typeof action === 'function') {
            retryButton = document.createElement('button');
            retryButton.type = 'button';
            retryButton.textContent = actionLabel;
            retryButton.style.cssText =
                'flex:none;border:0;border-radius:6px;padding:8px 12px;'
                + 'background:#2563eb;color:#fff;cursor:pointer;';
            retryButton.addEventListener('click', action);
            statusElement.appendChild(retryButton);
        }
    }

    function hasCookieModal() {
        return !!document.querySelector('.cookie-modal-overlay');
    }

    function distanceKm(lat1, lon1, lat2, lon2) {
        var toRad = function (value) { return value * Math.PI / 180; };
        var dLat = toRad(lat2 - lat1);
        var dLon = toRad(lon2 - lon1);
        var a = Math.pow(Math.sin(dLat / 2), 2)
            + Math.cos(toRad(lat1)) * Math.cos(toRad(lat2))
            * Math.pow(Math.sin(dLon / 2), 2);
        return 2 * 6371 * Math.asin(Math.min(1, Math.sqrt(a)));
    }

    function notifyAndReload(region) {
        var notice = document.createElement('div');
        notice.setAttribute('role', 'status');
        notice.textContent = 'Sua região mudou para ' + region + '. Atualizando as vagas...';
        notice.style.cssText =
            'position:fixed;left:50%;bottom:24px;transform:translateX(-50%);'
            + 'background:#202938;color:#fff;padding:12px 18px;'
            + 'border-radius:8px;font-size:14px;z-index:9999;max-width:90vw;';
        document.body.appendChild(notice);

        window.setTimeout(function () {
            if (window.location.pathname.indexOf('/vagas/') === 0) {
                window.location.assign('/');
                return;
            }
            var url = new URL(window.location.href);
            url.searchParams.delete('page');
            window.location.assign(url.toString());
        }, 1200);
    }

    function send(position) {
        var coords = position.coords;

        if (!Number.isFinite(coords.accuracy) || coords.accuracy > MAX_ACCURACY_METERS) {
            showStatus(
                'A melhor precisão obtida foi de '
                + Math.round(coords.accuracy).toLocaleString('pt-BR')
                + ' m. O limite para liberar vagas presenciais e híbridas é 5.000 m. '
                + 'Ative a localização do Windows ou tente em um dispositivo com localização mais precisa.',
                'Tentar novamente',
                function () { requestLocation(true); }
            );
            return Promise.resolve();
        }

        var state = readState();
        if (
            typeof state.lat === 'number'
            && typeof state.lon === 'number'
            && LOCATION_MODE === 'available'
            && distanceKm(state.lat, state.lon, coords.latitude, coords.longitude) < 1
        ) {
            clearStatus();
            return Promise.resolve();
        }

        return fetch(ENDPOINT, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': CSRF_TOKEN,
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({
                latitude: coords.latitude,
                longitude: coords.longitude,
                accuracy: coords.accuracy,
                captured_at: position.timestamp
            })
        }).then(function (response) {
            if (!response.ok) {
                var error = new Error('HTTP ' + response.status);
                error.status = response.status;
                throw error;
            }
            return response.json();
        }).then(function (result) {
            var next = readState();
            next.lat = coords.latitude;
            next.lon = coords.longitude;
            next.checkedAt = Date.now();
            writeState(next);

            if (result.updated) {
                LOCATION_MODE = 'available';
                clearStatus();
                notifyAndReload(result.region);
                return;
            }

            if (result.reason === 'inaccurate') {
                showStatus('O servidor recebeu uma precisão acima de 5.000 m. Tente novamente.', 'Tentar novamente', function () { requestLocation(true); });
            } else if (result.reason === 'unverified') {
                showStatus('Não foi possível validar esta leitura. Aguarde um pouco e tente novamente.', 'Tentar novamente', function () { requestLocation(true); });
            } else if (result.reason === 'unresolved') {
                showStatus('Não foi possível identificar um município brasileiro com esta posição. Confira a localização do dispositivo e tente novamente.', 'Tentar novamente', function () { requestLocation(true); });
            } else {
                clearStatus();
            }
        }).catch(function (error) {
            var message = error.status === 419
                ? 'Sua sessão expirou. Atualize a página e tente novamente.'
                : error.status === 429
                    ? 'Muitas tentativas em pouco tempo. Aguarde um minuto antes de tentar novamente.'
                    : 'Não foi possível enviar sua localização ao servidor. Verifique a conexão e tente novamente.';
            showStatus(message, 'Tentar novamente', function () { requestLocation(true); });
        });
    }

    function acquireBestPosition() {
        return new Promise(function (resolve, reject) {
            var watchId = null;
            var best = null;
            var finished = false;
            var deadline;

            function finish(error) {
                if (finished) return;
                finished = true;
                window.clearTimeout(deadline);
                if (watchId !== null) navigator.geolocation.clearWatch(watchId);
                if (best) resolve(best);
                else reject(error || new Error('POSITION_UNAVAILABLE'));
            }

            watchId = navigator.geolocation.watchPosition(function (position) {
                if (!best || position.coords.accuracy < best.coords.accuracy) {
                    best = position;
                }
                if (position.coords.accuracy <= MAX_ACCURACY_METERS) {
                    finish();
                }
            }, function (error) {
                finish(error);
            }, {
                enableHighAccuracy: true,
                timeout: 25000,
                maximumAge: 0
            });

            deadline = window.setTimeout(function () {
                finish(best ? null : new Error('TIMEOUT'));
            }, ACQUISITION_WINDOW_MS);
        });
    }

    function requestLocation(force) {
        if (running || (attemptedThisPage && !force) || hasCookieModal()) {
            return;
        }

        running = true;
        attemptedThisPage = true;

        if (retryButton) retryButton.disabled = true;
        showStatus('Obtendo a melhor localização disponível. Isso pode levar alguns segundos...');

        acquireBestPosition().then(function (position) {
            var state = readState();
            state.lastAttemptAt = Date.now();
            writeState(state);
            return send(position);
        }).catch(function (error) {
            var denied = error && error.code === error.PERMISSION_DENIED;
            var unavailable = error && error.code === error.POSITION_UNAVAILABLE;
            var message = denied
                ? 'A permissão de localização foi bloqueada. Libere a localização deste site nas configurações do navegador e tente novamente.'
                : unavailable
                    ? 'O dispositivo não conseguiu determinar sua posição. Confira se a localização do Windows está ativada.'
                    : 'A localização demorou para responder. Confira as configurações do dispositivo e tente novamente.';
            showStatus(message, denied ? null : 'Tentar novamente', function () { requestLocation(true); });
        }).finally(function () {
            running = false;
            if (retryButton) retryButton.disabled = false;
        });
    }

    function getPermissionState() {
        if (!navigator.permissions || typeof navigator.permissions.query !== 'function') {
            return Promise.resolve('unknown');
        }
        return navigator.permissions.query({ name: 'geolocation' })
            .then(function (status) { return status.state; })
            .catch(function () { return 'unknown'; });
    }

    function check() {
        if (running || document.visibilityState !== 'visible' || hasCookieModal()) return;

        getPermissionState().then(function (permissionState) {
            var state = readState();
            var last = typeof state.lastAttemptAt === 'number' ? state.lastAttemptAt : 0;
            var due = Date.now() - last >= MIN_INTERVAL_MS;

            if (permissionState === 'granted') {
                if (due) {
                    requestLocation(false);
                } else if (LOCATION_MODE === 'limited') {
                    showStatus(
                        'Sua localização ainda não foi confirmada com precisão suficiente. Você pode tentar novamente agora.',
                        'Tentar novamente',
                        function () { requestLocation(true); }
                    );
                }
                return;
            }

            if (permissionState === 'denied') {
                showStatus('A localização está bloqueada para este site. Você pode liberar a permissão nas configurações do navegador.');
                return;
            }

            showStatus(
                'Ative a localização para encontrar vagas presenciais e híbridas próximas. Você também pode continuar usando o portal sem informar sua localização.',
                'Ativar localização',
                function () { requestLocation(true); }
            );
        });
    }

    // Com aviso de cookies aberto, a localização aguarda a escolha do visitante.
    check();

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') check();
    });
})();
</script>
@endif
