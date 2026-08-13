<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FeedbackRoundStatus;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FeedbackRound extends Model
{
    use HasUuid;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status'       => FeedbackRoundStatus::class,
            'is_paid'      => 'boolean',
            'opened_at'    => 'datetime',
            'submitted_at' => 'datetime',
            'closed_at'    => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(FeedbackItem::class, 'round_id');
    }

    public function isOpen(): bool
    {
        return $this->status === FeedbackRoundStatus::Open;
    }
}
