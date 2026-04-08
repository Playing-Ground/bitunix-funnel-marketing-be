<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CampaignVipMapping extends Model
{
    protected $table = 'campaign_vip_mappings';

    protected $fillable = [
        'vip_code',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'notes',
    ];
}
