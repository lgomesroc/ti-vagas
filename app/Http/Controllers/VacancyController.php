<?php

namespace App\Http\Controllers;

use App\Models\Vacancy;
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
    ];

    private const SUPPORT_TERMS = [
        'suporte tecnico',
        'suporte de ti',
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
    ];

    private const JUNIOR_TERMS = [
        'junior',
        'jr',
        'estagio',
        'estagiario',
        'intern',
        'internship',
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
    ];

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
        'data analyst',
        'analytics engineer',
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
    ];

    public function __construct(
        private readonly VacancyLocationFilter $locationFilter
    ) {
    }

    private function eligibleVacancies(): Collection
    {
        $vacancies = Vacancy::query()
            ->whereNotNull('original_published_at')
            ->where('original_published_at', '>=', now()->subDays(60))
            ->orderByDesc('original_published_at')
            ->get()
            ->filter(function (Vacancy $vacancy) {
                $title = $this->normalize($vacancy->title);


                if ($this->containsAny($title, self::EXCLUDED_SENIORITY_TERMS)) {
                    return false;
                }

                if (preg_match('/\bpl\s*$/i', $title)
                    || preg_match('/\bsoftware engineer\s+(?:iii|iv|v)\b/i', $title)) {
                    return false;
                }

                $description = $this->normalize($vacancy->description);

                if (preg_match(
                    '/(?:minimum|minimo|minima|at least|more than|over|mais de|a partir de|pelo menos)\s*(?:of\s*)?(?:3|[4-9]|\d{2,})\s*(?:\+|plus|[-–]\s*\d+)?\s*(?:years?|anos)|(?:experience|experiencia).{0,50}\b(?:3|[4-9]|\d{2,})\s*(?:\+|plus|[-–]\s*\d+)?\s*(?:years?|anos)/i',
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
                if (
                    (string) $vacancy->external_id !== '13421'
                    && preg_match(
                        '/(?:forte|solida|solido|ampla|vasta|extensa|extenso|strong|extensive).{0,40}(?:experiencia|experience|knowledge|conhecimento)/i',
                        $description
                    )
                ) {
                    return false;
                }
                if ($this->containsAny($title, self::IRRELEVANT_TERMS)) {
                    return false;
                }

                if (!$this->isTechnologyRole($title)) {
                    return false;
                }

                $workModel = $this->locationFilter->classifyWorkModel(
                    $vacancy->title,
                    $vacancy->location,
                    $vacancy->description
                );

                $vacancy->display_work_model = $workModel ?? 'Não informado';

                return $this->locationFilter->isEligible(
                    $vacancy->location,
                    $vacancy->title,
                    $vacancy->description,
                    $workModel
                );
            })
            ->values();

        return $this->removeDuplicateVacancies($vacancies);
    }

    private function removeDuplicateVacancies(Collection $vacancies): Collection
    {
        return $vacancies
            ->groupBy(function (Vacancy $vacancy) {
                $normalizedTitle = $this->normalize($vacancy->title);

                if (!preg_match('/\((\d{4,})\)/', $normalizedTitle, $matches)) {
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
        return $this->containsAny($title, self::DEVELOPMENT_TERMS)
            || $this->containsAny($title, self::SUPPORT_TERMS)
            || $this->containsAny($title, self::INFRASTRUCTURE_TERMS);
    }

    private function hasAllowedSeniority(string $title, string $description): bool
    {
        return $this->containsAny($title, self::JUNIOR_TERMS)
            || $this->containsAny(
                $this->normalize($description),
                self::JUNIOR_TERMS
            )
            || preg_match('/\bsoftware engineer i\b/', $title) === 1;
    }

    private function containsAny(string $text, array $terms): bool
    {
        foreach ($terms as $term) {
            $normalizedTerm = $this->normalize($term);

            if (preg_match(
                '/(^|[^a-z0-9])' . preg_quote($normalizedTerm, '/') . '([^a-z0-9]|$)/',
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

        $eligibleVacancies = $this->eligibleVacancies();

        if ($search !== '') {
            $normalizedSearch = $this->normalize($search);

            $eligibleVacancies = $eligibleVacancies
                ->filter(function (Vacancy $vacancy) use ($normalizedSearch) {
                    $searchableText = $this->normalize(implode(' ', [
                        $vacancy->title,
                        $vacancy->company,
                        $vacancy->location ?? '',
                        $vacancy->description,
                    ]));

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

    public function show(Vacancy $vacancy): View
    {
        $eligibleVacancy = $this->eligibleVacancies()
            ->firstWhere('id', $vacancy->getKey());

        abort_unless($eligibleVacancy, 404);

        return view('vacancies.show', [
            'vacancy' => $eligibleVacancy,
        ]);
    }
}
