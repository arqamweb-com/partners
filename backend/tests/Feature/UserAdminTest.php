<?php

/**
 * إدارة الحسابات من الواجهة.
 *
 * الفكرة المحمية هنا واحدة: فتح الأدوار للواجهة لا يعني فتحها لمن يملك
 * الواجهة. المدير يرى كل المشاريع ويسعّرها ولا يصنع أدمن، والأدمن نفسه
 * لا يملك أن يخرج نفسه من الباب الذي يقف عليه.
 */

declare(strict_types=1);

use App\Enums\ProjectRole;
use App\Enums\SystemRole;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;

uses(RefreshDatabase::class);

it('يمنع غير الأدمن من رؤية الحسابات أو إنشائها', function () {
    foreach ([User::factory()->manager(), User::factory()->supervisor(), User::factory()] as $factory) {
        $user = $factory->create();

        $this->actingAs($user)->getJson('/api/users')->assertStatus(403);

        $this->actingAs($user)
            ->postJson('/api/users', [
                'email'       => 'new@arqam.test',
                'password'    => 'password123',
                'full_name'   => 'محاولة',
                'system_role' => 'admin',
            ])
            ->assertStatus(403);
    }

    expect(User::where('email', 'new@arqam.test')->exists())->toBeFalse();
});

it('ينشئ الأدمن حسابًا بدور صريح ويربط دعواته المعلّقة', function () {
    $admin = User::factory()->admin()->create();

    $project = Project::create([
        'name'                   => 'مشروع الدعوة',
        'owner_id'               => $admin->id,
        'owner_name'             => $admin->full_name,
        'original_delivery_date' => '2026-09-01',
        'adjusted_delivery_date' => '2026-09-01',
    ]);

    // دعوة سبقت الحساب — الصف موجود بلا user_id
    $invite = ProjectMember::create([
        'project_id'    => $project->id,
        'invited_email' => 'lead@arqam.test',
        'role'          => ProjectRole::Lead,
    ]);

    $this->actingAs($admin)
        ->postJson('/api/users', [
            'email'       => 'LEAD@Arqam.test',
            'password'    => 'password123',
            'full_name'   => 'مسؤول التنفيذ',
            'system_role' => 'supervisor',
        ])
        ->assertStatus(201)
        ->assertJsonPath('data.system_role', 'supervisor')
        ->assertJsonPath('data.email', 'lead@arqam.test');   // البريد يُطبَّع

    $user = User::where('email', 'lead@arqam.test')->first();

    expect($user->system_role)->toBe(SystemRole::Supervisor)
        ->and($invite->fresh()->user_id)->toBe($user->id);
});

it('يرفض شريكًا بلا اسم وكالة، لأنه لن يرى أي مشروع', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->postJson('/api/users', [
            'email'       => 'partner@agency.test',
            'password'    => 'password123',
            'full_name'   => 'شريك',
            'system_role' => 'partner',
        ])
        ->assertStatus(422);

    $partner = User::factory()->partner()->create();

    $this->actingAs($admin)
        ->patchJson("/api/users/{$partner->id}", ['partner_agency' => ''])
        ->assertStatus(422);
});

it('يمسح وكالة الشريك عند خروجه من دور الشريك', function () {
    $admin = User::factory()->admin()->create();
    $partner = User::factory()->partner('وكالة نون')->create();

    $this->actingAs($admin)
        ->patchJson("/api/users/{$partner->id}", ['system_role' => 'client'])
        ->assertOk()
        ->assertJsonPath('data.partner_agency', null);

    expect($partner->fresh()->partner_agency)->toBeNull();
});

it('يمنع الأدمن من تغيير دور نفسه أو تعطيله', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->patchJson("/api/users/{$admin->id}", ['system_role' => 'client'])
        ->assertStatus(403)
        // المنع يشرح نفسه بالعربية، لا برسالة لارافيل الافتراضية
        ->assertJsonPath('message', 'لا تغيّر دور حسابك ولا حالته بنفسك. اطلب ذلك من أدمن آخر.');

    $this->actingAs($admin)
        ->patchJson("/api/users/{$admin->id}", ['is_active' => false])
        ->assertStatus(403);

    $this->actingAs($admin)
        ->deleteJson("/api/users/{$admin->id}")
        ->assertStatus(403);

    expect($admin->fresh()->system_role)->toBe(SystemRole::Admin)
        ->and($admin->fresh()->is_active)->toBeTrue();

    // تعديل بياناته الوصفية مسموح — الممنوع ما يمسّ صلاحيته
    $this->actingAs($admin)
        ->patchJson("/api/users/{$admin->id}", ['full_name' => 'الاسم الجديد'])
        ->assertOk();
});

