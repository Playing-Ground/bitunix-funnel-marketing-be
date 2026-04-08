<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Ga4Acquisition extends Model
{
    protected $table = 'ga4_acquisition';

    protected $fillable = [
        'date',
        'source',
        'medium',
        'campaign',
        'source_platform',
        'users',
        'new_users',
        'sessions',
        'engaged_sessions',
        'key_events',
        'conversions',
        'row_hash',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'users' => 'integer',
            'new_users' => 'integer',
            'sessions' => 'integer',
            'engaged_sessions' => 'integer',
            'key_events' => 'integer',
            'conversions' => 'integer',
        ];
    }
}
