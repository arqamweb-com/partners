#!/usr/bin/env bash
#
# يجمّع نسخة الاستضافة في مجلد host/
#
#   host/public_html/  ->  يُرفع كما هو إلى جذر النطاق الفرعي
#   host/database/     ->  يُستورد من phpMyAdmin ولا يُرفع
#
# المجلد يُعاد بناؤه من الصفر في كل مرة — لا تعدّل فيه شيئًا، عدّل في المصدر.
# الاستخدام:  npm run host

set -euo pipefail

cd "$(dirname "$0")/.."
OUT="host"

if [ ! -d dist ]; then
  echo "خطأ: مجلد dist غير موجود. شغّل 'npm run build' أولًا." >&2
  exit 1
fi

rm -rf "$OUT"
mkdir -p "$OUT/public_html" "$OUT/database"

# ---- الواجهة: ناتج البناء كما هو (يشمل .htaccess المخفي) ----
cp -R dist/. "$OUT/public_html/"

# ---- الـ API: كل المجلد ما عدا الإعدادات المحلية والملفات المرفوعة ----
mkdir -p "$OUT/public_html/api"
cp -R api/. "$OUT/public_html/api/"
rm -f "$OUT/public_html/api/config.php"        # بيانات الاتصال المحلية — تُنشأ على السيرفر
rm -rf "$OUT/public_html/api/storage/uploads"  # ملفات التجربة المحلية
mkdir -p "$OUT/public_html/api/storage/uploads"
cp api/storage/uploads/.gitignore "$OUT/public_html/api/storage/uploads/" 2>/dev/null || true

# ---- قاعدة البيانات: مرقّمة بترتيب الاستيراد ----
cp db/schema.sql            "$OUT/database/1-schema.sql"
cp db/seed-production.sql   "$OUT/database/2-seed.sql"
cp db/triggers.sql          "$OUT/database/3-triggers.sql"
cp -R db/migrations         "$OUT/database/migrations"

cp scripts/host-readme.md "$OUT/README.md"

echo "✅ جاهز في host/"
echo "   public_html/  $(find "$OUT/public_html" -type f | wc -l | tr -d ' ') ملف  —  يُرفع إلى جذر النطاق الفرعي"
echo "   database/     يُستورد من phpMyAdmin ولا يُرفع"
