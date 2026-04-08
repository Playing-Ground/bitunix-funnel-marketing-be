<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BitunixInvitation extends Model
{
    protected $table = 'bitunix_invitations';

    protected $fillable = [
        'date',
        'vip_code',
        'customer_vip_code',
        'exchange_self_ratio',
        'exchange_sub_ratio',
        'future_self_ratio',
        'future_sub_ratio',
        'registered_users',
        'first_deposit_users',
        'first_trade_users',
        'click_users',
        'trading_volume',
        'my_commission',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'registered_users' => 'integer',
            'first_deposit_users' => 'integer',
            'first_trade_users' => 'integer',
            'click_users' => 'integer',
            'trading_volume' => 'string',
            'my_commission' => 'string',
        ];
    }
}