it('لا يترك النظام بلا أدمن نشط', function () {
    $admin = User::factory()->admin()->create();
    $other = User::factory()->admin()->create();

    // ما دام هناك أدمن آخر، التنزيل مسموح
    $this->actingAs($admin)
        ->patchJson("/api/users/{$other->id}", ['system_role' => 'manager'])
        ->assertOk();

    // ولو كان الأخير غير الفاعل — يبقى الشرط محروسًا
    $lastAdmin = User::factory()->admin()->create();
    $admin->update(['system_role' => SystemRole::Manager]);

    $this->actingAs($lastAdmin)
        ->patchJson("/api/users/{$lastAdmin->id}", ['is_active' => false])
        ->assertStatus(403);

    expect(User::where('system_role', SystemRole::Admin)->where('is_active', true)->count())->toBe(1);
});

it('يمنع الحساب الموقوف من الدخول', function () {
    $admin = User::factory()->admin()->create();
    $client = User::factory()->create(['email' => 'client@arqam.test', 'password' => 'password123']);

    $this->actingAs($admin)
        ->patchJson("/api/users/{$client->id}", ['is_active' => false])
        ->assertOk()
        ->assertJsonPath('data.is_active', false);

    // مسار الدخول للزائر — نترك جلسة الأدمن قبل تجربته
    Auth::logout();

    $this->postJson('/api/auth/login', [
        'email'    => 'client@arqam.test',
        'password' => 'password123',
    ])->assertStatus(422);
});

it('يعيّن الأدمن كلمة مرور جديدة فيدخل بها صاحبها', function () {
    $admin = User::factory()->admin()->create();
    $client = User::factory()->create(['email' => 'lost@arqam.test', 'password' => 'password123']);

    $this->actingAs($admin)
        ->postJson("/api/users/{$client->id}/password", ['password' => 'short'])
        ->assertStatus(422);

    $this->actingAs($admin)
        ->postJson("/api/users/{$client->id}/password", ['password' => 'brand-new-pass'])
        ->assertOk();

    Auth::logout();

    $this->postJson('/api/auth/login', [
        'email'    => 'lost@arqam.test',
        'password' => 'brand-new-pass',
    ])->assertOk();
});

it('يرفض حذف حساب يملك مشاريع ويسمح بحذف غيره', function () {
    $admin = User::factory()->admin()->create();
    $owner = User::factory()->create();
    $spare = User::factory()->create();

    Project::create([
        'name'                   => 'مشروع بمالك',
        'owner_id'               => $owner->id,
        'owner_name'             => $owner->full_name,
        'original_delivery_date' => '2026-09-01',
        'adjusted_delivery_date' => '2026-09-01',
    ]);

    $this->actingAs($admin)->deleteJson("/api/users/{$owner->id}")->assertStatus(422);
    $this->actingAs($admin)->deleteJson("/api/users/{$spare->id}")->assertOk();

    expect(User::whereKey($owner->id)->exists())->toBeTrue()
        ->and(User::whereKey($spare->id)->exists())->toBeFalse();
});

it('يبحث ويصفّي القائمة بالدور والحالة', function () {
    $admin = User::factory()->admin()->create();
    User::factory()->manager()->create(['full_name' => 'مدير المشاريع', 'email' => 'manager@arqam.test']);
    User::factory()->create(['full_name' => 'عميل موقوف', 'is_active' => false]);

    $this->actingAs($admin)
        ->getJson('/api/users?role=manager')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.email', 'manager@arqam.test');

    $this->actingAs($admin)
        ->getJson('/api/users?status=disabled')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.is_active', false);

    $this->actingAs($admin)
        ->getJson('/api/users?q=manager@arqam')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});
