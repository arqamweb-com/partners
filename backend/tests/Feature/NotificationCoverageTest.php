<?php

/**
 * تغطية الإشعارات على دورة حياة المشروع كاملة.
 *
 * كل تسليم للكرة يجب أن يُبلَّغ به الطرف المستلِم. هذا الملف يمشي الدورة
 * من أولها لآخرها ويتحقق أن أحدًا لم يُترك ينتظر بلا خبر.
 */

declare(strict_types=1);

use App\Enums\ChangeRequestStatus;
use App\Enums\ContentStatus;
use App\Enums\ProjectRole;
use App\Models\ChangeRequest;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * إشعار بمفتاح حدث بعينه.
 *
 * البحث بالمفتاح لا بـ«الأحدث»: الطوابع الزمنية بدقة الثانية، وأحداث
 * الاختبار تقع كلها داخل ثانية واحدة، فترتيبها غير مضمون.
 */
function noticeOf(User $user, string $eventKey): ?array
{
    return $user->fresh()->notifications
        ->firstWhere('data.event_key', $eventKey)?->data;
}

function unreadKeys(User $user): array
{
    return $user->fresh()->notifications()->get()
        ->pluck('data.event_key')->all();
}

it('يبلّغ كل طرف في وقته عبر دورة الحياة كاملة', function () {
    $admin = User::factory()->admin()->create(['full_name' => 'مدير أرقام']);
    $client = User::factory()->create(['full_name' => 'العميل']);

    // ── 1. العميل يسجّل طلبًا → الأدمن يعرف ─────────────────────────────
    $projectId = $this->actingAs($client)
        ->postJson('/api/projects', ['name' => 'متجري', 'project_type' => 'brochure'])
        ->assertCreated()->json('data.id');

    expect(noticeOf($admin, 'project.requested'))->not->toBeNull();

    // ── 2. الأدمن يعتمد → العميل يعرف أن طلبه صار مشروعًا ───────────────
    $this->actingAs($admin)->postJson("/api/projects/{$projectId}/approve")->assertOk();

    $notice = noticeOf($client, 'project.approved');
    expect($notice)->not->toBeNull()
        ->and($notice['title'])->toContain('متجري');

    $project = Project::find($projectId);

    // ── 3. العميل يقدّم محتوى → الفريق يعرف ────────────────────────────
    $item = $project->contentItems()->first();
    $this->actingAs($client)
        ->postJson("/api/content-items/{$item->id}/submit", ['value' => 'نص الصفحة'])
        ->assertOk();

    expect(noticeOf($admin, 'content.submitted'))->not->toBeNull();

    // ── 4. الفريق يرفض المحتوى → العميل يعرف بالسبب ────────────────────
    $this->actingAs($admin)
        ->postJson("/api/content-items/{$item->id}/review",
            ['accept' => false, 'reason' => 'الصور بجودة منخفضة'])
        ->assertOk();

    $notice = noticeOf($client, 'content.reviewed');
    expect($notice)->not->toBeNull()
        ->and($notice['body'])->toContain('الصور بجودة منخفضة');

    // ── 5. الفريق يفتح جولة ملاحظات → العميل يعرف ──────────────────────
    $roundId = $this->actingAs($admin)
        ->postJson("/api/projects/{$projectId}/feedback", [])
        ->assertCreated()->json('data.id');

    expect(noticeOf($client, 'feedback.opened'))->not->toBeNull();

    // ── 6. العميل يرسل الجولة → الفريق يعرف ───────────────────────────
    $this->actingAs($client)
        ->postJson("/api/feedback-rounds/{$roundId}/items", ['description' => 'الخط صغير']);
    $this->actingAs($client)->postJson("/api/feedback-rounds/{$roundId}/submit")->assertOk();

    expect(noticeOf($admin, 'feedback.submitted'))->not->toBeNull();

    // ── 7. الفريق يصنّف → العميل يعرف أن مهلة الاعتراض بدأت ────────────
    $this->actingAs($admin)
        ->postJson("/api/feedback-rounds/{$roundId}/classify", ['status' => 'classified'])
        ->assertOk();

    expect(noticeOf($client, 'feedback.classified'))->not->toBeNull();

    // ── 8. العميل يطلب تغييرًا → الفريق يعرف أنه ينتظر تسعيرًا ─────────
    $crId = $this->actingAs($client)
        ->postJson("/api/projects/{$projectId}/change-requests",
            ['title' => 'إضافة صفحة أسعار', 'description' => 'صفحة بجدول'])
        ->assertCreated()->json('data.id');

    expect(noticeOf($admin, 'cr.created'))->not->toBeNull();

    // ── 9. الفريق يسعّر ويرسل → العميل يعرف ───────────────────────────
    $this->actingAs($admin)
        ->postJson("/api/change-requests/{$crId}/send",
            ['price' => 3000, 'delivery_impact_days' => 4])
        ->assertOk();

    expect(noticeOf($client, 'cr.sent'))->not->toBeNull();

    // ── 10. العميل يعتمد → الفريق يعرف أن العمل يبدأ ──────────────────
    $this->actingAs($client)
        ->postJson("/api/change-requests/{$crId}/decide", ['approve' => true])
        ->assertOk();

    $notice = noticeOf($admin, 'cr.decided');
    expect($notice)->not->toBeNull()
        ->and($notice['title'])->toContain('اعتماد');

    // ── 11. الفريق يجمّد المشروع → العميل يعرف بالسبب ─────────────────
    $this->actingAs($admin)
        ->postJson("/api/projects/{$projectId}/status",
            ['status' => 'frozen', 'reason' => 'تأخر وصول المحتوى'])
        ->assertOk();

    $notice = noticeOf($client, 'project.status_changed');
    expect($notice)->not->toBeNull()
        ->and($notice['body'])->toContain('تأخر وصول المحتوى');
});

