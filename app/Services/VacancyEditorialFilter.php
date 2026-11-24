<?php

namespace App\Services;

use Illuminate\Support\Str;

/**
 * Aplica os critérios editoriais do TI Vagas antes da persistência.
 *
 * O escopo é global: somente oportunidades júnior efetivas de
 * desenvolvimento, QA, suporte e infraestrutura no Brasil.
 */
class VacancyEditorialFilter
{
    private const DEVELOPMENT_TERMS = [
        'desenvolvedor', 'developer', 'programador', 'software engineer',
        'engenheiro de software', 'backend', 'back-end', 'frontend',
        'front-end', 'full stack', 'fullstack', 'integration developer',
        'php developer', '.net developer', 'java developer', 'python developer',
        'desenvolvedor de software', 'desenvolvedor de sistemas',
        'desenvolvedor web', 'desenvolvedor mobile',
        'desenvolvedor de aplicativos', 'software developer', 'web developer',
        'mobile developer', 'application developer',
        'analista de desenvolvimento', 'analista de sistemas de informação',
        'ios developer', 'android developer', 'qa', 'quality assurance',
        'quality analyst', 'analista de qa', 'analista de testes', 'tester',
        'software tester', 'test engineer', 'engenheiro de testes',
        'automation tester', 'qa automation',
    ];

    private const SUPPORT_TERMS = [
        'suporte tecnico', 'suporte de ti', 'suporte ti',
        'analista de suporte', 'tecnico de informatica',
        'tecnico de suporte', 'help desk', 'helpdesk', 'service desk',
        'servicedesk', 'technical support', 'it support', 'field support',
        'analista de service desk', 'analista de help desk',
        'suporte ao usuario', 'suporte de sistemas', 'suporte a sistemas',
        'suporte de informatica', 'assistente de suporte',
        'auxiliar de suporte', 'analista de suporte de sistemas',
        'analista de suporte tecnico', 'tecnico de suporte ti',
        'tecnico de service desk', 'operador de service desk',
        'field service', 'microinformatica',
    ];

    private const INFRASTRUCTURE_TERMS = [
        'infraestrutura', 'infrastructure', 'analista de redes',
        'tecnico de redes', 'network engineer', 'network analyst',
        'cloud engineer', 'devops', 'sysadmin', 'system administrator',
        'administrador de sistemas', 'analista de sistemas', 'noc',
        'analista de infraestrutura', 'tecnico de infraestrutura',
        'assistente de infraestrutura', 'auxiliar de infraestrutura',
        'administrador de redes', 'administrador de banco de dados',
        'database administrator', 'dba', 'site reliability engineer', 'sre',
        'network administrator', 'cloud administrator', 'analista de cloud',
        'engenheiro de infraestrutura', 'security analyst',
        'analista de seguranca da informacao', 'analista de seguranca',
        'cybersecurity', 'ciberseguranca', 'soc analyst',
    ];

    private const EXCLUDED_SENIORITY_TERMS = [
        'pleno', 'senior', 'sr', 'staff', 'principal', 'lead', 'manager',
        'gerente', 'director', 'diretor', 'head', 'architect', 'arquiteto',
        'especialista', 'coordenador', 'coordenadora', 'lider', 'supervisor',
        'supervisora', 'mid', 'middle', 'intermediate', 'trainee', 'estagio',
        'estagiario', 'intern', 'internship',
    ];

    private const IRRELEVANT_TERMS = [
        'programador(a) de operacao', 'programador de operacao',
        'programadora de operacao', 'programador operacional',
        'programadora operacional', 'programador de servicos',
        'programadora de servicos',
        'marketing', 'vendas', 'sales', 'juridico', 'legal', 'financeiro',
        'financial', 'recruiter', 'recrutamento', 'recursos humanos',
        'human resources', 'engenheiro de planejamento', 'engenheiro de pocos',
        'engenheiro mecanico', 'engenheiro de qualidade', 'engenheiro de risco',
        'engenheiro fiscal', 'engenheiro de seguranca', 'commercial contracts',
        'legal ops', 'analista administrativo', 'analista de gestao',
        'analista de operacoes', 'compras', 'procurement', 'contabilidade',
        'accounting', 'departamento pessoal', 'folha de pagamento', 'payroll',
        'logistica', 'logistics', 'almoxarifado', 'estoque', 'comercial',
        'customer success', 'customer experience', 'atendimento ao cliente',
        'operador de caixa', 'secretaria', 'secretariado', 'recepcionista',
        'engenheiro civil', 'engenheiro eletricista', 'engenheiro de producao',
        'engenheiro ambiental', 'tecnico de seguranca do trabalho',
        'consultor crm', 'consultora crm', 'analista crm', 'consultor erp',
        'consultora erp', 'analista erp', 'consultor sap', 'consultora sap',
        'analista sap', 'desenvolvedor sap', 'sap abap', 'abap', 'totvs',
        'protheus', 'consultor funcional', 'consultora funcional',
        'analista funcional', 'implantacao de sistemas', 'implantador de sistemas',
        'data analyst', 'data analytics', 'analytics engineer',
        'analista de dados', 'engenheiro de dados', 'data engineer',
        'cientista de dados', 'data scientist', 'business intelligence',
        'business intelligence analyst', 'bi analyst', 'analista de bi',
        'engenheiro de machine learning', 'machine learning engineer',
        'machine learning specialist', 'deep learning', 'ml engineer',
        'ai engineer', 'engenheiro de inteligencia artificial',
        'especialista em inteligencia artificial',
        'cientista de machine learning',
    ];

    public function __construct(
        private readonly VacancyLocationFilter $locationFilter
    ) {
    }

