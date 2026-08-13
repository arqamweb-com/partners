<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Side;
use App\Enums\StageStatus;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Stage extends Model
{
    use HasUuid;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_parallel'   => 'boolean',
            'status'        => StageStatus::class,
            'ball_in_court' => Side::class,
            'deliverables'  => 'array',
            'started_at'    => 'datetime',
            'due_at'        => 'datetime',
            'submitted_at'  => 'datetime',
            'rejected_at'   => 'datetime',
            'locked_at'     => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function gateApprovals(): HasMany
    {
        return $this->hasMany(GateApproval::class);
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }
}
