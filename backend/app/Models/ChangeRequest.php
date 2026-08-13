<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ChangeRequestStatus;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChangeRequest extends Model
{
    use HasUuid;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status'               => ChangeRequestStatus::class,
            'price'                => 'decimal:2',
            'sent_at'              => 'datetime',
            'quote_valid_until'    => 'date',
            'decision_deadline'    => 'date',
            'decided_at'           => 'datetime',
            'delivery_extended_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** القرار اتخذ فعلًا — لا يُعاد فتحه ولا يُمدّد التسليم مرة ثانية. */
    public function isDecided(): bool
    {
        return $this->status->isFinal();
    }
}
