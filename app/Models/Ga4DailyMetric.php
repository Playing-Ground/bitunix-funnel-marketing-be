<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Ga4DailyMetric extends Model
{
    protected $table = 'ga4_daily_metrics';

    protected $fillable = [
        'date',
        'users',
        'new_users',
        'sessions',
        'engaged_sessions',
        'page_views',
        'key_events',
        'avg_session_duration_seconds',
        'engagement_rate',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'users' => 'integer',
            'new_users' => 'integer',
            'sessions' => 'integer',
            'engaged_sessions' => 'integer',
            'page_views' => 'integer',
            'key_events' => 'integer',
            'avg_session_duration_seconds' => 'decimal:2',
            'engagement_rate' => 'decimal:5',
        ];
    }
}
