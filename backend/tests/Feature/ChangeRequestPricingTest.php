<?php

/**
 * تسعير طلب التغيير ومن يملك القرار عليه.
 *
 * الفكرة المحميّة: الدورة طرفان لا طرف واحد بصلاحيات أكثر. أرقام تسعّر
 * وترسل ولا تعتمد، والعميل يعتمد ولا يسعّر. وحين يُمنع أحدهما يُقال له
 * السبب بالعربية — لا «This action is unauthorized».
 */

declare(strict_types=1);

use App\Enums\ChangeRequestStatus;
use App\Enums\ProjectRole;
use App\Enums\ProjectStatus;
use App\Models\ChangeRequest;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** مشروع نشط، ومعه طلب تغيير سجّله العميل بلا سعر. */
function pricingScenario(): array
{
    $admin  = User::factory()->admin()->create();
    $client = User::factory()->create();

    $project = Project::create([
        'name'                   => 'مشروع التسعير',
        'owner_id'               => $client->id,
        'owner_name'             => $client->full_name,
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

    return compact('admin', 'client', 'project');
}

it('يسجّل العميل طلبه بلا سعر ثم يسعّره فريق أرقام ويرسله', function () {
    ['admin' => $admin, 'client' => $client, 'project' => $project] = pricingScenario();

    $cr = $this->actingAs($client)
        ->postJson("/api/projects/{$project->id}/change-requests", [
            'title'       => 'إضافة صفحة تواصل',
            'description' => 'محتاج صفحة تواصل بخريطة.',
        ])
        ->assertStatus(201)
        ->json('data');

    // العميل لا يسعّر لنفسه ولو حاول
    expect((float) $cr['price'])->toBe(0.0)
        ->and($cr['status'])->toBe(ChangeRequestStatus::Draft->value);

    $this->actingAs($admin)
        ->postJson("/api/change-requests/{$cr['id']}/send", [
            'price'                => 1500,
            'duration_days'        => 3,
            'delivery_impact_days' => 2,
        ])
        ->assertOk()
        ->assertJsonPath('data.status', ChangeRequestStatus::Sent->value);

    $fresh = ChangeRequest::find($cr['id']);

    expect((float) $fresh->price)->toBe(1500.0)
        ->and($fresh->duration_days)->toBe(3);
});

it('يمنع العميل من تسعير طلبه', function () {
    ['client' => $client, 'project' => $project] = pricingScenario();

    $cr = $this->actingAs($client)
        ->postJson("/api/projects/{$project->id}/change-requests", ['title' => 'طلب العميل'])
        ->json('data');

    $this->actingAs($client)
        ->postJson("/api/change-requests/{$cr['id']}/send", ['price' => 0])
        ->assertStatus(403);

    expect(ChangeRequest::find($cr['id'])->status)->toBe(ChangeRequestStatus::Draft);
});

it('يمنع فريق أرقام من اعتماد طلب التغيير ويقول السبب بالعربية', function () {
    ['admin' => $admin, 'client' => $client, 'project' => $project] = pricingScenario();

    $cr = $this->actingAs($client)
        ->postJson("/api/projects/{$project->id}/change-requests", ['title' => 'طلب العميل'])
        ->json('data');

    $this->actingAs($admin)
        ->postJson("/api/change-requests/{$cr['id']}/send", ['price' => 1000])
        ->assertOk();

    $response = $this->actingAs($admin)
        ->postJson("/api/change-requests/{$cr['id']}/decide", ['approve' => true])
        ->assertStatus(403);

    // الرسالة تشرح الدورة لا تكتفي بالمنع، ولا تعود بالإنجليزية
    expect($response->json('message'))
        ->toContain('قرار العميل')
        ->not->toContain('unauthorized');

    expect(ChangeRequest::find($cr['id'])->status)->toBe(ChangeRequestStatus::Sent);
});

it('يعتمد العميل الطلب المُسعَّر ويتحرّك التسليم بأثره', function () {
    ['admin' => $admin, 'client' => $client, 'project' => $project] = pricingScenario();

    $cr = $this->actingAs($client)
        ->postJson("/api/projects/{$project->id}/change-requests", ['title' => 'طلب العميل'])
        ->json('data');

    $this->actingAs($admin)
        ->postJson("/api/change-requests/{$cr['id']}/send", [
            'price'                => 1000,
            'delivery_impact_days' => 2,
        ])
        ->assertOk();

    $this->actingAs($client)
        ->postJson("/api/change-requests/{$cr['id']}/decide", ['approve' => true])
        ->assertOk()
        ->assertJsonPath('data.status', ChangeRequestStatus::Approved->value);

    expect($project->fresh()->adjusted_delivery_date->toDateString())->not->toBe('2026-09-01');
});

it('لا يعود منع الصلاحية برسالة لارافيل الإنجليزية', function () {
    ['project' => $project] = pricingScenario();

    // غريب تمامًا عن المشروع
    $outsider = User::factory()->create();

    $response = $this->actingAs($outsider)
        ->getJson("/api/projects/{$project->id}")
        ->assertStatus(403);

    expect($response->json('message'))->not->toContain('This action is unauthorized');
});
