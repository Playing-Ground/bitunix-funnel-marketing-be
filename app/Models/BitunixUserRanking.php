<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BitunixUserRanking extends Model
{
    protected $table = 'bitunix_user_rankings';

    protected $fillable = [
        'date',
        'uid',
        'trade_amount',
        'fee',
        'commission',
        'deposit_amount',
        'withdraw_amount',
        'asset_balance',
        'net_deposit_amount',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'uid' => 'integer',
            'trade_amount' => 'string',
            'fee' => 'string',
            'commission' => 'string',
            'deposit_amount' => 'string',
            'withdraw_amount' => 'string',
            'asset_balance' => 'string',
            'net_deposit_amount' => 'string',
        ];
    }
}
