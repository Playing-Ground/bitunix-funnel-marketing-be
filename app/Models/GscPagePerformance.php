<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GscPagePerformance extends Model
{
    protected $table = 'gsc_page_performance';

    protected $fillable = [
        'date',
        'page',
        'clicks',
        'impressions',
        'ctr',
        'position',
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
