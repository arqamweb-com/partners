<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProjectRole;
use App\Enums\ProjectStatus;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    use HasUuid;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status'                 => ProjectStatus::class,
            'type_details'           => 'array',
            'intake_data'            => 'array',
            'payment_milestones'     => 'array',
            'original_delivery_date' => 'date',
            'adjusted_delivery_date' => 'date',
            'queue_slot_date'        => 'date',
            'credit_expires_at'      => 'date',
            'reactivated_at'         => 'datetime',
            'frozen_at'              => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(ProjectMember::class);
    }

    public function stages(): HasMany
    {
        return $this->hasMany(Stage::class)->orderBy('stage_index');
    }

    public function accessItems(): HasMany
    {
        return $this->hasMany(AccessItem::class)->orderBy('item_order');
    }

    public function contentItems(): HasMany
    {
        return $this->hasMany(ContentItem::class)->orderBy('item_order');
    }

    public function feedbackRounds(): HasMany
    {
        return $this->hasMany(FeedbackRound::class)->orderBy('round_number');
    }

    public function changeRequests(): HasMany
    {
        return $this->hasMany(ChangeRequest::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class)->latest('created_at');
    }

    /**
     * المشاريع التي يراها هذا المستخدم.
     *
     * البديل الصريح لـ scope_conditions() في النسخة السابقة، والفرق أنه
     * لم يعد «أدمن يرى الكل / غيره يرى ما يملك»: كل دور له نطاقه.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->seesAllProjects()) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($user) {
            $q->whereHas('members', fn (Builder $m) => $m->where('user_id', $user->id))
              ->orWhere('owner_id', $user->id);

            // الشريك يرى مشاريع وكالته حتى قبل إسناده لكل مشروع فيها
            if ($user->partner_agency) {
                $q->orWhere('partner_agency', $user->partner_agency);
            }
        });
    }

    /** أعضاء جهة المستلِم مرتّبين بأولوية الاعتماد (الشريك قبل العميل). */
    public function approvers()
    {
        return $this->members
            ->filter(fn (ProjectMember $m) => $m->role->approvalRank() > 0 && $m->user_id !== null)
            ->sortByDesc(fn (ProjectMember $m) => $m->role->approvalRank())
            ->values();
    }

    public function isCharterLocked(): bool
    {
        return $this->status !== ProjectStatus::Draft;
    }
}
