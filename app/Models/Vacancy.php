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
    ];

    protected function casts(): array
    {
        return [
            'original_published_at' => 'datetime',
            'collected_at' => 'datetime',
        ];
    }
}
