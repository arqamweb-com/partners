<?php

/**
 * أرشفة المشاريع وحذفها النهائي.
 *
 * الفكرة المحمية هنا: الحذف فعل من طبقتين. الأولى — الأرشفة — تُخفي
 * المشروع من كل شاشة وتُبقي كل ما تحته، والثانية تمحوه فعلًا ولا تُتاح
 * إلا لمشروع مؤرشف. وسجل التدقيق يعيش الطبقة الأولى ويموت مع الثانية،
 * فما يبقى بعده هو اللوج.
 */

declare(strict_types=1);

use App\Enums\ProjectRole;
use App\Enums\StageStatus;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Stage;
use App\Models\Upload;
use App\Models\User;
use App\Notifications\ProjectApproved;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/** مشروع بأدمن ومالك عميل ومرحلة واحدة. */
function archiveScenario(): array
{
    $admin  = User::factory()->admin()->create();
    $client = User::factory()->create();

    $project = Project::create([
        'name'       => 'مشروع للأرشفة',
        'owner_id'   => $client->id,
        'owner_name' => $client->full_name,
    ]);

    ProjectMember::create([
        'project_id' => $project->id, 'user_id' => $admin->id, 'role' => ProjectRole::Lead,
    ]);
    ProjectMember::create([
        'project_id' => $project->id, 'user_id' => $client->id, 'role' => ProjectRole::Client,
    ]);

    Stage::create([
        'project_id' => $project->id, 'stage_index' => 0,
        'name'       => 'التصميم', 'status' => StageStatus::Pending,
    ]);

    return compact('admin', 'client', 'project');
}

it('يمنع كل من ليس أدمن من أرشفة مشروع', function () {
    ['project' => $project] = archiveScenario();

    $others = [
        User::factory()->manager()->create(),
        User::factory()->supervisor()->create(),
        $project->owner,
    ];

    foreach ($others as $user) {
        $this->actingAs($user)->deleteJson("/api/projects/{$project->id}")->assertStatus(403);
    }

    expect($project->fresh()->trashed())->toBeFalse();
});

it('يؤرشف الأدمن المشروع فيختفي من كل الشاشات ويبقى صفه', function () {
    ['admin' => $admin, 'client' => $client, 'project' => $project] = archiveScenario();

    $this->actingAs($admin)->deleteJson("/api/projects/{$project->id}")->assertOk();

    $trashed = Project::withTrashed()->find($project->id);

    expect($trashed)->not->toBeNull()
        ->and($trashed->trashed())->toBeTrue()
        ->and($trashed->deleted_by)->toBe($admin->id)
        // المراحل وسجل التدقيق باقيان: الأرشفة ليست محوًا
        ->and($trashed->stages()->count())->toBe(1)
        ->and($trashed->auditLogs()->where('event_type', 'project_archived')->exists())->toBeTrue();

    // ولا أثر له في أي مسار قراءة، لا للأدمن ولا لمالكه
    $this->actingAs($admin)->getJson('/api/projects')->assertOk()->assertJsonCount(0, 'data');
    $this->actingAs($admin)->getJson("/api/projects/{$project->id}")->assertStatus(404);
    $this->actingAs($admin)->getJson('/api/overview')->assertOk()->assertJsonCount(0, 'projects');
    $this->actingAs($client)->getJson('/api/projects')->assertOk()->assertJsonCount(0, 'data');
});

it('يرفع إشعارات المشروع معه، فلا يبقى إشعار على رابط ميت', function () {
    ['admin' => $admin, 'client' => $client, 'project' => $project] = archiveScenario();

    $client->notify(new ProjectApproved($project, $admin));

    // القناة تكتب معرّف المشروع في عمود مستقل لا داخل نص data
    expect(DB::table('notifications')->where('project_id', $project->id)->count())->toBe(1);

    $this->actingAs($admin)->deleteJson("/api/projects/{$project->id}")->assertOk();

    expect(DB::table('notifications')->where('project_id', $project->id)->count())->toBe(0)
        ->and($client->fresh()->unreadNotifications()->count())->toBe(0);
});

