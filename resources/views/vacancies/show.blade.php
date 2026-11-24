
@extends('layouts.app')

@section('title', $vacancy->title . ' | TI Vagas')

@section('description', 'Confira os detalhes da oportunidade de ' . $vacancy->title . ' na empresa ' . $vacancy->company . '.')

@section('content')

<main class="main">
    <div class="container">

        <a href="{{ route('vacancies.index') }}" class="back-link">
            &larr; Voltar para as oportunidades
        </a>

        <article class="details-card">

            <h1>{{ $vacancy->title }}</h1>

            <p class="company">
                {{ $vacancy->company }}
            </p>

            <div class="vacancy-meta">

                @if ($vacancy->location)
                    <span>{{ $vacancy->location }}</span>
                @endif

                <span>
                    {{ $vacancy->display_work_model ?? 'Não informado' }}
                </span>

                @if ($vacancy->employment_type)
                    <span>{{ $vacancy->employment_type }}</span>
                @endif

                @if ($vacancy->salary)
                    <span>{{ $vacancy->salary }}</span>
                @endif

            </div>

            <div class="vacancy-date">
                Publicada em:
                {{ $vacancy->original_published_at->format('d/m/Y') }}
            </div>

            <aside
                class="ad-slot"
                aria-label="Espaço publicitário"
            >
                <span class="ad-label">Publicidade</span>

                <div class="ad-placeholder">
                    Espaço reservado para publicidade relacionada
                    à carreira, educação, tecnologia e serviços.
                </div>

                <p class="ad-continuation">
                    A descrição da vaga continua após o anúncio.
                </p>
            </aside>

            <section class="details-section">
                <h2>Descrição da oportunidade</h2>

                <div class="description-content">
                    {!! nl2br(e($vacancy->description)) !!}
                </div>
            </section>

            <div class="details-actions">
                @if ($vacancy->application_email)
                    <a href="mailto:{{ $vacancy->application_email }}@if ($vacancy->application_subject)?subject={{ rawurlencode($vacancy->application_subject) }}@endif" class="button">Enviar currículo por e-mail</a>
                    <p class="vacancy-date">
                        E-mail para candidatura: <a href="mailto:{{ $vacancy->application_email }}">{{ $vacancy->application_email }}</a>
                        @if ($vacancy->application_subject)<br>Assunto: {{ $vacancy->application_subject }}@endif
                    </p>
                    @if ($vacancy->application_url)
                        <a href="{{ $vacancy->application_url }}" target="_blank" rel="noopener noreferrer" class="button">Acessar página de candidatura</a>
                    @elseif ($vacancy->source_url)
                        <a href="{{ $vacancy->source_url }}" target="_blank" rel="noopener noreferrer" class="button">Ver publicação original</a>
                    @endif
                @elseif ($vacancy->application_url)
                    <a href="{{ $vacancy->application_url }}" target="_blank" rel="noopener noreferrer" class="button">Candidatar-se na empresa</a>
                @endif
                <a href="{{ route('vacancies.index', ['company' => $vacancy->company]) }}" class="button">Ver outras vagas</a>
            </div>

            <p class="vacancy-date">
                @if ($vacancy->application_email)
                    A candidatura é realizada pelo endereço de e-mail informado na publicação original.
                @else
                    O processo de candidatura é realizado diretamente no site da empresa ou plataforma responsável pela oportunidade.
                @endif
            </p>

        </article>

    </div>
</main>

@endsection
