<?php

namespace App\Services\Geo;

use App\Models\Vacancy;

/**
 * Descobre o município de uma vaga.
 *
 * Ordem de prioridade:
 *   1. "Cidade/UF" ou "Cidade - UF" no título (substitui uma localização
 *      genérica, como "Brasil - Rio de Janeiro");
 *   2. cidade e UF estruturadas informadas pela fonte;
 *   3. texto livre da localização.
 *
 * Quando a UF é informada, o município precisa existir nessa UF.
 * "Santos, Rio de Janeiro", por exemplo, não resolve para nenhum município,
 * pois Santos não fica no Rio de Janeiro.
 */
class VacancyLocationResolver
{
    private const STATE_NAMES = [
        'acre' => 'AC', 'alagoas' => 'AL', 'amapa' => 'AP',
        'amazonas' => 'AM', 'bahia' => 'BA', 'ceara' => 'CE',
        'distrito federal' => 'DF', 'espirito santo' => 'ES',
        'goias' => 'GO', 'maranhao' => 'MA', 'mato grosso' => 'MT',
        'mato grosso do sul' => 'MS', 'minas gerais' => 'MG',
        'para' => 'PA', 'paraiba' => 'PB', 'parana' => 'PR',
        'pernambuco' => 'PE', 'piaui' => 'PI',
        'rio de janeiro' => 'RJ', 'rio grande do norte' => 'RN',
        'rio grande do sul' => 'RS', 'rondonia' => 'RO',
        'roraima' => 'RR', 'santa catarina' => 'SC',
        'sao paulo' => 'SP', 'sergipe' => 'SE', 'tocantins' => 'TO',
    ];

    private const NOISE_TOKENS = [
        'brasil', 'brazil', 'br', 'remoto', 'remote', 'hibrido',
        'hybrid', 'presencial', 'home office', 'on site', 'onsite',
        'latam', 'latin america', 'america latina', 'anywhere',
    ];

    public function __construct(
        private readonly MunicipalityDirectory $directory
    ) {
    }

