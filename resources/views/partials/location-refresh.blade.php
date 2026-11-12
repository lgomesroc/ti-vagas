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

    var STORAGE_KEY = 'ti_vagas_location_refresh';

    var running = false;
    var attemptedThisPage = false;
    var memoryState = {};

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
            return Promise.resolve();
        }

        var state = readState();

        if (
            typeof state.lat === 'number'
            && typeof state.lon === 'number'
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
                accuracy: coords.accuracy
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
                notifyAndReload(result.region);
            }
        })
        .catch(function () {
            // Falha de rede: tenta novamente na próxima verificação.
        });
    }

    function blockCurrentPermissionState(permissionState) {
        return getPermissionState().then(function (status) {
            var state = readState();

            state.blockedPermissionState = status
                ? status.state
                : permissionState;

            writeState(state);
        });
    }

    function requestLocation(permissionState) {
        if (running || attemptedThisPage) {
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
            function () {
                blockCurrentPermissionState(
                    permissionState
                ).then(function () {
                    running = false;
                });
            },
            {
                enableHighAccuracy: false,
                timeout: 10000,
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

            // Uma falha anterior bloqueia novas tentativas enquanto
            // o estado da permissão continuar igual.
            if (
                state.blockedPermissionState === permissionState
            ) {
                return;
            }

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
            requestLocation(permissionState);
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
