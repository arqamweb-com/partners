<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationPreference extends Model
{
    use HasUuid;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['in_app' => 'boolean', 'email' => 'boolean', 'digest_only' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** الافتراضي: يتبلّغ. الصمت اختيار صريح من المستخدم. */
    public static function allows(User $user, string $eventKey): bool
    {
        $pref = static::query()
            ->where('user_id', $user->id)
            ->whereIn('event_key', [$eventKey, '*'])
            ->orderByRaw("event_key = '*'")   // الأخص يسبق العام
            ->first();

        return $pref === null || $pref->in_app || $pref->email;
    }

    /** القنوات المفعّلة لهذا المستخدم على هذا الحدث. */
    public static function channelsFor(User $user, string $eventKey): array
    {
        $pref = static::query()
            ->where('user_id', $user->id)
            ->whereIn('event_key', [$eventKey, '*'])
            ->orderByRaw("event_key = '*'")
            ->first();

        if ($pref === null) {
            return ['database', 'mail'];
        }

        if ($pref->digest_only) {
            return ['database'];   // الملخّص اليومي يتولّى البريد
        }

        return array_values(array_filter([
            $pref->in_app ? 'database' : null,
            $pref->email ? 'mail' : null,
        ]));
    }
}
