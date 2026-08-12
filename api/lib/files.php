<?php
/**
 * رفع الملفات وتنزيلها.
 *
 * الملفات تُخزَّن في api/storage/uploads خارج أي مسار يقدر المتصفح يوصله،
 * ولا تُقدَّم إلا عبر /api/files/{id} بعد فحص الصلاحية. الاسم المخزَّن عشوائي
 * ولا علاقة له باسم العميل الأصلي، فلا يمكن تخمين مسار ملف ولا تنفيذه.
 */

declare(strict_types=1);

const MAX_UPLOAD_BYTES = 8 * 1024 * 1024;   // 8 ميجابايت

/**
 * الامتدادات المسموحة وأنواع المحتوى المقبولة لكل واحدة.
 *
 * ملفات أوفيس الحديثة (docx/xlsx) وملفات الأرشيف كلها ZIP في جوهرها،
 * فالكشف عنها يرجّع أنواعًا متقاربة — لذلك الامتداد هو الحكم، ونوع المحتوى
 * فحص إضافي يقبل صيغ ZIP العامة.
 */
const ALLOWED_UPLOADS = [
    // مستندات
    'pdf'  => ['application/pdf'],
    'doc'  => ['application/msword', 'application/octet-stream'],
    'docx' => [
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/zip', 'application/octet-stream',
    ],
    'xls'  => ['application/vnd.ms-excel', 'application/octet-stream'],
    'xlsx' => [
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/zip', 'application/octet-stream',
    ],
    'csv'  => ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'],

    // صور
    'jpg'  => ['image/jpeg'],
    'jpeg' => ['image/jpeg'],
    'png'  => ['image/png'],
    'gif'  => ['image/gif'],
    'webp' => ['image/webp'],
    'bmp'  => ['image/bmp', 'image/x-ms-bmp'],
    'svg'  => ['image/svg+xml', 'text/plain', 'text/xml', 'application/xml'],

    // أرشيف
    'zip'  => ['application/zip', 'application/x-zip-compressed', 'application/octet-stream'],
    'rar'  => [
        'application/vnd.rar', 'application/x-rar-compressed',
        'application/x-rar', 'application/octet-stream',
    ],
];

/** صيغ تُعرض داخل المتصفح؛ ما عداها يُنزَّل. SVG ليست منها عمدًا. */
const INLINE_MIMES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp', 'application/pdf'];

function storage_root(): string
{
    return __DIR__ . '/../storage/uploads';
}

function human_size(int $bytes): string
{
    return $bytes >= 1048576
        ? round($bytes / 1048576, 1) . ' ميجابايت'
        : max(1, (int) round($bytes / 1024)) . ' كيلوبايت';
}

// ---------------------------------------------------------------------------

/** يرفع ملفًا واحدًا ويعيد بياناته. */
function file_upload(array $file): array
{
    $user = require_user();

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new ApiError(match ($file['error'] ?? UPLOAD_ERR_NO_FILE) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE =>
                'الملف أكبر من الحد المسموح (' . human_size(MAX_UPLOAD_BYTES) . ').',
            UPLOAD_ERR_NO_FILE => 'لم يصل أي ملف.',
            default => 'تعذّر رفع الملف.',
        });
    }

    if (!is_uploaded_file($file['tmp_name'])) {
        throw new ApiError('ملف غير صالح.', 400);
    }

    $size = (int) $file['size'];
    if ($size <= 0) {
        throw new ApiError('الملف فارغ.');
    }
    if ($size > MAX_UPLOAD_BYTES) {
        throw new ApiError(
            'الملف ' . human_size($size) . ' — الحد الأقصى ' . human_size(MAX_UPLOAD_BYTES) . '.'
        );
    }

    $originalName = (string) ($file['name'] ?? 'ملف');
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    if (!isset(ALLOWED_UPLOADS[$ext])) {
        throw new ApiError(
            'صيغة غير مسموحة. المسموح: ' . implode('، ', array_keys(ALLOWED_UPLOADS)) . '.'
        );
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) ($finfo->file($file['tmp_name']) ?: 'application/octet-stream');
    if (!in_array($mime, ALLOWED_UPLOADS[$ext], true)) {
        throw new ApiError("محتوى الملف لا يطابق امتداده ($ext).");
    }

    // مسار مقسَّم بالشهور حتى لا يتضخّم مجلد واحد
    $dir = storage_root() . '/' . gmdate('Y/m');
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        error_log('[arqam] cannot create upload dir: ' . $dir);
        throw new ApiError('تعذّر تجهيز مكان التخزين.', 500);
    }

    $id = uuid4();
    $relative = gmdate('Y/m') . '/' . $id . '.' . $ext;

    if (!move_uploaded_file($file['tmp_name'], storage_root() . '/' . $relative)) {
        throw new ApiError('تعذّر حفظ الملف.', 500);
    }

    db_run(
        'INSERT INTO uploads (id, user_id, original_name, stored_path, mime, size_bytes)
         VALUES (?, ?, ?, ?, ?, ?)',
        [$id, $user['id'], mb_substr($originalName, 0, 255), $relative, $mime, $size]
    );

    return ['id' => $id, 'name' => $originalName, 'size' => $size];
}

