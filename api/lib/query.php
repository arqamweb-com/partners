<?php
/**
 * محرّك الاستعلامات — ينفّذ select/insert/update/delete بعد فحص الصلاحيات.
 *
 * كل طلب يمرّ بالترتيب التالي:
 *   1. الجدول موجود في القائمة البيضاء (schema.php)؟
 *   2. المستخدم مصرَّح له بهذا الإجراء على هذا الجدول؟   <- بديل RLS
 *   3. تقييد الصفوف على مشاريع المستخدم (لغير الأدمن)     <- بديل USING في RLS
 *   4. تنقية الأعمدة من الطلب (حماية من mass assignment)
 *   5. تطبيق قواعد العمل (rules.php)                      <- بديل الـ triggers
 *   6. التنفيذ عبر PDO بمعاملات مربوطة (لا دمج نصوص)
 */

declare(strict_types=1);

const FILTER_OPS = ['eq' => '=', 'neq' => '<>', 'gt' => '>', 'gte' => '>=', 'lt' => '<', 'lte' => '<='];

/** أسماء أعمدة الجدول الحقيقية — تُستخدم للتحقق قبل وضع أي اسم عمود في SQL. */
function table_columns(string $table): array
{
    static $cache = [];
    if (!isset($cache[$table])) {
        $rows = db_all("SHOW COLUMNS FROM `$table`");   // $table مُتحقَّق منه مسبقًا
        $cache[$table] = array_column($rows, 'Field');
    }

    return $cache[$table];
}

function assert_column(string $table, string $column): string
{
    if (!in_array($column, table_columns($table), true)) {
        throw new ApiError("عمود غير معروف: $column");
    }

    return $column;
}

/** هل يملك المستخدم الحالي هذا الإجراء على هذا الجدول؟ */
function check_permission(string $table, array $meta, string $action): void
{
    $rule = $meta[$action] ?? 'none';

    if ($rule === 'none') {
        throw new ApiError('هذا الإجراء غير مسموح به.', 403);
    }

    require_user();

    if ($rule === 'admin' && !is_admin()) {
        throw new ApiError('هذا الإجراء متاح للأدمن فقط.', 403);
    }
    // 'any' | 'owner' | 'member' | 'own'
    // التقييد على مستوى الصفوف يتم في scope_conditions() و assert_project_access()
}

/**
 * شرط تقييد الصفوف لغير الأدمن — البديل المباشر لعبارة USING في سياسات RLS.
 * يعيد [نص الشرط, المعاملات].
 */
function scope_conditions(string $table, array $meta): array
{
    if (is_admin()) {
        return ['', []];
    }

    $user = require_user();

    switch ($meta['scope']) {
        case 'global':
            return ['', []];

        case 'own':
            $key = assert_column($table, $meta['own_key']);
            return ["`$key` = ?", [$user['id']]];

        case 'project':
            $ids = accessible_project_ids();
            if (!$ids) {
                return ['1 = 0', []];      // لا مشاريع = لا صفوف
            }
            $key = assert_column($table, $meta['project_key'] ?? 'project_id');
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            return ["`$key` IN ($placeholders)", $ids];

        default:
            throw new ApiError('إعداد صلاحيات غير صالح.', 500);
    }
}

/** يبني WHERE من المرشِّحات القادمة من العميل + شرط التقييد. */
function build_where(string $table, array $meta, array $filters): array
{
    [$scopeSql, $params] = scope_conditions($table, $meta);
    $clauses = $scopeSql !== '' ? [$scopeSql] : [];

    foreach ($filters as $f) {
        if (!is_array($f) || count($f) < 3) {
            throw new ApiError('مرشِّح غير صالح.');
        }
        [$op, $column, $value] = $f;

        if (!isset(FILTER_OPS[$op])) {
            throw new ApiError("عملية مقارنة غير مدعومة: $op");
        }
        $col = assert_column($table, (string) $column);

        if ($value === null) {
            $clauses[] = $op === 'eq' ? "`$col` IS NULL" : "`$col` IS NOT NULL";
            continue;
        }

        $clauses[] = "`$col` " . FILTER_OPS[$op] . ' ?';
        // التواريخ تصل بصيغة ISO من المتصفح؛ المقارنة مع DATETIME تحتاج صيغة MySQL
        $params[] = is_string($value) ? iso_to_mysql($value) : normalize_scalar($value);
    }

    return [$clauses ? 'WHERE ' . implode(' AND ', $clauses) : '', $params];
}

