<?php

namespace App\Sources;

use App\Contracts\VacancySource;
use App\Services\VacancyLocationFilter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

class RadarTecSource implements VacancySource
{
    private const LIST_URL = 'https://www.radartec.com.br/pesquisar/vaga';

    private const INCLUDED_IDS = [
        '13421',
        '13423',
    ];

    private const EXCLUDED_IDS = [
        '13422',
    ];

    private readonly VacancyLocationFilter $locationFilter;

    public function __construct(
        private readonly ?string $caBundle = null,
        private readonly int $maxJobs = 200,
        ?VacancyLocationFilter $locationFilter = null,
    ) {
        $this->locationFilter = $locationFilter ?? new VacancyLocationFilter();
    }

    public function name(): string
    {
        return 'radartec';
    }

    public function fetch(): iterable
    {
        $response = $this->http()->get(self::LIST_URL, [
            'quantidade' => 100,
            'ordem' => 0,
        ]);

        $response->throw();

        $cards = $this->extractCards($response->body());
        $vacancies = [];

        foreach ($cards as $card) {
            if (count($vacancies) >= $this->maxJobs) {
                break;
            }

            $id = $card['id'];

            if (in_array($id, self::EXCLUDED_IDS, true)) {
                continue;
            }

            if (!$this->isPotentiallyRelevant($card)) {
                continue;
            }

            $details = $this->fetchDetails($card['url']);

            if ($details === null) {
                continue;
            }

            if (!$this->isEligibleVacancy($card, $details)) {
                continue;
            }

            $publishedAt = $this->parsePublicationDate(
                $card['published_at']
            );

            if ($publishedAt === null) {
                continue;
            }

            $location = trim($card['location']);

            if ($location !== '') {
                $location .= ', Brasil';
            }

            $workModel = $this->locationFilter->classifyWorkModel(
                $card['title'],
                $location,
                $details['description']
            );

            $vacancies[] = [
                'title' => $card['title'],
                'company' => 'Empresa confidencial',
                'description' => $details['description'],
                'location' => $location !== '' ? $location : null,
                'work_model' => $workModel,
                'employment_type' => $details['employment_type'],
                'salary' => $card['salary'] !== ''
                    ? $card['salary']
                    : null,
                'application_url' => $card['url'],
                'external_id' => $id,
                'original_published_at' => $publishedAt,

                'city' => $details['city'],
                'state' => $details['state'],
                'state_code' => $details['state_code'],
                'country' => $details['country'],
                'country_code' => $details['country_code'],
                'latitude' => $details['latitude'],
                'longitude' => $details['longitude'],
            ];
        }

        return $vacancies;
    }

    private function http()
    {
        return Http::timeout(45)
            ->withHeaders([
                'Accept' => 'text/html,application/xhtml+xml',
                'User-Agent' => 'Mozilla/5.0 (compatible; TIVagas/1.0)',
            ])
            ->withOptions([
                'verify' => $this->caBundle ?: true,
            ]);
    }

    private function extractCards(string $html): array
    {
        $xpath = $this->createXPath($html);

        $nodes = $xpath->query(
            '//div[contains(concat(" ", normalize-space(@class), " "), " vote-item ")]'
        );

        if ($nodes === false) {
            return [];
        }

        $cards = [];

        foreach ($nodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }

            $titleNode = $this->firstNode(
                $xpath,
                './/a[contains(concat(" ", normalize-space(@class), " "), " vote-title ")]',
                $node
            );

            if (!$titleNode instanceof DOMElement) {
                continue;
            }

            $url = trim($titleNode->getAttribute('href'));

            if ($url === '') {
                continue;
            }

            if (str_starts_with($url, '/')) {
                $url = 'https://www.radartec.com.br' . $url;
            }

            if (!preg_match('~/vaga/(\d+)/~', $url, $matches)) {
                continue;
            }

            $cards[] = [
                'id' => $matches[1],
                'title' => $this->nodeText($titleNode),
                'url' => $url,
                'location' => $this->nodeText(
                    $this->firstNode(
                        $xpath,
                        './/span[contains(concat(" ", normalize-space(@class), " "), " cidade ")]',
                        $node
                    )
                ),
                'salary' => $this->nodeText(
                    $this->firstNode(
                        $xpath,
                        './/span[contains(concat(" ", normalize-space(@class), " "), " salario ")]',
                        $node
                    )
                ),
                'published_at' => $this->nodeText(
                    $this->firstNode(
                        $xpath,
                        './/*[contains(concat(" ", normalize-space(@class), " "), " info-data ")]//p',
                        $node
                    )
                ),
                'summary' => $this->nodeText(
                    $this->firstNode(
                        $xpath,
                        './/*[contains(concat(" ", normalize-space(@class), " "), " vote-info ")]//p',
                        $node
                    )
                ),
            ];
        }

