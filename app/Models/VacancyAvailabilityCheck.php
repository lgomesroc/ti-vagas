<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VacancyAvailabilityCheck extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'vacancy_id',
        'check_type',
        'status',
        'http_status',
        'checked_url',
        'final_url',
        'reason',
        'response_time_ms',
        'checked_at',
    ];

    protected function casts(): array
    {
        return [
            'checked_at' => 'datetime',
        ];
    }

    public function vacancy(): BelongsTo
    {
        return $this->belongsTo(Vacancy::class);
    }
}
