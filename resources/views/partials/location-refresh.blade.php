@if (\App\Http\Middleware\EnsureWelcomeAccepted::isAccepted(request()))
<script id="location-refresh">
(function () {
    'use strict';

    if (!('geolocation' in navigator) || !('fetch' in window)) {
        return;
    }

    var ENDPOINT = @json(route('location.refresh'));
    var CSRF_TOKEN = @json(csrf_token());
    var MAX_ACCURACY_METERS = @json(
        \App\Services\Geo\VisitorLocationResolver::MAX_ACCURACY_METERS
    );

    var MIN_INTERVAL_MS = 5 * 60 * 1000;
    var PERIODIC_MS = 15 * 60 * 1000;
    var MIN_MOVE_KM = 1;
    var LOCATION_MODE = @json(session('location_mode', 'limited'));

    var STORAGE_KEY = 'ti_vagas_location_refresh';

    var running = false;
    var attemptedThisPage = false;
    var memoryState = {};
    var statusElement = null;
    var retryButton = null;

    function showStatus(message, canRetry) {
        if (!statusElement) {
            statusElement = document.createElement('div');
            statusElement.id = 'location-refresh-status';
            statusElement.setAttribute('role', 'status');
            statusElement.style.cssText =
                'position:fixed;left:16px;right:16px;bottom:16px;'
                + 'z-index:9998;margin:0 auto;max-width:560px;'
                + 'padding:12px 14px;background:#fff;color:#202938;'
                + 'border:1px solid #cbd5e1;border-radius:8px;'
                + 'box-shadow:0 4px 16px rgba(0,0,0,.12);'
                + 'font-size:14px;display:flex;gap:12px;'
                + 'align-items:center;justify-content:space-between;';
            document.body.appendChild(statusElement);
        }

        statusElement.textContent = message;

        if (canRetry) {
            retryButton = document.createElement('button');
            retryButton.type = 'button';
            retryButton.textContent = 'Tentar novamente';
            retryButton.style.cssText =
                'flex:none;border:0;border-radius:6px;padding:8px 12px;'
                + 'background:#2563eb;color:#fff;cursor:pointer;';
            retryButton.addEventListener('click', function () {
                attemptedThisPage = false;
                requestLocation('granted', true);
            });
            statusElement.appendChild(retryButton);
        } else if (retryButton && retryButton.parentNode) {
            retryButton.parentNode.removeChild(retryButton);
            retryButton = null;
        }
    }

    function clearStatus() {
        if (statusElement && statusElement.parentNode) {
            statusElement.parentNode.removeChild(statusElement);
        }

        statusElement = null;
        retryButton = null;
    }

    function readState() {
        try {
            var raw = window.localStorage.getItem(STORAGE_KEY);

            return raw ? JSON.parse(raw) || {} : {};
        } catch (error) {
            return memoryState;
        }
    }

    function writeState(state) {
        memoryState = state;

        try {
            window.localStorage.setItem(
                STORAGE_KEY,
                JSON.stringify(state)
            );
        } catch (error) {
            // Sem armazenamento local, segue com a memória da página.
        }
    }

    function distanceKm(lat1, lon1, lat2, lon2) {
        var toRad = function (value) {
            return value * Math.PI / 180;
        };

        var dLat = toRad(lat2 - lat1);
        var dLon = toRad(lon2 - lon1);

        var a = Math.pow(Math.sin(dLat / 2), 2)
            + Math.cos(toRad(lat1))
            * Math.cos(toRad(lat2))
            * Math.pow(Math.sin(dLon / 2), 2);

        return 2 * 6371 * Math.asin(
            Math.min(1, Math.sqrt(a))
        );
    }

    function getPermissionState() {
        if (
            !navigator.permissions
            || typeof navigator.permissions.query !== 'function'
        ) {
            return Promise.resolve(null);
        }

        return navigator.permissions
            .query({ name: 'geolocation' })
            .then(function (status) {
                return status;
            })
            .catch(function () {
                return null;
            });
    }

    function notifyAndReload(region) {
        var notice = document.createElement('div');

        notice.setAttribute('role', 'status');

        notice.textContent =
            'Sua região mudou para ' + region
            + '. Atualizando as vagas...';

        notice.style.cssText =
            'position:fixed;left:50%;bottom:24px;'
            + 'transform:translateX(-50%);'
            + 'background:#202938;color:#fff;'
            + 'padding:12px 18px;border-radius:8px;'
            + 'font-size:14px;z-index:9999;'
            + 'max-width:90vw;'
            + 'box-shadow:0 4px 16px rgba(0,0,0,.25);';

        document.body.appendChild(notice);

        window.setTimeout(function () {
            if (window.location.pathname.indexOf('/vagas/') === 0) {
                window.location.assign('/');

                return;
            }

            var url = new URL(window.location.href);

            url.searchParams.delete('page');

            window.location.assign(url.toString());
        }, 1500);
    }

    function send(position) {
        var coords = position.coords;

        if (!(coords.accuracy <= MAX_ACCURACY_METERS)) {
            showStatus(
                'A precisão informada pelo dispositivo é de '
                + Math.round(coords.accuracy).toLocaleString('pt-BR')
                + ' m. Precisamos de até 5.000 m para mostrar vagas presenciais e híbridas.',
                true
            );
            return Promise.resolve();
        }

        var state = readState();

        if (
            typeof state.lat === 'number'
            && typeof state.lon === 'number'
            && LOCATION_MODE === 'available'
            && distanceKm(
                state.lat,
                state.lon,
                coords.latitude,
                coords.longitude
            ) < MIN_MOVE_KM
        ) {
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
        })
        .then(function (response) {
            if (!response.ok) {
                return null;
            }

            return response.json();
        })
        .then(function (result) {
            if (!result) {
                return;
            }

            var next = readState();

            next.lat = coords.latitude;
            next.lon = coords.longitude;

            writeState(next);

            if (result.updated) {
                LOCATION_MODE = 'available';
                clearStatus();
                notifyAndReload(result.region);
            } else if (result.reason === 'inaccurate') {
                showStatus('A localização veio com precisão acima de 5.000 m. Tente novamente em alguns instantes.', true);
            } else if (result.reason === 'unverified') {
                showStatus('Não foi possível validar esta leitura de localização. Tente novamente após alguns instantes.', true);
            }
        })
        .catch(function () {
            // Falha de rede: tenta novamente na próxima verificação.
        });
    }

    function requestLocation(permissionState, force) {
        if (running || (attemptedThisPage && !force)) {
            return;
        }

        running = true;
        attemptedThisPage = true;

        navigator.geolocation.getCurrentPosition(
            function (position) {
                var state = readState();

                state.checkedAt = Date.now();

                delete state.blockedPermissionState;

                writeState(state);

                send(position).then(function () {
                    running = false;
                });
            },
            function (error) {
                running = false;

                if (error && error.code === error.PERMISSION_DENIED) {
                    showStatus('A permissão de localização foi negada. Altere a permissão deste site nas configurações do navegador para habilitar vagas locais.', false);
                    return;
                }

                var state = readState();
                state.checkedAt = Date.now();
                writeState(state);
                showStatus('Não conseguimos obter uma localização precisa agora. Confira o GPS/localização do dispositivo e tente novamente.', true);
            },
            {
                enableHighAccuracy: true,
                timeout: 20000,
                maximumAge: 0
            }
        );
    }

    function check(force) {
        if (
            running
            || document.visibilityState !== 'visible'
        ) {
            return;
        }

        getPermissionState().then(function (status) {
            var permissionState = status
                ? status.state
                : 'unknown';

            // Negou a permissão: não solicita novamente.
            // A solicitação volta a ser possível quando o estado
            // mudar após uma alteração nas configurações do navegador.
            if (permissionState === 'denied') {
                return;
            }

            var state = readState();

            var now = Date.now();

            // Para permissões concedidas, respeita o intervalo mínimo.
            if (
                permissionState === 'granted'
                && !force
                && typeof state.checkedAt === 'number'
                && now - state.checkedAt < MIN_INTERVAL_MS
            ) {
                return;
            }

            // Em prompt, solicita no máximo uma vez por carregamento
            // da página. Se o usuário escolher "somente desta vez",
            // uma próxima visita poderá solicitar novamente.
            requestLocation(permissionState, false);
        });
    }

    // Ao entrar na página de vagas.
    check(true);

    // Ao retornar para a aba.
    document.addEventListener('visibilitychange', function () {
        check(false);
    });

    // Verificações periódicas enquanto a página estiver visível.
    window.setInterval(function () {
        check(false);
    }, PERIODIC_MS);
})();
</script>
@endif
