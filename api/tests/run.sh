#!/usr/bin/env bash
#
# حزمة اختبار الصلاحيات وقواعد العمل.
#
#   bash api/tests/run.sh
#
# تشتغل على قاعدة بيانات منفصلة (api/tests/config.php) وتعمل TRUNCATE لكل
# جداولها قبل كل مجموعة. لا تلمس قاعدة التطوير إطلاقًا.
#
# التجهيز مرة واحدة:
#   mysql -u root -proot -e "CREATE DATABASE arqam_flow_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
#   mysql -u root -proot arqam_flow_test < db/schema.sql
#   mysql -u root -proot arqam_flow_test < db/seed-production.sql
#   cp api/tests/config.example.php api/tests/config.php

set -uo pipefail
cd "$(dirname "$0")"

PHP="${PHP:-php}"
command -v "$PHP" >/dev/null || PHP=/Applications/MAMP/bin/php/php8.3.9/bin/php

# الثغرات المسدودة — كل حالة لازم تُرفض
BLOCKED=(audit gate stage_selfapprove stage_pending content access round cr cr_selfsend throttle)
# المسار السليم — كل حالة لازم تعدّي (حالة نظيفة، لأن الأولى تغيّر حالة المرحلة)
HAPPY=(stage_happy stage_happy2)

fail=0

echo "═══ الصلاحيات وقواعد العمل ═══"
rm -rf ../storage/throttle/*.json 2>/dev/null
"$PHP" setup.php >/dev/null || exit 1
for c in "${BLOCKED[@]}"; do
    "$PHP" case.php "$c" || { echo "  ‼️ فشلت: $c"; fail=1; }
done

echo
echo "═══ المسار السليم للمراحل ═══"
"$PHP" setup.php >/dev/null || exit 1
for c in "${HAPPY[@]}"; do
    "$PHP" case.php "$c" || { echo "  ‼️ فشلت: $c"; fail=1; }
done

echo
if [ "$fail" -eq 0 ]; then
    echo "✅ كل الحالات عدّت."
else
    echo "❌ في حالات فشلت."
fi
exit "$fail"
