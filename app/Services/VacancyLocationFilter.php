<?php

namespace App\Services;

use Illuminate\Support\Str;

class VacancyLocationFilter
{
    private const RJ_METRO_CITIES = [
        'rio de janeiro',
        'niteroi',
        'sao goncalo',
        'duque de caxias',
        'nova iguacu',
        'sao joao de meriti',
        'belford roxo',
        'nilopolis',
        'mesquita',
        'itaborai',
    ];

    private const NON_METRO_CITIES = [
        'macae', 'santos', 'campos dos goytacazes',
        'cabo frio', 'angra dos reis', 'petropolis',
        'volta redonda', 'resende', 'itaperuna',
        'teresopolis', 'nova friburgo', 'araruama',
        'rio das ostras', 'buzios', 'sao paulo',
        'curitiba', 'belo horizonte', 'porto alegre',
        'florianopolis', 'brasilia', 'salvador',
        'recife', 'fortaleza', 'goiania', 'manaus', 'belem',
    ];

    private const STATES = [
        'ac' => 'AC', 'acre' => 'AC',
        'al' => 'AL', 'alagoas' => 'AL',
        'ap' => 'AP', 'amapa' => 'AP',
        'am' => 'AM', 'amazonas' => 'AM',
        'ba' => 'BA', 'bahia' => 'BA',
        'ce' => 'CE', 'ceara' => 'CE',
        'df' => 'DF', 'distrito federal' => 'DF',
        'es' => 'ES', 'espirito santo' => 'ES',
        'go' => 'GO', 'goias' => 'GO',
        'ma' => 'MA', 'maranhao' => 'MA',
        'mt' => 'MT', 'mato grosso' => 'MT',
        'ms' => 'MS', 'mato grosso do sul' => 'MS',
        'mg' => 'MG', 'minas gerais' => 'MG',
        'pa' => 'PA', 'para' => 'PA',
        'pb' => 'PB', 'paraiba' => 'PB',
        'pr' => 'PR', 'parana' => 'PR',
        'pe' => 'PE', 'pernambuco' => 'PE',
        'pi' => 'PI', 'piaui' => 'PI',
        'rj' => 'RJ', 'rio de janeiro' => 'RJ',
        'rn' => 'RN', 'rio grande do norte' => 'RN',
        'rs' => 'RS', 'rio grande do sul' => 'RS',
        'ro' => 'RO', 'rondonia' => 'RO',
        'rr' => 'RR', 'roraima' => 'RR',
        'sc' => 'SC', 'santa catarina' => 'SC',
        'sp' => 'SP', 'sao paulo' => 'SP',
        'se' => 'SE', 'sergipe' => 'SE',
        'to' => 'TO', 'tocantins' => 'TO',
    ];

    private const FOREIGN_COUNTRIES = [
        'india', 'poland', 'united kingdom', 'great britain',
        'england', 'scotland', 'wales', 'united states', 'usa',
        'estados unidos', 'canada', 'germany', 'alemanha',
        'singapore', 'singapura', 'hong kong', 'australia',
        'ireland', 'irlanda', 'france', 'franca', 'spain',
        'espanha', 'portugal', 'netherlands', 'pais es',
        'paises baixos', 'switzerland', 'suica', 'mexico',
        'argentina', 'chile', 'colombia', 'peru', 'japan',
        'japao', 'china', 'south africa', 'africa do sul',
        'czech republic', 'republica tcheca', 'romania',
        'romenia', 'reino unido', 'gra bretanha', 'escocia',
        'pais de gales',
    ];

    public function classifyWorkModel(
        ?string $title,
        ?string $location,
        ?string $description
    ): ?string {
        $titleText = $this->normalize($title);
        $locationText = $this->normalize($location);
        $descriptionText = $this->normalize(
            mb_substr($description ?? '', 0, 3000)
        );

        $primaryText = $titleText . ' ' . $locationText;

        if ($this->containsAny($primaryText, [
            'hibrido', 'hybrid', 'modelo hibrido', 'regime hibrido',
        ])) {
            return 'Híbrido';
        }

        if ($this->containsAny($primaryText, [
            'presencial', 'on-site', 'onsite', 'office based',
        ])) {
            return 'Presencial';
        }

        if ($this->containsAny($primaryText, [
            'remoto', 'remote', 'home office', '100% home office',
            'fully remote',
        ])) {
            return 'Remoto';
        }

        if ($this->containsAny($descriptionText, [
            'trabalho 100% remoto',
            'vaga 100% remota',
            'posicao 100% remota',
            'fully remote',
            '100% remote',
            'remote position',
            'work remotely from brazil',
            'remote in brazil',
            'trabalho remoto no brasil',
            'vaga remota no brasil',
            'posicao remota no brasil',
            'regime de trabalho remoto',
            'contratacao totalmente remota',
            'home office integral',
        ])) {
            return 'Remoto';
        }

        if ($this->containsAny($descriptionText, [
            'modelo de trabalho hibrido',
            'regime de trabalho hibrido',
            'trabalho em modelo hibrido',
            'hybrid work model',
            'hybrid position',
        ])) {
            return 'Híbrido';
        }

        if ($this->containsAny($descriptionText, [
            'trabalho presencial',
            'regime presencial',
            'atuacao presencial',
            'presencialmente no escritorio',
            'on-site position',
            'work on-site',
        ])) {
            return 'Presencial';
        }

        return null;
    }

