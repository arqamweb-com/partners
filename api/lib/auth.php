<?php
/**
 * تسجيل الدخول والصلاحيات — بديل Supabase Auth و دوال has_role / is_project_member.
 */

declare(strict_types=1);

function session_boot(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $s = config()['session'];
    session_name($s['name']);
    session_set_cookie_params([
        'lifetime' => $s['lifetime'],
        'path'     => '/',
        'secure'   => (bool) $s['secure'],
        'httponly' => true,
        // Lax تمنع إرسال الكوكي مع طلبات POST القادمة من مواقع أخرى (حماية CSRF)
        'samesite' => 'Lax',
    ]);
    session_start();
}

/** المستخدم الحالي أو null. */
function current_user(): ?array
{
    static $cached = false;
    static $user = null;

    if ($cached) {
        return $user;
    }
    $cached = true;

    session_boot();
    $uid = $_SESSION['user_id'] ?? null;
    if (!$uid) {
        return null;
    }

    $user = db_one('SELECT id, email, created_at FROM users WHERE id = ?', [$uid]);
    if (!$user) {
        // المستخدم اتحذف من قاعدة البيانات — نلغي الجلسة
        auth_logout();
        return null;
    }

    $roles = db_all('SELECT role FROM user_roles WHERE user_id = ?', [$uid]);
    $user['roles'] = array_column($roles, 'role');

    return $user;
}

function require_user(): array
{
    $user = current_user();
    if (!$user) {
        fail('لازم تسجّل الدخول.', 401);
    }

    return $user;
}

/** يقابل has_role(auth.uid(),'admin') */
function is_admin(): bool
{
    $user = current_user();

    return $user !== null && in_array('admin', $user['roles'], true);
}

function require_admin(): array
{
    $user = require_user();
    if (!is_admin()) {
        fail('هذا الإجراء متاح للأدمن فقط.', 403);
    }

    return $user;
}

/** يقابل is_project_member(_project_id, _user_id): عضو في المشروع أو مالكه. */
function is_project_member(string $projectId): bool
{
    $user = current_user();
    if (!$user) {
        return false;
    }

    $row = db_one(
        'SELECT 1 AS ok FROM project_members WHERE project_id = ? AND user_id = ?
         UNION SELECT 1 FROM projects WHERE id = ? AND owner_id = ?
         LIMIT 1',
        [$projectId, $user['id'], $projectId, $user['id']]
    );

    return $row !== null;
}

/** مالك المشروع — من أنشأه. يملك صلاحيات إعداد أوسع من العضو العادي. */
function is_project_owner(string $projectId): bool
{
    $user = current_user();
    if (!$user) {
        return false;
    }

    return db_one('SELECT 1 AS ok FROM projects WHERE id = ? AND owner_id = ?',
        [$projectId, $user['id']]) !== null;
}

/**
 * يربط المستخدم بكل المشاريع المدعو إليها ببريده.
 * يُستدعى عند التسجيل وعند كل تسجيل دخول، فالدعوة تعمل قبل التسجيل وبعده.
 */
function claim_project_invites(string $userId, string $email): int
{
    $invites = db_all(
        'SELECT id, project_id FROM project_invites WHERE email = ? AND claimed_at IS NULL',
        [mb_strtolower($email)]
    );

    foreach ($invites as $invite) {
        db_run(
            'INSERT IGNORE INTO project_members (id, project_id, user_id) VALUES (?, ?, ?)',
            [uuid4(), $invite['project_id'], $userId]
        );
        db_run('UPDATE project_invites SET claimed_at = ? WHERE id = ?', [now_utc(), $invite['id']]);
    }

    return count($invites);
}

/** معرّفات المشاريع التي يصل إليها المستخدم الحالي — تُستخدم لتقييد قراءة الصفوف. */
function accessible_project_ids(): array
{
    $user = current_user();
    if (!$user) {
        return [];
    }

    $rows = db_all(
        'SELECT project_id AS id FROM project_members WHERE user_id = ?
         UNION SELECT id FROM projects WHERE owner_id = ?',
        [$user['id'], $user['id']]
    );

    return array_column($rows, 'id');
}

// ---------------------------------------------------------------------------
// التسجيل والدخول
// ---------------------------------------------------------------------------

/**
 * إنشاء حساب عميل.
 *
 * كل تسجيل من الموقع ينشئ حساب 'client' — لا يوجد أي طريق لصنع أدمن من
 * الواجهة. حسابات الأدمن تُنشأ من التيرمينال فقط عبر api/bin/admin.php
 */
function auth_signup(string $email, string $password, string $fullName, ?string $agencyName): array
{
    $email = trim(mb_strtolower($email));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new ApiError('البريد الإلكتروني غير صالح.');
    }
    if (mb_strlen($password) < 8) {
        throw new ApiError('كلمة المرور لازم تكون 8 أحرف على الأقل.');
    }
    if (db_one('SELECT id FROM users WHERE email = ?', [$email])) {
        throw new ApiError('البريد الإلكتروني مسجَّل من قبل.', 409);
    }

    $id = uuid4();
    $pdo = db();
    $pdo->beginTransaction();

    try {
        db_run(
            'INSERT INTO users (id, email, password_hash) VALUES (?, ?, ?)',
            [$id, $email, password_hash($password, PASSWORD_DEFAULT)]
        );

        db_run(
            'INSERT INTO profiles (id, full_name, email, agency_name) VALUES (?, ?, ?, ?)',
            [$id, mb_substr(trim($fullName), 0, 255), $email, $agencyName ? mb_substr(trim($agencyName), 0, 255) : null]
        );

        db_run(
            'INSERT INTO user_roles (id, user_id, role) VALUES (?, ?, ?)',
            [uuid4(), $id, 'client']
        );

        // ربط الحساب الجديد بأي مشاريع دُعي إليها بنفس البريد
        claim_project_invites($id, $email);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    session_boot();
    session_regenerate_id(true);
    $_SESSION['user_id'] = $id;

    return ['id' => $id, 'email' => $email];
}

function auth_login(string $email, string $password): array
{
    $email = trim(mb_strtolower($email));

    // الكبح قبل أي لمسة لقاعدة البيانات — انظر api/lib/throttle.php
    $ipKey      = 'ip:' . throttle_ip();
    $accountKey = 'login:' . throttle_ip() . '|' . $email;

    throttle_assert($ipKey, THROTTLE_MAX_PER_IP);
    throttle_assert($accountKey, THROTTLE_MAX_PER_ACCOUNT);

    $user = db_one('SELECT id, email, password_hash FROM users WHERE email = ?', [$email]);

    // نفس الرسالة في الحالتين حتى لا نكشف البريد المسجَّل من غيره
    if (!$user || !password_verify($password, $user['password_hash'])) {
        throttle_hit($ipKey);
        throttle_hit($accountKey);
        usleep(300_000);
        throw new ApiError('البريد الإلكتروني أو كلمة المرور غير صحيحة.', 401);
    }

    throttle_clear($accountKey);

    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        db_run('UPDATE users SET password_hash = ? WHERE id = ?',
            [password_hash($password, PASSWORD_DEFAULT), $user['id']]);
    }

    session_boot();
    session_regenerate_id(true);
    $_SESSION['user_id'] = $user['id'];

    // دعوات وصلت بعد تسجيل الحساب تُفعَّل عند أول دخول
    claim_project_invites($user['id'], $user['email']);

    return ['id' => $user['id'], 'email' => $user['email']];
}

function auth_logout(): void
{
    session_boot();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}
