<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">

    <title>{{ $heading }} | TI Vagas</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background: #f5f7fa;
            color: #202938;
            font-family: Arial, Helvetica, sans-serif;
            line-height: 1.6;
        }

        main {
            width: min(560px, 100%);
            background: #ffffff;
            border-radius: 12px;
            border-top: 6px solid #172554;
            padding: 32px;
            box-shadow: 0 8px 24px rgba(23, 37, 84, 0.08);
        }

        h1 {
            margin: 0 0 12px;
            font-size: 1.4rem;
            color: #172554;
        }

        p {
            margin: 0 0 12px;
        }

        .hint {
            font-size: 0.92rem;
            color: #475569;
        }

        a {
            color: #1d4ed8;
        }
    </style>
</head>
<body>
    <main>
        <h1>{{ $heading }}</h1>

        <p>{{ $message }}</p>

        @if ($retryAfter)
            <p>Tente novamente em cerca de {{ $retryAfter }} {{ $retryAfter === 1 ? 'segundo' : 'segundos' }}.</p>
        @endif

        <p class="hint">
            Esta verificação não usa a sua localização. Se você acredita que o bloqueio é um engano,
            avise o responsável pelo projeto em
            <a href="https://github.com/lgomesroc/ti-vagas" rel="noopener noreferrer">github.com/lgomesroc/ti-vagas</a>.
        </p>
    </main>
</body>
</html>
