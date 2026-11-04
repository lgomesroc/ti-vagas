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
        return Vacancy::query()
            ->whereNotNull('original_published_at')
            ->where('original_published_at', '>=', now()->subDays(60))
            ->orderByDesc('original_published_at')
            ->get()
            ->filter(function (Vacancy $vacancy) {
                $title = $this->normalize($vacancy->title);

                if (!$this->hasAllowedSeniority($title)) {
                    return false;
                }

                if ($this->containsAny($title, self::EXCLUDED_SENIORITY_TERMS)) {
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
    }

    private function isTechnologyRole(string $title): bool
    {
        return $this->containsAny($title, self::DEVELOPMENT_TERMS)
            || $this->containsAny($title, self::SUPPORT_TERMS)
            || $this->containsAny($title, self::INFRASTRUCTURE_TERMS);
    }

    private function hasAllowedSeniority(string $title): bool
    {
        return $this->containsAny($title, self::JUNIOR_TERMS);
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
