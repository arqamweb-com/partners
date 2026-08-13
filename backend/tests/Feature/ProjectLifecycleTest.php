<?php

/**
 * دورة حياة المشروع كاملة: طلب العميل ← اعتماد وبذر ← المحتوى ← الملاحظات
 * ← طلب تغيير ← الملفات، مع فحص من يملك كل خطوة.
 */

declare(strict_types=1);

use App\Enums\ContentStatus;
use App\Enums\FeedbackRoundStatus;
use App\Enums\ProjectRole;
use App\Enums\ProjectStatus;
use App\Enums\Side;
use App\Enums\StageStatus;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/** أدمن + عميل، والعميل عضو في مشروع الأدمن. */
function team(): array
{
    return [
        'admin'  => User::factory()->admin()->create(),
        'client' => User::factory()->create(['full_name' => 'عميل الاختبار']),
    ];
}

function projectFor(User $admin, User $client, array $attrs = []): Project
{
    $project = Project::create([
        'name'         => 'مشروع اختبار',
        'project_type' => 'brochure',
        'owner_id'     => $admin->id,
        'owner_name'   => $admin->full_name,
        ...$attrs,
    ]);

    ProjectMember::create(['project_id' => $project->id, 'user_id' => $admin->id, 'role' => ProjectRole::Lead]);
    ProjectMember::create(['project_id' => $project->id, 'user_id' => $client->id, 'role' => ProjectRole::Client]);

    return $project;
}

// ---------------------------------------------------------------------------
// الطلب والاعتماد والبذر
// ---------------------------------------------------------------------------

it('يسجّل طلب العميل مسودة بلا مدد ولا تسعير', function () {
    $client = User::factory()->create();

    $response = $this->actingAs($client)
        ->postJson('/api/projects', [
            'name'         => 'موقع شركتي',
            'project_type' => 'brochure',
            'scope'        => 'موقع تعريفي بخمس صفحات',
            // محاولة تمرير بنود تعاقدية مع الطلب
            'original_delivery_date' => '2026-01-01',
            'warranty_days'          => 999,
        ])
        ->assertCreated();

    $project = Project::find($response->json('data.id'));

    expect($project->status)->toBe(ProjectStatus::Draft)
        ->and($project->original_delivery_date)->toBeNull()   // لم تُقبل من الطلب
        ->and($project->warranty_days)->toBe(14)              // القيمة الافتراضية
        ->and($project->owner_id)->toBe($client->id);
});

it('يمنع العميل من كتابة البنود التعاقدية', function () {
    ['admin' => $admin, 'client' => $client] = team();
    $project = projectFor($admin, $client, ['status' => ProjectStatus::Draft]);

    $this->actingAs($client)
        ->patchJson("/api/projects/{$project->id}/charter", ['warranty_days' => 999])
        ->assertForbidden();
});

it('يبذر المشروع من القالب في معاملة واحدة عند الاعتماد', function () {
    ['admin' => $admin, 'client' => $client] = team();
    $project = projectFor($admin, $client, [
        'status'       => ProjectStatus::Draft,
        'project_type' => 'woocommerce',
        'type_details' => ['products' => 200],
    ]);

    $this->actingAs($admin)
        ->postJson("/api/projects/{$project->id}/approve")
        ->assertOk();

    $project->refresh();

    // 9 مراحل من القالب + مسار الوصول المتوازي
    expect($project->status)->toBe(ProjectStatus::Active)
        ->and($project->stages()->where('is_parallel', false)->count())->toBe(9)
        ->and($project->stages()->where('is_parallel', true)->count())->toBe(1)
        ->and($project->accessItems()->count())->toBeGreaterThan(0)
        ->and($project->contentItems()->count())->toBeGreaterThan(0)
        ->and($project->original_delivery_date)->not->toBeNull();

    // الأولى نشطة والكرة عند أرقام، والباقي منتظر
    $first = $project->stages()->where('stage_index', 0)->first();
    expect($first->status)->toBe(StageStatus::Active)
        ->and($first->ball_in_court)->toBe(Side::Us);
});

it('يرفض بذر مشروع مبذور من قبل', function () {
    ['admin' => $admin, 'client' => $client] = team();
    $project = projectFor($admin, $client, ['status' => ProjectStatus::Draft]);

    $this->actingAs($admin)->postJson("/api/projects/{$project->id}/approve")->assertOk();
    $this->actingAs($admin)->postJson("/api/projects/{$project->id}/approve")->assertStatus(422);

    expect($project->stages()->count())->toBe(9);   // 8 + المتوازي، بلا تضاعف
});

