<?php

namespace App\Services;

use App\Models\Vacancy;
use Illuminate\Database\Eloquent\Builder;

class VacancyDuplicateDetector
{
    /**
     * Procura a mesma oportunidade em outra fonte.
     *
     * A identidade cruzada é baseada na URL de candidatura normalizada.
     * Título/empresa/data apenas limitam a consulta de candidatos; nunca
     * são suficientes, isoladamente, para declarar uma duplicata.
     */
    public function findDuplicate(
        array $data,
        string $sourceName,
        string $externalId
    ): ?Vacancy {
        $url = $this->normalizeApplicationUrl(
            (string) ($data['application_url'] ?? '')
        );

        if ($url === '') {
            return null;
        }

        $directMatch = Vacancy::query()
            ->where('source', '!=', $sourceName)
            ->whereIn('application_url', array_unique([
                trim((string) ($data['application_url'] ?? '')),
                $url,
            ]))
            ->first();

        if ($directMatch !== null) {
            return $directMatch;
        }

        $title = trim((string) ($data['title'] ?? ''));
        $company = trim((string) ($data['company'] ?? ''));

        if ($title === '' || $company === '') {
            return null;
        }

        $publishedAt = $data['original_published_at'] ?? null;
        $query = Vacancy::query()
            ->select([
                'id',
                'source',
                'external_id',
                'application_url',
                'title',
                'company',
                'location',
                'original_published_at',
            ])
            ->where('source', '!=', $sourceName)
            ->whereRaw('LOWER(TRIM(title)) = ?', [mb_strtolower($title)])
            ->whereRaw('LOWER(TRIM(company)) = ?', [mb_strtolower($company)]);

        $location = trim((string) ($data['location'] ?? ''));

        if ($location !== '') {
            $query->whereRaw('LOWER(TRIM(location)) = ?', [mb_strtolower($location)]);
        } else {
            $query->whereNull('location');
        }

        if ($publishedAt !== null && $publishedAt !== '') {
            try {
                $date = \Illuminate\Support\Carbon::parse($publishedAt);
                $query->whereBetween('original_published_at', [
                    $date->copy()->subDays(45),
                    $date->copy()->addDays(45),
                ]);
            } catch (\Throwable) {
                return null;
            }
        }

        foreach ($query->limit(100)->get() as $candidate) {
            if (
                $this->normalizeApplicationUrl($candidate->application_url)
                === $url
            ) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Remove somente parâmetros sabidamente usados para rastreamento.
     * Mantém parâmetros funcionais, pois podem identificar a vaga.
     */
    public function normalizeApplicationUrl(?string $url): string
    {
        $url = trim((string) $url);

        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            return '';
        }

        $parts = parse_url($url);

        if (!is_array($parts) || empty($parts['host'])) {
            return '';
        }

        $host = mb_strtolower($parts['host']);
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        $port = $parts['port'] ?? null;
        $portSuffix = $port !== null && !in_array(
            [$parts['scheme'] ?? 'https', $port],
            [['http', 80], ['https', 443]],
            true
        ) ? ':' . $port : '';

        $path = $parts['path'] ?? '/';
        $path = $path === '' ? '/' : $path;
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        $query = [];
        parse_str($parts['query'] ?? '', $query);

        foreach (array_keys($query) as $key) {
            $normalizedKey = mb_strtolower((string) $key);

            if (
                str_starts_with($normalizedKey, 'utm_')
                || in_array($normalizedKey, [
                    'fbclid', 'gclid', 'dclid', 'msclkid',
                    'mc_cid', 'mc_eid', 'ref_src', 'ref_url',
                ], true)
            ) {
                unset($query[$key]);
            }
        }

        ksort($query);

        $queryString = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        return $host . $portSuffix . $path
            . ($queryString !== '' ? '?' . $queryString : '');
    }
}
