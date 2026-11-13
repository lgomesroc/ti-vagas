
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- Google AdSense -->
    <script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-8810922325021053"
         crossorigin="anonymous"></script>

    <title>@yield('title', 'TI Vagas')</title>

    <meta
        name="description"
        content="@yield('description', 'Encontre oportunidades de emprego em tecnologia, desenvolvimento, suporte e infraestrutura.')"
    >

    <style>
        * {
            box-sizing: border-box;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            margin: 0;
            background: #f5f7fa;
            color: #202938;
            font-family: Arial, Helvetica, sans-serif;
            line-height: 1.6;
        }

        body.cookie-modal-open {
            overflow: hidden;
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        button,
        input {
            font: inherit;
        }

        .container {
            width: min(1100px, 92%);
            margin: 0 auto;
        }

        .header {
            background: #172554;
            color: #ffffff;
            padding: 20px 0;
        }

        .header-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
        }

        .logo {
            font-size: 25px;
            font-weight: bold;
        }

        .logo span {
            color: #60a5fa;
        }

        .header-link {
            color: #ffffff;
            font-size: 14px;
        }

        .hero {
            background: #1e3a8a;
            color: #ffffff;
            padding: 55px 0;
        }

        .hero h1 {
            margin: 0 0 12px;
            font-size: clamp(27px, 4vw, 36px);
            line-height: 1.25;
        }

        .hero p {
            margin: 0 0 25px;
            color: #dbeafe;
        }

        .search-form {
            display: flex;
            gap: 10px;
            max-width: 750px;
        }

        .search-form input {
            flex: 1;
            min-width: 0;
            padding: 14px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 15px;
        }

        .button {
            display: inline-block;
            padding: 12px 20px;
            border: 0;
            border-radius: 6px;
            background: #2563eb;
            color: #ffffff;
            font-size: 14px;
            font-weight: bold;
            cursor: pointer;
            text-align: center;
        }

        .button:hover {
            background: #1d4ed8;
        }

        .button:disabled {
            cursor: wait;
            opacity: .65;
        }

        .main {
            padding: 35px 0 55px;
        }

        .section-heading {
            margin-bottom: 20px;
        }

        .section-heading h2 {
            margin: 0;
            font-size: 24px;
        }

        .section-heading p {
            margin: 5px 0 0;
            color: #64748b;
        }

        .vacancy-list {
            display: grid;
            gap: 15px;
        }

        .vacancy-card {
            padding: 22px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
        }

        .vacancy-card h3 {
            margin: 0 0 8px;
            color: #1e40af;
            font-size: 19px;
        }

        .vacancy-card h3 a:hover {
            text-decoration: underline;
        }

        .company {
            font-weight: bold;
            color: #334155;
        }

        .vacancy-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 8px 18px;
            margin-top: 12px;
            color: #64748b;
            font-size: 13px;
        }

        .vacancy-description {
            margin: 14px 0 0;
            color: #475569;
            overflow-wrap: anywhere;
        }

        .vacancy-date {
            margin-top: 12px;
            color: #64748b;
            font-size: 13px;
        }

        .empty-state {
            padding: 35px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            text-align: center;
        }

        .details-card {
            padding: 30px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
        }

        .details-card h1 {
            margin: 0 0 8px;
            font-size: 30px;
            line-height: 1.3;
            overflow-wrap: anywhere;
        }

        .details-section {
            margin-top: 30px;
        }

        .details-section h2 {
            font-size: 20px;
        }

        .description-content {
            color: #475569;
            overflow-wrap: anywhere;
        }

        .details-actions {
            margin-top: 30px;
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
        }

        .back-link {
            display: inline-block;
            margin-bottom: 20px;
            color: #2563eb;
        }

        .pagination {
            margin-top: 25px;
            overflow-x: auto;
        }

        /* Publicidade */

        .ad-slot {
            width: 100%;
            max-width: 900px;
            margin: 28px auto;
            padding: 18px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            text-align: center;
        }

        .ad-label {
            display: block;
            margin-bottom: 12px;
            color: #64748b;
            font-size: 11px;
            font-weight: bold;
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        .ad-placeholder {
            min-height: 150px;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: #f8fafc;
            border: 1px dashed #cbd5e1;
            border-radius: 6px;
            color: #64748b;
            font-size: 14px;
        }

        .ad-continuation {
            margin: 12px 0 0;
            color: #64748b;
            font-size: 12px;
        }

        /* Boas-vindas */

        .welcome-page {
            padding: 35px 0 60px;
        }

        .welcome-hero {
            margin-bottom: 25px;
            padding: 38px;
            background: #1e3a8a;
            border-radius: 10px;
            color: #ffffff;
        }

        .welcome-eyebrow {
            color: #bfdbfe;
            font-size: 12px;
            font-weight: bold;
            letter-spacing: 1.5px;
        }

        .welcome-hero h1 {
            max-width: 760px;
            margin: 12px 0;
            font-size: clamp(28px, 4vw, 40px);
            line-height: 1.2;
        }

        .welcome-hero p {
            max-width: 760px;
            margin: 0;
            color: #dbeafe;
        }

        .welcome-card,
        .legal-section {
            margin-bottom: 22px;
            padding: 28px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 9px;
        }

        .welcome-card h2,
        .legal-section h2 {
            margin-top: 0;
            font-size: 22px;
        }

        .welcome-information {
            margin-top: 24px;
            padding: 20px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 7px;
        }

        .welcome-information h3 {
            margin-top: 0;
            font-size: 17px;
        }

        .welcome-information p:last-child {
            margin-bottom: 0;
        }

        .terms-acceptance {
            margin-top: 28px;
            padding: 18px;
            background: #eff6ff;
            border-radius: 7px;
        }

        .terms-acceptance label {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            cursor: pointer;
        }

        .terms-acceptance input {
            width: 18px;
            height: 18px;
            flex-shrink: 0;
            margin-top: 4px;
        }

        .terms-acceptance a {
            color: #1d4ed8;
            font-weight: bold;
            text-decoration: underline;
        }

        .location-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 20px;
        }

        .welcome-primary-button {
            background: #2563eb;
        }

        .welcome-secondary-button {
            background: #475569;
        }

        .welcome-secondary-button:hover {
            background: #334155;
        }

        .location-message {
            margin: 14px 0 0;
            color: #64748b;
            font-size: 13px;
        }

        .form-error {
            margin: 8px 0 0;
            color: #b91c1c;
            font-size: 13px;
        }

        .legal-section {
            scroll-margin-top: 20px;
        }

        .legal-section p {
            color: #475569;
            overflow-wrap: anywhere;
        }

        .terms-already-accepted {
            padding: 20px;
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            border-radius: 8px;
        }

        .terms-already-accepted h2 {
            color: #166534;
        }

        .terms-already-accepted p {
            color: #334155;
        }

        /* Modal de consentimento de cookies */

        .cookie-modal-overlay {
            position: fixed;
            inset: 0;
            z-index: 2000;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: rgba(15, 23, 42, .72);
            backdrop-filter: blur(2px);
        }

        .cookie-modal {
            width: min(560px, 100%);
            padding: 30px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, .3);
        }

        .cookie-modal h2 {
            margin: 0 0 12px;
            color: #172554;
            font-size: 23px;
        }

        .cookie-modal p {
            margin: 0 0 15px;
            color: #475569;
            font-size: 14px;
        }

        .cookie-modal a {
            color: #1d4ed8;
            font-weight: bold;
            text-decoration: underline;
        }

        .cookie-modal-actions {
            display: flex;
            justify-content: flex-end;
            margin-top: 22px;
        }

        .cookie-modal-actions form,
        .cookie-modal-actions button {
            width: 100%;
        }

        /* Rodapé */

        .footer {
            padding: 25px 0;
            background: #172554;
            color: #cbd5e1;
            text-align: center;
            font-size: 13px;
        }

        .footer-links {
            display: flex;
            justify-content: center;
            flex-wrap: wrap;
            gap: 12px 22px;
            margin-top: 10px;
        }

        .footer-links a:hover {
            color: #ffffff;
            text-decoration: underline;
        }

        /* Tablet */

        @media (max-width: 900px) {
            .container {
                width: 94%;
            }

            .hero {
                padding: 45px 0;
            }

            .details-card {
                padding: 25px;
            }

            .welcome-hero {
                padding: 30px;
            }
        }

        /* Celular */

        @media (max-width: 600px) {
            .header {
                padding: 15px 0;
            }

            .header-content {
                gap: 12px;
            }

            .logo {
                font-size: 22px;
            }

            .header-link {
                font-size: 12px;
                text-align: right;
            }

            .hero {
                padding: 35px 0;
            }

            .search-form {
                flex-direction: column;
            }

            .search-form input,
            .search-form .button {
                width: 100%;
            }

            .main {
                padding: 25px 0 40px;
            }

            .vacancy-card {
                padding: 18px;
            }

            .details-card {
                padding: 18px;
            }

            .details-card h1 {
                font-size: 24px;
            }

            .details-actions,
            .location-actions {
                flex-direction: column;
            }

            .details-actions .button,
            .location-actions .button {
                width: 100%;
            }

            .ad-slot {
                margin: 22px auto;
                padding: 12px;
            }

            .ad-placeholder {
                min-height: 120px;
            }

            .welcome-page {
                padding: 20px 0 35px;
            }

            .welcome-hero {
                padding: 25px 20px;
            }

            .welcome-card,
            .legal-section {
                padding: 20px;
            }

            .welcome-information {
                padding: 16px;
            }

            .cookie-modal {
                padding: 24px 20px;
            }

            .cookie-modal h2 {
                font-size: 21px;
            }

            .cookie-modal-actions {
                justify-content: stretch;
            }

            .footer-links {
                flex-direction: column;
                gap: 8px;
            }
        }

        /* Monitores grandes */

        @media (min-width: 1500px) {
            .container {
                width: min(1200px, 90%);
            }

            .main {
                padding-top: 45px;
            }

            .vacancy-card {
                padding: 26px;
            }
        }
    </style>