it('يعرض الأرشيف للأدمن وحده', function () {
    ['admin' => $admin, 'project' => $project] = archiveScenario();

    $this->actingAs($admin)->deleteJson("/api/projects/{$project->id}")->assertOk();

    $this->actingAs($admin)->getJson('/api/projects?archived=1')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $project->id);

    $this->actingAs(User::factory()->manager()->create())
        ->getJson('/api/projects?archived=1')
        ->assertStatus(403);
});

it('يعيد المشروع من الأرشيف كما كان', function () {
    ['admin' => $admin, 'project' => $project] = archiveScenario();

    $this->actingAs($admin)->deleteJson("/api/projects/{$project->id}")->assertOk();
    $this->actingAs($admin)->postJson("/api/projects/{$project->id}/restore")->assertOk();

    $restored = $project->fresh();

    expect($restored->trashed())->toBeFalse()
        ->and($restored->deleted_by)->toBeNull()
        ->and($restored->auditLogs()->where('event_type', 'project_restored')->exists())->toBeTrue();

    $this->actingAs($admin)->getJson('/api/projects')->assertOk()->assertJsonCount(1, 'data');
});

it('يقفل أفعال توابع المشروع المؤرشف بدل أن ينهار عليها', function () {
    ['admin' => $admin, 'client' => $client, 'project' => $project] = archiveScenario();

    $stage = $project->stages()->first();

    // مرفق رفعه غيره: وصول العميل إليه يمر بعضويته في المشروع
    $upload = Upload::create([
        'project_id'    => $project->id,
        'user_id'       => $admin->id,
        'original_name' => 'brief.pdf',
        'stored_path'   => 'projects/brief.pdf',
    ]);

    $this->actingAs($admin)->deleteJson("/api/projects/{$project->id}")->assertOk();

    // المراحل والمرفقات لا تُؤرشف مع مشروعها، فمعرّفها يبقى صالحًا —
    // والجواب يجب أن يكون منعًا صريحًا لا خطأ سيرفر
    $this->actingAs($admin)->postJson("/api/stages/{$stage->id}/submit")->assertStatus(403);
    $this->actingAs($client)->postJson("/api/stages/{$stage->id}/submit")->assertStatus(403);
    $this->actingAs($client)->getJson("/api/files/{$upload->id}")->assertStatus(403);

    // والمسارات المربوطة بالمشروع نفسه تختفي أصلًا
    $this->actingAs($admin)->postJson("/api/projects/{$project->id}/status", [
        'status' => 'frozen',
    ])->assertStatus(404);
});

it('يرفض الحذف النهائي لمشروع لم يُؤرشف', function () {
    ['admin' => $admin, 'project' => $project] = archiveScenario();

    $this->actingAs($admin)->deleteJson("/api/projects/{$project->id}/purge")->assertStatus(403);

    expect(Project::withTrashed()->find($project->id))->not->toBeNull();
});

it('يمحو الحذف النهائي المشروع وتوابعه وملفاته من القرص', function () {
    Storage::fake('private');

    ['admin' => $admin, 'project' => $project] = archiveScenario();

    Storage::disk('private')->put('projects/brief.pdf', 'x');

    Upload::create([
        'project_id'    => $project->id,
        'user_id'       => $admin->id,
        'original_name' => 'brief.pdf',
        'stored_path'   => 'projects/brief.pdf',
    ]);

    $this->actingAs($admin)->deleteJson("/api/projects/{$project->id}")->assertOk();
    $this->actingAs($admin)->deleteJson("/api/projects/{$project->id}/purge")->assertOk();

    expect(Project::withTrashed()->find($project->id))->toBeNull()
        ->and(DB::table('stages')->where('project_id', $project->id)->count())->toBe(0)
        ->and(DB::table('audit_logs')->where('project_id', $project->id)->count())->toBe(0)
        ->and(DB::table('uploads')->where('project_id', $project->id)->count())->toBe(0);

    Storage::disk('private')->assertMissing('projects/brief.pdf');
});
