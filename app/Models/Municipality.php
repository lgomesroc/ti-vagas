<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Municipality extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'ibge_code',
        'name',
        'normalized_name',
        'state_code',
        'latitude',
        'longitude',
        'intermediate_region_code',
        'intermediate_region_name',
        'immediate_region_code',
        'immediate_region_name',
        'region_type',
        'region_code',
        'region_name',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }
}
