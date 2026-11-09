<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Vacancy extends Model
{
    protected $fillable = [
        'title',
        'company',
        'description',
        'location',
        'work_model',
        'employment_type',
        'salary',
        'application_url',
        'source',
        'external_id',
        'original_published_at',
        'collected_at',
        'city',
        'state',
        'state_code',
        'country_code',
        'ibge_municipality_code',
        'latitude',
        'longitude',
    ];

    protected function casts(): array
    {
        return [
            'original_published_at' => 'datetime',
            'collected_at' => 'datetime',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }
}
