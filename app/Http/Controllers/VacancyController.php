<?php

namespace App\Http\Controllers;

use App\Models\Vacancy;
use App\Services\Geo\RegionScope;
use App\Services\Geo\VacancyLocationResolver;
use App\Services\Geo\VisitorLocationResolver;
use App\Services\VacancyLocationFilter;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class VacancyController extends Controller
{
    private const DEVELOPMENT_TERMS = [
        'desenvolvedor',
        'developer',
        'programador',
        'software engineer',
        'engenheiro de software',
        'backend',
        'back-end',
        'frontend',
        'front-end',
        'full stack',
        'fullstack',
        'integration developer',
        'php developer',
        '.net developer',
        'java developer',
        'python developer',
        'desenvolvedor de software',
        'desenvolvedor de sistemas',
        'desenvolvedor web',
        'desenvolvedor mobile',
        'desenvolvedor de aplicativos',
        'software developer',
        'web developer',
        'mobile developer',
        'application developer',
        'analista de desenvolvimento',
        'analista de sistemas de informação',
        'ios developer',
        'android developer',
        'qa',
        'quality assurance',
        'quality analyst',
        'analista de qa',
        'analista de testes',
        'tester',
        'software tester',
        'test engineer',
        'engenheiro de testes',
        'automation tester',
        'qa automation',
    ];

    private const SUPPORT_TERMS = [
        'suporte tecnico',
        'suporte de ti',
        'suporte ti',
        'analista de suporte',
        'tecnico de informatica',
        'tecnico de suporte',
        'help desk',
        'helpdesk',
        'service desk',
        'servicedesk',
        'technical support',
        'it support',
        'field support',
        'analista de service desk',
        'analista de help desk',
        'suporte ao usuario',
        'suporte de sistemas',
        'suporte a sistemas',
        'suporte de informatica',
        'assistente de suporte',
        'auxiliar de suporte',
        'analista de suporte de sistemas',
        'analista de suporte tecnico',
        'tecnico de suporte ti',
        'tecnico de service desk',
        'operador de service desk',
        'field service',
        'microinformatica',
    ];

    private const INFRASTRUCTURE_TERMS = [
        'infraestrutura',
        'infrastructure',
        'analista de redes',
        'tecnico de redes',
        'network engineer',
        'network analyst',
        'cloud engineer',
        'devops',
        'sysadmin',
        'system administrator',
        'administrador de sistemas',
        'analista de sistemas',
        'noc',
        'analista de infraestrutura',
        'tecnico de infraestrutura',
        'assistente de infraestrutura',
        'auxiliar de infraestrutura',
        'administrador de redes',
        'administrador de banco de dados',
        'database administrator',
        'dba',
        'site reliability engineer',
        'sre',
        'network administrator',
        'cloud administrator',
        'analista de cloud',
        'engenheiro de infraestrutura',
        'security analyst',
        'analista de seguranca da informacao',
        'analista de seguranca',
        'cybersecurity',
        'ciberseguranca',
        'soc analyst',
    ];

    private const EXCLUDED_SENIORITY_TERMS = [
        'pleno',
        'senior',
        'sr',
        'staff',
        'principal',
        'lead',
        'manager',
        'gerente',
        'director',
        'diretor',
        'head',
        'architect',
        'arquiteto',
        'especialista',
        'coordenador',
        'coordenadora',
        'lider',
        'supervisor',
        'supervisora',
        'trainee',
        'estagio',
        'estagiario',
        'intern',
        'internship',
    ];

    /**
     * Áreas e especialidades que não fazem parte do escopo editorial.
     *
     * A verificação é feita no título da vaga para evitar que uma
     * menção incidental na descrição elimine uma vaga convencional
     * de desenvolvimento, QA, infraestrutura ou suporte.
     */
    private const IRRELEVANT_TERMS = [
        'marketing',
        'vendas',
        'sales',
        'juridico',
        'legal',
        'financeiro',
        'financial',
        'recruiter',
        'recrutamento',
        'recursos humanos',
        'human resources',
        'engenheiro de planejamento',
        'engenheiro de pocos',
        'engenheiro mecanico',
        'engenheiro de qualidade',
        'engenheiro de risco',
        'engenheiro fiscal',
        'engenheiro de seguranca',
        'commercial contracts',
        'legal ops',
        'analista administrativo',
        'analista de gestao',
        'analista de operacoes',
        'compras',
        'procurement',
        'contabilidade',
        'accounting',
        'departamento pessoal',
        'folha de pagamento',
        'payroll',
        'logistica',
        'logistics',
        'almoxarifado',
        'estoque',
        'comercial',
        'customer success',
        'customer experience',
        'atendimento ao cliente',
        'operador de caixa',
        'secretaria',
        'secretariado',
        'recepcionista',
        'engenheiro civil',
        'engenheiro eletricista',
        'engenheiro de producao',
        'engenheiro ambiental',
        'tecnico de seguranca do trabalho',

        // CRM, ERP e sistemas corporativos especializados.
        'consultor crm',
        'consultora crm',
        'analista crm',
        'consultor erp',
        'consultora erp',
        'analista erp',
        'consultor sap',
        'consultora sap',
        'analista sap',
        'desenvolvedor sap',
        'sap abap',
        'abap',
        'totvs',
        'protheus',
        'consultor funcional',
        'consultora funcional',
        'analista funcional',
        'implantacao de sistemas',
        'implantador de sistemas',

        // Dados, BI e inteligência artificial como especialidade.
        'data analyst',
        'data analytics',
        'analytics engineer',
        'analista de dados',
        'engenheiro de dados',
        'data engineer',
        'cientista de dados',
        'data scientist',
        'business intelligence',
        'business intelligence analyst',
        'bi analyst',
        'analista de bi',
        'engenheiro de machine learning',
        'machine learning engineer',
        'machine learning specialist',
        'deep learning',
        'ml engineer',
        'ai engineer',
        'engenheiro de inteligencia artificial',
        'especialista em inteligencia artificial',
        'cientista de machine learning',
    ];

    public function __construct(
        private readonly VacancyLocationFilter $locationFilter,
        private readonly VacancyLocationResolver $vacancyLocationResolver,
        private readonly VisitorLocationResolver $visitorLocationResolver
    ) {
    }

    /**
     * Executa no PostgreSQL os filtros editoriais e a pesquisa textual para
     * evitar transferir ao PHP registros que já seriam descartados.
     * A elegibilidade regional permanece em PHP, pois depende do visitante.
     */
    private function applySqlEditorialFilters($query, string $search): void
    {
        $title = $this->normalizedSqlExpression('title');
        $description = $this->normalizedSqlExpression('description');
        $company = $this->normalizedSqlExpression('company');
        $location = $this->normalizedSqlExpression('location');

        if ($search !== '') {
            $searchable = "concat_ws(' ', {$title}, {$company}, {$location}, {$description})";
            $query->whereRaw("position(? in {$searchable}) > 0", [$this->normalize($search)]);
        }

        $query->whereRaw("{$title} !~ ?", [$this->sqlTermPattern(self::EXCLUDED_SENIORITY_TERMS)]);
        $query->whereRaw("{$title} !~ ?", ['(^|[^a-z0-9])pl[[:space:]]*$']);
        $query->whereRaw("{$title} !~ ?", ['(^|[^a-z0-9])software engineer[[:space:]]+(iii|iv|v)([^a-z0-9]|$)']);

        $query->whereRaw(
            "{$description} !~ ?",
            ['(^|[^a-z0-9])(3|[4-9]|[0-9]{2,})[[:space:]]*((\\+|plus)[[:space:]]*)?([-–][[:space:]]*(3|[4-9]|[0-9]{2,})[[:space:]]*)?(years?|anos)([^a-z0-9]|$)']
        );

        $seniorityDescriptionPattern = '(desenvolvedor|developer|software engineer|engineer|consultor|consultant|programador|programmer).{0,80}(^|[^a-z0-9])(pleno|senior|sr|staff|principal|lead)([^a-z0-9]|$)|(^|[^a-z0-9])(pleno|senior|sr|staff|principal|lead)([^a-z0-9]|$).{0,80}(desenvolvedor|developer|software engineer|engineer|consultor|consultant|programador|programmer)';
        $query->whereRaw("{$description} !~ ?", [$seniorityDescriptionPattern]);

        $strongExperiencePattern = '(forte|solida|solido|ampla|vasta|extensa|extenso|strong|extensive).{0,40}(experiencia|experience|knowledge|conhecimento)';
        $radarExceptionTitlePattern = '(^|[^a-z0-9])desenvolvedor full stack web([^a-z0-9]|$)';
        $query->whereRaw(
            "NOT (source = 'radartec' AND external_id = '13421' AND {$title} ~ ? AND {$description} ~ ?)",
            [$radarExceptionTitlePattern, $strongExperiencePattern]
        );
        $query->whereRaw("{$description} !~ ?", [$strongExperiencePattern]);

        $query->whereRaw("{$title} !~ ?", [$this->sqlTermPattern(self::IRRELEVANT_TERMS)]);
        $query->whereRaw(
            "{$title} ~ ?",
            [$this->sqlTermPattern(array_merge(self::DEVELOPMENT_TERMS, self::SUPPORT_TERMS, self::INFRASTRUCTURE_TERMS))]
        );

        $statusTerms = [
            'closed', 'filled', 'on hold', 'paused', 'suspended', 'cancelled', 'canceled',
            'completed', 'finished', 'inactive', 'expired', 'encerrado', 'encerrada',
            'finalizado', 'finalizada', 'preenchido', 'preenchida', 'pausado', 'pausada',
            'suspenso', 'suspensa', 'cancelado', 'cancelada', 'congelado', 'congelada',
        ];
        $statusPattern = '(?:' . implode('|', array_map(
            static fn (string $term): string => preg_quote($term, '/'),
            $statusTerms
        )) . ')';
        $vacancyTerms = '(?:job|position|role|vacancy|opening|requisition|posting|vaga|oportunidade|posicao|candidatura|inscricoes)';
        $titleUnavailable = '(?:^|[\\[\\(\\-:|])[[:space:]]*' . $statusPattern . '[[:space:]]*(?:[\\]\\)\\-:|]|$)|(^|[^a-z0-9])' . $vacancyTerms . '([^a-z0-9]|$).{0,35}(^|[^a-z0-9])' . $statusPattern . '([^a-z0-9]|$)|(^|[^a-z0-9])' . $statusPattern . '([^a-z0-9]|$).{0,35}(^|[^a-z0-9])' . $vacancyTerms . '([^a-z0-9]|$)';
        $query->whereRaw("{$title} !~ ?", [$titleUnavailable]);

        $descriptionUnavailablePatterns = [
            '\m(esta|a|essa|desta)?[[:space:]]*vaga[[:space:]]+(foi[[:space:]]+)?(encerrada|finalizada|cancelada|suspensa|pausada|congelada|preenchida|removida)\M',
            '\m(esta|a|essa|desta)?[[:space:]]*oportunidade[[:space:]]+(foi[[:space:]]+)?(encerrada|finalizada|cancelada|suspensa|pausada|congelada|preenchida|removida)\M',
            '\m(esta|a|essa)?[[:space:]]*posicao[[:space:]]+(foi[[:space:]]+)?(encerrada|finalizada|cancelada|suspensa|pausada|congelada|preenchida)\M',
            '\minscricoes[[:space:]]+(foram[[:space:]]+)?encerradas\M',
            '\mcandidaturas[[:space:]]+(foram[[:space:]]+)?encerradas\M',
            '\mnao estamos mais recebendo candidaturas\M',
            '\mnao estamos mais aceitando candidaturas\M',
            '\mnao estamos mais aceitando inscricoes\M',
            '\mnao aceitamos mais candidaturas\M',
            '\mprocesso seletivo (desta vaga|desta oportunidade|para esta vaga|para esta oportunidade|da vaga)[[:space:]]+(foi[[:space:]]+)?(encerrado|finalizado|cancelado|suspenso|pausado)\M',
            '\mprocesso seletivo[[:space:]]+(foi[[:space:]]+)?(encerrado|finalizado|cancelado|suspenso|pausado)\M',
            '\m(this|the|our)[[:space:]]+(job|position|role|vacancy|opening|requisition|posting)[[:space:]]+(is|has been|was)[[:space:]]+(closed|filled|cancelled|canceled|suspended|paused|completed|finished)\M',
            '\m(job|position|role|vacancy|opening|requisition|posting)[[:space:]]+(is|has been|was)[[:space:]]+(closed|filled|cancelled|canceled|suspended|paused|completed|finished)\M',
            '\m(position|job|role|vacancy|opening)[[:space:]]+(has been[[:space:]]+)?filled\M',
            '\m(applications|registrations)[[:space:]]+(are|have been|were)[[:space:]]+(closed|suspended|cancelled|canceled)\M',
            '\mno longer accepting applications\M',
            '\mno longer accepting candidates\M',
            '\mwe are no longer receiving applications\M',
            '\mthis opportunity is no longer available\M',
            '\mthis position is no longer available\M',
            '\mthe hiring process for this (job|position|role|vacancy)[[:space:]]+(is|has been|was)[[:space:]]+(closed|completed|finished|cancelled|canceled)\M',
        ];
        $descriptionUnavailable = '(?:' . implode('|', $descriptionUnavailablePatterns) . ')';
        $historicalException = '(experiencia|experience|participacao|participated|worked on|historico).{0,70}(processo seletivo|processo de selecao|hiring process|recruitment process).{0,50}(encerrad|finalizad|closed|completed|finished)';
        $otherTeamsException = '(outras areas|outras equipes|outros departamentos|other departments|other teams|other roles|other positions)';

        $query->whereRaw(
            "NOT ({$description} ~ ? AND {$description} !~ ? AND {$description} !~ ?)",
            [$descriptionUnavailable, $historicalException, $otherTeamsException]
        );
    }

    private function normalizedSqlExpression(string $column): string
    {
        return "translate(lower(coalesce({$column}, '')), 'áàâãäåéèêëíìîïóòôõöúùûüçñ', 'aaaaaaeeeeiiiiooooouuuucn')";
    }

    private function sqlTermPattern(array $terms): string
    {
        $alternatives = array_map(
            fn (string $term): string => preg_quote($this->normalize($term), '/'),
            $terms
        );

        return '(^|[^a-z0-9])(' . implode('|', $alternatives) . ')([^a-z0-9]|$)';
    }

    /**
     * Retorna as vagas elegíveis para a região do visitante.
     *
     * A região é recebida explicitamente para que o serviço de localização
     * não dependa do container de sessão do Laravel.
     */
    /**
     * Percorre as vagas elegíveis sem hidratar toda a tabela em memória.
     *
     * A consulta seleciona somente os campos usados pelos filtros e pela
     * listagem. O cursor mantém o consumo de memória estável mesmo quando
     * existem muitas vagas no período.
     *
     * @return \Generator<int, Vacancy>
     */
    private function eligibleVacancies(
        ?RegionScope $visitorScope = null,
        string $search = ''
    ): \Generator {
        $query = Vacancy::query()
            ->select([
                'id',
                'title',
                'company',
                'description',
                'location',
                'work_model',
                'employment_type',
                'salary',
                'application_url',
                'source',
                'external_id',
                'original_published_at',
                'collected_at',
                'ibge_municipality_code',
                'city',
                'state_code',
            ])
            ->where('availability_status', 'active')
            ->whereNotNull('original_published_at')
            ->where('original_published_at', '>=', now()->subDays(60))
            ->orderByDesc('original_published_at')
            ->orderByDesc('collected_at')
            ->orderByRaw("CASE WHEN lower(title) LIKE '%exclusivo pcd%' THEN 1 ELSE 0 END ASC")
            ->orderByDesc('id');

        $this->applySqlEditorialFilters($query, $search);

        $seenDuplicateKeys = [];

        foreach ($query->cursor() as $vacancy) {
            $workModel = $this->locationFilter->classifyWorkModel(
                $vacancy->title,
                $vacancy->location,
                $vacancy->description
            );

            $vacancy->display_work_model = $workModel ?? 'Não informado';

            if (!$this->locationFilter->isEligibleForVisitor(
                $vacancy->location,
                $vacancy->title,
                $vacancy->description,
                $workModel,
                $visitorScope,
                $this->vacancyLocationResolver->resolveForVacancy($vacancy)
            )) {
                continue;
            }

            // Deduplica somente depois da regra regional: uma vaga duplicada
            // fora da região não pode ocultar outra cópia elegível.
            if (preg_match('/\\((\\d{4,})\\)/', $title, $matches)) {
                $duplicateKey = implode('|', [
                    $this->normalize($vacancy->company),
                    $matches[1],
                ]);

                if (isset($seenDuplicateKeys[$duplicateKey])) {
                    continue;
                }

                $seenDuplicateKeys[$duplicateKey] = true;
            }

            yield $vacancy;
        }
    }

    private function removeDuplicateVacancies(
        Collection $vacancies
    ): Collection {
        return $vacancies
            ->groupBy(function (Vacancy $vacancy) {
                $normalizedTitle = $this->normalize(
                    $vacancy->title
                );

                if (!preg_match(
                    '/\((\d{4,})\)/',
                    $normalizedTitle,
                    $matches
                )) {
                    return 'unique-' . $vacancy->id;
                }

                return implode('|', [
                    $this->normalize($vacancy->company),
                    $matches[1],
                ]);
            })
            ->map(function (Collection $group) {
                return $group
                    ->sort(function (Vacancy $a, Vacancy $b) {
                        $dateComparison = $b->original_published_at
                            <=> $a->original_published_at;

                        if ($dateComparison !== 0) {
                            return $dateComparison;
                        }

                        $collectionComparison = $b->collected_at
                            <=> $a->collected_at;

                        if ($collectionComparison !== 0) {
                            return $collectionComparison;
                        }

                        $aIsExclusivePcd = str_contains(
                            $this->normalize($a->title),
                            'exclusivo pcd'
                        );

                        $bIsExclusivePcd = str_contains(
                            $this->normalize($b->title),
                            'exclusivo pcd'
                        );

                        if ($aIsExclusivePcd !== $bIsExclusivePcd) {
                            return $aIsExclusivePcd ? 1 : -1;
                        }

                        return $b->id <=> $a->id;
                    })
                    ->first();
            })
            ->sortByDesc('original_published_at')
            ->values();
    }

    private function isTechnologyRole(string $title): bool
    {
        return $this->containsAny(
            $title,
            self::DEVELOPMENT_TERMS
        )
            || $this->containsAny(
                $title,
                self::SUPPORT_TERMS
            )
            || $this->containsAny(
                $title,
                self::INFRASTRUCTURE_TERMS
            );
    }

    private function containsAny(
        string $text,
        array $terms
    ): bool {
        foreach ($terms as $term) {
            $normalizedTerm = $this->normalize($term);

            if (preg_match(
                '/(^|[^a-z0-9])' .
                preg_quote($normalizedTerm, '/') .
                '([^a-z0-9]|$)/',
                $text
            )) {
                return true;
            }
        }

        return false;
    }

    private function normalize(?string $value): string
    {
        return Str::ascii(mb_strtolower($value ?? ''));
    }

    public function index(Request $request): View
    {
        $search = trim($request->query('q', ''));

        $perPage = 12;
        $currentPage = Paginator::resolveCurrentPage();
        $firstItem = ($currentPage - 1) * $perPage;
        $eligibleCount = 0;
        $items = [];
        $hasMorePages = false;

        foreach ($this->eligibleVacancies(
            $this->visitorLocationResolver->scopeFromSession(
                $request->session()
            ),
            $search
        ) as $vacancy) {
            if ($eligibleCount < $firstItem) {
                $eligibleCount++;
                continue;
            }

            if (count($items) === $perPage) {
                $hasMorePages = true;
                break;
            }

            $items[] = $vacancy;
            $eligibleCount++;
        }

        $vacancies = new Paginator(
            collect($items),
            $perPage,
            $currentPage,
            [
                'path' => $request->url(),
                'query' => $request->query(),
            ]
        );

        $vacancies->hasMorePagesWhen($hasMorePages);

        return view('vacancies.index', [
            'vacancies' => $vacancies,
            'search' => $search,
            'locationMode' => $request->session()->get('location_mode', 'limited'),
            'ipLocationLabel' => $request->session()->get('ip_location_label'),
        ]);
    }

    public function show(
        Request $request,
        Vacancy $vacancy
    ): View {
        $eligibleVacancy = null;

        foreach ($this->eligibleVacancies(
            $this->visitorLocationResolver->scopeFromSession(
                $request->session()
            )
        ) as $candidate) {
            if ($candidate->is($vacancy)) {
                $eligibleVacancy = $candidate;
                break;
            }
        }

        abort_unless($eligibleVacancy, 404);

        return view('vacancies.show', [
            'vacancy' => $eligibleVacancy,
        ]);
    }
}
