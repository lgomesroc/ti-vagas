
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

            <section class="details-section">
                <h2>Descrição da oportunidade</h2>

                <div class="description-content">
                    {!! nl2br(e($vacancy->description)) !!}
                </div>
            </section>

            <div class="details-actions">

                <a
                    href="{{ $vacancy->application_url }}"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="button"
                >
                    Candidatar-se na empresa
                </a>

                <a
                    href="{{ route('vacancies.index') }}"
                    class="button"
                >
                    Ver outras vagas
                </a>

            </div>

            <p class="vacancy-date">
                O processo de candidatura é realizado diretamente no site
                da empresa ou plataforma responsável pela oportunidade.
            </p>

        </article>

    </div>
</main>

@endsection
