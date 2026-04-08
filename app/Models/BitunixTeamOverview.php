<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BitunixTeamOverview extends Model
{
    protected $table = 'bitunix_team_overview';

    protected $fillable = [
        'date',
        'nick_name',
        'team_size',
        'partner_size',
        'direct_size',
        'new_users',
        'my_profit',
        'total_deposit',
        'total_withdraw',
        'trade_amount',
        'fee',
        'deposits_numbers',
        'withdraw_numbers',
        'recent_register_time',
        'recent_join_time',
        'recent_trade_time',
        'recent_deposit_time',
        'recent_withdraw_time',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'team_size' => 'integer',
            'partner_size' => 'integer',
            'direct_size' => 'integer',
            'new_users' => 'integer',
            'my_profit' => 'string',
            'total_deposit' => 'string',
            'total_withdraw' => 'string',
            'trade_amount' => 'string',
            'fee' => 'string',
            'deposits_numbers' => 'integer',
            'withdraw_numbers' => 'integer',
            'recent_register_time' => 'datetime',
            'recent_join_time' => 'datetime',
            'recent_trade_time' => 'datetime',
            'recent_deposit_time' => 'datetime',
            'recent_withdraw_time' => 'datetime',
        ];
    }
}
