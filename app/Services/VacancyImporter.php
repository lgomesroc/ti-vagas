<?php

namespace App\Services;

use App\Contracts\VacancySource;
use App\Models\Vacancy;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Throwable;

class VacancyImporter
{
    public function __construct(
        private readonly VacancyAvailabilityDetector $availabilityDetector
    ) {
    }

    public function import(VacancySource $source): array
    {
        $sourceName = trim($source->name());

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

        foreach ($source->fetch() as $data) {
            if (!is_array($data)) {
                $result['skipped']++;
                continue;
            }

            $externalId = $data['external_id'] ?? null;

            if (
                !is_string($externalId) ||
                trim($externalId) === ''
            ) {
                $result['skipped']++;
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
                Vacancy::query()
                    ->where('source', $sourceName)
                    ->where('external_id', $externalId)
                    ->delete();

                $result['skipped']++;

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
                !filter_var(
                    $data['application_url'],
                    FILTER_VALIDATE_URL
                ) ||
                empty($data['original_published_at'])
            ) {
                $result['skipped']++;

                continue;
            }

            try {
                $publishedAt = Carbon::parse(
                    $data['original_published_at']
                );
            } catch (Throwable) {
                $result['skipped']++;

                continue;
            }

            $vacancy = Vacancy::firstOrNew([
                'source' => $sourceName,
                'external_id' => $externalId,
            ]);

            $isNew = !$vacancy->exists;

            $vacancy->fill([
                'title' => trim($data['title']),
                'company' => trim($data['company']),
                'description' => trim($data['description']),
                'location' => $data['location'] ?? null,
                'work_model' => $data['work_model'] ?? null,
                'employment_type' => $data['employment_type'] ?? null,
                'salary' => $data['salary'] ?? null,
                'application_url' => trim($data['application_url']),
                'source' => $sourceName,
                'external_id' => $externalId,
                'collected_at' => now(),
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
}