        return $cards;
    }

    private function fetchDetails(string $url): ?array
    {
        $response = $this->http()->get($url);
        $response->throw();

        $xpath = $this->createXPath($response->body());

        // O JSON-LD precisa ser lido antes da remoção dos scripts.
        $geography = $this->extractJsonLdGeography($xpath);

        foreach ($xpath->query('//script | //style | //noscript') ?: [] as $node) {
            $node->parentNode?->removeChild($node);
        }

        $body = $this->firstNode($xpath, '//body');
        $text = $this->nodeText($body);

        if ($text === '') {
            return null;
        }

        $description = null;

        if (preg_match(
            '/\bDescrição\s+(.*?)\s+(?:há\s+\d+\s+(?:dia|dias|semana|semanas|mês|meses)|Vagas relacionadas)/iu',
            $text,
            $matches
        )) {
            $description = trim($matches[1]);
        }

        if ($description === null || $description === '') {
            return null;
        }

        $employmentType = null;

        if (preg_match(
            '/Tipo de contrato\s+(.*?)\s+Descrição/iu',
            $text,
            $matches
        )) {
            $employmentType = trim($matches[1]);
        }

        return array_merge([
            'description' => $description,
            'employment_type' => $employmentType,
        ], $geography);
    }

    /**
     * Localiza o JobPosting no JSON-LD e extrai sua localização.
     * Suporta objetos diretos, arrays e estruturas @graph.
     */
    private function extractJsonLdGeography(DOMXPath $xpath): array
    {
        $empty = [
            'city' => null,
            'state' => null,
            'state_code' => null,
            'country' => null,
            'country_code' => null,
            'latitude' => null,
            'longitude' => null,
        ];

        $scripts = $xpath->query(
            '//script[contains(translate(@type, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "ld+json")]'
        );

        if ($scripts === false) {
            return $empty;
        }

        foreach ($scripts as $script) {
            $json = json_decode(
                $script->textContent ?? '',
                true
            );

            if (!is_array($json)) {
                continue;
            }

            $jobPosting = $this->findJobPosting($json);

            if ($jobPosting === null) {
                continue;
            }

            $jobLocation = $jobPosting['jobLocation'] ?? null;

            if (!is_array($jobLocation)) {
                continue;
            }

            if (array_is_list($jobLocation)) {
                $jobLocation = $jobLocation[0] ?? null;
            }

            if (!is_array($jobLocation)) {
                continue;
            }

            $address = $jobLocation['address'] ?? [];

            if (!is_array($address)) {
                continue;
            }

            $geo = $jobLocation['geo'] ?? [];

            if (!is_array($geo)) {
                $geo = [];
            }

            $city = $this->readStructuredString(
                $address,
                ['addressLocality', 'city', 'cityName']
            );

            $state = $this->readStructuredString(
                $address,
                ['addressRegion', 'state', 'stateName']
            );

            // addressRegionCode é o campo usado para informar a UF.
            $stateCode = $this->readStructuredString(
                $address,
                [
                    'addressRegionCode',
                    'stateCode',
                    'state_code',
                    'uf',
                ]
            );

            if ($stateCode !== null) {
                $stateCode = strtoupper($stateCode);
            }

            // Fallback somente quando o próprio campo de estado
            // contém uma UF de exatamente duas letras.
            if (
                $stateCode === null
                && $state !== null
                && preg_match('/^[A-Z]{2}$/i', $state)
            ) {
                $stateCode = strtoupper($state);
            }

            $countryValue = $this->readStructuredString(
                $address,
                ['addressCountry', 'country', 'countryName']
            );

            // addressCountryCode é o campo usado para informar
            // o código ISO do país.
            $countryCode = $this->readStructuredString(
                $address,
                [
                    'addressCountryCode',
                    'countryCode',
                    'country_code',
                ]
            );

            if ($countryCode !== null) {
                $countryCode = strtoupper($countryCode);
            }

            if (
                $countryCode === null
                && $countryValue !== null
                && preg_match('/^[A-Z]{2}$/i', $countryValue)
            ) {
                $countryCode = strtoupper($countryValue);
            }

            $country = $countryValue;

            if (in_array(
                Str::upper(trim((string) $countryValue)),
                ['BR', 'BRA', 'BRASIL', 'BRAZIL'],
                true
            )) {
                $country = 'Brasil';
                $countryCode = 'BR';
            }

            return [
                'city' => $city,
                'state' => $state,
                'state_code' => $stateCode,
                'country' => $country,
                'country_code' => $countryCode,
                'latitude' => $this->readNumericValue(
                    $geo,
                    ['latitude', 'lat']
                ),
                'longitude' => $this->readNumericValue(
                    $geo,
                    ['longitude', 'lng', 'lon']
                ),
            ];
        }

        return $empty;
    }

    /**
     * Procura um objeto JobPosting em estruturas JSON-LD,
     * incluindo @graph e arrays de objetos.
     */
    private function findJobPosting(array $data): ?array
    {
        $type = $data['@type'] ?? null;

        if (is_string($type) && Str::lower($type) === 'jobposting') {
            return $data;
        }

        if (is_array($type) && in_array('JobPosting', $type, true)) {
            return $data;
        }

        foreach ($data as $value) {
            if (!is_array($value)) {
                continue;
            }

            if (array_is_list($value)) {
                foreach ($value as $item) {
                    if (!is_array($item)) {
                        continue;
                    }

                    $found = $this->findJobPosting($item);

                    if ($found !== null) {
                        return $found;
                    }
                }

                continue;
            }

            $found = $this->findJobPosting($value);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    private function readStructuredString(
        array $data,
        array $keys
    ): ?string {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }

            $value = $data[$key];

            if (is_array($value)) {
                foreach (['name', 'value', '@value'] as $nestedKey) {
                    if (
                        isset($value[$nestedKey])
                        && is_string($value[$nestedKey])
                        && trim($value[$nestedKey]) !== ''
                    ) {
                        return trim($value[$nestedKey]);
                    }
                }

                continue;
            }

            if (is_string($value) || is_numeric($value)) {
                $value = trim((string) $value);

                if ($value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    private function readNumericValue(
        array $data,
        array $keys
    ): int|float|null {
        foreach ($keys as $key) {
            if (
                array_key_exists($key, $data)
                && is_numeric($data[$key])
            ) {
                return (float) $data[$key];
            }
        }

        return null;
    }

    private function isPotentiallyRelevant(array $card): bool
    {
        $text = $this->normalize(
            $card['title'] . ' ' . $card['summary']
        );

        return preg_match(
            '/desenvolvedor|developer|programador|software|backend|back.?end|frontend|front.?end|full.?stack|java|php|\.net|python|angular|react|suporte|infraestrutura|infra|sistemas|devops|cloud|redes|analista soc|seguranca da informacao|sysadmin|dba|engenheiro de dados|data engineer|qa|testes/i',
            $text
        ) === 1;
    }

    private function isEligibleVacancy(array $card, array $details): bool
    {
        $id = $card['id'];

        if (in_array($id, self::INCLUDED_IDS, true)) {
            return true;
        }

        $text = $this->normalize(
            $card['title'] . ' ' . $details['description']
        );

        if (preg_match(
            '/\b(pleno|senior|sr|staff|principal|lead|lider|gerente|manager|especialista|coordenador|arquiteto)\b/i',
            $text
        )) {
            return false;
        }

        if (preg_match(
            '/(?:minimo|minima|pelo menos)\s+(?:de\s+)?([3-9]|\d{2})\s+anos/i',
            $text
        )) {
            return false;
        }

        if (preg_match(
            '/experiencia.{0,60}\b([3-9]|\d{2})\s+anos/i',
            $text
        )) {
            return false;
        }

        $hasJuniorSignal = preg_match(
            '/\b(junior|jr|estagio|estagiario|intern|internship)\b/i',
            $text
        ) === 1;

        if (!$hasJuniorSignal) {
            return false;
        }

        $location = trim($card['location']);

        if ($location !== '') {
            $location .= ', Brasil';
        }

        $workModel = $this->locationFilter->classifyWorkModel(
            $card['title'],
            $location,
            $details['description']
        );

        return $this->locationFilter->isEligibleForCollection(
            $location,
            $card['title'],
            $details['description'],
            $workModel
        );
    }

    private function parsePublicationDate(string $date): ?string
    {
        try {
            return Carbon::createFromFormat(
                '!d/m/Y',
                trim($date)
            )->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function createXPath(string $html): DOMXPath
    {
        $document = new DOMDocument('1.0', 'UTF-8');

        $previous = libxml_use_internal_errors(true);

        $document->loadHTML(
            '<?xml encoding="UTF-8">' . $html,
            LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }

    private function firstNode(
        DOMXPath $xpath,
        string $query,
        ?DOMNode $context = null
    ): ?DOMNode {
        $nodes = $xpath->query($query, $context);

        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        return $nodes->item(0);
    }

    private function nodeText(?DOMNode $node): string
    {
        if ($node === null) {
            return '';
        }

        $text = html_entity_decode(
            $node->textContent ?? '',
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );

        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    private function normalize(string $value): string
    {
        return Str::ascii(mb_strtolower($value));
    }
}
