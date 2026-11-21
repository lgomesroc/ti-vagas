<?php

namespace App\Services;

use App\Contracts\VacancySource;
use App\Models\Vacancy;
use App\Models\VacancyAvailabilityCheck;
use App\Services\Geo\VacancyLocationResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class VacancyImporter
{
    public function __construct(
        private readonly VacancyAvailabilityDetector $availabilityDetector,
        private readonly GeoLocationNormalizer $geoLocationNormalizer,
        private readonly VacancyLocationResolver $locationResolver,
        private readonly VacancyEditorialFilter $editorialFilter,
        private readonly VacancyDuplicateDetector $duplicateDetector
    ) {
    }

    public function import(VacancySource $source): array
    {
        return $this->importFetched(
            $source->name(),
            $source->fetch()
        );
    }

    public function importFetched(
        string $sourceName,
        iterable $vacancies
    ): array {
        $sourceName = trim($sourceName);

        if ($sourceName === '') {
            throw new InvalidArgumentException(
                'A fonte precisa possuir um identificador.'
            );
        }

        $result = [
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
        ];

        foreach ($vacancies as $data) {
            if (!is_array($data)) {
                $this->skip($result, $sourceName, [], 'invalid_payload');
                continue;
            }

            $externalId = $data['external_id'] ?? null;

            if (
                !is_string($externalId) ||
                trim($externalId) === ''
            ) {
                $this->skip($result, $sourceName, $data, 'missing_external_id');
                continue;
            }

            $externalId = trim($externalId);

            $status = $data['status']
                ?? $data['job_status']
                ?? $data['availability_status']
                ?? null;

            if ($this->availabilityDetector->isUnavailable(
                is_string($data['title'] ?? null)
                    ? $data['title']
                    : null,
                is_string($data['description'] ?? null)
                    ? $data['description']
                    : null,
                is_string($status) ? $status : null
            )) {
                $existingVacancy = Vacancy::query()
                    ->where('source', $sourceName)
                    ->where('external_id', $externalId)
                    ->first();

                if ($existingVacancy !== null) {
                    $checkedAt = now();

                    $existingVacancy->forceFill([
                        'availability_status' => 'expired',
                        'availability_checked_at' => $checkedAt,
                        'availability_http_status' => null,
                        'availability_consecutive_not_found' => 0,
                        'availability_last_error' => null,
                    ])->save();

                    VacancyAvailabilityCheck::query()->create([
                        'vacancy_id' => $existingVacancy->getKey(),
                        'check_type' => 'source',
                        'status' => 'expired',
                        'http_status' => null,
                        'checked_url' => $existingVacancy->application_url,
                        'final_url' => null,
                        'reason' => 'A fonte informou que a oportunidade está indisponível.',
                        'response_time_ms' => null,
                        'checked_at' => $checkedAt,
                    ]);
                }

                $this->skip($result, $sourceName, $data, 'source_reports_unavailable');

                continue;
            }

            $requiredFields = [
                'title',
                'company',
                'description',
                'application_url',
            ];

            $valid = true;

            foreach ($requiredFields as $field) {
                if (
                    !isset($data[$field]) ||
                    !is_string($data[$field]) ||
                    trim($data[$field]) === ''
                ) {
                    $valid = false;
                    break;
                }
            }

            if (
                !$valid ||
                !$this->isValidApplicationUrl($data['application_url']) ||
                empty($data['original_published_at'])
            ) {
                 $reason = !$valid ? 'missing_required_field' : (!$this->isValidApplicationUrl($data['application_url']) ? 'invalid_application_url' : 'missing_original_published_at');
                $this->skip($result, $sourceName, $data, $reason);
                continue;
            }

            try {
                $publishedAt = Carbon::parse(
                    $data['original_published_at']
                );
            } catch (Throwable) {
                $this->skip($result, $sourceName, $data, 'invalid_original_published_at');
                continue;
            }

            // Aplica os critérios editoriais antes de qualquer consulta ou gravação
            // da vaga no banco. O banco recebe somente oportunidades elegíveis.
            if (!$this->editorialFilter->isEligible($data, $sourceName)) {
                $this->skip($result, $sourceName, $data, 'editorial_filter');
                continue;
            }

            $legacyVacancy = null;

            // Reaproveita os registros criados pelos antigos coletores por empresa.
            // Assim a busca global não troca a identidade nem duplica vagas existentes.
            if ($sourceName === 'gupy-global') {
                $legacyVacancy = Vacancy::query()
                    ->where('source', 'like', 'gupy-%')
                    ->where('external_id', $externalId)
                    ->orderBy('id')
                    ->first();
            }

            $vacancy = $legacyVacancy ?? Vacancy::firstOrNew([
                'source' => $sourceName,
                'external_id' => $externalId,
            ]);

            $isNew = !$vacancy->exists;

            // Mantém a identidade da fonte original e evita cadastrar a mesma
            // candidatura novamente quando ela aparece em outra plataforma.
            if ($isNew) {
                $duplicate = $this->duplicateDetector->findDuplicate(
                    $data,
                    $sourceName,
                    $externalId
                );

                if ($duplicate !== null) {
                    $this->skip($result, $sourceName, $data, 'duplicate_in_another_source');
                    continue;
                }
            }

            $geo = $this->geoLocationNormalizer->normalize($data);

            // Identifica o município pela base do IBGE quando a fonte
            // não informa o código.
            $municipality = $geo['ibge_municipality_code'] === null
                ? $this->locationResolver->resolve(
                    is_string($data['location'] ?? null)
                        ? $data['location']
                        : null,
                    $data['title'],
                    $geo['city'],
                    $geo['state_code']
                )
                : null;

            $vacancy->fill([
                'title' => trim($data['title']),
                'company' => trim($data['company']),
                'description' => trim($data['description']),
                'location' => $data['location'] ?? null,
                'work_model' => $data['work_model'] ?? null,
                'employment_type' => $data['employment_type'] ?? null,
                'salary' => $data['salary'] ?? null,
                'application_url' => trim($data['application_url']),
                'source' => $legacyVacancy !== null
                    ? $legacyVacancy->source
                    : $sourceName,
                'external_id' => $externalId,
                'collected_at' => now(),

                'city' => $municipality['name']
                    ?? $geo['city']
                    ?? $vacancy->city,
                'state' => $geo['state'] ?? $vacancy->state,
                'state_code' => $municipality['uf']
                    ?? $geo['state_code']
                    ?? $vacancy->state_code,
                'country_code' => $geo['country_code']
                    ?? ($municipality !== null ? 'BR' : $vacancy->country_code),
                'ibge_municipality_code' => $geo['ibge_municipality_code']
                    ?? $municipality['ibge']
                    ?? $vacancy->ibge_municipality_code,
                'latitude' => $geo['latitude'] ?? $vacancy->latitude,
                'longitude' => $geo['longitude'] ?? $vacancy->longitude,
            ]);

            if ($isNew) {
                $vacancy->original_published_at = $publishedAt;
            }

            $vacancy->save();

            if ($isNew) {
                $result['created']++;
            } else {
                $result['updated']++;
            }
        }

        return $result;
    }

    /**
     * Registra o motivo de descarte das fontes Gupy sem alterar os critérios.
     * @param array{created:int,updated:int,skipped:int} $result
     * @param array<string,mixed> $data
     */
    private function skip(array &$result, string $sourceName, array $data, string $reason): void
    {
        $result['skipped']++;
        if (str_starts_with($sourceName, 'gupy-')) {
            Log::warning('Gupy: vaga ignorada pelo importador.', [
                'source' => $sourceName,
                'reason' => $reason,
                'external_id' => $data['external_id'] ?? null,
                'title' => $data['title'] ?? null,
            ]);
        }
    }

    private function isValidApplicationUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL)) {
            return in_array(
                strtolower((string) parse_url($url, PHP_URL_SCHEME)),
                ['http', 'https'],
                true
            );
        }

        if (str_starts_with(strtolower($url), 'mailto:')) {
            return filter_var(substr($url, 7), FILTER_VALIDATE_EMAIL) !== false;
        }

        return false;
    }
}
