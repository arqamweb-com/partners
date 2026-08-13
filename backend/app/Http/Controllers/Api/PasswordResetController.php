<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * استعادة كلمة المرور.
 *
 * قاعدتان تحكمان الردود هنا:
 *
 * 1. **لا نكشف البُرد المسجَّلة.** طلب الاستعادة يردّ نفس الرسالة سواء
 *    كان البريد مسجَّلًا أم لا — وإلا صار المسار أداة لمعرفة من له حساب.
 *
 * 2. **الكبح على البريد وعلى الـ IP.** بدونه يصير المسار وسيلة لإغراق
 *    صندوق أي شخص برسائل، ونحن من يدفع ثمنها.
 */
class PasswordResetController extends Controller
{
    public function forgot(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        $email = mb_strtolower(trim($data['email']));

        $this->assertNotThrottled([
            'pw-forgot:'.$email        => 3,    // نفس البريد
            'pw-forgot-ip:'.$request->ip() => 10,   // نفس المصدر
        ]);

        RateLimiter::hit('pw-forgot:'.$email, 900);
        RateLimiter::hit('pw-forgot-ip:'.$request->ip(), 900);

        // الحساب الموقوف لا يستعيد كلمة مروره
        $status = Password::sendResetLink(['email' => $email, 'is_active' => true]);

        if ($status === Password::RESET_LINK_SENT) {
            logger()->info('[arqam] password reset requested', ['email' => $email]);
        }

        // نفس الرد في كل الحالات — لا يكشف من له حساب
        return response()->json([
            'message' => 'لو البريد ده مسجَّل عندنا، هتوصلك رسالة فيها رابط تعيين كلمة مرور جديدة.',
        ]);
    }

    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token'    => ['required', 'string'],
            'email'    => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'password.min'       => 'كلمة المرور لازم تكون 8 أحرف على الأقل.',
            'password.confirmed' => 'تأكيد كلمة المرور مش مطابق.',
        ]);

        $status = Password::reset(
            [
                'email'                 => mb_strtolower(trim($data['email'])),
                'password'              => $data['password'],
                'password_confirmation' => $data['password'],
                'token'                 => $data['token'],
                'is_active'             => true,
            ],
            function (User $user, string $password): void {
                $user->forceFill([
                    'password'       => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            // التوكن المنتهي والمزوَّر يستحقان نفس الرسالة
            throw ValidationException::withMessages([
                'token' => 'الرابط غير صالح أو انتهت صلاحيته. اطلب رابطًا جديدًا.',
            ]);
        }

        // الاستعادة الناجحة تلغي الكبح: صاحب الحساب أثبت ملكيته للبريد
        RateLimiter::clear('pw-forgot:'.mb_strtolower(trim($data['email'])));

        return response()->json([
            'message' => 'تم تعيين كلمة المرور. تقدر تسجّل دخولك دلوقتي.',
        ]);
    }

    /** @param  array<string, int>  $limits  مفتاح => الحد الأقصى */
    private function assertNotThrottled(array $limits): void
    {
        foreach ($limits as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);

                throw ValidationException::withMessages([
                    'email' => "طلبات كتيرة. استنى {$minutes} دقيقة وحاول تاني.",
                ])->status(429);
            }
        }
    }
}
