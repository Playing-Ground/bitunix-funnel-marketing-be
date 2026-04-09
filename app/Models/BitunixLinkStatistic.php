<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BitunixLinkStatistic extends Model
{
    protected $table = 'bitunix_link_statistics';

    protected $fillable = [
        'vip_code',
        'customer_vip_code',
        'click_users',
        'registered_users',
        'first_deposit_users',
        'first_trade_users',
        'transaction_users',
        'deposit_users',
        'trade_amount',
        'created_at_bitunix',
        'latest_registration_time',
    ];

    protected function casts(): array
    {
        return [
            'click_users' => 'integer',
            'registered_users' => 'integer',
            'first_deposit_users' => 'integer',
            'first_trade_users' => 'integer',
            'transaction_users' => 'integer',
            'deposit_users' => 'integer',
            'created_at_bitunix' => 'datetime',
            'latest_registration_time' => 'datetime',
        ];
    }
}
