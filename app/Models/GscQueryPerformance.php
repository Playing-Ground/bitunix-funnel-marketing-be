<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GscQueryPerformance extends Model
{
    protected $table = 'gsc_query_performance';

    protected $fillable = [
        'date',
        'query',
        'page',
        'country',
        'device',
        'search_appearance',
        'clicks',
        'impressions',
        'ctr',
        'position',
        'data_state',
        'row_hash',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'clicks' => 'integer',
            'impressions' => 'integer',
            'ctr' => 'decimal:7',
            'position' => 'decimal:4',
        ];
    }
}
