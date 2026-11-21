@if (\App\Http\Middleware\EnsureWelcomeAccepted::isAccepted(request()))
<script id="location-refresh">
(function () {
    'use strict';

    if (!('geolocation' in navigator) || !('fetch' in window)) {
        return;
    }

    var ENDPOINT = @json(route('location.refresh'));
    var DISABLE_ENDPOINT = @json(route('location.disable'));
    var CSRF_TOKEN = @json(csrf_token());
    var MAX_ACCURACY_METERS = @json(
        \App\Services\Geo\VisitorLocationResolver::MAX_ACCURACY_METERS
    );

    // Precisão (m) a partir da qual a leitura é enviada na hora, sem esperar
    // uma leitura melhor.
    var GOOD_ACCURACY_METERS = 100;
    // Depois da primeira leitura aceitável, quanto esperar por uma melhor.
    // Só começa a contar quando o aparelho já devolveu uma posição: o tempo
    // gasto respondendo ao aviso de permissão do navegador não entra aqui.
    var REFINE_WINDOW_MS = 8000;
    // Limite do navegador para obter uma posição. Pela especificação, também
    // não inclui o tempo de resposta ao aviso de permissão.
    var GEO_TIMEOUT_MS = 30000;
    // Deslocamento mínimo para enviar uma nova posição ao servidor.
    var MOVE_THRESHOLD_KM = 1;
    // O servidor aceita 6 envios por minuto: ficamos bem abaixo disso.
    var MIN_SEND_INTERVAL_MS = 20000;
    // Pausa depois que o servidor recusa uma leitura (evita insistir).
    var REFUSED_BACKOFF_MS = 120000;
    var RATE_LIMIT_BACKOFF_MS = 60000;
    var NETWORK_BACKOFF_MS = 30000;

    // Caixa aproximada do Brasil (a mesma usada pelo servidor): fora dela
    // não há o que enviar, e o servidor trataria como tentativa suspeita.
    var BRAZIL = { minLat: -34.0, maxLat: 6.0, minLon: -74.5, maxLon: -28.0 };

    var STORAGE_KEY = 'ti_vagas_location_refresh';
    var LOCATION_MODE = @json(session('location_mode', 'limited'));

    var watchId = null;
    var permissionWatched = false;
    var askedThisPage = false;
    var sending = false;
    var disabling = false;
    var firstSendDone = false;
    var refineTimer = null;
    var best = null;
    var lastSent = null;
    var blockedUntil = 0;
    var memoryState = {};
    var statusElement = null;
    var actionButton = null;
    // Mensagem "fixa" (recusa do servidor, rede, bloqueio): só sai por uma
    // nova ação ou por sucesso, não a cada leitura do GPS.
    var statusSticky = false;

    // ---------------------------------------------------------------
    // Armazenamento local (só a última posição enviada)
    // ---------------------------------------------------------------

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

    // ---------------------------------------------------------------
    // Mensagens na tela
    // ---------------------------------------------------------------

    function clearStatus() {
        if (statusElement && statusElement.parentNode) {
            statusElement.parentNode.removeChild(statusElement);
        }
        statusElement = null;
        actionButton = null;
        statusSticky = false;
    }

    function showStatus(message, actionLabel, action, sticky) {
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
        statusSticky = sticky === true;
        actionButton = null;

        if (actionLabel && typeof action === 'function') {
            actionButton = document.createElement('button');
            actionButton.type = 'button';
            actionButton.textContent = actionLabel;
            actionButton.style.cssText =
                'flex:none;border:0;border-radius:6px;padding:8px 12px;'
                + 'background:#2563eb;color:#fff;cursor:pointer;';
            actionButton.addEventListener('click', action);
            statusElement.appendChild(actionButton);
        }
    }

    function hasCookieModal() {
        return !!document.querySelector('.cookie-modal-overlay');
    }

    function reloadWithNotice(message) {
        var notice = document.createElement('div');
        notice.setAttribute('role', 'status');
        notice.textContent = message;
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

    // ---------------------------------------------------------------
    // Geometria
    // ---------------------------------------------------------------

    function distanceKm(lat1, lon1, lat2, lon2) {
        var toRad = function (value) { return value * Math.PI / 180; };
        var dLat = toRad(lat2 - lat1);
        var dLon = toRad(lon2 - lon1);
        var a = Math.pow(Math.sin(dLat / 2), 2)
            + Math.cos(toRad(lat1)) * Math.cos(toRad(lat2))
            * Math.pow(Math.sin(dLon / 2), 2);
        return 2 * 6371 * Math.asin(Math.min(1, Math.sqrt(a)));
    }

    function insideBrazil(lat, lon) {
        return lat >= BRAZIL.minLat && lat <= BRAZIL.maxLat
            && lon >= BRAZIL.minLon && lon <= BRAZIL.maxLon;
    }

    // ---------------------------------------------------------------
    // Comunicação com o servidor
    // ---------------------------------------------------------------

    function post(url, payload) {
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': CSRF_TOKEN,
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify(payload || {})
        }).then(function (response) {
            if (!response.ok) {
                var error = new Error('HTTP ' + response.status);
                error.status = response.status;
                throw error;
            }
            return response.json();
        });
    }

    function retryAction() {
        blockedUntil = 0;
        stopTracking();
        startTracking();
        showStatus('Obtendo sua localização. Isso pode levar alguns segundos...');
    }

    function showRefusal(reason) {
        var message;

        if (reason === 'inaccurate') {
            message = 'O servidor recebeu uma precisão acima de '
                + Math.round(MAX_ACCURACY_METERS).toLocaleString('pt-BR')
                + ' m. Tente novamente.';
        } else if (reason === 'unverified') {
            message = 'Não foi possível validar esta leitura. Aguarde um pouco e tente novamente.';
        } else if (reason === 'unresolved') {
            message = 'Não foi possível identificar um município brasileiro com esta posição. Confira a localização do dispositivo e tente novamente.';
        } else {
            clearStatus();
            return;
        }

        showStatus(message, 'Tentar novamente', retryAction, true);
    }

    function sendPosition(position) {
        var coords = position.coords;
        var state = readState();

        // O servidor já tem uma região para este ponto: não há o que enviar.
        if (
            LOCATION_MODE === 'available'
            && typeof state.lat === 'number'
            && typeof state.lon === 'number'
            && distanceKm(state.lat, state.lon, coords.latitude, coords.longitude) < MOVE_THRESHOLD_KM
        ) {
            lastSent = { lat: coords.latitude, lon: coords.longitude, at: Date.now() };
            clearStatus();
            return Promise.resolve();
        }

        sending = true;

        return post(ENDPOINT, {
            latitude: coords.latitude,
            longitude: coords.longitude,
            accuracy: coords.accuracy,
            captured_at: position.timestamp
        }).then(function (result) {
            var accepted = result.updated === true
                || result.reason === 'unchanged'
                || result.reason === 'nearby';

            if (accepted) {
                LOCATION_MODE = 'available';
                lastSent = { lat: coords.latitude, lon: coords.longitude, at: Date.now() };

                var next = readState();
                next.lat = coords.latitude;
                next.lon = coords.longitude;
                next.checkedAt = Date.now();
                writeState(next);
            }

            if (result.updated) {
                clearStatus();
                reloadWithNotice('Sua região mudou para ' + result.region + '. Atualizando as vagas...');
                return;
            }

            if (accepted) {
                clearStatus();
                return;
            }

            blockedUntil = Date.now() + REFUSED_BACKOFF_MS;
            showRefusal(result.reason);
        }).catch(function (error) {
            if (error.status === 419) {
                showStatus(
                    'Sua sessão expirou. Atualize a página para continuar.',
                    'Atualizar página',
                    function () { window.location.reload(); },
                    true
                );
                return;
            }

            if (error.status === 429) {
                blockedUntil = Date.now() + RATE_LIMIT_BACKOFF_MS;
                showStatus(
                    'Muitas tentativas em pouco tempo. Aguarde um minuto antes de tentar novamente.',
                    'Tentar novamente',
                    retryAction,
                    true
                );
                return;
            }

            blockedUntil = Date.now() + NETWORK_BACKOFF_MS;
            showStatus(
                'Não foi possível enviar sua localização ao servidor. Verifique a conexão e tente novamente.',
                'Tentar novamente',
                retryAction,
                true
            );
        }).finally(function () {
            sending = false;
        });
    }

    // A permissão foi bloqueada ou revogada: o servidor deixa de mostrar
    // vagas locais e a página volta a listar somente vagas remotas.
    function disableOnServer() {
        if (disabling) {
            return;
        }
        disabling = true;

        post(DISABLE_ENDPOINT, {}).then(function (result) {
            LOCATION_MODE = 'limited';
            lastSent = null;
            best = null;

            var next = readState();
            delete next.lat;
            delete next.lon;
            writeState(next);

            if (result.changed) {
                reloadWithNotice('A permissão de localização foi removida. Mostrando somente vagas remotas...');
            }
        }).catch(function () {
            showStatus(
                'A permissão de localização foi removida, mas não foi possível atualizar as vagas. Tente novamente.',
                'Tentar novamente',
                disableOnServer,
                true
            );
        }).finally(function () {
            disabling = false;
        });
    }

    // ---------------------------------------------------------------
    // Leituras do aparelho
    // ---------------------------------------------------------------

    function flush() {
        if (refineTimer !== null) {
            window.clearTimeout(refineTimer);
            refineTimer = null;
        }

        if (!best || sending || Date.now() < blockedUntil) {
            return;
        }

        var position = best;
        best = null;
        firstSendDone = true;
        sendPosition(position);
    }

    function movedEnough(position) {
        if (sending || Date.now() < blockedUntil) {
            return false;
        }

        // Nada aceito pelo servidor ainda: qualquer leitura serve.
        if (!lastSent) {
            return true;
        }

        return Date.now() - lastSent.at >= MIN_SEND_INTERVAL_MS
            && distanceKm(
                lastSent.lat,
                lastSent.lon,
                position.coords.latitude,
                position.coords.longitude
            ) >= MOVE_THRESHOLD_KM;
    }

    function onPosition(position) {
        var coords = position.coords;

        if (!Number.isFinite(coords.accuracy) || coords.accuracy > MAX_ACCURACY_METERS) {
            // Com a região já definida, uma leitura ruim passageira não
            // precisa incomodar: a próxima tende a ser melhor.
            if (LOCATION_MODE !== 'available') {
                showStatus(
                    'A melhor precisão obtida foi de '
                    + Math.round(coords.accuracy).toLocaleString('pt-BR')
                    + ' m. O limite para liberar vagas presenciais e híbridas é '
                    + Math.round(MAX_ACCURACY_METERS).toLocaleString('pt-BR')
                    + ' m. Ative a localização do aparelho (GPS ou serviço de localização do sistema) e tente novamente.',
                    'Tentar novamente',
                    retryAction
                );
            }
            return;
        }

        if (!insideBrazil(coords.latitude, coords.longitude)) {
            showStatus('Sua posição está fora do Brasil. Mostrando somente vagas remotas nacionais.');
            return;
        }

        if (!statusSticky) {
            clearStatus();
        }

        if (!firstSendDone) {
            if (!best || coords.accuracy < best.coords.accuracy) {
                best = position;
            }

            if (coords.accuracy <= GOOD_ACCURACY_METERS) {
                flush();
            } else if (refineTimer === null) {
                refineTimer = window.setTimeout(flush, REFINE_WINDOW_MS);
            }
            return;
        }

        // Já enviado nesta página: acompanha o deslocamento do aparelho.
        if (movedEnough(position)) {
            best = position;
            flush();
        }
    }

    function onGeolocationError(error) {
        var code = error && error.code;

        // 1 = PERMISSION_DENIED: o navegador negou ou o aviso foi dispensado.
        if (code === 1) {
            stopTracking();
            getPermissionState().then(function (state) {
                if (state === 'prompt') {
                    promptDismissed();
                } else if (state === 'granted') {
                    // O site tem permissão, mas o sistema do aparelho (e não o
                    // navegador) está impedindo o acesso à localização.
                    showStatus(
                        'O sistema do aparelho está impedindo o acesso à localização. '
                        + 'Ative a localização do dispositivo para este navegador e tente novamente.',
                        'Tentar novamente',
                        retryAction,
                        true
                    );
                } else {
                    blocked();
                }
            });
            return;
        }

        // 2 e 3: a leitura segue sendo tentada pelo navegador; só avisamos.
        if (code === 3) {
            showStatus(
                'A localização demorou para responder. Confira as configurações do dispositivo.',
                'Tentar novamente',
                retryAction
            );
        } else {
            showStatus(
                'O dispositivo não conseguiu determinar sua posição. Confira se a localização do aparelho está ativada.',
                'Tentar novamente',
                retryAction
            );
        }
    }

    function startTracking() {
        if (watchId !== null) {
            return;
        }

        watchId = navigator.geolocation.watchPosition(onPosition, onGeolocationError, {
            enableHighAccuracy: true,
            timeout: GEO_TIMEOUT_MS,
            maximumAge: 0
        });
    }

    function stopTracking() {
        if (watchId !== null) {
            navigator.geolocation.clearWatch(watchId);
            watchId = null;
        }

        if (refineTimer !== null) {
            window.clearTimeout(refineTimer);
            refineTimer = null;
        }

        best = null;
    }

    // ---------------------------------------------------------------
    // Permissão do navegador
    // ---------------------------------------------------------------

    function getPermissionState() {
        if (!navigator.permissions || typeof navigator.permissions.query !== 'function') {
            return Promise.resolve('unknown');
        }

        return navigator.permissions.query({ name: 'geolocation' })
            .then(function (status) {
                // Mudança feita nas configurações do navegador com a página
                // aberta (liberar, bloquear ou redefinir): reage na hora.
                if (!permissionWatched && typeof status.addEventListener === 'function') {
                    permissionWatched = true;
                    status.addEventListener('change', sync);
                }
                return status.state;
            })
            .catch(function () { return 'unknown'; });
    }

    // Permissão bloqueada: não há como perguntar de novo pelo site.
    function blocked() {
        stopTracking();

        if (LOCATION_MODE === 'available') {
            disableOnServer();
        }

        var noPermissionsApi = !(navigator.permissions && typeof navigator.permissions.query === 'function');

        showStatus(
            'A localização está bloqueada para este site, então mostramos somente vagas remotas. '
            + 'Para ver também vagas presenciais e híbridas da sua região, libere a localização nas configurações do navegador.',
            noPermissionsApi ? 'Tentar novamente' : null,
            noPermissionsApi ? userRequestedLocation : null,
            true
        );
    }

    // O aviso do navegador foi fechado sem uma resposta.
    function promptDismissed() {
        showStatus(
            'A permissão de localização não foi concedida. Você continua vendo somente vagas remotas.',
            'Permitir localização',
            userRequestedLocation,
            true
        );
    }

    // Permissão ainda não decidida (primeira visita) ou redefinida/revogada
    // nas configurações: o navegador precisa perguntar de novo.
    function ask() {
        stopTracking();

        // Uma região guardada de antes não vale mais sem permissão.
        if (LOCATION_MODE === 'available') {
            disableOnServer();
            return;
        }

        if (askedThisPage) {
            promptDismissed();
            return;
        }

        askedThisPage = true;
        showStatus(
            'Permita a localização no aviso do navegador para ver também vagas presenciais e híbridas da sua região. '
            + 'Sem a permissão, mostramos somente vagas remotas.'
        );
        startTracking();
    }

    // Decide o que fazer a partir da permissão que o navegador tem agora.
    function sync() {
        if (document.visibilityState !== 'visible' || hasCookieModal()) {
            return;
        }

        getPermissionState().then(function (state) {
            if (state === 'granted') {
                if (!firstSendDone && LOCATION_MODE !== 'available') {
                    showStatus('Obtendo sua localização. Isso pode levar alguns segundos...');
                }
                startTracking();
                return;
            }

            if (state === 'denied') {
                blocked();
                return;
            }

            if (state === 'prompt') {
                ask();
                return;
            }

            // Navegador sem API de permissões: ele mesmo decide se pergunta.
            startTracking();
        });
    }

    // Pedido explícito do visitante (botão ou link "Ativar localização").
    function userRequestedLocation() {
        askedThisPage = false;
        blockedUntil = 0;

        getPermissionState().then(function (state) {
            if (state === 'denied') {
                blocked();
                return;
            }

            stopTracking();
            showStatus('Obtendo sua localização. Isso pode levar alguns segundos...');
            startTracking();
        });
    }

    document.addEventListener('click', function (event) {
        var target = event.target && event.target.closest
            ? event.target.closest('[data-location-request]')
            : null;

        if (target) {
            event.preventDefault();
            userRequestedLocation();
        }
    });

    // Com a aba em segundo plano o acompanhamento para (bateria e privacidade);
    // ao voltar, a permissão é conferida de novo.
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') {
            sync();
        } else {
            stopTracking();
        }
    });

    window.addEventListener('pagehide', stopTracking);

    // Com o aviso de cookies aberto, a localização aguarda a escolha do
    // visitante; a página recarrega depois dela e o fluxo recomeça.
    sync();
})();
</script>
@endif
