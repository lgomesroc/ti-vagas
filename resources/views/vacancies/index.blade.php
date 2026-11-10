
@extends('layouts.app')

@section('title', 'TI Vagas | Oportunidades em Tecnologia')

@section('description', 'Encontre vagas júnior em desenvolvimento, suporte técnico e infraestrutura.')

@section('content')

<section class="hero">
    <div class="container">
        <h1>Encontre sua próxima oportunidade em TI</h1>

        <p>
            Vagas de desenvolvimento, suporte técnico e infraestrutura.
        </p>

        <form
            action="{{ route('vacancies.index') }}"
            method="GET"
            class="search-form"
        >
            <input
                type="search"
                name="q"
                value="{{ $search }}"
                placeholder="Cargo, empresa ou localização"
                aria-label="Pesquisar oportunidades"
            >

            <button type="submit" class="button">
                Pesquisar vagas
            </button>
        </form>
    </div>
</section>

<main class="main">
    <div class="container">

        @if (($locationMode ?? 'limited') !== 'available')
            <div class="welcome-information" role="status">
                <h3>Localização não ativada</h3>
                <p>
                    @if (!empty($ipLocationLabel))
                        Identificamos aproximadamente sua região pelo IP: <strong>{{ $ipLocationLabel }}</strong>.
                    @else
                        Não foi possível estimar sua região pelo IP.
                    @endif
                    Essa estimativa é imprecisa e não é usada para liberar vagas locais.
                    Por enquanto, você verá somente vagas remotas nacionais.
                </p>
                <p>
                    <a href="{{ route('welcome') }}">Ativar localização</a>
                    para visualizar também oportunidades presenciais, híbridas ou sem modalidade informada da sua região.
                </p>
            </div>
        @endif

        <div class="section-heading">
            @if ($search !== '')
                <h2>Resultados da pesquisa</h2>

                <p>
                    Encontramos {{ $vacancies->total() }}
                    oportunidade(s) para "{{ $search }}".
                </p>
            @else
                <h2>Oportunidades recentes</h2>

                <p>
                    Confira as oportunidades disponíveis na área de tecnologia.
                </p>
            @endif
        </div>

        @if ($vacancies->isNotEmpty())

            <div class="vacancy-list">

                @foreach ($vacancies as $vacancy)

                    <article class="vacancy-card">

                        <h3>
                            <a href="{{ route('vacancies.show', $vacancy) }}">
                                {{ $vacancy->title }}
                            </a>
                        </h3>

                        <div class="company">
                            {{ $vacancy->company }}
                        </div>

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

                        <p class="vacancy-description">
                            {{ \Illuminate\Support\Str::limit(strip_tags($vacancy->description), 220) }}
                        </p>

                        <div class="vacancy-date">
                            Publicada em:
                            {{ $vacancy->original_published_at->format('d/m/Y') }}
                        </div>

                    </article>

                    @if ($loop->iteration % 4 === 0 && !$loop->last)

                        <aside
                            class="ad-slot"
                            aria-label="Espaço publicitário"
                        >
                            <span class="ad-label">Publicidade</span>

                            <div class="ad-placeholder">
                                Espaço reservado para publicidade relacionada
                                à carreira, educação, tecnologia e serviços.
                            </div>
                        </aside>

                    @endif

                @endforeach

            </div>

            <div class="pagination">
                {{ $vacancies->links() }}
            </div>

        @else

            <div class="empty-state">
                <h3>Nenhuma oportunidade encontrada.</h3>

                <p>
                    Tente pesquisar usando outro cargo, empresa ou localização.
                </p>
            </div>

        @endif

    </div>
</main>

@endsection
