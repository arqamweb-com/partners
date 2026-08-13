<?php

/**
 * توجيه الإشعارات وظهورها في الجرس.
 */

declare(strict_types=1);

use App\Enums\ProjectRole;
use App\Enums\Side;
use App\Enums\StageStatus;
use App\Models\NotificationPreference;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Stage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** مشروع بأدمن وعميل ومرحلة نشطة عند فريق أرقام. */
function bellScenario(): array
{
    $admin = User::factory()->admin()->create();
    $client = User::factory()->create(['full_name' => 'عميل الاختبار']);

    $project = Project::create([
        'name' => 'مشروع الإشعارات', 'owner_id' => $admin->id, 'owner_name' => $admin->full_name,
    ]);

    ProjectMember::create(['project_id' => $project->id, 'user_id' => $admin->id, 'role' => ProjectRole::Lead]);
    ProjectMember::create(['project_id' => $project->id, 'user_id' => $client->id, 'role' => ProjectRole::Client]);

    $stage = Stage::create([
        'project_id' => $project->id, 'stage_index' => 0, 'name' => 'التصميم',
        'status' => StageStatus::Active, 'ball_in_court' => Side::Us, 'started_at' => now(),
    ]);

    return compact('admin', 'client', 'project', 'stage');
}

it('يكتب إشعار التطبيق فورًا بلا عامل طابور', function () {
    ['admin' => $admin, 'client' => $client, 'stage' => $stage] = bellScenario();

    // بلا queue:work — الإشعار داخل التطبيق يجب أن يظهر مع ذلك
    $this->actingAs($admin)->postJson("/api/stages/{$stage->id}/submit")->assertOk();

    expect($client->fresh()->unreadNotifications()->count())->toBe(1);
});

it('يوجّه الإشعار لجهة المراجعة لا لكل الأعضاء', function () {
    ['admin' => $admin, 'client' => $client, 'stage' => $stage] = bellScenario();

    $this->actingAs($admin)->postJson("/api/stages/{$stage->id}/submit")->assertOk();

    // المقدِّم لا يُشعَر بتقديمه هو
    expect($client->fresh()->notifications()->count())->toBe(1)
        ->and($admin->fresh()->notifications()->count())->toBe(0);
});

it('يعيد الإشعارات وعدد غير المقروء للجرس', function () {
    ['admin' => $admin, 'client' => $client, 'stage' => $stage] = bellScenario();

    $this->actingAs($admin)->postJson("/api/stages/{$stage->id}/submit");

    $response = $this->actingAs($client)->getJson('/api/notifications')->assertOk();

    expect($response->json('unread'))->toBe(1);

    $first = $response->json('data.0.data');
    expect($first['title'])->toContain('التصميم')
        ->and($first['url'])->toBe("/projects/{$stage->project_id}")
        ->and($first['event_key'])->toBe('stage.submitted');
});

it('يعلّم الكل مقروءًا', function () {
    ['admin' => $admin, 'client' => $client, 'stage' => $stage] = bellScenario();

    $this->actingAs($admin)->postJson("/api/stages/{$stage->id}/submit");

    $this->actingAs($client)->postJson('/api/notifications/read')->assertOk();

    expect($client->fresh()->unreadNotifications()->count())->toBe(0)
        ->and($client->fresh()->notifications()->count())->toBe(1);
});

it('يحترم إسكات المستخدم لنوع حدث', function () {
    ['admin' => $admin, 'client' => $client, 'stage' => $stage] = bellScenario();

    NotificationPreference::create([
        'user_id'   => $client->id,
        'event_key' => 'stage.submitted',
        'in_app'    => false,
        'email'     => false,
    ]);

    $this->actingAs($admin)->postJson("/api/stages/{$stage->id}/submit")->assertOk();

    expect($client->fresh()->notifications()->count())->toBe(0);
});

it('لا يرى المستخدم إشعارات غيره', function () {
    ['admin' => $admin, 'client' => $client, 'stage' => $stage] = bellScenario();
    $outsider = User::factory()->create();

    $this->actingAs($admin)->postJson("/api/stages/{$stage->id}/submit");

    $this->actingAs($outsider)->getJson('/api/notifications')
        ->assertOk()
        ->assertJsonPath('unread', 0)
        ->assertJsonCount(0, 'data');

    expect($client->fresh()->notifications()->count())->toBe(1);
});

