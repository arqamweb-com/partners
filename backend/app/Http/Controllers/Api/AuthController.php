<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\SystemRole;
use App\Http\Controllers\Controller;
use App\Models\ProjectMember;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * تسجيل الدخول والخروج — جلسة كوكي HttpOnly كما في النسخة الحالية،
 * فلا يتغيّر شيء في الفرونت غير إضافة ترويسة CSRF (انظر routes/api.php).
 *
 * كبح المحاولات صار عبر RateLimiter المدمج بدل الملفات اليدوية.
 */
class AuthController extends Controller
{
    private const MAX_ATTEMPTS = 8;
    private const DECAY_SECONDS = 900;   // 15 دقيقة

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email'       => ['required', 'email', 'max:255', 'unique:users,email'],
            'password'    => ['required', 'string', 'min:8'],
            'full_name'   => ['required', 'string', 'max:255'],
            'agency_name' => ['nullable', 'string', 'max:255'],
        ]);

        $user = DB::transaction(function () use ($data) {
            $user = User::create([
                ...$data,
                'email' => mb_strtolower(trim($data['email'])),
                // التسجيل الذاتي ينشئ عميلًا دائمًا. الأدوار الأخرى من
                // التيرمينال فقط — php artisan arqam:user
                'system_role' => SystemRole::selfServiceDefault(),
            ]);

            $this->claimInvites($user);

            return $user;
        });

        Auth::login($user);
        $request->session()->regenerate();

        return response()->json(['user' => $this->profile($user)], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $email = mb_strtolower(trim($data['email']));
        $keys = [
            'login:'.$request->ip().'|'.$email,   // تخمين حساب بعينه
            'ip:'.$request->ip(),                  // رشّ على حسابات كثيرة
        ];

        foreach ($keys as $i => $key) {
            $max = $i === 0 ? self::MAX_ATTEMPTS : self::MAX_ATTEMPTS * 4;
            if (RateLimiter::tooManyAttempts($key, $max)) {
                $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);
                throw ValidationException::withMessages([
                    'email' => "محاولات دخول كتيرة. استنى {$minutes} دقيقة وحاول تاني.",
                ])->status(429);
            }
        }

        if (! Auth::attempt(['email' => $email, 'password' => $data['password'], 'is_active' => true], true)) {
            foreach ($keys as $key) {
                RateLimiter::hit($key, self::DECAY_SECONDS);
            }

            // نفس الرسالة في الحالتين حتى لا نكشف البريد المسجَّل من غيره
            throw ValidationException::withMessages([
                'email' => 'البريد الإلكتروني أو كلمة المرور غير صحيحة.',
            ]);
        }

        RateLimiter::clear($keys[0]);
        $request->session()->regenerate();

        $user = $request->user();
        $this->claimInvites($user);

        return response()->json(['user' => $this->profile($user)]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['ok' => true]);
    }

    /** يقابل GET /api/auth/me في النسخة الحالية. */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(['user' => $user ? $this->profile($user) : null]);
    }

    /**
     * يربط المستخدم بكل المشاريع المدعو إليها ببريده.
     * يعمل قبل التسجيل وبعده — الدعوة صف في project_members بلا user_id.
     */
    private function claimInvites(User $user): int
    {
        return ProjectMember::claimInvitesFor($user);
    }

    private function profile(User $user): array
    {
        return [
            'id'             => $user->id,
            'email'          => $user->email,
            'full_name'      => $user->full_name,
            'agency_name'    => $user->agency_name,
            'system_role'    => $user->system_role->value,
            'role_label'     => $user->system_role->label(),
            'is_staff'       => $user->isStaff(),
            'can_price'      => $user->canPrice(),
            'partner_agency' => $user->partner_agency,
        ];
    }
}
