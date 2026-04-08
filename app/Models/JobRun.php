<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JobRun extends Model
{
    protected $table = 'job_runs';

    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'job_name',
        'status',
        'started_at',
        'finished_at',
        'records_processed',
        'error',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'records_processed' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function markCompleted(int $recordsProcessed = 0): self
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'finished_at' => now(),
            'records_processed' => $recordsProcessed,
        ]);

        return $this;
    }

    public function markFailed(string $error): self
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'finished_at' => now(),
            'error' => $error,
        ]);

        return $this;
    }
}
