<?php

/**
 * موعد استحقاق المرحلة، وتراكم أيام تأخير العميل.
 *
 * الفكرتان المحميّتان هنا:
 *
 * ١) العدّاد يخصّ الطرف الذي بيده الكرة لا المرحلة. كل انتقال للكرة يبدأ
 *    عدّادًا جديدًا بمدّة صاحبه، ولا يُكمل ما تبقّى من عدّاد سابقه.
 *
 * ٢) التأخير زمن لا قائمة مهام: يوم واحد عن اليوم مهما تعدّدت المراحل
 *    المتأخرة، ولا يُحتسب مرتين إن شُغّل الأمر مرتين.
 */

declare(strict_types=1);

use App\Enums\ProjectRole;
use App\Enums\ProjectStatus;
use App\Enums\Side;
use App\Enums\StageStatus;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Stage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/**
 * الثلاثاء ١٨ أغسطس ٢٠٢٦، تاسعة صباحًا.
 * الجمعة والسبت إجازة، فمن الثلاثاء: +1 أربعاء، +2 خميس، +3 الأحد.
 */
function atTuesday(): Carbon
{
    $now = Carbon::parse('2026-08-18 09:00:00');
    Carbon::setTestNow($now);

    return $now;
}

/** مشروع بمرحلة واحدة جارية عند فريق أرقام، بمدّتين معلومتين. */
function deadlineScenario(int $ours = 3, int $theirs = 2): array
{
    $admin  = User::factory()->admin()->create();
    $client = User::factory()->create();

    $project = Project::create([
        'name'                   => 'مشروع المواعيد',
        'owner_id'               => $admin->id,
        'owner_name'             => $admin->full_name,
        'status'                 => ProjectStatus::Active,
        'original_delivery_date' => '2026-09-01',
        'adjusted_delivery_date' => '2026-09-01',
    ]);

    ProjectMember::create([
        'project_id' => $project->id, 'user_id' => $admin->id, 'role' => ProjectRole::Lead,
    ]);
    ProjectMember::create([
        'project_id' => $project->id, 'user_id' => $client->id, 'role' => ProjectRole::Client,
    ]);

    $stage = Stage::create([
        'project_id'          => $project->id,
        'stage_index'         => 0,
        'name'                => 'التصميم',
        'status'              => StageStatus::Active,
        'ball_in_court'       => Side::Us,
        'our_duration_days'   => $ours,
        'their_duration_days' => $theirs,
        'started_at'          => now(),
        'due_at'              => now()->copy()->addDays(1),
    ]);

    return compact('admin', 'client', 'project', 'stage');
}

afterEach(fn () => Carbon::setTestNow());

// ---------------------------------------------------------------------------
// موعد الاستحقاق
// ---------------------------------------------------------------------------

it('يبدأ عدّاد الطرف الآخر بمدّته هو عند التقديم', function () {
    atTuesday();
    ['admin' => $admin, 'stage' => $stage] = deadlineScenario(ours: 3, theirs: 2);

    $this->actingAs($admin)->postJson("/api/stages/{$stage->id}/submit")->assertOk();

    // الكرة انتقلت للعميل، فالموعد = الثلاثاء + يومَي عمل = الخميس
    expect($stage->fresh()->due_at->toDateString())->toBe('2026-08-20');
});

it('يعيد العدّاد كاملًا لصاحب المرحلة عند الرفض', function () {
    atTuesday();
    ['admin' => $admin, 'client' => $client, 'stage' => $stage] = deadlineScenario(ours: 3, theirs: 2);

    $this->actingAs($admin)->postJson("/api/stages/{$stage->id}/submit")->assertOk();

    // العميل يرفض بعد يومين — الكرة ترجع لأرقام بمدّتنا كاملة لا بما تبقّى
    Carbon::setTestNow(Carbon::parse('2026-08-20 09:00:00'));

    $this->actingAs($client)
        ->postJson("/api/stages/{$stage->id}/reject", ['reason' => 'الألوان بعيدة عن الهوية'])
        ->assertOk();

    // الخميس + ٣ أيام عمل = الأحد، الاثنين، الثلاثاء
    expect($stage->fresh()->due_at->toDateString())->toBe('2026-08-25');
});

it('يعطي المرحلة التالية موعدها حين تبدأ', function () {
    atTuesday();
    ['admin' => $admin, 'client' => $client, 'project' => $project, 'stage' => $stage]
        = deadlineScenario();

    $next = Stage::create([
        'project_id'          => $project->id,
        'stage_index'         => 1,
        'name'                => 'التطوير',
        'status'              => StageStatus::Pending,
        'ball_in_court'       => Side::Us,
        'our_duration_days'   => 5,
        'their_duration_days' => 2,
    ]);

    expect($next->due_at)->toBeNull();

    $this->actingAs($admin)->postJson("/api/stages/{$stage->id}/submit")->assertOk();
    $this->actingAs($client)->postJson("/api/stages/{$stage->id}/approve", [
        'approver_name'        => 'عميل الاختبار',
        'acknowledgement_text' => 'اعتماد',
    ])->assertOk();

    // الثلاثاء + ٥ أيام عمل: أربعاء، خميس، أحد، اثنين، ثلاثاء
    expect($next->fresh()->status)->toBe(StageStatus::Active)
        ->and($next->fresh()->due_at->toDateString())->toBe('2026-08-25');
});

it('يترك المرحلة بلا موعد حين لا مدّة لها', function () {
    atTuesday();
    ['admin' => $admin, 'stage' => $stage] = deadlineScenario(ours: 3, theirs: 0);

    // مدّة الطرف الآخر صفر — الضمان مثلًا: التزام بلا سقف زمني
    $this->actingAs($admin)->postJson("/api/stages/{$stage->id}/submit")->assertOk();

    expect($stage->fresh()->due_at)->toBeNull();
});

