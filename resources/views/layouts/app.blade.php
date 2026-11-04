<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>@yield('title', 'TI Vagas')</title>

    <meta
        name="description"
        content="@yield('description', 'Encontre oportunidades de emprego em tecnologia, desenvolvimento, suporte e infraestrutura.')"
    >

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #f5f7fa;
            color: #202938;
            font-family: Arial, Helvetica, sans-serif;
            line-height: 1.6;
        }

        a {
            color: inherit;
            text-decoration: none;
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
            font-size: 34px;
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
        }

        .footer {
            padding: 25px 0;
            background: #172554;
            color: #cbd5e1;
            text-align: center;
            font-size: 13px;
        }

        @media (max-width: 600px) {
            .hero h1 {
                font-size: 27px;
            }

            .search-form {
                flex-direction: column;
            }

            .details-card {
                padding: 20px;
            }

            .details-card h1 {
                font-size: 24px;
            }
        }
    </style>
</head>

<body>

    <header class="header">
        <div class="container header-content">
            <a href="{{ route('vacancies.index') }}" class="logo">
                TI <span>Vagas</span>
            </a>

            <a href="{{ route('vacancies.index') }}" class="header-link">
                Encontrar oportunidades
            </a>
        </div>
    </header>

    @yield('content')

    <footer class="footer">
        <div class="container">
            TI Vagas — Oportunidades na área de tecnologia.
        </div>
    </footer>

</body>
</html>
