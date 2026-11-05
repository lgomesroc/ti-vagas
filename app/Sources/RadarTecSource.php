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
use RuntimeException;

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

            $id = $matches[1];

            $cards[] = [
                'id' => $id,
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

        return [
            'description' => $description,
            'employment_type' => $employmentType,
        ];
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

        return $this->locationFilter->isEligible(
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
