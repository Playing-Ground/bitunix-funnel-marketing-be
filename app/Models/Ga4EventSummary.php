<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Ga4EventSummary extends Model
{
    protected $table = 'ga4_events_summary';

    protected $fillable = [
        'date',
        'event_name',
        'event_count',
        'users',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'event_count' => 'integer',
            'users' => 'integer',
        ];
    }
}