/**
 * يربط ملفات مرفوعة بمشروع بعد حفظه.
 * لا يُقبل إلا ما رفعه المستخدم نفسه ولم يُربط بعد — فلا يستطيع أحد سحب
 * ملف غيره إلى مشروعه.
 */
function files_claim(string $projectId, array $fileIds): int
{
    $user = require_user();

    if (!is_admin() && !is_project_member($projectId) && !is_project_owner($projectId)) {
        throw new ApiError('ليس لديك صلاحية على هذا المشروع.', 403);
    }

    $claimed = 0;
    foreach ($fileIds as $id) {
        $claimed += db_run(
            'UPDATE uploads SET project_id = ?
             WHERE id = ? AND user_id = ? AND project_id IS NULL',
            [$projectId, (string) $id, $user['id']]
        );
    }

    return $claimed;
}

/** يُرسل الملف بعد التأكد من صلاحية طالبه. */
function file_download(string $id): never
{
    $user = require_user();
    $row = db_one('SELECT * FROM uploads WHERE id = ?', [$id]);

    if (!$row) {
        fail('الملف غير موجود.', 404);
    }

    $allowed = is_admin()
        || $row['user_id'] === $user['id']
        || ($row['project_id'] !== null && is_project_member($row['project_id']));

    if (!$allowed) {
        fail('ليس لديك صلاحية على هذا الملف.', 403);
    }

    $path = storage_root() . '/' . $row['stored_path'];
    if (!is_file($path)) {
        error_log('[arqam] missing upload file: ' . $path);
        fail('الملف غير موجود على السيرفر.', 404);
    }

    $disposition = in_array($row['mime'], INLINE_MIMES, true) ? 'inline' : 'attachment';
    $name = str_replace(['"', "\r", "\n"], '', $row['original_name']);

    header('Content-Type: ' . $row['mime']);
    header('Content-Length: ' . (string) filesize($path));
    header(
        sprintf(
            "Content-Disposition: %s; filename=\"%s\"; filename*=UTF-8''%s",
            $disposition,
            $name,
            rawurlencode($row['original_name'])
        )
    );
    header('X-Content-Type-Options: nosniff');
    header('Content-Security-Policy: default-src \'none\'; sandbox');
    header('Cache-Control: private, max-age=600');

    readfile($path);
    exit;
}

/** يحذف ملفًا: صاحبه قبل الربط، أو الأدمن في أي وقت. */
function file_delete(string $id): void
{
    $user = require_user();
    $row = db_one('SELECT * FROM uploads WHERE id = ?', [$id]);

    if (!$row) {
        throw new ApiError('الملف غير موجود.', 404);
    }
    if (!is_admin() && $row['user_id'] !== $user['id']) {
        throw new ApiError('ليس لديك صلاحية على هذا الملف.', 403);
    }

    $path = storage_root() . '/' . $row['stored_path'];
    if (is_file($path)) {
        @unlink($path);
    }
    db_run('DELETE FROM uploads WHERE id = ?', [$id]);
}
