<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LinkedInJobScan extends Model
{
    protected $table = 'linkedin_job_scans';

    protected $fillable = [
        'job_id',
        'http_status',
        'consulted_at',
    ];

    protected function casts(): array
    {
        return [
            'consulted_at' => 'datetime',
        ];
    }
}
