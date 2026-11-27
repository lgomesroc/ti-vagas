{{--
    PWA: registro do service worker e botão "Instalar app".

    - O service worker só é registrado em contexto seguro (https ou localhost).
    - O botão fica oculto e só aparece quando o navegador dispara
      `beforeinstallprompt` (Chrome, Edge, Android). Em navegadores sem esse
      evento (Safari/iOS), a instalação é feita pelo menu "Adicionar à Tela de
      Início" e o botão nunca aparece.
    Detalhes em docs/AplicativoPWA.md.
--}}
<script>
    (function () {
        if (!('serviceWorker' in navigator) || !window.isSecureContext) {
            return;
        }

        window.addEventListener('load', function () {
            navigator.serviceWorker
                .register(@json(asset('sw.js')), { scope: '/' })
                .catch(function (error) {
                    // Falhas no registro não podem afetar o uso do portal.
                    if (window.console && console.warn) {
                        console.warn('TI Vagas: service worker não registrado.', error);
                    }
                });
        });
    })();

    (function () {
        var button = document.getElementById('pwa-install-button');
        var deferredPrompt = null;

        if (!button) {
            return;
        }

        window.addEventListener('beforeinstallprompt', function (event) {
            event.preventDefault();
            deferredPrompt = event;
            button.hidden = false;
        });

        button.addEventListener('click', function () {
            if (!deferredPrompt) {
                return;
            }

            button.hidden = true;
            deferredPrompt.prompt();
            deferredPrompt.userChoice.finally(function () {
                deferredPrompt = null;
            });
        });

        window.addEventListener('appinstalled', function () {
            deferredPrompt = null;
            button.hidden = true;
        });
    })();
</script>
