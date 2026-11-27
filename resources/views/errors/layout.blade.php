<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#172554">
    <title>@yield('title', 'Ocorreu um problema') | TI Vagas</title>
    <style>
        :root {
            color-scheme: light;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            color: #172554;
            background: #f4f7fb;
        }
        * { box-sizing: border-box; }
        body {
            min-height: 100vh;
            margin: 0;
            display: grid;
            place-items: center;
            padding: 28px 18px;
            background:
                radial-gradient(ellipse at 50% 0, rgba(191, 219, 254, .55), transparent 48%),
                #f4f7fb;
        }
        .error-page {
            width: min(100%, 620px);
            padding: clamp(28px, 6vw, 52px);
            text-align: center;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 22px;
            box-shadow: 0 18px 55px rgba(15, 23, 42, .08);
        }
        .brand {
            display: inline-flex;
            align-items: center;
            gap: 9px;
            margin-bottom: 42px;
            color: #172554;
            font-size: 1rem;
            font-weight: 800;
            letter-spacing: -.035em;
            text-decoration: none;
        }
        .brand-mark {
            display: grid;
            width: 34px;
            height: 34px;
            place-items: center;
            color: #fff;
            background: #1d4ed8;
            border-radius: 10px;
            font-size: .95rem;
            font-weight: 800;
        }
        .status {
            display: block;
            margin: 0 0 12px;
            color: #2563eb;
            font-size: clamp(3.3rem, 12vw, 5.3rem);
            font-weight: 850;
            line-height: 1;
            letter-spacing: -.075em;
        }
        h1 {
            margin: 0;
            color: #0f172a;
            font-size: clamp(1.45rem, 5vw, 2rem);
            line-height: 1.25;
            letter-spacing: -.04em;
        }
        .message {
            max-width: 440px;
            margin: 16px auto 0;
            color: #475569;
            font-size: 1rem;
            line-height: 1.7;
        }
        .actions {
            display: flex;
            justify-content: center;
            margin-top: 30px;
        }
        .button {
            display: inline-flex;
            min-height: 46px;
            align-items: center;
            justify-content: center;
            padding: 0 22px;
            color: #fff;
            background: #1d4ed8;
            border: 1px solid #1d4ed8;
            border-radius: 10px;
            font-size: .95rem;
            font-weight: 700;
            text-decoration: none;
            transition: background .15s ease, transform .15s ease;
        }
        .button:hover { background: #1e40af; transform: translateY(-1px); }
        .button:focus-visible, .brand:focus-visible {
            outline: 3px solid #93c5fd;
            outline-offset: 3px;
        }
        .footer {
            margin: 34px 0 0;
            color: #94a3b8;
            font-size: .8rem;
        }
        @media (max-width: 420px) {
            .error-page { border-radius: 16px; }
            .brand { margin-bottom: 34px; }
        }
    </style>
</head>
<body>
    <main class="error-page" aria-labelledby="error-title">
        <a class="brand" href="{{ route('welcome') }}" aria-label="TI Vagas — página inicial">
            <span class="brand-mark" aria-hidden="true">TI</span>
            <span>TI Vagas</span>
        </a>

        <span class="status" aria-hidden="true">@yield('code', 'Erro')</span>
        <h1 id="error-title">@yield('heading', 'Não foi possível concluir sua solicitação')</h1>
        <p class="message">@yield('message', 'Ocorreu uma situação inesperada. Tente novamente em alguns instantes.')</p>

        <div class="actions">
            <a class="button" href="{{ route('welcome') }}">Ir para a página inicial</a>
        </div>

        <p class="footer">TI Vagas — Menos tempo procurando. Mais tempo se preparando.</p>
    </main>
</body>
</html>
