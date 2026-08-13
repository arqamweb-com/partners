<?php

/**
 * مواصفة السلوك الأمني — منقولة عن api/tests في النسخة السابقة.
 *
 * كل اختبار هنا يقابل ثغرة حقيقية كانت مفتوحة. وجودها في لارافيل يعني أن
 * إعادة الكتابة لم تُدخل الثغرة من باب آخر.
 */

declare(strict_types=1);

use App\Enums\ChangeRequestStatus;
use App\Enums\ContentStatus;
use App\Enums\FeedbackRoundStatus;
use App\Enums\ProjectRole;
use App\Enums\Side;
use App\Enums\StageStatus;
use App\Models\AccessItem;
use App\Models\ChangeRequest;
use App\Models\ContentItem;
use App\Models\FeedbackRound;
use App\Models\GateApproval;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Stage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** مشروع بأدواره: أدمن + عميل، ومرحلتان وقوائم. */
function scenario(array $extra = []): array
{
    $admin = User::factory()->admin()->create();
    $client = User::factory()->create(['full_name' => 'عميل الاختبار']);

    $project = Project::create([
        'name'                   => 'مشروع اختبار',
        'owner_id'               => $admin->id,
        'owner_name'             => $admin->full_name,
        'original_delivery_date' => '2026-09-01',
        'adjusted_delivery_date' => '2026-09-01',
    ]);

    ProjectMember::create([
        'project_id' => $project->id, 'user_id' => $admin->id, 'role' => ProjectRole::Lead,
    ]);
    ProjectMember::create([
        'project_id' => $project->id, 'user_id' => $client->id, 'role' => ProjectRole::Client,
    ]);

    $stage0 = Stage::create([
        'project_id' => $project->id, 'stage_index' => 0, 'name' => 'التصميم',
        'status' => StageStatus::Active, 'ball_in_court' => Side::Us, 'started_at' => now(),
    ]);
    $stage1 = Stage::create([
        'project_id' => $project->id, 'stage_index' => 1, 'name' => 'التنفيذ',
        'status' => StageStatus::Pending, 'ball_in_court' => Side::Us,
    ]);

    return compact('admin', 'client', 'project', 'stage0', 'stage1') + $extra;
}

// ---------------------------------------------------------------------------
// المرحلة: الإقفال بلا مراجعة
// ---------------------------------------------------------------------------

it('يمنع الطرف صاحب الكرة من اعتماد مرحلته بنفسه بعد الرفض', function () {
    ['client' => $client, 'stage0' => $stage] = scenario();

    // ما بعد الرفض: الكرة عند العميل والحالة active — كانت الثغرة هنا
    $stage->update(['ball_in_court' => Side::Them, 'status' => StageStatus::Active]);

    $this->actingAs($client)
        ->postJson("/api/stages/{$stage->id}/approve", [
            'approver_name' => 'عميل الاختبار', 'acknowledgement' => 'موافق',
        ])
        ->assertForbidden();

    expect($stage->fresh()->locked_at)->toBeNull();
});

it('يمنع رفض مرحلة لم تُقدَّم', function () {
    ['client' => $client, 'stage0' => $stage] = scenario();
    $stage->update(['ball_in_court' => Side::Them, 'status' => StageStatus::Active]);

    $this->actingAs($client)
        ->postJson("/api/stages/{$stage->id}/reject", ['reason' => 'سبب مكتوب بوضوح'])
        ->assertForbidden();
});

it('يمنع تقديم مرحلة لم تبدأ', function () {
    ['admin' => $admin, 'stage1' => $pending] = scenario();

    $this->actingAs($admin)
        ->postJson("/api/stages/{$pending->id}/submit", ['note' => 'تقديم مبكر'])
        ->assertForbidden();
});

