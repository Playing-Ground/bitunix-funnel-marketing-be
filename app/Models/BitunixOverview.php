<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BitunixOverview extends Model
{
    protected $table = 'bitunix_overview';

    protected $fillable = [
        'date',
        'registrations',
        'first_deposit_users',
        'first_trade_users',
        'commission',
        'fee',
        'raw',
    ];

    /**
     * Money fields stay as strings to preserve precision — Bitunix returns
     * decimal strings (never floats) for monetary values.
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'registrations' => 'integer',
            'first_deposit_users' => 'integer',
            'first_trade_users' => 'integer',
            'commission' => 'string',
            'fee' => 'string',
            'raw' => 'array',
        ];
    }
}
