<?php
/** تهيئة مشتركة لكل حالة اختبار — كل حالة تعمل في عملية مستقلة. */

declare(strict_types=1);

// التيرمينال فقط — الملفات دي جوه مجلد يقدّمه أباتشي
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// المكتبات من api/ نفسه؛ وقاعدة البيانات من api/tests/config.php (منفصلة)
$API = __DIR__ . '/..';

if (!is_file(__DIR__ . '/config.php')) {
    fwrite(STDERR, "انسخ api/tests/config.example.php إلى api/tests/config.php أولًا.\n");
    exit(2);
}

// لازم قبل db.php — توجّه الاتصال لقاعدة الاختبار لا قاعدة التطوير
define('ARQAM_CONFIG', __DIR__ . '/config.php');

require $API . '/lib/http.php';
require $API . '/lib/db.php';
require $API . '/lib/schema.php';
require $API . '/lib/throttle.php';
require $API . '/lib/auth.php';
require $API . '/lib/rules.php';
require $API . '/lib/query.php';
require $API . '/lib/stages.php';

/** يسجّل الدخول بدون HTTP: نضع المستخدم في الجلسة مباشرة. */
function login_as(string $userId): void
{
    session_boot();
    $_SESSION['user_id'] = $userId;
}

function uid_of(string $email): string
{
    $row = db_one('SELECT id FROM users WHERE email = ?', [$email]);
    if (!$row) {
        fwrite(STDERR, "no user $email\n");
        exit(1);
    }
    return $row['id'];
}

/** يتوقّع نجاح العملية. */
function expect_ok(string $label, callable $fn): void
{
    try {
        $fn();
        echo "  ✅ $label\n";
    } catch (Throwable $e) {
        echo "  ❌ $label — اتمنع بالغلط: " . $e->getMessage() . "\n";
        exit(1);
    }
}

/** يتوقّع رفض العملية (هذا هو الإصلاح). */
function expect_blocked(string $label, callable $fn): void
{
    try {
        $fn();
        echo "  ❌ $label — عدّت! الثغرة لسه مفتوحة\n";
        exit(1);
    } catch (ApiError $e) {
        echo "  ✅ $label — اتمنعت (" . $e->getMessage() . ")\n";
    } catch (Throwable $e) {
        echo "  ⚠️  $label — اتمنعت بخطأ غير متوقع: " . get_class($e) . ' ' . $e->getMessage() . "\n";
        exit(1);
    }
}

function q(array $req): array
{
    return handle_query($req);
}
