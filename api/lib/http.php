<?php
/**
 * أدوات الطلب والاستجابة — كل الردود JSON.
 */

declare(strict_types=1);

/** خطأ متوقّع يُعرض نصه للمستخدم (رسائل قواعد العمل بالعربي). */
class ApiError extends RuntimeException
{
    public function __construct(string $message, private int $status = 400)
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }
}

function json_out(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(string $message, int $status = 400): never
{
    json_out(['error' => ['message' => $message]], $status);
}

/** يقرأ جسم الطلب JSON. نرفض غير الـ JSON عمدًا: يمنع طلبات CSRF من نماذج HTML خارجية. */
function read_json_body(): array
{
    $type = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($type, 'application/json') === false) {
        fail('Content-Type لازم يكون application/json.', 415);
    }

    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        fail('جسم الطلب غير صالح.', 400);
    }

    return $data;
}
