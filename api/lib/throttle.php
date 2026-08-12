<?php
/**
 * كبح محاولات الدخول — حماية من تخمين كلمات المرور.
 *
 * العدّاد ملف JSON صغير لكل مفتاح داخل api/storage/throttle، فلا يحتاج
 * جدولًا جديدًا ولا امتدادًا (APCu/Redis) غير متوفّر على الاستضافة المشتركة.
 * المجلد محمي بـ api/storage/.htaccess فلا يُقرأ من المتصفح.
 *
 * مفتاحان لكل محاولة:
 *   IP + البريد  — يوقف تخمين كلمة مرور حساب بعينه
 *   IP وحده      — يوقف الرش على حسابات كثيرة من نفس المصدر
 */

declare(strict_types=1);

const THROTTLE_WINDOW_SECONDS = 900;   // نافذة 15 دقيقة
const THROTTLE_MAX_PER_ACCOUNT = 8;    // محاولة فاشلة على نفس الحساب
const THROTTLE_MAX_PER_IP = 30;        // محاولة فاشلة من نفس الـ IP

function throttle_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? 'cli');
}

function throttle_dir(): string
{
    return __DIR__ . '/../storage/throttle';
}

function throttle_file(string $key): string
{
    return throttle_dir() . '/' . hash('sha256', $key) . '.json';
}

/** الطوابع الباقية داخل النافذة الزمنية. */
function throttle_recent(string $key): array
{
    $raw = @file_get_contents(throttle_file($key));
    if ($raw === false) {
        return [];
    }

    $stamps = json_decode($raw, true);
    if (!is_array($stamps)) {
        return [];
    }

    $since = time() - THROTTLE_WINDOW_SECONDS;

    return array_values(array_filter(
        array_map('intval', $stamps),
        static fn(int $t) => $t > $since
    ));
}

/** يرفض الطلب لو تجاوز الحد. يُستدعى قبل فحص كلمة المرور. */
function throttle_assert(string $key, int $max): void
{
    $hits = throttle_recent($key);
    if (count($hits) < $max) {
        return;
    }

    $minutes = max(1, (int) ceil((min($hits) + THROTTLE_WINDOW_SECONDS - time()) / 60));

    throw new ApiError(
        "محاولات دخول كتيرة. استنى $minutes دقيقة وحاول تاني.",
        429
    );
}

/** يسجّل محاولة فاشلة. */
function throttle_hit(string $key): void
{
    $dir = throttle_dir();
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        error_log('[arqam] cannot create throttle dir: ' . $dir);
        return;   // الكبح حماية إضافية — تعذّره لا يعطّل تسجيل الدخول
    }

    $hits = throttle_recent($key);
    $hits[] = time();

    @file_put_contents(throttle_file($key), json_encode($hits), LOCK_EX);
    throttle_sweep();
}

/** ينسى المحاولات بعد دخول ناجح. */
function throttle_clear(string $key): void
{
    @unlink(throttle_file($key));
}

/** تنظيف عشوائي خفيف للملفات المنتهية — بديل كرون. */
function throttle_sweep(): void
{
    if (random_int(1, 50) !== 1) {
        return;
    }

    $cutoff = time() - (THROTTLE_WINDOW_SECONDS * 2);
    foreach (glob(throttle_dir() . '/*.json') ?: [] as $file) {
        if (@filemtime($file) < $cutoff) {
            @unlink($file);
        }
    }
}
