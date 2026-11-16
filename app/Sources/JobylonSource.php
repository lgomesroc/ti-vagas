<?php

namespace App\Sources;

use App\Contracts\VacancySource;
use App\Services\VacancyLocationFilter;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class JobylonSource implements VacancySource
{
    private readonly VacancyLocationFilter $locationFilter;

    public function __construct(
        private readonly string $companyKey,
        private readonly string $companyName,
        private readonly int $companyId,
        private readonly string $careerUrl,
        private readonly ?string $caBundle = null,
        private readonly int $maxJobs = 200,
        ?VacancyLocationFilter $locationFilter = null,
    ) {
        $this->locationFilter = $locationFilter ?? new VacancyLocationFilter();
    }

    public function name(): string
    {
        return 'jobylon:' . $this->companyKey;
    }

    public function fetch(): iterable
    {
        $html = $this->get($this->careerUrl);
        $xpath = $this->xpath($html);
        $nodes = $xpath->query('//a[contains(@href, "/jobs/")]');
        $urls = [];

        if ($nodes !== false) {
            foreach ($nodes as $node) {
                if (!$node instanceof DOMElement) {
                    continue;
                }

                $href = trim($node->getAttribute('href'));
                if (preg_match('~^https?://emp\.jobylon\.com/jobs/\d+-~i', $href)) {
                    $urls[] = $href;
                } elseif (preg_match('~^/jobs/\d+-~i', $href)) {
                    $urls[] = 'https://emp.jobylon.com' . $href;
                }
            }
        }

        $urls = array_slice(array_values(array_unique($urls)), 0, $this->maxJobs);
        $vacancies = [];

        foreach ($urls as $url) {
            $detailHtml = $this->get($url);
            $posting = $this->extractJobPosting($detailHtml);

            if ($posting === null) {
                continue;
            }

            $vacancy = $this->normalize($posting, $url);
            if ($vacancy !== null) {
                $vacancies[] = $vacancy;
            }
        }

        return $vacancies;
    }

    private function get(string $url): string
    {
        $response = Http::timeout(45)
            ->withOptions(['verify' => $this->caBundle ?: true])
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (compatible; TI-Vagas/1.0)',
                'Accept' => 'text/html,application/xhtml+xml',
            ])
            ->get($url);

        $response->throw();
        $html = $response->body();

        if (trim($html) === '') {
            throw new RuntimeException("Jobylon retornou conteúdo vazio para {$this->companyName}.");
        }

        return $html;
    }

    private function extractJobPosting(string $html): ?array
    {
        $xpath = $this->xpath($html);
        $scripts = $xpath->query('//script[contains(translate(@type, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "ld+json")]');

        if ($scripts === false) {
            return null;
        }

        foreach ($scripts as $script) {
            $data = json_decode(
                html_entity_decode($script->textContent ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                true
            );

            if (is_array($data) && ($posting = $this->findPosting($data)) !== null) {
                return $posting;
            }
        }

        return null;
    }

    private function findPosting(array $data): ?array
    {
        $type = $data['@type'] ?? null;
        if ($type === 'JobPosting' || (is_array($type) && in_array('JobPosting', $type, true))) {
            return $data;
        }

        foreach ($data as $value) {
            if (!is_array($value)) {
                continue;
            }

            if (array_is_list($value)) {
                foreach ($value as $item) {
                    if (is_array($item) && ($found = $this->findPosting($item)) !== null) {
                        return $found;
                    }
                }
            } elseif (($found = $this->findPosting($value)) !== null) {
                return $found;
            }
        }

        return null;
    }

    private function normalize(array $posting, string $url): ?array
    {
        $title = $this->clean($this->scalar($posting['title'] ?? null));
        $description = $this->clean(strip_tags($this->scalar($posting['description'] ?? null)));
        $date = $posting['datePosted'] ?? null;

        if ($title === '' || $description === '' || !is_string($date) || trim($date) === '') {
            return null;
        }

        try {
            $publishedAt = CarbonImmutable::parse($date, 'UTC')->toIso8601String();
        } catch (\Throwable) {
            return null;
        }

        $location = $this->location($posting['jobLocation'] ?? null);
        $workModel = $this->locationFilter->classifyWorkModel($title, $location, $description);

        if (!$this->locationFilter->isEligibleForCollection($location, $title, $description, $workModel)) {
            return null;
        }

        $id = $this->scalar($posting['identifier']['value'] ?? null)
            ?: $this->scalar($posting['identifier'] ?? null)
            ?: basename((string) parse_url($url, PHP_URL_PATH));

        return [
            'title' => $title,
            'company' => $this->companyName,
            'description' => $description,
            'location' => $location !== '' ? $location : null,
            'work_model' => $workModel,
            'employment_type' => $this->employmentType($posting['employmentType'] ?? null),
            'salary' => null,
            'application_url' => $url,
            'external_id' => $this->name() . ':' . Str::slug($id),
            'original_published_at' => $publishedAt,
        ];
    }

    private function location(mixed $value): string
    {
        if (!is_array($value)) {
            return '';
        }

        if (isset($value['address'])) {
            $value = [$value];
        }

        foreach ($value as $place) {
            $address = is_array($place) ? ($place['address'] ?? []) : [];
            if (!is_array($address)) {
                continue;
            }

            $parts = array_filter([
                $this->scalar($address['addressLocality'] ?? null),
                $this->scalar($address['addressRegion'] ?? null),
                $this->scalar($address['addressCountry'] ?? null),
            ]);

            if ($parts !== []) {
                return implode(', ', $parts);
            }
        }

        return '';
    }

    private function employmentType(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return match (Str::upper(trim($value))) {
            'FULL_TIME' => 'Tempo integral',
            'PART_TIME' => 'Meio período',
            'CONTRACTOR' => 'Contrato',
            'TEMPORARY' => 'Temporário',
            'INTERN' => 'Estágio',
            default => trim($value),
        };
    }

    private function scalar(mixed $value): string
    {
        if (is_array($value)) {
            $value = $value['name'] ?? $value['value'] ?? '';
        }

        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function clean(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML(
            '<?xml encoding="UTF-8">' . $html,
            LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            throw new RuntimeException('Não foi possível interpretar o HTML do Jobylon.');
        }

        return new DOMXPath($document);
    }
}
