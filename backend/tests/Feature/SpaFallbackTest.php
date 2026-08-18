<?php

/**
 * تقديم الواجهة من لارافيل.
 *
 * التطبيق صفحة واحدة، فالتوجيه في المتصفح. لكن الطلب الأول — أو تحديث
 * الصفحة على ‎/dashboard‎ — يصل السيرفر، ولا بد أن يجد ما يردّه.
 */

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('يردّ 404 بصيغة JSON على مسار API غير معروف، لا صفحة HTML', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->getJson('/api/there-is-no-such-endpoint')
        ->assertStatus(404)
        ->assertJsonPath('message', 'مسار غير معروف.');
});

it('يشرح غياب البناء بدل أن يصمت', function () {
    // في بيئة الاختبار لا يوجد public/index.html — الواجهة يقدّمها vite
    $this->get('/dashboard')
        ->assertStatus(404)
        ->assertSee('npm run build', escape: false);
})->skip(fn () => is_file(public_path('index.html')), 'الواجهة مبنية هنا، فالمسار يردّها.');

it('يردّ الواجهة على أي مسار ليس API متى كانت مبنية', function () {
    $this->get('/dashboard')->assertOk();
})->skip(fn () => ! is_file(public_path('index.html')), 'الواجهة غير مبنية في هذه البيئة.');
