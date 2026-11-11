<script id="location-refresh">
(function () {
    'use strict';

    if (!('geolocation' in navigator) || !('fetch' in window)) {
        return;
    }

    var ENDPOINT = @json(route('location.refresh'));
    var CSRF_TOKEN = @json(csrf_token());
    var MAX_ACCURACY_METERS = @json(\App\Services\Geo\VisitorLocationResolver::MAX_ACCURACY_METERS);

    // Intervalo mínimo entre verificações (evita repetir a cada página/aba).
    var MIN_INTERVAL_MS = 5 * 60 * 1000;
    // Verificação periódica enquanto a aba está aberta e visível.
    var PERIODIC_MS = 15 * 60 * 1000;
    // Se o visitante não se moveu mais que isso desde o último envio, nem
    // consulta o servidor.
    var MIN_MOVE_KM = 1;
    var STORAGE_KEY = 'ti_vagas_location_refresh';

    var running = false;
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
            window.localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
        } catch (error) {
            // Sem armazenamento: segue só com a memória da página.
        }
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

    // Só verifica quando o navegador já autorizou a localização para o site.
    // Nunca abre o pedido de permissão sozinho.
    function permissionGranted() {
        if (!navigator.permissions || !navigator.permissions.query) {
            return Promise.resolve(false);
        }

        return navigator.permissions
            .query({ name: 'geolocation' })
            .then(function (status) { return status.state === 'granted'; })
            .catch(function () { return false; });
    }

    function notifyAndReload(region) {
        var notice = document.createElement('div');

        notice.setAttribute('role', 'status');
        notice.textContent = 'Sua região mudou para ' + region + '. Atualizando as vagas...';
        notice.style.cssText = 'position:fixed;left:50%;bottom:24px;transform:translateX(-50%);'
            + 'background:#202938;color:#fff;padding:12px 18px;border-radius:8px;'
            + 'font-size:14px;z-index:9999;max-width:90vw;box-shadow:0 4px 16px rgba(0,0,0,.25);';
        document.body.appendChild(notice);

        window.setTimeout(function () {
            // A página de uma vaga pode deixar de existir na nova região.
            if (window.location.pathname.indexOf('/vagas/') === 0) {
                window.location.assign('/');

                return;
            }

            var url = new URL(window.location.href);

            // A página atual pode não existir na nova lista.
            url.searchParams.delete('page');
            window.location.assign(url.toString());
        }, 1500);
    }

    function send(position) {
        var coords = position.coords;

        // Coordenadas imprecisas não alteram a região.
        if (!(coords.accuracy <= MAX_ACCURACY_METERS)) {
            return Promise.resolve();
        }

        var state = readState();

        if (
            typeof state.lat === 'number' && typeof state.lon === 'number'
            && distanceKm(state.lat, state.lon, coords.latitude, coords.longitude) < MIN_MOVE_KM
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
        }).then(function (response) {
            if (!response.ok) {
                return null;
            }

            return response.json();
        }).then(function (result) {
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
        }).catch(function () {
            // Falha de rede: tenta de novo na próxima verificação.
        });
    }

    function check() {
        if (running || document.visibilityState !== 'visible') {
            return;
        }

        var state = readState();
        var now = Date.now();

        if (typeof state.checkedAt === 'number' && now - state.checkedAt < MIN_INTERVAL_MS) {
            return;
        }

        running = true;

        permissionGranted().then(function (granted) {
            if (!granted) {
                running = false;

                return;
            }

            // Relê o estado: o antigo pode ter perdido a posição gravada nesse meio tempo.
            var fresh = readState();

            fresh.checkedAt = now;
            writeState(fresh);

            // Leitura única, sem acompanhamento contínuo. As verificações já são
            // espaçadas em pelo menos MIN_INTERVAL_MS, então não vale aceitar
            // uma posição antiga em cache: ela esconderia uma mudança de lugar.
            navigator.geolocation.getCurrentPosition(
                function (position) {
                    send(position).then(function () { running = false; });
                },
                function () { running = false; },
                { enableHighAccuracy: false, timeout: 10000, maximumAge: 0 }
            );
        });
    }

    // 1. Ao entrar no portal.
    check();

    // 2. Ao voltar para a aba.
    document.addEventListener('visibilitychange', check);

    // 3. Periodicamente, enquanto a aba está visível.
    window.setInterval(check, PERIODIC_MS);
})();
</script>