function normalize_scalar(mixed $value): mixed
{
    if (is_bool($value)) {
        return $value ? 1 : 0;
    }
    if (is_array($value)) {
        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    return $value;
}

/** ينقّي الأعمدة المسموح كتابتها ويحوّل الأنواع. */
function sanitize_values(string $table, array $meta, array $input, bool $isUpdate): array
{
    $allowed = $meta['columns'];

    // العضو غير الأدمن مقيَّد بأعمدة محددة.
    // الإضافة والتعديل لهما قائمتان مختلفتان: عند الإضافة يحتاج العضو أعمدة
    // الربط (project_id/round_id) وهي ليست من حقه تعديلها لاحقًا.
    if (!is_admin()) {
        $key = $isUpdate ? 'member_columns' : 'member_insert_columns';
        if (isset($meta[$key])) {
            $allowed = array_intersect($allowed, $meta[$key]);
        }
    }

    $values = [];
    foreach ($input as $key => $value) {
        if (!in_array($key, $allowed, true)) {
            continue;   // عمود غير مسموح — يُتجاهل بصمت مثل RLS
        }

        if (in_array($key, $meta['json'] ?? [], true)) {
            $values[$key] = $value === null ? null : json_encode($value, JSON_UNESCAPED_UNICODE);
            continue;
        }
        if (in_array($key, $meta['bool'] ?? [], true)) {
            $values[$key] = $value ? 1 : 0;
            continue;
        }
        if (in_array($key, $meta['num'] ?? [], true) && $value !== null) {
            $values[$key] = 0 + $value;
            continue;
        }
        if (is_string($value)) {
            // التواريخ تصل من المتصفح بصيغة ISO ‏2026-08-04T12:00:00.000Z
            $values[$key] = iso_to_mysql($value);
            continue;
        }

        $values[$key] = normalize_scalar($value);
    }

    if (!$values && !$isUpdate) {
        throw new ApiError('لا توجد بيانات صالحة للحفظ.');
    }

    return $values;
}

function iso_to_mysql(string $value): string
{
    if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:?\d{2})$/', $value)) {
        return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    return $value;
}

/**
 * يتأكد أن المشروع المستهدف في نطاق المستخدم قبل الكتابة.
 * $level: 'member' يكفي فيها العضوية، و'owner' تتطلب أن يكون هو من أنشأ المشروع.
 */
function assert_project_access(string $table, array $meta, array $values, string $level = 'member'): void
{
    if (is_admin() || $meta['scope'] !== 'project') {
        return;
    }

    $key = $meta['project_key'] ?? 'project_id';

    // جدول المشاريع نفسه: الصف الجديد هو المشروع، فلا يوجد مشروع سابق نتحقق منه
    if ($key === 'id') {
        return;
    }

    $projectId = $values[$key] ?? null;

    if ($projectId === null) {
        throw new ApiError('ليس لديك صلاحية على هذا المشروع.', 403);
    }

    $allowed = $level === 'owner'
        ? is_project_owner((string) $projectId)
        : is_project_member((string) $projectId);

    if (!$allowed) {
        throw new ApiError(
            $level === 'owner'
                ? 'هذا الإجراء متاح لمن أنشأ المشروع أو لفريق أرقام.'
                : 'ليس لديك صلاحية على هذا المشروع.',
            403
        );
    }
}

// ---------------------------------------------------------------------------
// الإجراءات
// ---------------------------------------------------------------------------

function q_select(string $table, array $meta, array $req): array
{
    check_permission($table, $meta, 'read');
    [$where, $params] = build_where($table, $meta, $req['filters'] ?? []);

    $columns = '*';
    if (!empty($req['columns']) && is_array($req['columns'])) {
        $cols = array_map(fn($c) => '`' . assert_column($table, (string) $c) . '`', $req['columns']);
        $columns = implode(', ', $cols);
    }

    $orderSql = '';
    if (!empty($req['order'])) {
        $col = assert_column($table, (string) $req['order'][0]);
        $dir = (($req['order'][1] ?? 'asc') === 'desc') ? 'DESC' : 'ASC';
        $orderSql = "ORDER BY `$col` $dir";
    }

    $limitSql = '';
    if (!empty($req['limit'])) {
        $limitSql = 'LIMIT ' . max(1, min(5000, (int) $req['limit']));
    }

    $rows = db_all(trim("SELECT $columns FROM `$table` $where $orderSql $limitSql"), $params);

    return array_map(fn($r) => decode_row($table, $meta, $r), $rows);
}

function q_insert(string $table, array $meta, array $req): array
{
    check_permission($table, $meta, 'insert');

    $rows = $req['values'] ?? [];
    if (!$rows) {
        throw new ApiError('لا توجد بيانات للإضافة.');
    }
    // نقبل صفًا واحدًا أو مصفوفة صفوف (نفس سلوك supabase.insert)
    if (!array_is_list($rows)) {
        $rows = [$rows];
    }

    $hasUuid = in_array('id', table_columns($table), true) && $table !== 'app_settings';
    $ids = [];
    $pdo = db();
    $pdo->beginTransaction();

    try {
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new ApiError('صف غير صالح.');
            }

            $values = sanitize_values($table, $meta, $row, false);
            assert_project_access($table, $meta, $values, $meta['insert']);
            $values = apply_insert_rules($table, $values);

            // أعمدة JSON كانت NOT NULL DEFAULT '[]' في Postgres، وMySQL لا يقبل
            // DEFAULT على JSON — فنضبط القيمة هنا للحفاظ على نفس السلوك
            foreach ($meta['json'] ?? [] as $jsonCol) {
                if (!array_key_exists($jsonCol, $values)) {
                    $values[$jsonCol] = '[]';
                }
            }

            if ($hasUuid) {
                $values['id'] = uuid4();
                $ids[] = $values['id'];
            }

            $cols = array_keys($values);
            $sql = sprintf(
                'INSERT INTO `%s` (%s) VALUES (%s)',
                $table,
                implode(', ', array_map(fn($c) => "`$c`", $cols)),
                implode(', ', array_fill(0, count($cols), '?'))
            );
            db_run($sql, array_values($values));
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    if (!$ids) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $inserted = db_all("SELECT * FROM `$table` WHERE `id` IN ($placeholders)", $ids);

    // نعيدها بنفس ترتيب الإضافة، لا بترتيب MySQL
    $byId = array_column($inserted, null, 'id');
    $ordered = array_values(array_filter(array_map(fn($id) => $byId[$id] ?? null, $ids)));

    return array_map(fn($r) => decode_row($table, $meta, $r), $ordered);
}