it('يمشّي المسار السليم: تقديم ثم اعتماد يقفل ويبدأ التالية', function () {
    ['admin' => $admin, 'client' => $client, 'stage0' => $stage, 'stage1' => $next] = scenario();

    $this->actingAs($admin)
        ->postJson("/api/stages/{$stage->id}/submit", ['note' => 'التصميم جاهز'])
        ->assertOk();

    $stage->refresh();
    expect($stage->status)->toBe(StageStatus::AwaitingApproval)
        ->and($stage->ball_in_court)->toBe(Side::Them);

    $this->actingAs($client)
        ->postJson("/api/stages/{$stage->id}/approve", [
            'approver_name' => 'عميل الاختبار', 'acknowledgement' => 'أقر بالاستلام',
        ])
        ->assertOk();

    expect($stage->fresh()->status)->toBe(StageStatus::Locked)
        ->and($next->fresh()->status)->toBe(StageStatus::Active)
        ->and(GateApproval::where('stage_id', $stage->id)->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// طلبات التغيير: تمديد التسليم المتكرر
// ---------------------------------------------------------------------------

it('يمدّ تاريخ التسليم مرة واحدة مهما تكرّر الاعتماد', function () {
    ['client' => $client, 'admin' => $admin, 'project' => $project] = scenario();

    $cr = ChangeRequest::create([
        'project_id' => $project->id, 'title' => 'إضافة صفحة منتجات',
        'price' => 5000, 'duration_days' => 5, 'delivery_impact_days' => 5,
        'status' => ChangeRequestStatus::Sent, 'sent_at' => now(),
    ]);

    $this->actingAs($client)
        ->postJson("/api/change-requests/{$cr->id}/decide", ['approve' => true, 'note' => 'موافق'])
        ->assertOk();

    $afterFirst = $project->fresh()->adjusted_delivery_date;
    expect($afterFirst->toDateString())->not->toBe('2026-09-01')
        ->and($cr->fresh()->decided_by)->toBe($client->id);   // من الجلسة لا من الطلب

    // محاولة إعادة القرار — الحالة النهائية لا تُعاد فتحها
    $this->actingAs($client)
        ->postJson("/api/change-requests/{$cr->id}/decide", ['approve' => true])
        ->assertForbidden();

    expect($project->fresh()->adjusted_delivery_date->toDateString())
        ->toBe($afterFirst->toDateString());
});

it('يمنع العميل من اعتماد طلب لم يُسعَّر ولم يُرسَل', function () {
    ['client' => $client, 'project' => $project] = scenario();

    $draft = ChangeRequest::create([
        'project_id' => $project->id, 'title' => 'طلب مسودة',
        'delivery_impact_days' => 10, 'status' => ChangeRequestStatus::Draft,
    ]);

    $this->actingAs($client)
        ->postJson("/api/change-requests/{$draft->id}/decide", ['approve' => true])
        ->assertForbidden();
});

it('يمنع العميل من تسعير طلب وإرساله', function () {
    ['client' => $client, 'project' => $project] = scenario();

    $draft = ChangeRequest::create([
        'project_id' => $project->id, 'title' => 'طلب مسودة',
        'status' => ChangeRequestStatus::Draft,
    ]);

    $this->actingAs($client)
        ->postJson("/api/change-requests/{$draft->id}/send", ['price' => 0])
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// الأدوار الجديدة — ما كان مستحيلًا التعبير عنه في النموذج الثنائي
// ---------------------------------------------------------------------------

it('يمنع المشرف من رؤية مشروع غير مُسنَد إليه', function () {
    ['project' => $project] = scenario();
    $supervisor = User::factory()->supervisor()->create();

    expect(Project::visibleTo($supervisor)->count())->toBe(0);

    ProjectMember::create([
        'project_id' => $project->id, 'user_id' => $supervisor->id,
        'role' => ProjectRole::Contributor,
    ]);

    expect(Project::visibleTo($supervisor->fresh())->count())->toBe(1);
});

it('يمنع المنفّذ من تقديم المراحل رغم أنه من فريق أرقام', function () {
    ['stage0' => $stage, 'project' => $project] = scenario();

    $supervisor = User::factory()->supervisor()->create();
    ProjectMember::create([
        'project_id' => $project->id, 'user_id' => $supervisor->id,
        'role' => ProjectRole::Contributor,   // منفّذ لا مسؤول تنفيذ
    ]);

    $this->actingAs($supervisor)
        ->postJson("/api/stages/{$stage->id}/submit", ['note' => 'محاولة'])
        ->assertForbidden();
});

it('يمنع المشرف من تسعير طلبات التغيير', function () {
    ['project' => $project] = scenario();

    $supervisor = User::factory()->supervisor()->create();
    ProjectMember::create([
        'project_id' => $project->id, 'user_id' => $supervisor->id, 'role' => ProjectRole::Lead,
    ]);

    $cr = ChangeRequest::create([
        'project_id' => $project->id, 'title' => 'طلب', 'status' => ChangeRequestStatus::Draft,
    ]);

    $this->actingAs($supervisor)
        ->postJson("/api/change-requests/{$cr->id}/send", ['price' => 1000])
        ->assertForbidden();
});

it('يجعل الشريك صاحب الاعتماد حين يكون على المشروع شريك وعميل', function () {
    ['project' => $project, 'client' => $client, 'stage0' => $stage, 'admin' => $admin] = scenario();

    $partner = User::factory()->partner()->create();
    ProjectMember::create([
        'project_id' => $project->id, 'user_id' => $partner->id, 'role' => ProjectRole::Partner,
    ]);

    $this->actingAs($admin)->postJson("/api/stages/{$stage->id}/submit")->assertOk();

    // العميل النهائي لم يعد صاحب الاعتماد — الشريك هو المتعاقد
    $this->actingAs($client)
        ->postJson("/api/stages/{$stage->id}/approve", [
            'approver_name' => 'عميل الاختبار', 'acknowledgement' => 'موافق',
        ])
        ->assertForbidden();

    $this->actingAs($partner)
        ->postJson("/api/stages/{$stage->id}/approve", [
            'approver_name' => 'مسؤول الوكالة الشريكة', 'acknowledgement' => 'موافق',
        ])
        ->assertOk();

    expect($stage->fresh()->status)->toBe(StageStatus::Locked);
});

// ---------------------------------------------------------------------------
// سجل التدقيق واعتمادات البوابات
// ---------------------------------------------------------------------------

it('لا يترك أي مسار لكتابة سجل تدقيق أو اعتماد بوابة من الطلب', function () {
    // GateApprovalPolicy و AuditLogPolicy ترفضان الإنشاء والتعديل والحذف
    // للجميع بلا استثناء — حتى الأدمن. الكتابة من الخدمات وحدها.
    $admin = User::factory()->admin()->create();

    expect($admin->can('create', GateApproval::class))->toBeFalse()
        ->and($admin->can('create', \App\Models\AuditLog::class))->toBeFalse();
});

it('يكتب الفاعل في سجل التدقيق من الجلسة لا من الطلب', function () {
    ['admin' => $admin, 'client' => $client, 'stage0' => $stage, 'project' => $project] = scenario();

    $this->actingAs($admin)->postJson("/api/stages/{$stage->id}/submit")->assertOk();

    $entry = $project->auditLogs()->first();

    expect($entry->actor_id)->toBe($admin->id)
        ->and($entry->actor_id)->not->toBe($client->id)
        ->and($entry->event_type)->toBe('stage_submitted');
});

// ---------------------------------------------------------------------------
// المحتوى وجولات الملاحظات
// ---------------------------------------------------------------------------

it('يمنع العميل من قبول محتواه بنفسه ويسمح بتقديمه', function () {
    ['client' => $client, 'admin' => $admin, 'project' => $project] = scenario();

    $item = ContentItem::create([
        'project_id' => $project->id, 'item_group' => 'blocking',
        'name' => 'نصوص الصفحة الرئيسية', 'status' => ContentStatus::Pending,
    ]);

    expect($client->can('review', $item))->toBeFalse()
        ->and($client->can('submit', $item))->toBeTrue()
        ->and($admin->can('review', $item))->toBeTrue();
});

it('يمنع العميل من القفز بجولة الملاحظات إلى التصنيف', function () {
    ['client' => $client, 'admin' => $admin, 'project' => $project] = scenario();

    $round = FeedbackRound::create([
        'project_id' => $project->id, 'round_number' => 1,
        'status' => FeedbackRoundStatus::Open, 'opened_at' => now(),
    ]);

    expect($client->can('classify', $round))->toBeFalse()
        ->and($client->can('submit', $round))->toBeTrue()
        ->and($admin->can('classify', $round))->toBeTrue();
});

it('يمنع غير العضو من الوصول لأي شيء في المشروع', function () {
    ['project' => $project, 'stage0' => $stage] = scenario();
    $outsider = User::factory()->create();

    $item = AccessItem::create(['project_id' => $project->id, 'name' => 'بيانات الاستضافة']);

    expect($outsider->can('view', $project))->toBeFalse()
        ->and($outsider->can('view', $stage))->toBeFalse()
        ->and($outsider->can('toggleDone', $item))->toBeFalse();
});

// ---------------------------------------------------------------------------
// كبح محاولات الدخول
// ---------------------------------------------------------------------------

it('يكبح محاولات الدخول المتكررة', function () {
    $user = User::factory()->create(['email' => 'client@test.local']);

    foreach (range(1, 8) as $i) {
        $this->postJson('/api/auth/login', [
            'email' => 'client@test.local', 'password' => 'كلمة-غلط',
        ])->assertStatus(422);
    }

    // التاسعة تُكبح — وحتى بكلمة المرور الصحيحة
    $this->postJson('/api/auth/login', [
        'email' => 'client@test.local', 'password' => 'password123',
    ])->assertStatus(429);
});