it('يشعر الجهة الأخرى عند الاعتماد والرفض', function () {
    ['admin' => $admin, 'client' => $client, 'stage' => $stage] = bellScenario();

    $this->actingAs($admin)->postJson("/api/stages/{$stage->id}/submit");

    // العميل يرفض → يرجع الإشعار لفريق أرقام
    $this->actingAs($client)
        ->postJson("/api/stages/{$stage->id}/reject", ['reason' => 'الألوان مش مطابقة للهوية'])
        ->assertOk();

    $adminNotice = $admin->fresh()->notifications()->first();

    expect($adminNotice)->not->toBeNull()
        ->and($adminNotice->data['event_key'])->toBe('stage.rejected')
        ->and($adminNotice->data['body'])->toContain('الألوان مش مطابقة للهوية');
});

// ---------------------------------------------------------------------------
// طلب مشروع جديد — الحالة التي لا تغطيها العضوية
// ---------------------------------------------------------------------------

it('يُشعر الأدمن والمدير بطلب مشروع جديد', function () {
    $admin = User::factory()->admin()->create();
    $manager = User::factory()->manager()->create();
    $supervisor = User::factory()->supervisor()->create();
    $client = User::factory()->create(['full_name' => 'عميل جديد']);

    $this->actingAs($client)
        ->postJson('/api/projects', [
            'name' => 'موقع شركتي', 'project_type' => 'brochure', 'scope' => 'خمس صفحات',
        ])
        ->assertCreated();

    // من يملك المراجعة والتسعير يُشعَر؛ المشرف لا يسعّر فلا يُشعَر
    expect($admin->fresh()->unreadNotifications()->count())->toBe(1)
        ->and($manager->fresh()->unreadNotifications()->count())->toBe(1)
        ->and($supervisor->fresh()->notifications()->count())->toBe(0)
        ->and($client->fresh()->notifications()->count())->toBe(0);

    $notice = $admin->fresh()->notifications()->first();
    expect($notice->data['event_key'])->toBe('project.requested')
        ->and($notice->data['title'])->toContain('موقع شركتي')
        ->and($notice->data['body'])->toContain('عميل جديد');
});

it('لا يُشعر بطلب حين ينشئ فريق أرقام المشروع بنفسه', function () {
    $admin = User::factory()->admin()->create();
    $other = User::factory()->manager()->create();

    // مشروع الفريق يبدأ نشطًا لا مسودة، فلا شيء ينتظر المراجعة
    $this->actingAs($admin)
        ->postJson('/api/projects', ['name' => 'مشروع داخلي', 'project_type' => 'brochure'])
        ->assertCreated();

    expect($other->fresh()->notifications()->count())->toBe(0);
});

it('يجعل من اعتمد الطلب عضوًا، فتصل إشعارات المراحل بعدها', function () {
    $admin = User::factory()->admin()->create();
    $client = User::factory()->create();

    $id = $this->actingAs($client)
        ->postJson('/api/projects', ['name' => 'مشروع العميل', 'project_type' => 'brochure'])
        ->json('data.id');

    $project = Project::find($id);
    expect($project->members()->count())->toBe(1);   // العميل وحده

    $this->actingAs($admin)->postJson("/api/projects/{$id}/approve")->assertOk();

    // صار المعتمِد مسؤول التنفيذ
    $lead = $project->fresh()->members()->where('user_id', $admin->id)->first();
    expect($lead)->not->toBeNull()
        ->and($lead->role)->toBe(ProjectRole::Lead);

    // ولذلك يصله إشعار حين يقدّم العميل مرحلته
    $parallel = $project->stages()->where('is_parallel', true)->first();
    $this->actingAs($client)->postJson("/api/stages/{$parallel->id}/submit")->assertOk();

    expect($admin->fresh()->unreadNotifications()->count())->toBeGreaterThan(0);
});

it('لا يُسقط إشعار جهة أرقام حين لا يكون لها عضو بعد', function () {
    $admin = User::factory()->admin()->create();
    $client = User::factory()->create();

    $project = Project::create([
        'name' => 'مشروع بلا مسؤول', 'owner_id' => $client->id, 'owner_name' => 'عميل',
    ]);
    ProjectMember::create([
        'project_id' => $project->id, 'user_id' => $client->id, 'role' => ProjectRole::Client,
    ]);

    $stage = Stage::create([
        'project_id' => $project->id, 'stage_index' => 0, 'name' => 'المحتوى',
        'status' => StageStatus::Active, 'ball_in_court' => Side::Them, 'started_at' => now(),
    ]);

    $this->actingAs($client)->postJson("/api/stages/{$stage->id}/submit")->assertOk();

    // لا عضو من الفريق على المشروع، فيسقط الإشعار على من يملك المراجعة
    expect($admin->fresh()->unreadNotifications()->count())->toBe(1);
});
