<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GscDailySummary extends Model
{
    protected $table = 'gsc_daily_summary';

    protected $fillable = [
        'date',
        'total_clicks',
        'total_impressions',
        'avg_ctr',
        'avg_position',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'total_clicks' => 'integer',
            'total_impressions' => 'integer',
            'avg_ctr' => 'decimal:7',
            'avg_position' => 'decimal:4',
        ];
    }
}
