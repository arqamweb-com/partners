<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProjectRole;
use App\Enums\SystemRole;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use App\Notifications\ResetPassword;

class User extends Authenticatable
{
    use HasFactory;
    use HasUuid;
    use Notifiable;

    protected $fillable = [
        'email', 'password', 'full_name', 'agency_name', 'system_role',
        'partner_agency', 'is_active',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password'          => 'hashed',
            'email_verified_at' => 'datetime',
            'is_active'         => 'boolean',
            'system_role'       => SystemRole::class,
        ];
    }

    /** رسالة الاستعادة عربية وتشير للواجهة — انظر App\Notifications\ResetPassword */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPassword($token));
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(ProjectMember::class);
    }

    public function ownedProjects(): HasMany
    {
        return $this->hasMany(Project::class, 'owner_id');
    }

    // -----------------------------------------------------------------------
    // اختصارات الدور — الصلاحية الحقيقية في السياسات، وهذه قراءتها فقط
    // -----------------------------------------------------------------------

    public function isStaff(): bool
    {
        return $this->system_role->isStaff();
    }

    public function isSuperUser(): bool
    {
        return $this->system_role->isSuperUser();
    }

    public function canPrice(): bool
    {
        return $this->system_role->canPrice();
    }

    public function seesAllProjects(): bool
    {
        return $this->system_role->seesAllProjects();
    }

    /**
     * دور المستخدم داخل مشروع بعينه.
     *
     * الأدمن والمدير يريان كل المشاريع بلا عضوية، فيُعاملان Lead ضمنيًا.
     * المشرف والشريك والعميل يحتاجون عضوية صريحة — وهذا هو المقصود:
     * لا وصول بلا صف في project_members.
     */
    public function roleOn(Project $project): ?ProjectRole
    {
        if ($this->seesAllProjects()) {
            return ProjectRole::Lead;
        }

        $membership = $this->relationLoaded('memberships')
            ? $this->memberships->firstWhere('project_id', $project->id)
            : $this->memberships()->where('project_id', $project->id)->first();

        return $membership?->role;
    }
}