    /**
     * @param array<string, mixed> $vacancy
     */
    public function isEligible(array $vacancy, string $sourceName): bool
    {
        $title = is_string($vacancy['title'] ?? null)
            ? $vacancy['title']
            : '';
        $description = is_string($vacancy['description'] ?? null)
            ? $vacancy['description']
            : '';
        $location = is_string($vacancy['location'] ?? null)
            ? $vacancy['location']
            : '';
        $titleText = $this->normalize($title);
        $descriptionText = $this->normalize($description);

        if ($titleText === '' || $descriptionText === '') {
            return false;
        }

        // Telecomunicações especializadas (operadoras/NOC de telecom) não fazem parte do escopo atual.
        if ($this->isOutOfScopeTelecomRole($titleText, $descriptionText)) {
            return false;
        }

        if ($this->containsAny($titleText, self::EXCLUDED_SENIORITY_TERMS)) {
            return false;
        }

        if (
            preg_match('/\\bpl\\s*$/i', $titleText) === 1
            || preg_match('/\\bsoftware engineer\\s+(?:iii|iv|v)\\b/i', $titleText) === 1
        ) {
            return false;
        }

        // Rejeita exigência explícita de três ou mais anos de experiência.
        if (preg_match(
            '/\\b(?:3|[4-9]|\\d{2,})\\s*(?:(?:\\+|plus)\\s*)?(?:[-–]\\s*(?:3|[4-9]|\\d{2,})\\s*)?(?:years?|anos)\\b/i',
            $descriptionText
        ) === 1) {
            return false;
        }

        $nonJuniorSeniority = '(?:pleno|senior|sr|staff|principal|lead|mid(?:[- ]level)?|middle(?:[- ]level)?|intermediate)';
        if (preg_match(
            '/(?:desenvolvedor|developer|software engineer|engineer|consultor|consultant|programador|programmer).{0,80}\\b' . $nonJuniorSeniority . '\\b|\\b' . $nonJuniorSeniority . '\\b.{0,80}(?:desenvolvedor|developer|software engineer|engineer|consultor|consultant|programador|programmer)/i',
            $descriptionText
        ) === 1) {
            return false;
        }

        $radarTecException = $sourceName === 'radartec'
            && (string) ($vacancy['external_id'] ?? '') === '13421'
            && str_contains($titleText, 'desenvolvedor full stack web');

        if (
            !$radarTecException
            && preg_match(
                '/(?:forte|solida|solido|ampla|vasta|extensa|extenso|strong|extensive).{0,40}(?:experiencia|experience|knowledge|conhecimento)/i',
                $descriptionText
            ) === 1
        ) {
            return false;
        }

        if ($this->containsAny($titleText, self::IRRELEVANT_TERMS)) {
            return false;
        }

        if (!$this->isTechnologyRole($titleText, $descriptionText)) {
            return false;
        }

        $workModel = is_string($vacancy['work_model'] ?? null)
            ? $vacancy['work_model']
            : null;
        $workModel = $workModel ?: $this->locationFilter->classifyWorkModel(
            $title,
            $location,
            $description
        );

        return $this->locationFilter->isEligibleForCollection(
            $location,
            $title,
            $description,
            $workModel
        );
    }

    private function isOutOfScopeTelecomRole(string $title, string $description): bool
    {
        if ($this->containsAny($title, ['telecom', 'telecomunicacoes', 'telecommunications'])) {
            return true;
        }

        // Também identifica anúncios cujo título é apenas um código, mas a descrição
        // deixa explícita a atuação em operadora e exige protocolos de telecom.
        $telecomOperator = $this->containsAny($description, [
            'operadora de internet', 'operadora de telecom', 'telecom operator',
        ]);
        $telecomTechnologies = [
            'bgp', 'ospf', 'is-is', 'eigrp', 'dwdm', 'mpls',
        ];
        $matchedTechnologies = 0;

        foreach ($telecomTechnologies as $technology) {
            if ($this->containsAny($description, [$technology])) {
                $matchedTechnologies++;
            }
        }

        return $telecomOperator && $matchedTechnologies >= 2;
    }

    private function isTechnologyRole(string $title, string $description): bool
    {
        if (
            $this->containsAny($title, self::DEVELOPMENT_TERMS)
            || $this->containsAny($title, self::SUPPORT_TERMS)
            || $this->containsAny($title, self::INFRASTRUCTURE_TERMS)
        ) {
            return true;
        }

        // Títulos genéricos (ex.: códigos internos) podem ser reconhecidos pela
        // descrição, desde que haja atividade de suporte E contexto técnico concreto.
        $supportActivity = $this->containsAny($description, [
            'suporte tecnico', 'suporte de ti', 'suporte n1', 'suporte nivel 1',
            'service desk', 'help desk', 'atendimento de chamados',
            'resolucao de incidentes', 'diagnostico de problemas', 'troubleshooting',
        ]);
        $technicalContext = $this->containsAny($description, [
            'sistemas operacionais', 'windows', 'linux', 'active directory',
            'office 365', 'microsoft 365', 'servidores', 'infraestrutura de ti',
            'redes corporativas', 'banco de dados', 'hardware', 'software',
            'aplicacoes', 'ambiente cloud',
        ]);

        return $supportActivity && $technicalContext;
    }

    private function containsAny(string $text, array $terms): bool
    {
        foreach ($terms as $term) {
            $normalizedTerm = $this->normalize($term);

            if (preg_match(
                '/(^|[^a-z0-9])' . preg_quote($normalizedTerm, '/') . '([^a-z0-9]|$)/',
                $text
            ) === 1) {
                return true;
            }
        }

        return false;
    }

    private function normalize(?string $value): string
    {
        return Str::ascii(mb_strtolower($value ?? ''));
    }
}
