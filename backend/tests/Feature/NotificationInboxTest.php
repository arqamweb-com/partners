<?php

/**
 * صفحة الإشعارات: التصفّح والتصفية وتعليم إشعار بعينه.
 *
 * الفكرة المحمية: الجرس لم يعد سقف ما يملكه المستخدم. ما زاد على الصفحة
 * الأولى يُطلَب بصفحة تالية، و«مقروء» فعل على إشعار واحد لا على كل شيء —
 * من فتح إشعارًا ليقرأه كان يخسر أثر ما لم يفتحه بعد.
 */

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/** يزرع إشعارات غير مقروءة، الأحدث آخرًا. */
function seedNotifications(User $user, int $count): array
{
    $ids = [];

    foreach (range(1, $count) as $i) {
        $ids[$i] = (string) Str::uuid();

        DB::table('notifications')->insert([
            'id'              => $ids[$i],
            'type'            => 'App\\Notifications\\StageSubmitted',
            'notifiable_type' => User::class,
            'notifiable_id'   => $user->id,
            'data'            => json_encode([
                'event_key' => 'stage.submitted',
                'title'     => "إشعار {$i}",
                'body'      => 'نص الإشعار',
                'url'       => '/dashboard',
            ], JSON_UNESCAPED_UNICODE),
            'read_at'    => null,
            'created_at' => now()->subMinutes($count - $i),
            'updated_at' => now()->subMinutes($count - $i),
        ]);
    }

    return $ids;
}

it('يصفّح الإشعارات بدل أن يقف عند سقف ثابت', function () {
    $user = User::factory()->create();
    seedNotifications($user, 25);

    $first = $this->actingAs($user)->getJson('/api/notifications?per_page=10')->assertOk();

    $first->assertJsonCount(10, 'data')
        ->assertJsonPath('total', 25)
        ->assertJsonPath('last_page', 3)
        ->assertJsonPath('unread', 25)
        // الأحدث أولًا
        ->assertJsonPath('data.0.data.title', 'إشعار 25');

    $this->actingAs($user)->getJson('/api/notifications?per_page=10&page=3')
        ->assertOk()
        ->assertJsonCount(5, 'data')
        ->assertJsonPath('data.4.data.title', 'إشعار 1');
});

it('يعلّم إشعارًا بعينه مقروءًا ويترك الباقي', function () {
    $user = User::factory()->create();
    $ids  = seedNotifications($user, 3);

    $this->actingAs($user)->postJson("/api/notifications/{$ids[2]}/read")->assertOk();

    expect($user->fresh()->unreadNotifications()->count())->toBe(2)
        ->and(DB::table('notifications')->where('id', $ids[2])->value('read_at'))->not->toBeNull();
});

it('يقصر التصفية على غير المقروء عند طلبها', function () {
    $user = User::factory()->create();
    $ids  = seedNotifications($user, 3);

    $this->actingAs($user)->postJson("/api/notifications/{$ids[1]}/read")->assertOk();

    $this->actingAs($user)->getJson('/api/notifications?filter=unread')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('unread', 2);

    $this->actingAs($user)->getJson('/api/notifications?filter=all')
        ->assertOk()
        ->assertJsonCount(3, 'data');
});

it('لا يعلّم أحد إشعار غيره مقروءًا', function () {
    $owner    = User::factory()->create();
    $intruder = User::factory()->admin()->create();
    $ids      = seedNotifications($owner, 1);

    $this->actingAs($intruder)->postJson("/api/notifications/{$ids[1]}/read")->assertStatus(404);

    expect($owner->fresh()->unreadNotifications()->count())->toBe(1);
});
