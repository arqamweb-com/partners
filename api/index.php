<?php
/**
 * نقطة الدخول الوحيدة للـ API.
 *
 *   POST /api/auth/signup    { email, password, full_name, agency_name }
 *   POST /api/auth/login     { email, password }
 *   POST /api/auth/logout
 *   GET  /api/auth/me        -> { user, profile, roles } أو { user: null }
 *   POST /api/db             { table, action, filters, values, order, columns, limit }
 *   GET  /api/health
 */

declare(strict_types=1);

require __DIR__ . '/lib/http.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/schema.php';
require __DIR__ . '/lib/throttle.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/rules.php';
require __DIR__ . '/lib/query.php';
require __DIR__ . '/lib/stages.php';
require __DIR__ . '/lib/files.php';

$debug = (bool) (config()['debug'] ?? false);
error_reporting($debug ? E_ALL : 0);
ini_set('display_errors', '0');   // الأخطاء ترجع JSON، لا HTML

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

// المسار بعد /api
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$path = preg_replace('#^.*/api#', '', $path);
$path = '/' . trim((string) $path, '/');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    switch ("$method $path") {

        case 'GET /health':
            // تشخيص ما بعد الرفع: الاتصال، واكتمال الجداول، وحدود الرفع
            $expected = array_keys(TABLES);
            $expected[] = 'users';
            $expected[] = 'uploads';

            // FETCH_COLUMN لأن اسم العمود في SHOW TABLES يحمل اسم القاعدة
            $found = db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
            $missing = array_values(array_diff($expected, $found));

            $version = db()->query('SELECT VERSION() AS v')->fetch()['v'] ?? '?';

            json_out([
                'ok'       => $missing === [],
                'time'     => now_utc(),
                'database' => [
                    'name'    => config()['db']['name'],
                    'version' => $version,
                    'tables'  => count($found),
                    // قائمة فارغة تعني أن الاستيراد اكتمل
                    'missing' => $missing,
                ],
                // للتأكد أن الاستضافة تسمح برفع 8 ميجا فعليًا
                'upload' => [
                    'max_file'       => MAX_UPLOAD_BYTES,
                    'php_upload_max' => ini_get('upload_max_filesize'),
                    'php_post_max'   => ini_get('post_max_size'),
                ],
            ], $missing === [] ? 200 : 503);

        // ------------------------------------------------------------------
        case 'POST /auth/signup':
            $b = read_json_body();
            $user = auth_signup(
                (string) ($b['email'] ?? ''),
                (string) ($b['password'] ?? ''),
                (string) ($b['full_name'] ?? ''),
                isset($b['agency_name']) ? (string) $b['agency_name'] : null,
            );
            json_out(['user' => $user]);

        case 'POST /auth/login':
            $b = read_json_body();
            $user = auth_login((string) ($b['email'] ?? ''), (string) ($b['password'] ?? ''));
            json_out(['user' => $user]);

        case 'POST /auth/logout':
            auth_logout();
            json_out(['ok' => true]);

        case 'GET /auth/me':
            $user = current_user();
            if (!$user) {
                json_out(['user' => null]);
            }
            $profile = db_one('SELECT * FROM profiles WHERE id = ?', [$user['id']]);
            json_out([
                'user'    => ['id' => $user['id'], 'email' => $user['email']],
                'profile' => $profile,
                'roles'   => $user['roles'],
            ]);

        // ------------------------------------------------------------------
        case 'POST /db':
            json_out(['data' => handle_query(read_json_body())]);

        // ---- دورة اعتماد المراحل -----------------------------------------
        case 'POST /stages/submit':
            $b = read_json_body();
            json_out(['data' => stage_submit(
                (string) ($b['stage_id'] ?? ''),
                (string) ($b['note'] ?? ''),
            )]);

        case 'POST /stages/approve':
            $b = read_json_body();
            json_out(['data' => stage_approve(
                (string) ($b['stage_id'] ?? ''),
                (string) ($b['approver_name'] ?? ''),
                (string) ($b['acknowledgement'] ?? ''),
            )]);

        case 'POST /stages/reject':
            $b = read_json_body();
            json_out(['data' => stage_reject(
                (string) ($b['stage_id'] ?? ''),
                (string) ($b['reason'] ?? ''),
            )]);

        // ---- الملفات ------------------------------------------------------
        // الرفع multipart وليس JSON، فلا يمر على read_json_body
        case 'POST /files/upload':
            // تجاوز post_max_size يجعل PHP يفرّغ $_FILES و$_POST بصمت،
            // فنكشفه من طول الطلب ونعطي رسالة مفهومة بدل «لم يصل أي ملف»
            if (!$_FILES && !$_POST && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
                fail('الملف أكبر من الحد المسموح (' . human_size(MAX_UPLOAD_BYTES) . ').', 413);
            }
            if (!isset($_FILES['file'])) {
                fail('لم يصل أي ملف.', 400);
            }
            json_out(['data' => file_upload($_FILES['file'])]);

        case 'POST /files/claim':
            $b = read_json_body();
            json_out(['data' => [
                'claimed' => files_claim(
                    (string) ($b['project_id'] ?? ''),
                    is_array($b['file_ids'] ?? null) ? $b['file_ids'] : [],
                ),
            ]]);

        case 'POST /files/delete':
            $b = read_json_body();
            file_delete((string) ($b['id'] ?? ''));
            json_out(['data' => ['ok' => true]]);

        default:
            // GET /files/{id} — تنزيل ملف بعد فحص الصلاحية
            if ($method === 'GET' && preg_match('#^/files/([0-9a-f-]{36})$#i', $path, $m)) {
                file_download($m[1]);
            }
            fail('المسار غير موجود.', 404);
    }
} catch (ApiError $e) {
    // أخطاء قواعد العمل — رسالتها معدّة للعرض على المستخدم
    json_out(['error' => ['message' => $e->getMessage()]], $e->status());
} catch (PDOException $e) {
    error_log('[arqam] SQL error: ' . $e->getMessage());

    // رسائل SIGNAL القادمة من triggers قاعدة البيانات (نفس نصوص Postgres)
    if (str_contains($e->getMessage(), 'SQLSTATE[45000]')) {
        $message = preg_replace('/^SQLSTATE\[45000\].*?:\s*\d+\s*/', '', $e->getMessage());
        json_out(['error' => ['message' => $message]], 409);
    }
    if ($e->getCode() === '23000') {
        json_out(['error' => ['message' => 'القيمة مكرّرة أو مرتبطة بسجل آخر.']], 409);
    }

    json_out(['error' => ['message' => $debug ? $e->getMessage() : 'خطأ في قاعدة البيانات.']], 500);
} catch (Throwable $e) {
    error_log('[arqam] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    json_out(['error' => ['message' => $debug ? $e->getMessage() : 'حدث خطأ غير متوقّع.']], 500);
}
