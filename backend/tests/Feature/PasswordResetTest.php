<?php

/**
 * استعادة كلمة المرور — من طلب الرابط إلى الدخول بالكلمة الجديدة.
 */

declare(strict_types=1);

use App\Models\User;
use App\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

beforeEach(function () {
    Notification::fake();
    // الكبح يعيش خارج قاعدة البيانات، فلا يصفّره RefreshDatabase
    RateLimiter::clear('pw-forgot:user@test.local');
    RateLimiter::clear('pw-forgot-ip:127.0.0.1');
});

it('يرسل رابط الاستعادة لمستخدم مسجَّل', function () {
    $user = User::factory()->create(['email' => 'user@test.local']);

    $this->postJson('/api/auth/forgot-password', ['email' => 'user@test.local'])
        ->assertOk();

    Notification::assertSentTo($user, ResetPassword::class);
});

it('يعطي نفس الرد لبريد غير مسجَّل — فلا يكشف من له حساب', function () {
    User::factory()->create(['email' => 'user@test.local']);

    $unknown = $this->postJson('/api/auth/forgot-password', ['email' => 'ghost@test.local']);

    RateLimiter::clear('pw-forgot-ip:127.0.0.1');
    $known = $this->postJson('/api/auth/forgot-password', ['email' => 'user@test.local']);

    // نفس الحالة ونفس النص — لا شيء في الرد يميّز المسجَّل من غيره
    expect($unknown->status())->toBe($known->status())
        ->and($unknown->json('message'))->toBe($known->json('message'));

    // ورسالة واحدة فقط أُرسلت: للمسجَّل وحده
    Notification::assertSentTimes(ResetPassword::class, 1);
});

it('لا يرسل رابطًا لحساب موقوف', function () {
    $user = User::factory()->create(['email' => 'user@test.local', 'is_active' => false]);

    $this->postJson('/api/auth/forgot-password', ['email' => 'user@test.local'])->assertOk();

    Notification::assertNothingSentTo($user);
});

it('يكبح الطلبات المتكررة على نفس البريد', function () {
    User::factory()->create(['email' => 'user@test.local']);

    foreach (range(1, 3) as $i) {
        $this->postJson('/api/auth/forgot-password', ['email' => 'user@test.local'])->assertOk();
    }

    $this->postJson('/api/auth/forgot-password', ['email' => 'user@test.local'])
        ->assertStatus(429);
});

it('يعيّن كلمة المرور بتوكن صالح ويقبل الدخول بها', function () {
    $user = User::factory()->create(['email' => 'user@test.local']);
    $token = null;

    $this->postJson('/api/auth/forgot-password', ['email' => 'user@test.local'])->assertOk();

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use (&$token) {
        $token = $n->token;

        return true;
    });

    $this->postJson('/api/auth/reset-password', [
        'token'                 => $token,
        'email'                 => 'user@test.local',
        'password'              => 'كلمة-جديدة-2026',
        'password_confirmation' => 'كلمة-جديدة-2026',
    ])->assertOk();

    // القديمة بطلت، والجديدة تعمل
    $this->postJson('/api/auth/login', [
        'email' => 'user@test.local', 'password' => 'password123',
    ])->assertStatus(422);

    $this->postJson('/api/auth/login', [
        'email' => 'user@test.local', 'password' => 'كلمة-جديدة-2026',
    ])->assertOk();
});

it('يرفض التوكن المزوَّر', function () {
    User::factory()->create(['email' => 'user@test.local']);

    $this->postJson('/api/auth/reset-password', [
        'token'    => 'توكن-من-عندي',
        'email'    => 'user@test.local',
        'password' => 'كلمة-جديدة-2026',
    ])->assertStatus(422);
});

it('يمنع استخدام نفس التوكن مرتين', function () {
    $user = User::factory()->create(['email' => 'user@test.local']);
    $token = null;

    $this->postJson('/api/auth/forgot-password', ['email' => 'user@test.local']);
    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use (&$token) {
        $token = $n->token;

        return true;
    });

    $payload = [
        'token'                 => $token,
        'email'                 => 'user@test.local',
        'password'              => 'أول-كلمة-2026',
        'password_confirmation' => 'أول-كلمة-2026',
    ];

    $this->postJson('/api/auth/reset-password', $payload)->assertOk();
    $this->postJson('/api/auth/reset-password', [
        ...$payload,
        'password'              => 'تانية-كلمة-2026',
        'password_confirmation' => 'تانية-كلمة-2026',
    ])->assertStatus(422);
});

it('يرفض كلمة مرور أقصر من ثمانية أحرف', function () {
    $user = User::factory()->create(['email' => 'user@test.local']);
    $token = null;

    $this->postJson('/api/auth/forgot-password', ['email' => 'user@test.local']);
    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use (&$token) {
        $token = $n->token;

        return true;
    });

    $this->postJson('/api/auth/reset-password', [
        'token' => $token, 'email' => 'user@test.local',
        'password' => 'قصيرة', 'password_confirmation' => 'قصيرة',
    ])->assertStatus(422);
});

it('يبني الرابط للواجهة لا للسيرفر', function () {
    config(['app.frontend_url' => 'https://app.arqam.test']);

    $user = User::factory()->create(['email' => 'user@test.local']);
    $this->postJson('/api/auth/forgot-password', ['email' => 'user@test.local']);

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use ($user) {
        $mail = $n->toMail($user);
        // الرابط يفتح صفحة الواجهة ومعه التوكن والبريد
        expect($mail->actionUrl)->toStartWith('https://app.arqam.test/auth/reset?')
            ->and($mail->actionUrl)->toContain('token='.$n->token)
            ->and($mail->actionUrl)->toContain(urlencode('user@test.local'));

        return true;
    });
});