function q_update(string $table, array $meta, array $req): array
{
    check_permission($table, $meta, 'update');

    $filters = $req['filters'] ?? [];
    if (!$filters) {
        throw new ApiError('التعديل بدون شرط غير مسموح.');
    }

    // نجلب الصفوف المستهدفة أولًا: قواعد العمل تحتاج الحالة القديمة للمقارنة
    [$where, $params] = build_where($table, $meta, $filters);
    $targets = db_all("SELECT * FROM `$table` $where", $params);
    if (!$targets) {
        return [];   // لا صفوف مطابقة أو خارج نطاق المستخدم — نفس سلوك RLS
    }

    $pdo = db();
    $pdo->beginTransaction();

    try {
        foreach ($targets as $existing) {
            // صلاحية 'owner': العضو العادي لا يعدّل مشروعًا لم ينشئه
            if (($meta['update'] ?? '') === 'owner' && !is_admin()) {
                $key = $meta['project_key'] ?? 'project_id';
                if (!is_project_owner((string) $existing[$key])) {
                    throw new ApiError('التعديل متاح لمن أنشأ المشروع أو لفريق أرقام.', 403);
                }
            }

            $values = sanitize_values($table, $meta, $req['values'] ?? [], true);
            if (!$values) {
                continue;
            }

            $values = apply_update_rules($table, $values, $existing);

            $sets = implode(', ', array_map(fn($c) => "`$c` = ?", array_keys($values)));
            db_run(
                "UPDATE `$table` SET $sets WHERE `id` = ?",
                [...array_values($values), $existing['id']]
            );
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    $ids = array_column($targets, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $rows = db_all("SELECT * FROM `$table` WHERE `id` IN ($placeholders)", $ids);

    return array_map(fn($r) => decode_row($table, $meta, $r), $rows);
}

function q_delete(string $table, array $meta, array $req): array
{
    check_permission($table, $meta, 'delete');

    $filters = $req['filters'] ?? [];
    if (!$filters) {
        throw new ApiError('الحذف بدون شرط غير مسموح.');
    }

    [$where, $params] = build_where($table, $meta, $filters);
    $targets = db_all("SELECT * FROM `$table` $where", $params);
    if (!$targets) {
        return [];
    }

    $pdo = db();
    $pdo->beginTransaction();

    try {
        foreach ($targets as $existing) {
            apply_delete_rules($table, $existing);
            db_run("DELETE FROM `$table` WHERE `id` = ?", [$existing['id']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return array_map(fn($r) => decode_row($table, $meta, $r), $targets);
}

/** يحوّل الصف لأنواع JavaScript المتوقَّعة (JSON مفكوك، booleans حقيقية). */
function decode_row(string $table, array $meta, array $row): array
{
    foreach ($meta['json'] ?? [] as $col) {
        if (isset($row[$col]) && is_string($row[$col])) {
            $row[$col] = json_decode($row[$col], true);
        }
    }
    foreach ($meta['bool'] ?? [] as $col) {
        if (array_key_exists($col, $row) && $row[$col] !== null) {
            $row[$col] = (bool) $row[$col];
        }
    }
    foreach ($meta['num'] ?? [] as $col) {
        if (array_key_exists($col, $row) && $row[$col] !== null) {
            $row[$col] = 0 + $row[$col];
        }
    }

    // التواريخ تعود بصيغة ISO حتى يتعامل معها المتصفح مثل Supabase تمامًا
    foreach ($row as $key => $value) {
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value)) {
            $row[$key] = str_replace(' ', 'T', $value) . '.000Z';
        }
    }

    return $row;
}

/** نقطة الدخول الوحيدة لكل عمليات قاعدة البيانات. */
function handle_query(array $req): array
{
    $table = (string) ($req['table'] ?? '');
    $meta = table_schema($table);
    if ($meta === null) {
        throw new ApiError('جدول غير معروف.', 404);
    }

    return match ($req['action'] ?? '') {
        'select' => q_select($table, $meta, $req),
        'insert' => q_insert($table, $meta, $req),
        'update' => q_update($table, $meta, $req),
        'delete' => q_delete($table, $meta, $req),
        default  => throw new ApiError('إجراء غير معروف.'),
    };
}