</head>

<body>

    <header class="header">
        <div class="container header-content">

            <a href="{{ route('welcome') }}" class="logo">
                TI <span>Vagas</span>
            </a>

            @unless (request()->routeIs('welcome'))
                <a href="{{ route('vacancies.index') }}" class="header-link">
                    Encontrar oportunidades
                </a>
            @endunless

        </div>
    </header>

    @yield('content')

    <footer class="footer">
        <div class="container">

            <div>
                TI Vagas — Oportunidades na área de tecnologia.
            </div>

            <nav class="footer-links" aria-label="Links institucionais">

                <a href="{{ route('welcome') }}#termos">
                    Termos de Uso
                </a>

                <a href="{{ route('welcome') }}#responsabilidade">
                    Termos de Responsabilidade
                </a>

                <a href="{{ route('welcome') }}#privacidade">
                    Política de Privacidade
                </a>

            </nav>
        </div>
    </footer>

    @if (\App\Http\Middleware\EnsureWelcomeAccepted::shouldShowCookieNotice(request()))

        <div
            class="cookie-modal-overlay"
            role="presentation"
        >
            <section
                class="cookie-modal"
                role="dialog"
                aria-modal="true"
                aria-labelledby="cookie-modal-title"
                aria-describedby="cookie-modal-description"
            >
                <h2 id="cookie-modal-title">
                    Utilizamos cookies
                </h2>

                <p id="cookie-modal-description">
                    O TI Vagas utiliza cookies necessários para manter
                    sua sessão, proteger a navegação e registrar suas
                    confirmações.
                </p>

                <p>
                    Também utilizamos cookies e tecnologias semelhantes
                    relacionados à publicidade do Google AdSense.
                    Eles podem ser utilizados para exibir anúncios
                    personalizados com base nos seus interesses e
                    na sua atividade de navegação.
                </p>

                <p>
                    Ao aceitar, você confirma que leu nosso aviso sobre
                    o uso dessas tecnologias. Consulte nossa
                    <a href="{{ route('welcome') }}#privacidade">
                        Política de Privacidade
                    </a>
                    para saber mais.
                </p>

                <div class="cookie-modal-actions">
                    <form
                        method="POST"
                        action="{{ route('cookies.acknowledge') }}"
                    >
                        @csrf

                        <button type="submit" class="button">
                            Aceitar e continuar
                        </button>
                    </form>
                </div>
            </section>
        </div>

    @endif

    @if (
        !request()->routeIs('welcome')
        && \App\Http\Middleware\EnsureWelcomeAccepted::isAccepted(request())
        && in_array(session('location_mode'), ['limited', 'available'], true)
    )
        @include('partials.location-refresh')
    @endif

</body>
</html>