    public function isEligibleForCollection(
        ?string $location,
        ?string $title,
        ?string $description,
        ?string $workModel
    ): bool {
        $locationText = $this->normalize($location);
        $titleText = $this->normalize($title);
        $descriptionText = $this->normalize($description);

        $combinedText = implode(' ', [
            $locationText,
            $titleText,
            $descriptionText,
        ]);

        if ($this->containsForeignCountry($locationText)) {
            return false;
        }

        if (
            $this->containsForeignCountry($titleText)
            && !$this->containsAny($titleText, ['brazil', 'brasil'])
        ) {
            return false;
        }

        if ($workModel === 'Remoto') {
            return $this->isBrazilCompatible(
                $locationText,
                $titleText,
                $descriptionText
            );
        }

        if ($locationText === '') {
            return $this->containsAny($combinedText, ['brazil', 'brasil']);
        }

        return !$this->containsForeignCountry($locationText);
    }

    /**
     * Compatibilidade com chamadas antigas e testes existentes.
     *
     * Considera o visitante como sendo do RJ, preservando a regra
     * anterior da Região Metropolitana.
     *
     * O serviço não acessa a sessão do Laravel.
     */
    public function isEligible(
        ?string $location,
        ?string $title,
        ?string $description,
        ?string $workModel
    ): bool {
        return $this->isEligibleForVisitor(
            $location,
            $title,
            $description,
            $workModel,
            'RJ'
        );
    }

    /**
     * Filtra a vaga conforme o estado informado do visitante.
     *
     * Remoto nacional: aparece para todos os estados.
     * RJ: mantém a regra da Região Metropolitana.
     * Outros estados: exige correspondência da UF.
     * Sem UF: não exibe vagas locais.
     */
    public function isEligibleForVisitor(
        ?string $location,
        ?string $title,
        ?string $description,
        ?string $workModel,
        ?string $visitorStateCode
    ): bool {
        $locationText = $this->normalize($location);
        $titleText = $this->normalize($title);
        $descriptionText = $this->normalize($description);

        if ($this->containsForeignCountry($locationText)) {
            return false;
        }

        if ($workModel === 'Remoto') {
            return $this->isBrazilCompatible(
                $locationText,
                $titleText,
                $descriptionText
            );
        }

        if (!in_array($workModel, ['Híbrido', 'Presencial', null], true)) {
            return false;
        }

        $visitorState = strtoupper(trim($visitorStateCode ?? ''));

        // Sem UF identificada, não exibe vagas locais.
        if (
            $visitorState === ''
            || !in_array($visitorState, array_values(self::STATES), true)
        ) {
            return false;
        }

        // Mantém a regra regional específica do RJ.
        if ($visitorState === 'RJ') {
            return $this->isEligibleForRioDeJaneiro(
                $locationText,
                $titleText
            );
        }

        $vacancyState = $this->extractStateCode($locationText);

        return $vacancyState !== null
            && $vacancyState === $visitorState;
    }

    private function isEligibleForRioDeJaneiro(
        string $location,
        string $title
    ): bool {
        $titleCities = $this->findCitiesInTitle($title);

        foreach ($titleCities as $city) {
            if (in_array($city, self::NON_METRO_CITIES, true)) {
                return false;
            }
        }

        if ($titleCities !== []) {
            foreach ($titleCities as $city) {
                if (!in_array($city, self::RJ_METRO_CITIES, true)) {
                    return false;
                }
            }

            return true;
        }

        return $this->containsAny($location, self::RJ_METRO_CITIES);
    }

    private function extractStateCode(string $location): ?string
    {
        $normalized = $this->normalize($location);

        // Procura uma UF isolada, por exemplo: "Rio do Sul, SC, Brasil".
        if (preg_match(
            '/(?:^|[^a-z])([a-z]{2})(?:$|[^a-z])/',
            $normalized,
            $matches
        )) {
            $code = strtoupper($matches[1]);

            if (in_array($code, array_values(self::STATES), true)) {
                return $code;
            }
        }

        // Procura o nome completo do estado.
        foreach (self::STATES as $name => $code) {
            if (
                strlen($name) > 2
                && preg_match(
                    '/(^|[^a-z])' . preg_quote($name, '/') . '([^a-z]|$)/',
                    $normalized
                )
            ) {
                return $code;
            }
        }

        return null;
    }

    private function isBrazilCompatible(
        string $location,
        string $title,
        string $description
    ): bool {
        if ($this->containsAny(
            $location . ' ' . $title,
            ['brazil', 'brasil']
        )) {
            return true;
        }

        return $this->containsAny($description, [
            'work remotely from brazil',
            'remote in brazil',
            'trabalho remoto no brasil',
            'vaga remota no brasil',
            'posicao remota no brasil',
        ]);
    }

    private function findCitiesInTitle(string $title): array
    {
        $cities = [];

        foreach (array_merge(
            self::RJ_METRO_CITIES,
            self::NON_METRO_CITIES
        ) as $city) {
            if (preg_match(
                '/(^|[^a-z])' . preg_quote($city, '/') . '([^a-z]|$)/',
                $title
            )) {
                $cities[] = $city;
            }
        }

        return array_values(array_unique($cities));
    }

    private function containsForeignCountry(string $location): bool
    {
        foreach (self::FOREIGN_COUNTRIES as $country) {
            if (preg_match(
                '/(^|[^a-z])' . preg_quote($country, '/') . '([^a-z]|$)/',
                $location
            )) {
                return true;
            }
        }

        return false;
    }

    private function containsAny(string $text, array $terms): bool
    {
        foreach ($terms as $term) {
            if (str_contains($text, $this->normalize($term))) {
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
