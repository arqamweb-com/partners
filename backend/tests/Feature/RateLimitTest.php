<?php

/**
 * السقف العام على الـ API.
 *
 * ليس بديلًا عن كبح الدخول — ذاك يحمي كلمات المرور من التخمين بحدود
 * ضيقة. هذا يحمي السيرفر من حساب واحد يستنزفه، والقياس بالمستخدم لا
 * بالـ IP حتى لا يُعاقب مكتب كامل بخطأ فردٍ فيه.
 */

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

beforeEach(fn () => RateLimiter::clear('api'));

it('يضع ترويسة الحد على ردود الـ API', function () {
    $user = User::factory()->admin()->create();

    $this->actingAs($user)
        ->getJson('/api/overview')
        ->assertOk()
        ->assertHeader('X-RateLimit-Limit', 120);
});

it('يقيس بالمستخدم لا بالـ IP، فلا يستهلك أحدهما رصيد الآخر', function () {
    $first = User::factory()->admin()->create();
    $second = User::factory()->admin()->create();

    $this->actingAs($first)->getJson('/api/overview')->assertOk();

    // نفس الـ IP في الاختبار — لو كان القياس به لنقص رصيد الثاني
    $this->actingAs($second)
        ->getJson('/api/overview')
        ->assertHeader('X-RateLimit-Remaining', 119);
});

it('يردّ 429 برسالة عربية بعد تجاوز السقف', function () {
    $user = User::factory()->admin()->create();

    foreach (range(1, 120) as $ignored) {
        $this->actingAs($user)->getJson('/api/overview')->assertOk();
    }

    $this->actingAs($user)
        ->getJson('/api/overview')
        ->assertStatus(429)
        ->assertJsonPath('message', 'طلبات كتيرة في وقت قصير. استنى دقيقة وحاول تاني.');
});