it('يبلّغ المدعوّ أنه أُضيف لمشروع وبأي صفة', function () {
    $admin = User::factory()->admin()->create();
    $partner = User::factory()->partner()->create();

    $project = Project::create([
        'name' => 'مشروع الشريك', 'owner_id' => $admin->id, 'owner_name' => $admin->full_name,
    ]);
    $project->members()->create(['user_id' => $admin->id, 'role' => ProjectRole::Lead]);

    $this->actingAs($admin)
        ->postJson("/api/projects/{$project->id}/members",
            ['email' => $partner->email, 'role' => 'partner'])
        ->assertCreated();

    $notice = noticeOf($partner, 'project.member_added');
    expect($notice)->not->toBeNull()
        ->and($notice['body'])->toContain('شريك')
        ->and($notice['url'])->toBe("/projects/{$project->id}");
});

it('يبلّغ الطرفين بالقبول التلقائي بعد فوات مهلة المراجعة', function () {
    $admin = User::factory()->admin()->create();
    $client = User::factory()->create();

    $id = $this->actingAs($client)
        ->postJson('/api/projects', ['name' => 'مشروع', 'project_type' => 'brochure'])
        ->json('data.id');
    $this->actingAs($admin)->postJson("/api/projects/{$id}/approve");

    $item = Project::find($id)->contentItems()->first();
    $this->actingAs($client)->postJson("/api/content-items/{$item->id}/submit", ['value' => 'نص']);

    $this->travel(5)->days();
    $this->artisan('arqam:auto-accept')->assertSuccessful();

    expect($item->fresh()->status)->toBe(ContentStatus::Accepted)
        ->and(unreadKeys($admin))->toContain('content.reviewed')
        ->and(unreadKeys($client))->toContain('content.reviewed');
});

it('يبلّغ الطرفين بانتهاء مهلة طلب التغيير', function () {
    $admin = User::factory()->admin()->create();
    $client = User::factory()->create();

    $id = $this->actingAs($client)
        ->postJson('/api/projects', ['name' => 'مشروع', 'project_type' => 'brochure'])
        ->json('data.id');
    $this->actingAs($admin)->postJson("/api/projects/{$id}/approve");

    ChangeRequest::create([
        'project_id' => $id, 'title' => 'طلب منسي',
        'status' => ChangeRequestStatus::Sent, 'sent_at' => now(),
        'decision_deadline' => now()->subDay()->toDateString(),
    ]);

    $this->artisan('arqam:expire-change-requests')->assertSuccessful();

    expect(unreadKeys($admin))->toContain('cr.expired')
        ->and(unreadKeys($client))->toContain('cr.expired');
});

it('لا يُشعر الفاعل بفعله هو', function () {
    $admin = User::factory()->admin()->create();
    $client = User::factory()->create();

    $id = $this->actingAs($client)
        ->postJson('/api/projects', ['name' => 'مشروع', 'project_type' => 'brochure'])
        ->json('data.id');

    // العميل سجّل الطلب، فلا يُشعَر بتسجيله
    expect($client->fresh()->notifications()->count())->toBe(0);

    $this->actingAs($admin)->postJson("/api/projects/{$id}/approve");

    // والأدمن اعتمد، فالإشعار للعميل لا له
    expect(unreadKeys($admin))->not->toContain('project.approved');
});

it('يبني رابط كل إشعار لصفحة مشروعه', function () {
    $admin = User::factory()->admin()->create();
    $client = User::factory()->create();

    $id = $this->actingAs($client)
        ->postJson('/api/projects', ['name' => 'مشروع', 'project_type' => 'brochure'])
        ->json('data.id');
    $this->actingAs($admin)->postJson("/api/projects/{$id}/approve");

    foreach ([$admin, $client] as $user) {
        foreach ($user->fresh()->notifications as $n) {
            expect($n->data['url'])->toBe("/projects/{$id}")
                ->and($n->data['title'])->not->toBeEmpty()
                ->and($n->data['body'])->not->toBeEmpty();
        }
    }
});
