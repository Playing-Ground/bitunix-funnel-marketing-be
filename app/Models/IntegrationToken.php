<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int                              $id
 * @property string                           $service
 * @property string                           $token
 * @property \Illuminate\Support\Carbon|null  $expires_at
 * @property \Illuminate\Support\Carbon|null  $last_refreshed_at
 * @property array<string,mixed>|null         $metadata
 */
class IntegrationToken extends Model
{
    protected $table = 'integration_tokens';

    protected $fillable = [
        'service',
        'token',
        'expires_at',
        'last_refreshed_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'metadata' => 'array',
            'expires_at' => 'datetime',
            'last_refreshed_at' => 'datetime',
        ];
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function expiresWithin(int $days): bool
    {
        return $this->expires_at !== null
            && $this->expires_at->diffInDays(now()) <= $days;
    }
}
