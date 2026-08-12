<?php
/**
 * إدارة حسابات الأدمن من التيرمينال.
 *
 *   php api/bin/admin.php create <email> [الاسم]   إنشاء حساب أدمن جديد
 *   php api/bin/admin.php promote <email>          ترقية مستخدم مسجَّل إلى أدمن
 *   php api/bin/admin.php demote  <email>          إرجاعه عميلًا عاديًا
 *   php api/bin/admin.php passwd  <email>          تغيير كلمة المرور
 *   php api/bin/admin.php list                     عرض كل الأدمن
 *
 * كلمة المرور تُطلب أثناء التشغيل ولا تُكتب في الأمر — حتى لا تتسجّل في
 * سِجل الأوامر (history).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../lib/http.php';
require __DIR__ . '/../lib/db.php';

// ---------------------------------------------------------------------------

function out(string $message): void
{
    fwrite(STDOUT, $message . PHP_EOL);
}

function bail(string $message): never
{
    fwrite(STDERR, "خطأ: $message" . PHP_EOL);
    exit(1);
}

/** كلمة مرور عشوائية قوية — تُستخدم حين يتعذّر السؤال التفاعلي. */
function random_password(int $length = 16): string
{
    $alphabet = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789@#%+=?';
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }

    return $out;
}

/**
 * يحصل على كلمة المرور بأنسب طريقة متاحة:
 *
 *   طرفية تفاعلية   -> يسأل مرتين بدون إظهار الحروف
 *   إدخال ممرَّر     -> يقرأ سطرًا واحدًا  (echo 'pass' | php admin.php …)
 *   بلا إدخال       -> يولّد كلمة عشوائية ويطبعها
 *
 * الحالة الأخيرة تجعل الأمر يعمل من Cron Jobs في cPanel حين لا تتوفر
 * طرفية — تظهر كلمة المرور في ناتج المهمة، فتُغيَّر بعد أول دخول.
 */
function get_password(): string
{
    $interactive = stream_isatty(STDIN);

    if (!$interactive) {
        $piped = stream_get_contents(STDIN);
        $piped = trim((string) $piped);

        if ($piped !== '') {
            // قد يصل السطر مكررًا (تأكيد) — نأخذ أوله
            $first = trim(explode("\n", $piped)[0]);
            if (mb_strlen($first) < 8) {
                bail('كلمة المرور قصيرة — 8 أحرف على الأقل.');
            }
            return $first;
        }

        $generated = random_password();
        out('');
        out('لا توجد طرفية تفاعلية، فوُلِّدت كلمة مرور عشوائية:');
        out("    $generated");
        out('انسخها الآن وغيّرها بعد أول تسجيل دخول.');
        out('');

        return $generated;
    }

    fwrite(STDOUT, 'كلمة المرور (8 أحرف على الأقل): ');
    shell_exec('stty -echo 2>/dev/null');
    $first = trim((string) fgets(STDIN));
    fwrite(STDOUT, PHP_EOL . 'أعد كتابتها للتأكيد: ');
    $second = trim((string) fgets(STDIN));
    shell_exec('stty echo 2>/dev/null');
    fwrite(STDOUT, PHP_EOL);

    if ($first === '' || $first !== $second) {
        bail('كلمتا المرور غير متطابقتين.');
    }
    if (mb_strlen($first) < 8) {
        bail('كلمة المرور قصيرة — 8 أحرف على الأقل.');
    }

    return $first;
}

function find_user(string $email): ?array
{
    return db_one('SELECT id, email FROM users WHERE email = ?', [mb_strtolower(trim($email))]);
}

function set_role(string $userId, string $role): void
{
    db_run('DELETE FROM user_roles WHERE user_id = ?', [$userId]);
    db_run('INSERT INTO user_roles (id, user_id, role) VALUES (?, ?, ?)', [uuid4(), $userId, $role]);
}

// ---------------------------------------------------------------------------

$command = $argv[1] ?? '';
$email = isset($argv[2]) ? mb_strtolower(trim($argv[2])) : '';

switch ($command) {

    case 'create':
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            bail('اكتب بريدًا إلكترونيًا صالحًا.  مثال: php api/bin/admin.php create you@site.com "اسمك"');
        }
        if (find_user($email)) {
            bail("البريد $email مسجَّل بالفعل. استخدم promote لترقيته.");
        }

        $fullName = $argv[3] ?? 'مدير النظام';
        $password = get_password();
        $id = uuid4();

        $pdo = db();
        $pdo->beginTransaction();
        try {
            db_run('INSERT INTO users (id, email, password_hash) VALUES (?, ?, ?)',
                [$id, $email, password_hash($password, PASSWORD_DEFAULT)]);
            db_run('INSERT INTO profiles (id, full_name, email) VALUES (?, ?, ?)',
                [$id, mb_substr($fullName, 0, 255), $email]);
            db_run('INSERT INTO user_roles (id, user_id, role) VALUES (?, ?, ?)',
                [uuid4(), $id, 'admin']);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            bail($e->getMessage());
        }

        out("✅ تم إنشاء حساب الأدمن: $email");
        break;

    case 'promote':
    case 'demote':
        $user = find_user($email) ?: bail("لا يوجد مستخدم بالبريد $email.");
        $role = $command === 'promote' ? 'admin' : 'client';
        set_role($user['id'], $role);
        out($command === 'promote' ? "✅ {$user['email']} صار أدمن." : "✅ {$user['email']} صار عميلًا عاديًا.");
        break;

    case 'passwd':
        $user = find_user($email) ?: bail("لا يوجد مستخدم بالبريد $email.");
        $password = get_password();
        db_run('UPDATE users SET password_hash = ? WHERE id = ?',
            [password_hash($password, PASSWORD_DEFAULT), $user['id']]);
        out("✅ تم تغيير كلمة مرور {$user['email']}.");
        break;

    case 'list':
        $admins = db_all(
            "SELECT u.email, p.full_name, u.created_at
             FROM users u
             JOIN user_roles r ON r.user_id = u.id AND r.role = 'admin'
             LEFT JOIN profiles p ON p.id = u.id
             ORDER BY u.created_at"
        );
        if (!$admins) {
            out('لا يوجد أي حساب أدمن. أنشئ واحدًا:  php api/bin/admin.php create you@site.com "اسمك"');
            break;
        }
        out('حسابات الأدمن:');
        foreach ($admins as $a) {
            out(sprintf('  • %-32s %s', $a['email'], $a['full_name'] ?? ''));
        }
        break;

    default:
        out(<<<TXT
        إدارة حسابات الأدمن

          php api/bin/admin.php create <email> [الاسم]   إنشاء حساب أدمن
          php api/bin/admin.php promote <email>          ترقية مستخدم إلى أدمن
          php api/bin/admin.php demote  <email>          إرجاعه عميلًا
          php api/bin/admin.php passwd  <email>          تغيير كلمة المرور
          php api/bin/admin.php list                     عرض كل الأدمن

        TXT);
        exit($command === '' ? 0 : 1);
}
