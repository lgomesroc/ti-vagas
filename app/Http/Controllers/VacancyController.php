<?php

namespace App\Http\Controllers;

use App\Models\Vacancy;
use App\Services\Geo\RegionScope;
use App\Services\Geo\VacancyLocationResolver;
use App\Services\Geo\VisitorLocationResolver;
use App\Services\VacancyAvailabilityDetector;
use App\Services\VacancyLocationFilter;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
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
        private readonly VacancyAvailabilityDetector $availabilityDetector,
        private readonly VacancyLocationResolver $vacancyLocationResolver,
        private readonly VisitorLocationResolver $visitorLocationResolver
    ) {
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
            ->where('original_published_at', '>=', now()->subDays(60));

        $this->applySqlFilters($query, $search);

        $query
            ->orderByDesc('original_published_at')
            ->orderByDesc('collected_at')
            ->orderByRaw("CASE WHEN lower(title) LIKE '%exclusivo pcd%' THEN 1 ELSE 0 END ASC")
            ->orderByDesc('id');

        $seenDuplicateKeys = [];

        foreach ($query->cursor() as $vacancy) {
            $title = $this->normalize($vacancy->title);

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

    /**
     * Aplica no PostgreSQL os filtros editoriais que não dependem da
     * resolução geográfica do município nem da região validada do visitante.
     *
     * A normalização SQL reproduz a remoção de acentos usada pelo filtro PHP.
     */
    private function applySqlFilters(Builder $query, string $search): void
    {
        $title = $this->normalizedSqlExpression('title');
        $description = $this->normalizedSqlExpression('description');

        $excludedSeniority = $this->termsRegex(self::EXCLUDED_SENIORITY_TERMS);
        $irrelevant = $this->termsRegex(self::IRRELEVANT_TERMS);
        $technology = $this->termsRegex(array_merge(
            self::DEVELOPMENT_TERMS,
            self::SUPPORT_TERMS,
            self::INFRASTRUCTURE_TERMS
        ));

        $query
            ->whereRaw("{$title} !~ ?", [$excludedSeniority])
            ->whereRaw("{$title} !~ ?", ['/pl[[:space:]]*
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
        $currentPage = LengthAwarePaginator::resolveCurrentPage();
        $firstItem = ($currentPage - 1) * $perPage;
        $total = 0;
        $items = [];

        foreach ($this->eligibleVacancies(
            $this->visitorLocationResolver->scopeFromSession(
                $request->session()
            ),
            $search
        ) as $vacancy) {
            if ($total >= $firstItem && count($items) < $perPage) {
                $items[] = $vacancy;
            }

            $total++;
        }

        $items = collect($items);

        $vacancies = new LengthAwarePaginator(
            $items,
            $total,
            $perPage,
            $currentPage,
            [
                'path' => $request->url(),
                'query' => $request->query(),
            ]
        );

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
])
            ->whereRaw("{$title} !~ ?", ['/software engineer[[:space:]]+(iii|iv|v)([^a-z0-9]|$)'])
            ->whereRaw("{$title} !~ ?", [$irrelevant])
            ->whereRaw("{$title} ~ ?", [$technology])
            ->whereRaw(
                "{$description} !~ ?",
                ['/([^a-z0-9]|^)(3|[4-9]|[0-9]{2,})[[:space:]]*((\\+|plus)[[:space:]]*)?(-[[:space:]]*(3|[4-9]|[0-9]{2,})[[:space:]]*)?(years?|anos)([^a-z0-9]|$)']
            )
            ->whereRaw(
                "{$description} !~ ?",
                ['/((desenvolvedor|developer|software engineer|engineer|consultor|consultant|programador|programmer).{0,80}(pleno|senior|sr|staff|principal|lead)([^a-z0-9]|$)|(^|[^a-z0-9])(pleno|senior|sr|staff|principal|lead).{0,80}(desenvolvedor|developer|software engineer|engineer|consultor|consultant|programador|programmer))/']
            );

        // Exceção histórica mantida para a vaga específica da RadarTec.
        $strongExperience = '/(forte|solida|solido|ampla|vasta|extensa|extenso|strong|extensive).{0,40}(experiencia|experience|knowledge|conhecimento)/';
        $query->where(function (Builder $nested) use ($description, $strongExperience) {
            $nested->whereRaw("{$description} !~ ?", [$strongExperience])
                ->orWhere(function (Builder $exception) {
                    $exception->where('source', 'radartec')
                        ->where('external_id', '13421')
                        ->whereRaw(
                            $this->normalizedSqlExpression('title') . " ~ ?",
                            ['/desenvolvedor full stack web/']
                        );
                });
        });

        // Vagas encerradas/canceladas: status e expressões do título.
        $unavailableStatuses = [
            'closed', 'filled', 'on hold', 'paused', 'suspended',
            'cancelled', 'canceled', 'completed', 'finished', 'inactive',
            'expired', 'encerrado', 'encerrada', 'finalizado', 'finalizada',
            'preenchido', 'preenchida', 'pausado', 'pausada', 'suspenso',
            'suspensa', 'cancelado', 'cancelada', 'congelado', 'congelada',
        ];
        $statusRegex = $this->termsRegex($unavailableStatuses);
        $vacancyWords = '(job|position|role|vacancy|opening|requisition|posting|vaga|oportunidade|posicao|candidatura|inscricoes)';
        $query->whereRaw(
            "{$title} !~ ?",
            ['/((^|[\[\(\-:|])[[:space:]]*' . trim($statusRegex, '/') . '[[:space:]]*([\]\)\-:|]|$))/']
        )->whereRaw(
            "{$title} !~ ?",
            ['/((^|[^a-z0-9])' . $vacancyWords . '([^a-z0-9]|$)).{0,35}(' . trim($statusRegex, '/') . ')([^a-z0-9]|$)/']
        )->whereRaw(
            "{$title} !~ ?",
            ['/(' . trim($statusRegex, '/') . ')([^a-z0-9]|$).{0,35}((^|[^a-z0-9])' . $vacancyWords . '([^a-z0-9]|$))/']
        );

        $unavailableDescription = '/((esta|a|essa|desta)?[[:space:]]*vaga[[:space:]]+(foi[[:space:]]+)?(encerrada|finalizada|cancelada|suspensa|pausada|congelada|preenchida|removida)|'
            . '((esta|a|essa|desta)?[[:space:]]*oportunidade[[:space:]]+(foi[[:space:]]+)?(encerrada|finalizada|cancelada|suspensa|pausada|congelada|preenchida|removida))|'
            . '((esta|a|essa)?[[:space:]]*posicao[[:space:]]+(foi[[:space:]]+)?(encerrada|finalizada|cancelada|suspensa|pausada|congelada|preenchida))|'
            . '(inscricoes[[:space:]]+(foram[[:space:]]+)?encerradas)|'
            . '(candidaturas[[:space:]]+(foram[[:space:]]+)?encerradas)|'
            . '(nao estamos mais (recebendo|aceitando) (candidaturas|inscricoes))|'
            . '(nao aceitamos mais candidaturas)|'
            . '(processo seletivo.{0,70}(encerrado|finalizado|cancelado|suspenso|pausado))|'
            . '((this|the|our)[[:space:]]+(job|position|role|vacancy|opening|requisition|posting)[[:space:]]+(is|has been|was)[[:space:]]+(closed|filled|cancelled|canceled|suspended|paused|completed|finished))|'
            . '((job|position|role|vacancy|opening|requisition|posting)[[:space:]]+(is|has been|was)[[:space:]]+(closed|filled|cancelled|canceled|suspended|paused|completed|finished))|'
            . '((position|job|role|vacancy|opening)[[:space:]]+(has been[[:space:]]+)?filled)|'
            . '((applications|registrations)[[:space:]]+(are|have been|were)[[:space:]]+(closed|suspended|cancelled|canceled))|'
            . '(no longer accepting (applications|candidates))|'
            . '(we are no longer receiving applications)|'
            . '(this opportunity is no longer available)|'
            . '(this position is no longer available)|'
            . '(the hiring process for this (job|position|role|vacancy).{0,30}(closed|completed|finished|cancelled|canceled)))';

        $historicalClosure = '/(experiencia|experience|participacao|participated|worked on|historico).{0,70}(processo seletivo|processo de selecao|hiring process|recruitment process).{0,50}(encerrad|finalizad|closed|completed|finished)/';
        $otherTeams = '/(outras areas|outras equipes|outros departamentos|other departments|other teams|other roles|other positions)/';

        $query->whereRaw(
            "NOT ({$description} ~ ? AND {$description} !~ ? AND {$description} !~ ?)",
            [$unavailableDescription, $historicalClosure, $otherTeams]
        );

        $normalizedSearch = $this->normalize($search);

        if ($normalizedSearch !== '') {
            $searchable = $this->normalizedSqlExpression(
                "concat_ws(' ', title, company, location, description)"
            );
            $query->whereRaw("position(? in {$searchable}) > 0", [$normalizedSearch]);
        }
    }

    /**
     * Expressão PostgreSQL equivalente a Str::ascii(mb_strtolower(...))
     * para os caracteres acentuados comuns em português e espanhol.
     */
    private function normalizedSqlExpression(string $column): string
    {
        return "translate(lower(coalesce({$column}, '')), "
            . "'áàâãäåéèêëíìîïóòôõöúùûüçñ', "
            . "'aaaaaaeeeeiiiioooouuuucn')";
    }

    /**
     * Cria uma expressão regular POSIX com limites de palavra.
     */
    private function termsRegex(array $terms): string
    {
        $alternatives = array_map(
            fn (string $term): string => preg_quote($this->normalize($term), '/'),
            $terms
        );

        return '(^|[^a-z0-9])(' . implode('|', $alternatives) . ')([^a-z0-9]|$)';
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
        $currentPage = LengthAwarePaginator::resolveCurrentPage();
        $firstItem = ($currentPage - 1) * $perPage;
        $total = 0;
        $items = [];

        foreach ($this->eligibleVacancies(
            $this->visitorLocationResolver->scopeFromSession(
                $request->session()
            ),
            $search
        ) as $vacancy) {
            if ($total >= $firstItem && count($items) < $perPage) {
                $items[] = $vacancy;
            }

            $total++;
        }

        $items = collect($items);

        $vacancies = new LengthAwarePaginator(
            $items,
            $total,
            $perPage,
            $currentPage,
            [
                'path' => $request->url(),
                'query' => $request->query(),
            ]
        );

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
