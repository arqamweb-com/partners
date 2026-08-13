<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProjectRole;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** عضوية المشروع — مصدر الحقيقة الوحيد لمن يصل لماذا وبأي صفة. */
class ProjectMember extends Model
{
    use HasUuid;

    protected $fillable = ['project_id', 'user_id', 'invited_email', 'role', 'invited_by', 'claimed_at'];

    protected function casts(): array
    {
        return ['role' => ProjectRole::class, 'claimed_at' => 'datetime'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** دعوة لم تُطالَب بعد: بريد بلا مستخدم. */
    public function isPending(): bool
    {
        return $this->user_id === null;
    }

    /**
     * يربط المستخدم بكل المشاريع المدعو إليها ببريده.
     *
     * تُستدعى عند إنشاء الحساب أيًا كان الطريق — تسجيل ذاتي أو إنشاء من
     * الأدمن — لأن الدعوة صف بلا user_id، ومن ينشئ الحساب لا يعلم أن أحدًا
     * دعا هذا البريد قبله.
     */
    public static function claimInvitesFor(User $user): int
    {
        return static::query()
            ->whereNull('user_id')
            ->where('invited_email', $user->email)
            ->update([
                'user_id'    => $user->id,
                'claimed_at' => now(),
            ]);
    }
}
