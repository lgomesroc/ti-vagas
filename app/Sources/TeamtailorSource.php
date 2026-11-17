<?php

namespace App\\Sources;

use App\\Contracts\\VacancySource;
use App\\Services\\VacancyLocationFilter;
use Carbon\\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\\Support\\Facades\\Http;
use Illuminate\\Support\\Str;
use RuntimeException;

class TeamtailorSource implements VacancySource
{
    private readonly VacancyLocationFilter $locationFilter;

    public function __construct(
        private readonly string $companyKey,
        private readonly string $companyName,
        private readonly string $feedUrl,
        private readonly ?string $caBundle = null,
        private readonly int $maxJobs = 200,
        ?VacancyLocationFilter $locationFilter = null,
    ) {
        $this->locationFilter = $locationFilter ?? new VacancyLocationFilter();
    }

    public function name(): string
    {
        return 'teamtailor-' . $this->companyKey;
    }

    public function fetch(): iterable
    {
        $response = Http::timeout(60)
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (compatible; TI-Vagas/1.0)',
                'Accept' => 'application/rss+xml, application/xml, text/xml',
            ])
            ->withOptions([
                'verify' => $this->caBundle ?: true,
            ])
            ->get($this->feedUrl, [
                'per_page' => min(max($this->maxJobs, 1), 500),
            ]);

        $response->throw();

        $xml = $response->body();

        if (trim($xml) === '') {
            throw new RuntimeException(
                'Feed RSS vazio recebido do Teamtailor: ' . $this->companyKey
            );
        }

        $previous = libxml_use_internal_errors(true);

        try {
            $document = new DOMDocument();
            $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);

            if (!$loaded) {
                throw new RuntimeException(
                    'XML inválido recebido do Teamtailor: ' . $this->companyKey
                );
            }

            $xpath = new DOMXPath($document);
            $items = $xpath->query('//*[local-name()="channel"]/*[local-name()="item"]');

            if ($items === false) {
                throw new RuntimeException(
                    'Não foi possível ler os itens do RSS Teamtailor: ' . $this->companyKey
                );
            }

            $vacancies = [];

            foreach ($items as $item) {
                if (!$item instanceof DOMElement) {
                    continue;
                }

                $vacancy = $this->normalizeItem($xpath, $item);

                if ($vacancy !== null) {
                    $vacancies[] = $vacancy;
                }
            }

            return $vacancies;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function normalizeItem(DOMXPath $xpath, DOMElement $item): ?array
    {
        $title = $this->nodeValue($xpath, './*[local-name()="title"]', $item);
        $description = $this->cleanDescription(
            $this->nodeValue($xpath, './*[local-name()="description"]', $item)
        );
        $url = $this->nodeValue($xpath, './*[local-name()="link"]', $item);
        $guid = $this->nodeValue($xpath, './*[local-name()="guid"]', $item);
        $publishedAt = $this->normalizePublicationDate(
            $this->nodeValue($xpath, './*[local-name()="pubDate"]', $item)
        );

        if (
            $title === ''
            || $description === ''
            || !$this->isValidUrl($url)
            || $publishedAt === null
        ) {
            return null;
        }

        $remoteStatus = strtolower($this->nodeValue(
            $xpath,
            './*[local-name()="remoteStatus"]',
            $item
        ));

        $locations = $this->extractLocations($xpath, $item);
        $countryNames = array_column($locations, 'country');
        $locationNames = array_column($locations, 'name');

        $hasBrazilLocation = false;

        foreach (array_merge($countryNames, $locationNames) as $locationPart) {
            $normalized = Str::ascii(mb_strtolower($locationPart));

            if (in_array($normalized, ['br', 'brasil', 'brazil'], true)
                || str_contains($normalized, 'brasil')
                || str_contains($normalized, 'brazil')) {
                $hasBrazilLocation = true;
                break;
            }
        }

        // O RSS é global. Não presumir que uma vaga remota aceita candidatos
        // no Brasil quando o feed não informa uma localização brasileira.
        if (!$hasBrazilLocation) {
            return null;
        }

        $workModel = match ($remoteStatus) {
            'fully', 'temporary' => 'Remoto',
            'hybrid' => 'Híbrido',
            default => $this->locationFilter->classifyWorkModel(
                $title,
                implode(' / ', $locationNames),
                $description
            ),
        };

        $location = $workModel === 'Remoto'
            ? 'Brasil (remoto)'
            : implode(' / ', array_values(array_unique(array_filter(
                array_map(
                    fn (array $location): string => implode(', ', array_filter([
                        $location['city'],
                        $location['country'],
                        $location['name'],
                    ])),
                    $locations
                )
            ))));

        if (!$this->locationFilter->isEligibleForCollection(
            $location,
            $title,
            $description,
            $workModel
        )) {
            return null;
        }

        $id = $guid !== '' ? $guid : $url;

        return [
            'title' => $title,
            'company' => $this->companyName,
            'description' => $description,
            'location' => $location !== '' ? $location : null,
            'work_model' => $workModel,
            'employment_type' => null,
            'salary' => null,
            'application_url' => $url,
            'external_id' => $this->companyKey . ':' . hash('sha256', $id),
            'original_published_at' => $publishedAt,
        ];
    }

    private function extractLocations(DOMXPath $xpath, DOMElement $item): array
    {
        $nodes = $xpath->query(
            './/*[local-name()="locations"]/*[local-name()="location"]',
            $item
        );

        if ($nodes === false) {
            return [];
        }

        $locations = [];

        foreach ($nodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }

            $locations[] = [
                'name' => $this->nodeValue(
                    $xpath,
                    './*[local-name()="name"]',
                    $node
                ),
                'city' => $this->nodeValue(
                    $xpath,
                    './*[local-name()="city"]',
                    $node
                ),
                'country' => $this->nodeValue(
                    $xpath,
                    './*[local-name()="country"]',
                    $node
                ),
            ];
        }

        return $locations;
    }

    private function nodeValue(
        DOMXPath $xpath,
        string $query,
        DOMElement $context
    ): string {
        $node = $xpath->query($query, $context)?->item(0);

        return $node !== null
            ? trim(html_entity_decode($node->textContent, ENT_QUOTES | ENT_HTML5, 'UTF-8'))
            : '';
    }

    private function cleanDescription(string $content): string
    {
        $content = html_entity_decode(
            $content,
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );

        $content = preg_replace(
            '/<\\s*br\\s*\\/?>|<\\/\\s*p\\s*>|<\\/\\s*div\\s*>|<\\/\\s*h[1-6]\\s*>/i',
            "\\n",
            $content
        );

        $content = preg_replace('/<\\s*li\\b[^>]*>/i', "\\n- ", $content);
        $content = strip_tags($content);
        $content = preg_replace('/[ \\t]+/', ' ', $content);
        $content = preg_replace('/ *\\n */', "\\n", $content);
        $content = preg_replace('/\\n{3,}/', "\\n\\n", $content);

        return trim($content);
    }

    private function normalizePublicationDate(string $date): ?string
    {
        if ($date === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($date)->utc()->toIso8601String();
        } catch (\\Throwable) {
            return null;
        }
    }

    private function isValidUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
    }
}
