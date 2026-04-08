<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttributionDaily extends Model
{
    protected $table = 'attribution_daily';

    protected $fillable = [
        'date',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'gsc_clicks',
        'gsc_impressions',
        'gsc_avg_position',
        'gsc_avg_ctr',
        'ga4_users',
        'ga4_sessions',
        'ga4_engaged_sessions',
        'ga4_conversions',
        'bitunix_signups',
        'bitunix_first_deposit',
        'bitunix_first_trade',
        'bitunix_commission',
        'bitunix_trading_volume',
        'click_to_session_rate',
        'session_to_signup_rate',
        'clicks_to_signup_rate',
        'row_hash',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'gsc_clicks' => 'integer',
            'gsc_impressions' => 'integer',
            'gsc_avg_position' => 'decimal:4',
            'gsc_avg_ctr' => 'decimal:7',
            'ga4_users' => 'integer',
            'ga4_sessions' => 'integer',
            'ga4_engaged_sessions' => 'integer',
            'ga4_conversions' => 'integer',
            'bitunix_signups' => 'integer',
            'bitunix_first_deposit' => 'integer',
            'bitunix_first_trade' => 'integer',
            'bitunix_commission' => 'string',
            'bitunix_trading_volume' => 'string',
            'click_to_session_rate' => 'decimal:5',
            'session_to_signup_rate' => 'decimal:5',
            'clicks_to_signup_rate' => 'decimal:5',
        ];
    }
}
