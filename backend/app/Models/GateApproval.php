<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProjectRole;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** لا يُنشئها إلا StageWorkflow، ولا تُعدَّل ولا تُحذف — انظر GateApprovalPolicy. */
class GateApproval extends Model
{
    use HasUuid;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_silent'     => 'boolean',
            'approved_at'   => 'datetime',
            'approver_role' => ProjectRole::class,
        ];
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(Stage::class);
    }
}
