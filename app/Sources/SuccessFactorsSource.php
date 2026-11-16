<?php

namespace App\Sources;

use App\Services\VacancyLocationFilter;

class SuccessFactorsSource extends OwnCareerPageSource
{
    public function __construct(
        private readonly string $companyKey,
        string $companyName,
        string $url,
        ?string $caBundle = null,
        ?VacancyLocationFilter $locationFilter = null,
    ) {
        parent::__construct(
            companyKey: 'successfactors-' . $companyKey,
            companyName: $companyName,
            url: $url,
            caBundle: $caBundle,
            locationFilter: $locationFilter,
        );
    }

    public function name(): string
    {
        return 'successfactors-' . $this->companyKey;
    }
}