it('يمنع العميل من اعتماد طلبه بنفسه', function () {
    ['admin' => $admin, 'client' => $client] = team();
    $project = projectFor($admin, $client, ['status' => ProjectStatus::Draft]);

    $this->actingAs($client)
        ->postJson("/api/projects/{$project->id}/approve")
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// المحتوى
// ---------------------------------------------------------------------------

it('يفصل تقديم المحتوى عن مراجعته', function () {
    ['admin' => $admin, 'client' => $client] = team();
    $project = projectFor($admin, $client, ['status' => ProjectStatus::Draft]);
    $this->actingAs($admin)->postJson("/api/projects/{$project->id}/approve");

    $item = $project->contentItems()->first();

    // العميل يقدّم
    $this->actingAs($client)
        ->postJson("/api/content-items/{$item->id}/submit", ['value' => 'النص المطلوب'])
        ->assertOk();

    $item->refresh();
    expect($item->status)->toBe(ContentStatus::Submitted)
        ->and($item->submitted_by)->toBe($client->id);

    // ولا يراجع
    $this->actingAs($client)
        ->postJson("/api/content-items/{$item->id}/review", ['accept' => true])
        ->assertForbidden();

    // فريق أرقام يراجع
    $this->actingAs($admin)
        ->postJson("/api/content-items/{$item->id}/review", ['accept' => true])
        ->assertOk();

    expect($item->fresh()->status)->toBe(ContentStatus::Accepted);
});

it('يحفظ تاريخ التقديم الأصلي عند إعادة التقديم', function () {
    ['admin' => $admin, 'client' => $client] = team();
    $project = projectFor($admin, $client, ['status' => ProjectStatus::Draft]);
    $this->actingAs($admin)->postJson("/api/projects/{$project->id}/approve");

    $item = $project->contentItems()->first();

    $this->actingAs($client)->postJson("/api/content-items/{$item->id}/submit", ['value' => 'أول']);
    $firstSubmission = $item->fresh()->submitted_at;

    $this->actingAs($admin)->postJson("/api/content-items/{$item->id}/review",
        ['accept' => false, 'reason' => 'ناقص تفاصيل']);

    $this->travel(2)->days();
    $this->actingAs($client)->postJson("/api/content-items/{$item->id}/submit", ['value' => 'تاني']);

    // التأخير يُحسب من التقديم الأصلي، فلا يتصفّر بإعادة التقديم
    expect($item->fresh()->submitted_at->timestamp)->toBe($firstSubmission->timestamp);
});

it('يقبل المحتوى تلقائيًا بعد تجاوز مهلة المراجعة', function () {
    ['admin' => $admin, 'client' => $client] = team();
    $project = projectFor($admin, $client, ['status' => ProjectStatus::Draft]);
    $this->actingAs($admin)->postJson("/api/projects/{$project->id}/approve");

    $item = $project->contentItems()->first();
    $this->actingAs($client)->postJson("/api/content-items/{$item->id}/submit", ['value' => 'نص']);

    $this->travel(5)->days();
    $this->artisan('arqam:auto-accept')->assertSuccessful();

    $item->refresh();
    expect($item->status)->toBe(ContentStatus::Accepted)
        ->and($item->auto_accepted)->toBeTrue();

    // ويُسجَّل باسم النظام لا باسم موظف
    expect($project->auditLogs()->where('event_type', 'content_auto_accepted')->first()->actor_name)
        ->toBe('النظام');
});

// ---------------------------------------------------------------------------
// الملاحظات
// ---------------------------------------------------------------------------

it('يمشّي جولة الملاحظات في اتجاه واحد', function () {
    ['admin' => $admin, 'client' => $client] = team();
    $project = projectFor($admin, $client);

    $round = $this->actingAs($admin)
        ->postJson("/api/projects/{$project->id}/feedback", [])
        ->assertCreated()->json('data.id');

    // جولة بلا ملاحظات لا تُرسل
    $this->actingAs($client)->postJson("/api/feedback-rounds/{$round}/submit")->assertStatus(422);

    $this->actingAs($client)
        ->postJson("/api/feedback-rounds/{$round}/items",
            ['description' => 'الخط صغير في الصفحة الرئيسية'])
        ->assertCreated();

    $this->actingAs($client)->postJson("/api/feedback-rounds/{$round}/submit")->assertOk();

    // بعد الإرسال: النافذة مقفولة والتصنيف لأرقام
    $this->actingAs($client)
        ->postJson("/api/feedback-rounds/{$round}/items", ['description' => 'ملاحظة متأخرة'])
        ->assertForbidden();

    $this->actingAs($client)
        ->postJson("/api/feedback-rounds/{$round}/classify", ['status' => 'classified'])
        ->assertForbidden();

    $this->actingAs($admin)
        ->postJson("/api/feedback-rounds/{$round}/classify", ['status' => 'classified'])
        ->assertOk();

    expect(\App\Models\FeedbackRound::find($round)->status)
        ->toBe(FeedbackRoundStatus::Classified);
});

// ---------------------------------------------------------------------------
// الملفات
// ---------------------------------------------------------------------------

it('يمنع تنزيل ملف مشروع لست عضوًا فيه', function () {
    Storage::fake('private');
    ['admin' => $admin, 'client' => $client] = team();
    $project = projectFor($admin, $client);
    $outsider = User::factory()->create();

    $fileId = $this->actingAs($client)
        ->postJson('/api/files', ['file' => UploadedFile::fake()->image('logo.png')])
        ->assertCreated()->json('data.id');

    $this->actingAs($client)
        ->postJson("/api/projects/{$project->id}/files/claim", ['file_ids' => [$fileId]])
        ->assertOk();

    $this->actingAs($outsider)->get("/api/files/{$fileId}")->assertForbidden();
    $this->actingAs($admin)->get("/api/files/{$fileId}")->assertOk();
});

it('يرفض الصيغ غير المسموحة', function () {
    Storage::fake('private');
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/files', ['file' => UploadedFile::fake()->create('shell.php', 10)])
        ->assertStatus(422);
});

it('يمنع سحب ملف رفعه غيرك إلى مشروعك', function () {
    Storage::fake('private');
    ['admin' => $admin, 'client' => $client] = team();
    $project = projectFor($admin, $client);
    $stranger = User::factory()->create();

    $fileId = $this->actingAs($stranger)
        ->postJson('/api/files', ['file' => UploadedFile::fake()->image('x.png')])
        ->json('data.id');

    $claimed = $this->actingAs($client)
        ->postJson("/api/projects/{$project->id}/files/claim", ['file_ids' => [$fileId]])
        ->assertOk()->json('data.claimed');

    expect($claimed)->toBe(0);
});

// ---------------------------------------------------------------------------
// الأعضاء والدعوات
// ---------------------------------------------------------------------------

it('يربط الدعوة تلقائيًا عند التسجيل بنفس البريد', function () {
    ['admin' => $admin, 'client' => $client] = team();
    $project = projectFor($admin, $client);

    $this->actingAs($admin)
        ->postJson("/api/projects/{$project->id}/members",
            ['email' => 'new@client.test', 'role' => 'client'])
        ->assertCreated();

    expect($project->members()->whereNull('user_id')->count())->toBe(1);

    // الدعوة تُطالَب عند التسجيل، والمسجِّل زائر لا مستخدم قائم
    auth()->logout();

    $this->postJson('/api/auth/register', [
        'email' => 'new@client.test', 'password' => 'password123', 'full_name' => 'عميل جديد',
    ])->assertCreated();

    $member = $project->members()->where('invited_email', 'new@client.test')->first();
    expect($member->user_id)->not->toBeNull()
        ->and($member->claimed_at)->not->toBeNull();
});

it('يمنع المدير من إسناد أدوار تنفيذية بلا صلاحية التسعير', function () {
    ['admin' => $admin, 'client' => $client] = team();
    $project = projectFor($admin, $client);

    $supervisor = User::factory()->supervisor()->create();
    ProjectMember::create([
        'project_id' => $project->id, 'user_id' => $supervisor->id, 'role' => ProjectRole::Lead,
    ]);

    // المشرف يدير الدعوات العادية...
    $this->actingAs($supervisor)
        ->postJson("/api/projects/{$project->id}/members",
            ['email' => 'x@client.test', 'role' => 'client'])
        ->assertCreated();

    // ...ولا يُسند أدوارًا تنفيذية
    $this->actingAs($supervisor)
        ->postJson("/api/projects/{$project->id}/members",
            ['email' => 'y@arqam.test', 'role' => 'lead'])
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// الإعدادات
// ---------------------------------------------------------------------------

it('يقصر إعدادات النظام على الأدمن ويبطل الحساب المخزَّن للأجازات', function () {
    ['admin' => $admin] = team();
    $manager = User::factory()->manager()->create();

    $this->actingAs($manager)->patchJson('/api/settings', ['warranty_days' => 30])->assertForbidden();
    $this->actingAs($admin)->patchJson('/api/settings', ['warranty_days' => 30])->assertOk();

    $this->actingAs($admin)
        ->postJson('/api/settings/holidays', ['holiday_date' => '2026-09-23', 'label' => 'اليوم الوطني'])
        ->assertCreated();

    // الأجازة الجديدة تدخل الحساب فورًا
    expect(app(\App\Services\BusinessDays::class)->holidays())->toContain('2026-09-23');
});
