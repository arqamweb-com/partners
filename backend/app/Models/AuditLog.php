<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProjectRole;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** إضافة فقط. الفاعل يُكتب من الجلسة في AuditLogger لا من الطلب. */
class AuditLog extends Model
{
    use HasUuid;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'actor_role' => ProjectRole::class];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
