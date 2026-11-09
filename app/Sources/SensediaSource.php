<?php

namespace App\Sources;

use App\Contracts\VacancySource;
use App\Services\VacancyLocationFilter;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SensediaSource implements VacancySource
{
    private const FEED_URL = 'https://sensedia.hire.trakstar.com/jobfeeds/sensedia';

    private readonly VacancyLocationFilter $locationFilter;

    public function __construct(
        private readonly ?string $caBundle = null,
        ?VacancyLocationFilter $locationFilter = null,
    ) {
        $this->locationFilter = $locationFilter ?? new VacancyLocationFilter();
    }

    public function name(): string
    {
        return 'sensedia-trakstar';
    }

    public function fetch(): iterable
    {
        $response = Http::accept('application/rss+xml')
            ->timeout(60)
            ->withOptions([
                'verify' => $this->caBundle ?: true,
            ])
            ->get(self::FEED_URL);

        $response->throw();

        $previousLibxmlState = libxml_use_internal_errors(true);

        try {
            $feed = simplexml_load_string(
                $response->body(),
                'SimpleXMLElement',
                LIBXML_NONET
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousLibxmlState);
        }

        if ($feed === false) {
            throw new RuntimeException(
                'Não foi possível interpretar o RSS da Sensedia.'
            );
        }

        $vacancies = [];

        foreach ($feed->channel->item ?? [] as $item) {
            $title = trim((string) ($item->title ?? ''));
            $descriptionHtml = (string) ($item->description ?? '');

            $decodedHtml = html_entity_decode(
                $descriptionHtml,
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            );

            $description = $this->cleanDescription($descriptionHtml);

            $city = trim((string) ($item->locationCity ?? ''));
            $state = trim((string) ($item->locationState ?? ''));
            $country = trim((string) ($item->locationCountry ?? ''));

            $location = implode(', ', array_filter([
                $city,
                $state,
                $country,
            ]));

            if ($location === '') {
                $location = $this->extractLocation($decodedHtml);
            }

            $publishedAt = trim((string) ($item->pubDate ?? ''));
            $guid = trim((string) ($item->guid ?? ''));
            $jobUrl = trim((string) ($item->link ?? ''));

            if (
                $title === '' ||
                $description === '' ||
                $publishedAt === '' ||
                $guid === '' ||
                $jobUrl === ''
            ) {
                continue;
            }

            $workModel = $this->locationFilter->classifyWorkModel(
                $title,
                $location,
                $description
            );

            if (preg_match(
                '/\b100\s*%\s*remot[oa]\b|\btotalmente remoto\b|\bfully remote\b/i',
                $description
            )) {
                $workModel = 'Remoto';
            }

            if (!$this->locationFilter->isEligibleForCollection(
                $location,
                $title,
                $description,
                $workModel
            )) {
                continue;
            }

            $vacancies[] = [
                'title' => $title,
                'company' => 'Sensedia',
                'description' => $description,
                'location' => $location ?: null,
                'work_model' => $workModel,
                'employment_type' => $this->normalizeEmploymentType(
                    (string) ($item->positionType ?? '')
                ),
                'salary' => null,
                'application_url' => $this->extractApplicationUrl(
                    $decodedHtml,
                    $jobUrl
                ),
                'external_id' => $guid,
                'original_published_at' => $publishedAt,

                // Dados geográficos estruturados fornecidos pelo RSS.
                'city' => $city !== '' ? $city : null,
                'state' => $state !== '' ? $state : null,
                'country' => $country !== '' ? $country : null,
            ];
        }

        return $vacancies;
    }

    private function extractLocation(string $html): string
    {
        if (!preg_match(
            '/<p\b[^>]*>\s*Location:\s*(.*?)\s*<\/p>/is',
            $html,
            $matches
        )) {
            return '';
        }

        return trim(strip_tags(
            html_entity_decode(
                $matches[1],
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            )
        ));
    }

    private function extractApplicationUrl(
        string $html,
        string $fallbackUrl
    ): string {
        if (preg_match(
            '~href=["\']([^"\']*apply=true[^"\']*)["\']~i',
            $html,
            $matches
        )) {
            return html_entity_decode(
                $matches[1],
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            );
        }

        return $fallbackUrl;
    }

    private function normalizeEmploymentType(string $type): ?string
    {
        return match (strtolower(trim($type))) {
            'full_time' => 'Tempo integral',
            'part_time' => 'Meio período',
            'contract' => 'Contrato',
            'internship' => 'Estágio',
            default => $type !== '' ? $type : null,
        };
    }

    private function cleanDescription(string $content): string
    {
        $content = html_entity_decode(
            $content,
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );

        $content = preg_replace(
            '/<\s*br\s*\/?>|<\/\s*p\s*>|<\/\s*div\s*>|<\/\s*h[1-6]\s*>/i',
            "\n",
            $content
        );

        $content = preg_replace(
            '/<\s*li\b[^>]*>/i',
            "\n- ",
            $content
        );

        $content = strip_tags($content);

        $content = html_entity_decode(
            $content,
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );

        $content = preg_replace('/[ \t]+/', ' ', $content);
        $content = preg_replace('/ *\n */', "\n", $content);
        $content = preg_replace('/\n{3,}/', "\n\n", $content);

        return trim($content);
    }
}
