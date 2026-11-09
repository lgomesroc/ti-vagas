<?php

namespace App\Sources;

use App\Contracts\VacancySource;
use App\Services\VacancyLocationFilter;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class RecrutAiSource implements VacancySource
{
    private readonly string $domain;

    public function __construct(
        private readonly string $tenant,
        private readonly string $companyName,
        string $domain,
        private readonly int $maxJobs = 200,
        private readonly ?string $caBundle = null,
        private readonly ?VacancyLocationFilter $locationFilter = null,
    ) {
        $domain = preg_replace(
            '#^https?://#i',
            '',
            trim($domain)
        );

        $this->domain = rtrim($domain, '/');
    }

    public function name(): string
    {
        return 'recrutai-' . Str::slug($this->tenant);
    }

    public function fetch(): iterable
    {
        $url = "https://{$this->domain}/company/public-jobs/*/*/*/*/?search=";

        try {
            $response = $this->http()->get($url);
            $response->throw();

            $payload = $response->json();

            $html = is_array($payload)
                ? ($payload['html'] ?? null)
                : null;

            if (!is_string($html) || trim($html) === '') {
                throw new RuntimeException(
                    'A resposta da Recrut.AI não contém o HTML da listagem.'
                );
            }

            $jobs = $this->extractListingJobs($html);
            $processed = 0;

            foreach ($jobs as $job) {
                if ($processed >= $this->maxJobs) {
                    break;
                }

                $processed++;

                try {
                    $vacancy = $this->fetchJobDetails($job);

                    if ($vacancy !== null) {
                        yield $vacancy;
                    }
                } catch (Throwable $exception) {
                    Log::warning(
                        'Falha ao consultar vaga da Recrut.AI.',
                        [
                            'source' => $this->name(),
                            'code' => $job['code'] ?? null,
                            'error' => $exception->getMessage(),
                        ]
                    );
                }
            }
        } catch (Throwable $exception) {
            Log::error(
                'Falha na coleta da Recrut.AI.',
                [
                    'source' => $this->name(),
                    'domain' => $this->domain,
                    'error' => $exception->getMessage(),
                ]
            );

            throw $exception;
        }
    }

    private function extractListingJobs(string $html): array
    {
        $document = $this->loadHtml($html);
        $xpath = new DOMXPath($document);

        $anchors = $xpath->query('//a[contains(@href, "job/")]');

        if ($anchors === false) {
            return [];
        }

        $jobs = [];

        foreach ($anchors as $anchor) {
            if (!$anchor instanceof DOMElement) {
                continue;
            }

            $href = $anchor->getAttribute('href');

            if (!preg_match(
                '#(?:^|/)job/([A-Za-z0-9_-]+)#',
                $href,
                $matches
            )) {
                continue;
            }

            $code = $matches[1];

            if (isset($jobs[$code])) {
                continue;
            }

            $title = $this->text($anchor);
            $listingText = '';

            $cards = $xpath->query(
                'ancestor::*[
                    contains(
                        concat(" ", normalize-space(@class), " "),
                        " card "
                    )
                ][1]',
                $anchor
            );

            if ($cards !== false && $cards->length > 0) {
                $card = $cards->item(0);
                $headings = $xpath->query('.//h5', $card);

                if ($headings !== false && $headings->length > 0) {
                    $cardTitle = $this->text($headings->item(0));

                    if ($cardTitle !== '') {
                        $title = $cardTitle;
                    }
                }

                $listingText = $this->text($card);
            }

            $rows = $xpath->query('ancestor::tr[1]', $anchor);

            if ($rows !== false && $rows->length > 0) {
                $row = $rows->item(0);
                $listingText = $this->text($row);

                if (
                    $title === ''
                    || Str::contains(
                        Str::upper($title),
                        'VER OPORTUNIDADE'
                    )
                ) {
                    $cells = $xpath->query('./td', $row);

                    if ($cells !== false && $cells->length > 0) {
                        $title = $this->text($cells->item(0));
                    }
                }
            }

            $title = preg_replace(
                '/\s+' . preg_quote($code, '/') . '\s*$/i',
                '',
                $title
            ) ?? $title;

            if (
                $title === ''
                || Str::contains(
                    Str::upper($title),
                    'VER OPORTUNIDADE'
                )
            ) {
                $title = null;
            }

            $jobs[$code] = [
                'code' => $code,
                'title' => $title,
                'listing_text' => $listingText,
            ];
        }

        return array_values($jobs);
    }

    private function fetchJobDetails(array $listingJob): ?array
    {
        $code = $listingJob['code'];

        $applicationUrl = "https://{$this->domain}/job/{$code}";

        $response = $this->http()->get($applicationUrl);
        $response->throw();

        $document = $this->loadHtml($response->body());
        $xpath = new DOMXPath($document);

        $posting = $this->extractJobPosting($xpath);

        if ($posting === null) {
            throw new RuntimeException(
                "JSON-LD JobPosting não encontrado para a vaga {$code}."
            );
        }

        $title = trim((string) ($posting['title'] ?? ''));

        if ($title === '') {
            $title = trim((string) ($listingJob['title'] ?? ''));
        }

        if ($title === '') {
            throw new RuntimeException(
                "Título ausente na vaga {$code}."
            );
        }

        $description = $this->cleanDescription(
            (string) ($posting['description'] ?? '')
        );

        if (!$this->isTechnologyVacancy($title, $description)) {
            return null;
        }

        $employmentType = $this->normalizeEmploymentType(
            $posting['employmentType'] ?? null
        );

        $normalizedTitle = Str::ascii(
            mb_strtolower($title)
        );

        if (
            str_contains($normalizedTitle, 'estagio')
            || str_contains($normalizedTitle, 'trainee')
            || $employmentType === 'Estágio'
        ) {
            return null;
        }

        $location = $this->extractLocation($posting);
        $geography = $this->extractGeographicFields($posting);

        $workModel = $this->extractWorkModel(
            (string) ($listingJob['listing_text'] ?? ''),
            $title,
            $location,
            $description
        );

        if (
            $this->locationFilter !== null
            && !$this->locationFilter->isEligibleForCollection(
                $location,
                $title,
                $description,
                $workModel
            )
        ) {
            return null;
        }

        $datePosted = $posting['datePosted'] ?? null;

        if (!is_string($datePosted) || trim($datePosted) === '') {
            throw new RuntimeException(
                "Data de publicação ausente na vaga {$code}."
            );
        }

        try {
            $publishedAt = CarbonImmutable::parse($datePosted);
        } catch (Throwable $exception) {
            throw new RuntimeException(
                "Data de publicação inválida na vaga {$code}.",
                previous: $exception
            );
        }

        return array_merge([
            'title' => $title,
            'company' => $this->companyName,
            'description' => $description,
            'location' => $location,
            'work_model' => $workModel,
            'employment_type' => $employmentType,
            'salary' => null,
            'application_url' => $applicationUrl,
            'external_id' => $code,
            'original_published_at' => $publishedAt,
        ], $geography);
    }

    private function extractJobPosting(DOMXPath $xpath): ?array
    {
        $scripts = $xpath->query(
            '//script[
                contains(
                    translate(
                        @type,
                        "ABCDEFGHIJKLMNOPQRSTUVWXYZ",
                        "abcdefghijklmnopqrstuvwxyz"
                    ),
                    "application/ld+json"
                )
            ]'
        );

        if ($scripts === false) {
            return null;
        }

        foreach ($scripts as $script) {
            $json = trim($script->textContent);

            if ($json === '') {
                continue;
            }

            $data = json_decode($json, true);

            if (!is_array($data)) {
                continue;
            }

            $posting = $this->findJobPosting($data);

            if ($posting !== null) {
                return $posting;
            }
        }

        return null;
    }

    private function findJobPosting(array $data): ?array
    {
        $type = $data['@type'] ?? null;

        if (
            $type === 'JobPosting'
            || (
                is_array($type)
                && in_array('JobPosting', $type, true)
            )
        ) {
            return $data;
        }

        foreach ($data as $value) {
            if (!is_array($value)) {
                continue;
            }

            $posting = $this->findJobPosting($value);

            if ($posting !== null) {
                return $posting;
            }
        }

        return null;
    }

    /**
     * Monta a localização textual usando somente os campos
     * estruturados de endereço disponíveis no JSON-LD.
     */
    private function extractLocation(array $posting): string
    {
        $locations = $this->normalizeJobLocations(
            $posting['jobLocation'] ?? []
        );

        $parts = [];

        foreach ($locations as $place) {
            $address = $place['address'] ?? [];

            if (!is_array($address)) {
                continue;
            }

            $city = $this->readStructuredString(
                $address,
                ['addressLocality', 'city', 'cityName']
            );

            $region = $this->readStructuredString(
                $address,
                ['addressRegion', 'state', 'stateName']
            );

            $location = implode(
                ', ',
                array_filter([$city, $region])
            );

            if ($location !== '') {
                $parts[] = $location;
            }
        }

        return implode(' / ', array_unique($parts));
    }

    /**
     * Extrai os campos geográficos estruturados do JobPosting.
     * Não utiliza texto livre da listagem ou da descrição.
     */
    private function extractGeographicFields(array $posting): array
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

        $locations = $this->normalizeJobLocations(
            $posting['jobLocation'] ?? []
        );

        foreach ($locations as $place) {
            $address = $place['address'] ?? [];

            if (!is_array($address)) {
                continue;
            }

            $geo = $place['geo'] ?? [];

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

            $countryValue = $this->readStructuredString(
                $address,
                ['addressCountry', 'country', 'countryName']
            );

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
     * Normaliza jobLocation para uma lista de objetos Place.
     */
    private function normalizeJobLocations(mixed $locations): array
    {
        if (!is_array($locations)) {
            return [];
        }

        if (array_is_list($locations)) {
            return array_values(array_filter(
                $locations,
                fn ($place) => is_array($place)
            ));
        }

        if (isset($locations['address'])) {
            return [$locations];
        }

        return [];
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

    private function extractWorkModel(
        string $listingText,
        string $title,
        string $location,
        string $description
    ): ?string {
        $text = Str::ascii(
            mb_strtolower($listingText)
        );

        if (str_contains($text, 'hibrido')) {
            return 'Híbrido';
        }

        if (str_contains($text, 'remoto')) {
            return 'Remoto';
        }

        if (str_contains($text, 'presencial')) {
            return 'Presencial';
        }

        return $this->locationFilter?->classifyWorkModel(
            $title,
            $location,
            $description
        );
    }

    private function normalizeEmploymentType(mixed $type): ?string
    {
        if (is_array($type)) {
            $type = $type[0] ?? null;
        }

        if (!is_string($type) || trim($type) === '') {
            return null;
        }

        return match (Str::upper(trim($type))) {
            'FULL_TIME' => 'Efetivo',
            'PART_TIME' => 'Meio período',
            'CONTRACTOR' => 'PJ',
            'TEMPORARY' => 'Temporário',
            'INTERN' => 'Estágio',
            'VOLUNTEER' => 'Voluntário',
            default => Str::headline(
                strtolower(str_replace('_', ' ', $type))
            ),
        };
    }

    private function cleanDescription(string $description): string
    {
        $description = html_entity_decode(
            $description,
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );

        $description = strip_tags($description);

        return trim(
            preg_replace('/\s+/u', ' ', $description) ?? ''
        );
    }

    private function isTechnologyVacancy(
        string $title,
        string $description
    ): bool {
        $normalizedTitle = Str::ascii(
            mb_strtolower(trim($title))
        );

        $patterns = [
            // Desenvolvimento
            '/\bdesenvolvedor(a)?\b/',
            '/\bdeveloper\b/',
            '/\bprogramador(a)?\b/',
            '/\bprogrammer\b/',
            '/\bengenheiro(a)? de software\b/',
            '/\bsoftware engineer\b/',
            '/\bbackend\b/',
            '/\bback[\s-]?end\b/',
            '/\bfrontend\b/',
            '/\bfront[\s-]?end\b/',
            '/\bfull[\s-]?stack\b/',

            // Sistemas e suporte
            '/\banalista de sistemas\b/',
            '/\banalista de suporte\b/',
            '/\banalista de infraestrutura\b/',
            '/\btecnico(a)? de informatica\b/',
            '/\btecnico(a)? de suporte\b/',
            '/\bsuporte tecnico\b/',
            '/\bsuporte de ti\b/',
            '/\bhelp[\s-]?desk\b/',
            '/\bservice[\s-]?desk\b/',

            // Infraestrutura e operações
            '/\banalista de redes\b/',
            '/\badministrador(a)? de redes\b/',
            '/\badministrador(a)? de sistemas\b/',
            '/\binfraestrutura de ti\b/',
            '/\bdevops\b/',
            '/\bsre\b/',
            '/\bcloud engineer\b/',
            '/\bengenheiro(a)? de cloud\b/',
            '/\banalista de cloud\b/',

            // Dados
            '/\banalista de dados\b/',
            '/\bdata analyst\b/',
            '/\bengenheiro(a)? de dados\b/',
            '/\bdata engineer\b/',
            '/\bcientista de dados\b/',
            '/\bdata scientist\b/',
            '/\badministrador(a)? de banco de dados\b/',
            '/\bdatabase administrator\b/',
            '/\bdba\b/',

            // Qualidade e segurança
            '/\banalista de qa\b/',
            '/\bquality assurance\b/',
            '/\bqa engineer\b/',
            '/\bengenheiro(a)? de qualidade de software\b/',
            '/\banalista de testes\b/',
            '/\btester\b/',
            '/\banalista de seguranca da informacao\b/',
            '/\bcybersecurity\b/',
            '/\bcyber security\b/',

            // Plataformas e sistemas especializados
            '/\bconsultor(a)? sap\b/',
            '/\banalista sap\b/',
            '/\bdesenvolvedor(a)? salesforce\b/',
            '/\badministrador(a)? salesforce\b/',
            '/\banalista salesforce\b/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $normalizedTitle) === 1) {
                return true;
            }
        }

        return false;
    }

    private function loadHtml(string $html): DOMDocument
    {
        $document = new DOMDocument('1.0', 'UTF-8');

        $previous = libxml_use_internal_errors(true);

        $document->loadHTML(
            '<?xml encoding="UTF-8">' . $html,
            LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $document;
    }

    private function text(?\DOMNode $node): string
    {
        if ($node === null) {
            return '';
        }

        return trim(
            preg_replace('/\s+/u', ' ', $node->textContent) ?? ''
        );
    }

    private function http()
    {
        return Http::acceptJson()
            ->timeout(30)
            ->withOptions([
                'verify' => $this->caBundle ?: true,
            ]);
    }
}
