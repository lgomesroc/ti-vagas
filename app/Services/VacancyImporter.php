<?php

namespace App\Services;

use App\Contracts\VacancySource;
use App\Models\Vacancy;
use Illuminate\Support\Carbon;
use Throwable;
use InvalidArgumentException;

class VacancyImporter
{
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

            $requiredFields = [
                'title',
                'company',
                'description',
                'application_url',
                'external_id',
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

            $externalId = trim($data['external_id']);

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
