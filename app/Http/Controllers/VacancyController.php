<?php

namespace App\Http\Controllers;

use App\Models\Vacancy;
use App\Services\VacancyAvailabilityDetector;
use App\Services\VacancyLocationFilter;
use Illuminate\Contracts\View\View;
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
        private readonly VacancyAvailabilityDetector $availabilityDetector
    ) {
    }

    /**
     * Retorna as vagas elegíveis para o estado do visitante.
     *
     * A UF é recebida explicitamente para que o serviço de localização
     * não dependa do container de sessão do Laravel.
     */
    private function eligibleVacancies(
        ?string $visitorStateCode = null
    ): Collection {
        $vacancies = Vacancy::query()
            ->whereNotNull('original_published_at')
            ->where('original_published_at', '>=', now()->subDays(60))
            ->orderByDesc('original_published_at')
            ->get()
            ->filter(function (Vacancy $vacancy) use ($visitorStateCode) {
                if ($this->availabilityDetector->isUnavailable(
                    $vacancy->title,
                    $vacancy->description
                )) {
                    return false;
                }

                $title = $this->normalize($vacancy->title);

                // Exclui níveis acima de júnior, estágio e trainee.
                if ($this->containsAny(
                    $title,
                    self::EXCLUDED_SENIORITY_TERMS
                )) {
                    return false;
                }

                if (
                    preg_match('/\bpl\s*$/i', $title) ||
                    preg_match(
                        '/\bsoftware engineer\s+(?:iii|iv|v)\b/i',
                        $title
                    )
                ) {
                    return false;
                }

                $description = $this->normalize(
                    $vacancy->description
                );

                /*
                 * Exclui vagas que exigem 3 ou mais anos de experiência.
                 *
                 * Exemplos:
                 * 3 years
                 * 5+ years
                 * 8 years
                 * 10 years
                 * 5 plus years
                 * 5-7 years
                 * 5–7 years
                 * 5 years of experience
                 * 5+ years in DevOps
                 * 5 anos de experiência
                 */
                if (preg_match(
                    '/\b(?:3|[4-9]|\d{2,})\s*(?:(?:\+|plus)\s*)?(?:[-–]\s*(?:3|[4-9]|\d{2,})\s*)?(?:years?|anos)\b/i',
                    $description
                )) {
                    return false;
                }

                if (preg_match(
                    '/(?:desenvolvedor|developer|software engineer|engineer|consultor|consultant|programador|programmer).{0,80}\b(?:pleno|senior|sr|staff|principal|lead)\b|\b(?:pleno|senior|sr|staff|principal|lead)\b.{0,80}(?:desenvolvedor|developer|software engineer|engineer|consultor|consultant|programador|programmer)/i',
                    $description
                )) {
                    return false;
                }

                $isRadarTecFullStackException =
                    $vacancy->source === 'radartec'
                    && (string) $vacancy->external_id === '13421'
                    && str_contains(
                        $title,
                        'desenvolvedor full stack web'
                    );

                if (
                    !$isRadarTecFullStackException &&
                    preg_match(
                        '/(?:forte|solida|solido|ampla|vasta|extensa|extenso|strong|extensive).{0,40}(?:experiencia|experience|knowledge|conhecimento)/i',
                        $description
                    )
                ) {
                    return false;
                }

                // Exclui especialidades fora do escopo pelo título.
                if ($this->containsAny(
                    $title,
                    self::IRRELEVANT_TERMS
                )) {
                    return false;
                }

                // Mantém apenas desenvolvimento, QA, suporte e infraestrutura.
                if (!$this->isTechnologyRole($title)) {
                    return false;
                }

                $workModel = $this->locationFilter->classifyWorkModel(
                    $vacancy->title,
                    $vacancy->location,
                    $vacancy->description
                );

                $vacancy->display_work_model = $workModel
                    ?? 'Não informado';

                return $this->locationFilter->isEligibleForVisitor(
                    $vacancy->location,
                    $vacancy->title,
                    $vacancy->description,
                    $workModel,
                    $visitorStateCode
                );
            })
            ->values();

        return $this->removeDuplicateVacancies($vacancies);
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

        $visitorStateCode = $request->session()->get('location_mode')
            === 'available'
                ? $request->session()->get('visitor_state_code')
                : null;

        $eligibleVacancies = $this->eligibleVacancies(
            $visitorStateCode
        );

        if ($search !== '') {
            $normalizedSearch = $this->normalize($search);

            $eligibleVacancies = $eligibleVacancies
                ->filter(function (Vacancy $vacancy) use (
                    $normalizedSearch
                ) {
                    $searchableText = $this->normalize(
                        implode(' ', [
                            $vacancy->title,
                            $vacancy->company,
                            $vacancy->location ?? '',
                            $vacancy->description,
                        ])
                    );

                    return Str::contains(
                        $searchableText,
                        $normalizedSearch
                    );
                })
                ->values();
        }

        $perPage = 12;
        $currentPage = LengthAwarePaginator::resolveCurrentPage();
        $total = $eligibleVacancies->count();

        $items = $eligibleVacancies
            ->forPage($currentPage, $perPage)
            ->values();

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
        ]);
    }

    public function show(
        Request $request,
        Vacancy $vacancy
    ): View {
        $visitorStateCode = $request->session()->get('location_mode')
            === 'available'
                ? $request->session()->get('visitor_state_code')
                : null;

        $eligibleVacancy = $this->eligibleVacancies(
            $visitorStateCode
        )->firstWhere('id', $vacancy->getKey());

        abort_unless($eligibleVacancy, 404);

        return view('vacancies.show', [
            'vacancy' => $eligibleVacancy,
        ]);
    }
}