it('يبذر المشروع بمواعيد محسوبة لا بأعمدة فارغة', function () {
    atTuesday();

    $admin  = User::factory()->admin()->create();
    $client = User::factory()->create();

    $project = Project::create([
        'name'         => 'مشروع البذر',
        'project_type' => 'brochure',
        'owner_id'     => $admin->id,
        'owner_name'   => $admin->full_name,
        'status'       => ProjectStatus::Draft,
    ]);
    ProjectMember::create([
        'project_id' => $project->id, 'user_id' => $admin->id, 'role' => ProjectRole::Lead,
    ]);
    ProjectMember::create([
        'project_id' => $project->id, 'user_id' => $client->id, 'role' => ProjectRole::Client,
    ]);

    $this->actingAs($admin)->postJson("/api/projects/{$project->id}/approve")->assertOk();

    $first    = $project->stages()->where('stage_index', 0)->first();
    $parallel = $project->stages()->where('is_parallel', true)->first();
    $later    = $project->stages()->where('stage_index', 1)->first();

    expect($first->due_at)->not->toBeNull()
        ->and($parallel->due_at)->not->toBeNull()
        // ما لم يبدأ لا موعد له: موعد مرحلة لم يأتِ دورها تخمين
        ->and($later->due_at)->toBeNull();
});

// ---------------------------------------------------------------------------
// تراكم تأخير العميل
// ---------------------------------------------------------------------------

it('يزيد يوم عمل عن كل يوم تأخر فيه العميل', function () {
    atTuesday();
    ['project' => $project, 'stage' => $stage] = deadlineScenario();

    // الكرة عند العميل ومستحقة الثلاثاء
    $stage->update(['ball_in_court' => Side::Them, 'due_at' => now()]);

    // الأحد التالي: مرّ أربعاء وخميس وأحد = ٣ أيام عمل
    Carbon::setTestNow(Carbon::parse('2026-08-23 09:00:00'));
    $this->artisan('arqam:accrue-client-delay')->assertSuccessful();

    expect($project->fresh()->client_delay_days)->toBe(3)
        // التسليم المعدّل مشتقّ من العدّاد فلا يُترك خلفه
        ->and($project->fresh()->adjusted_delivery_date->toDateString())->toBe('2026-09-06');
});

it('لا يحتسب التأخير مرتين إن شُغّل الأمر مرتين', function () {
    atTuesday();
    ['project' => $project, 'stage' => $stage] = deadlineScenario();

    $stage->update(['ball_in_court' => Side::Them, 'due_at' => now()]);

    Carbon::setTestNow(Carbon::parse('2026-08-23 09:00:00'));
    $this->artisan('arqam:accrue-client-delay')->assertSuccessful();
    $this->artisan('arqam:accrue-client-delay')->assertSuccessful();

    expect($project->fresh()->client_delay_days)->toBe(3);
});

it('يحتسب يومًا واحدًا مهما تعدّدت المراحل المتأخرة', function () {
    atTuesday();
    ['project' => $project, 'stage' => $stage] = deadlineScenario();

    $stage->update(['ball_in_court' => Side::Them, 'due_at' => now()]);

    Stage::create([
        'project_id'          => $project->id,
        'stage_index'         => 100,
        'is_parallel'         => true,
        'name'                => 'الوصول والحسابات',
        'status'              => StageStatus::Active,
        'ball_in_court'       => Side::Them,
        'our_duration_days'   => 0,
        'their_duration_days' => 10,
        'started_at'          => now(),
        'due_at'              => now(),
    ]);

    Carbon::setTestNow(Carbon::parse('2026-08-23 09:00:00'));
    $this->artisan('arqam:accrue-client-delay')->assertSuccessful();

    expect($project->fresh()->client_delay_days)->toBe(3);
});

it('لا يحمّل العميل تأخيرًا والكرة عندنا', function () {
    atTuesday();
    ['project' => $project, 'stage' => $stage] = deadlineScenario();

    // متأخرة فعلًا، لكن الدور على فريق أرقام
    $stage->update(['ball_in_court' => Side::Us, 'due_at' => now()]);

    Carbon::setTestNow(Carbon::parse('2026-08-23 09:00:00'));
    $this->artisan('arqam:accrue-client-delay')->assertSuccessful();

    expect($project->fresh()->client_delay_days)->toBe(0);
});

it('يوقف العدّاد على المشروع المجمّد', function () {
    atTuesday();
    ['project' => $project, 'stage' => $stage] = deadlineScenario();

    $stage->update(['ball_in_court' => Side::Them, 'due_at' => now()]);
    $project->update(['status' => ProjectStatus::Frozen]);

    Carbon::setTestNow(Carbon::parse('2026-08-23 09:00:00'));
    $this->artisan('arqam:accrue-client-delay')->assertSuccessful();

    expect($project->fresh()->client_delay_days)->toBe(0);
});

it('لا يكتب شيئًا في التشغيل التجريبي', function () {
    atTuesday();
    ['project' => $project, 'stage' => $stage] = deadlineScenario();

    $stage->update(['ball_in_court' => Side::Them, 'due_at' => now()]);

    Carbon::setTestNow(Carbon::parse('2026-08-23 09:00:00'));
    $this->artisan('arqam:accrue-client-delay', ['--dry-run' => true])->assertSuccessful();

    expect($project->fresh()->client_delay_days)->toBe(0)
        ->and($project->fresh()->delay_accrued_at)->toBeNull();
});