    /**
     * Município de uma vaga já gravada.
     *
     * Usa o código IBGE gravado na importação e, para registros antigos,
     * resolve a partir do texto.
     *
     * @return array<string, mixed>|null
     */
    public function resolveForVacancy(Vacancy $vacancy): ?array
    {
        $stored = $this->directory->find(
            $vacancy->ibge_municipality_code
        );

        if ($stored !== null) {
            return $stored;
        }

        return $this->resolve(
            $vacancy->location,
            $vacancy->title,
            $vacancy->city,
            $vacancy->state_code
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function resolve(
        ?string $location,
        ?string $title = null,
        ?string $city = null,
        ?string $stateCode = null
    ): ?array {
        $fromTitle = $this->resolveFromTitle($title);

        if ($fromTitle !== null) {
            return $fromTitle;
        }

        if ($city !== null && trim($city) !== '') {
            $matches = $this->directory->findByName(
                $city,
                $this->normalizeStateCode($stateCode)
            );

            if (count($matches) === 1) {
                return $matches[0];
            }
        }

        return $this->resolveFromText($location, $stateCode);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function resolveFromText(
        ?string $text,
        ?string $stateHint = null
    ): ?array {
        $tokens = $this->tokenize($text);

        if ($tokens === []) {
            return null;
        }

        $stateCode = $this->normalizeStateCode($stateHint);

        $remaining = [];

        // 1. Sigla de UF explícita (ex.: "Niterói - RJ").
        foreach ($tokens as $token) {
            if (
                strlen($token) === 2
                && in_array(strtoupper($token), self::STATE_NAMES, true)
            ) {
                $stateCode = strtoupper($token);

                continue;
            }

            $remaining[] = $token;
        }

        // 2. Nome do estado (ex.: "Niterói, Rio de Janeiro, Brasil").
        $remaining = $this->extractStateNames($remaining, $stateCode);

        $candidates = $remaining['candidates'];
        $stateCode = $remaining['state'];

        foreach ($candidates as $candidate) {
            $matches = $this->directory->findByName($candidate, $stateCode);

            if (count($matches) === 1) {
                return $matches[0];
            }
        }

        // Com UF conhecida, aceita termos extras junto da cidade
        // (ex.: "Híbrido Niterói").
        if ($stateCode !== null) {
            foreach ($candidates as $candidate) {
                $found = $this->matchWords($candidate, $stateCode);

                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveFromTitle(?string $title): ?array
    {
        if ($title === null || $title === '') {
            return null;
        }

        if (!preg_match_all(
            '/([\p{L}][\p{L}\.\' ]{2,60}?)\s*[\/\-–]\s*([A-Z]{2})(?![\p{L}])/u',
            $title,
            $matches,
            PREG_SET_ORDER
        )) {
            return null;
        }

        foreach ($matches as $match) {
            $stateCode = $match[2];

            if (!in_array($stateCode, self::STATE_NAMES, true)) {
                continue;
            }

            $found = $this->matchWords($match[1], $stateCode);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * Procura um município pelas últimas palavras da frase
     * (da frase inteira até uma única palavra).
     *
     * @return array<string, mixed>|null
     */
    private function matchWords(string $phrase, string $stateCode): ?array
    {
        $words = array_values(array_filter(
            explode(' ', MunicipalityDirectory::normalize($phrase))
        ));

        $count = count($words);

        for ($length = min($count, 5); $length >= 1; $length--) {
            $candidate = implode(' ', array_slice($words, $count - $length));
            $matches = $this->directory->findByName($candidate, $stateCode);

            if (count($matches) === 1) {
                return $matches[0];
            }
        }

        return null;
    }


    /**
     * Converte uma sigla ou o nome completo de uma UF para sua sigla.
     */
    private function normalizeStateCode(?string $state): ?string
    {
        if ($state === null || trim($state) === '') {
            return null;
        }

        $normalized = MunicipalityDirectory::normalize($state);

        if (strlen($normalized) === 2) {
            $code = strtoupper($normalized);

            return in_array($code, self::STATE_NAMES, true) ? $code : null;
        }

        return self::STATE_NAMES[$normalized] ?? null;
    }

    /**
     * Separa o que é estado do que é cidade nos tokens restantes.
     *
     * "Rio de Janeiro" e "São Paulo" são ao mesmo tempo estado e cidade:
     * só são tratados como estado quando há outra cidade no texto
     * ou quando aparecem repetidos.
     *
     * @param list<string> $tokens
     * @return array{candidates: list<string>, state: string|null}
     */
    private function extractStateNames(array $tokens, ?string $stateCode): array
    {
        $candidates = [];
        $ambiguous = [];

        foreach ($tokens as $token) {
            $name = preg_replace(
                '/^(estado|state) (de|do|da|of) /',
                '',
                $token
            );

            $state = self::STATE_NAMES[$name] ?? null;

            if ($state === null) {
                $candidates[] = $token;

                continue;
            }

            $isAlsoCity = $this->directory->findByName($name, $state) !== [];

            if ($isAlsoCity && !str_starts_with($token, 'estado ') && !str_starts_with($token, 'state ')) {
                $ambiguous[] = [$name, $state];

                continue;
            }

            if ($stateCode === null) {
                $stateCode = $state;
            }
        }

        foreach ($ambiguous as $index => [$name, $state]) {
            $hasOtherCity = $candidates !== []
                || count($ambiguous) > 1;

            if ($stateCode === null && $hasOtherCity && $index === count($ambiguous) - 1) {
                $stateCode = $state;

                continue;
            }

            $candidates[] = $name;
        }

        return ['candidates' => $candidates, 'state' => $stateCode];
    }

    /**
     * @return list<string>
     */
    private function tokenize(?string $text): array
    {
        if ($text === null || trim($text) === '') {
            return [];
        }

        $parts = preg_split(
            '/[,;|\/()\n]|\s[-–—]\s/u',
            $text
        ) ?: [];

        $tokens = [];

        foreach ($parts as $part) {
            $token = MunicipalityDirectory::normalize($part);

            if ($token === '' || in_array($token, self::NOISE_TOKENS, true)) {
                continue;
            }

            $tokens[] = $token;
        }

        return $tokens;
    }
}
