<?php

namespace App\Sources;

use App\Contracts\VacancySource;
use App\Services\VacancyLocationFilter;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class DerevoSource implements VacancySource
{
    private const URL = 'https://www.derevo.com.br/Home/pagina/97';

    private readonly VacancyLocationFilter $locationFilter;

    public function __construct(
        private readonly ?string $caBundle = null,
        ?VacancyLocationFilter $locationFilter = null,
    ) {
        $this->locationFilter = $locationFilter
            ?? new VacancyLocationFilter();
    }

    public function name(): string
    {
        return 'derevo';
    }

    public function fetch(): iterable
    {
        $response = Http::timeout(60)
            ->withHeaders([
                'User-Agent' => 'TI-Vagas/1.0',
                'Accept' => 'text/html',
            ])
            ->withOptions([
                'verify' => $this->caBundle ?: true,
            ])
            ->get(self::URL);

        $response->throw();

        $html = $response->body();

        if (trim($html) === '') {
            throw new RuntimeException(
                'A página de carreiras da Derevo retornou HTML vazio.'
            );
        }

        $previousState = libxml_use_internal_errors(true);

        try {
            $document = new DOMDocument('1.0', 'UTF-8');

            $loaded = $document->loadHTML(
                '<?xml encoding="UTF-8">' . $html,
                LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR
            );

            if (!$loaded) {
                throw new RuntimeException(
                    'Não foi possível interpretar o HTML da Derevo.'
                );
            }

            $xpath = new DOMXPath($document);

            return $this->extractVacancies($xpath);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousState);
        }
    }

    private function extractVacancies(DOMXPath $xpath): array
    {
        $headings = $xpath->query(
            '//h3[
                contains(
                    concat(" ", normalize-space(@class), " "),
                    " h5 "
                )
                and starts-with(
                    normalize-space(string(.)),
                    "Vaga para "
                )
            ]'
        );

        if ($headings === false) {
            throw new RuntimeException(
                'Não foi possível localizar as oportunidades da Derevo.'
            );
        }

        $vacancies = [];

        foreach ($headings as $heading) {
            if (!$heading instanceof DOMElement) {
                continue;
            }

            $title = $this->cleanText(
                $heading->textContent
            );

            $container = $xpath->query(
                'ancestor::div[
                    contains(
                        concat(" ", normalize-space(@class), " "),
                        " mb-7 "
                    )
                ][1]',
                $heading
            )?->item(0);

            if (!$container instanceof DOMElement) {
                continue;
            }

            $description = $this->cleanText(
                $container->textContent
            );

            $location = $this->extractElementText(
                $xpath,
                './/small',
                $container
            );

            $workModel = $this->extractElementText(
                $xpath,
                './/span[contains(
                    concat(" ", normalize-space(@class), " "),
                    " text-primary "
                )]',
                $container
            );

            $workModel = $this->normalizeWorkModel(
                $workModel
            );

            $publishedAt = $this->extractPublicationDate(
                $xpath,
                $container
            );

            // A página atual não informa a data original.
            // Não utilizar a data da coleta como substituta.
            if ($publishedAt === null) {
                continue;
            }

            if (!$this->locationFilter->isEligible(
                $location,
                $title,
                $description,
                $workModel
            )) {
                continue;
            }

            $salary = str_contains(
                mb_strtolower($description),
                'salário a combinar'
            ) ? 'A combinar' : null;

            $vacancies[] = [
                'title' => $title,
                'company' => 'Derevo',
                'description' => $description,
                'location' => $location !== ''
                    ? $location
                    : null,
                'work_model' => $workModel,
                'employment_type' => null,
                'salary' => $salary,
                'application_url' => self::URL,
                'external_id' => 'derevo:' .
                    $this->generateSlug($title),
                'original_published_at' => $publishedAt,
            ];
        }

        return $vacancies;
    }

    private function extractElementText(
        DOMXPath $xpath,
        string $expression,
        DOMElement $context
    ): string {
        $node = $xpath->query(
            $expression,
            $context
        )?->item(0);

        return $node
            ? $this->cleanText($node->textContent)
            : '';
    }

    private function extractPublicationDate(
        DOMXPath $xpath,
        DOMElement $container
    ): ?string {
        $dateNode = $xpath->query(
            './/time[@datetime]',
            $container
        )?->item(0);

        if ($dateNode instanceof DOMElement) {
            $date = trim(
                $dateNode->getAttribute('datetime')
            );

            if ($date !== '') {
                try {
                    return CarbonImmutable::parse(
                        $date,
                        'UTC'
                    )->toIso8601String();
                } catch (\Throwable) {
                    return null;
                }
            }
        }

        $text = $container->textContent;

        if (!preg_match(
            '/(?:publicad[oa]|data\s+de\s+publica[çc][ãa]o)'
            . '\s*(?:em|:)?\s*'
            . '(\d{2}\/\d{2}\/\d{4})/iu',
            $text,
            $matches
        )) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat(
                '!d/m/Y',
                $matches[1],
                'UTC'
            );

            return $date
                ? $date->toIso8601String()
                : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeWorkModel(
        string $workModel
    ): ?string {
        return match (mb_strtolower(trim($workModel))) {
            'presencial' => 'Presencial',
            'híbrido', 'hibrido' => 'Híbrido',
            'remoto' => 'Remoto',
            default => null,
        };
    }

    private function cleanText(string $text): string
    {
        $text = html_entity_decode(
            $text,
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );

        $text = preg_replace(
            '/\s+/u',
            ' ',
            $text
        );

        return trim($text);
    }

    private function generateSlug(string $title): string
    {
        $slug = mb_strtolower($title);

        $slug = iconv(
            'UTF-8',
            'ASCII//TRANSLIT//IGNORE',
            $slug
        ) ?: $slug;

        $slug = preg_replace(
            '/[^a-z0-9]+/',
            '-',
            $slug
        );

        return trim($slug, '-');
    }
}
