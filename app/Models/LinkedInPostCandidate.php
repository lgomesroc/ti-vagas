<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LinkedInPostCandidate extends Model
{
    protected $fillable = [
        'post_id',
        'post_url',
        'author_name',
        'author_url',
        'author_headline',
        'author_type',
        'matched_keyword',
        'published_at',
        'post_text',
        'comments',
        'embedded_links',
        'emails',
        'metadata',
        'title',
        'company',
        'description',
        'location',
        'work_model',
        'employment_type',
        'salary',
        'application_url',
        'status',
        'decision_reason',
        'duplicate_of_vacancy_id',
        'duplicate_of_candidate_id',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'comments' => 'array',
            'embedded_links' => 'array',
            'emails' => 'array',
            'metadata' => 'array',
        ];
    }

    public function duplicateOfVacancy(): BelongsTo
    {
        return $this->belongsTo(Vacancy::class, 'duplicate_of_vacancy_id');
    }

    public function duplicateOfCandidate(): BelongsTo
    {
        return $this->belongsTo(self::class, 'duplicate_of_candidate_id');
    }
}