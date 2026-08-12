<?php
/**
 * الاتصال بقاعدة البيانات + أدوات مساعدة.
 */

declare(strict_types=1);

function config(): array
{
    static $config = null;
    if ($config === null) {
        // ARQAM_CONFIG تسمح لحزمة الاختبار بتوجيه الاتصال لقاعدة منفصلة
        // (api/tests/boot.php) — الطلبات العادية لا تعرّفها فتقرأ config.php
        $path = defined('ARQAM_CONFIG') ? ARQAM_CONFIG : __DIR__ . '/../config.php';
        if (!is_file($path)) {
            fail('ملف api/config.php غير موجود. انسخ config.example.php وسمّه config.php.', 500);
        }
        $config = require $path;
    }

    return $config;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $c = config()['db'];
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $c['host'], $c['port'], $c['name'], $c['charset']);

    try {
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        error_log('[arqam] DB connection failed: ' . $e->getMessage());
        fail('تعذّر الاتصال بقاعدة البيانات.', 500);
    }

    // كل الأوقات UTC حتى تتطابق مع ما يرسله المتصفح (toISOString)
    $pdo->exec("SET time_zone = '+00:00'");

    return $pdo;
}

function db_all(string $sql, array $params = []): array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

function db_one(string $sql, array $params = []): ?array
{
    $rows = db_all($sql, $params);

    return $rows[0] ?? null;
}

function db_run(string $sql, array $params = []): int
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return $stmt->rowCount();
}

/** UUID v4 — يقابل gen_random_uuid() في Postgres. */
function uuid4(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/** الوقت الحالي بصيغة MySQL بتوقيت UTC. */
function now_utc(): string
{
    return gmdate('Y-m-d H:i:s');
}
