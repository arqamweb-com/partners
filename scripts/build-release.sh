#!/usr/bin/env bash
#
# يبني الواجهة ويضعها في backend/public استعدادًا للـ commit.
#
# النشر على الاستضافة يتم بـ git pull، والسيرفر لا يشغّل Node — فناتج
# البناء يُسجَّل في المستودع. هذا السكربت هو الخطوة التي تسبق الـ commit.
#
#   npm run release        ثم        git add -A && git commit && git push
#
# الاستخدام: npm run release

set -euo pipefail

cd "$(dirname "$0")/.."

PUBLIC="backend/public"

if [ ! -f "$PUBLIC/index.php" ]; then
  echo "خطأ: $PUBLIC/index.php غير موجود — هل أنت في جذر المشروع؟" >&2
  exit 1
fi

echo "▸ بناء الواجهة…"
npm run build

# الأصول تُمسح قبل النسخ لا بعده: أسماؤها تحمل بصمة تتغيّر مع كل بناء،
# فالنسخ فوقها يراكم ملفات ميتة لا يشير إليها شيء. ومسحها هنا يجعل git
# يسجّل الحذف، فيطبّقه السيرفر بـ pull بلا تدخّل.
echo "▸ إزالة أصول البناء السابق…"
rm -rf "$PUBLIC/assets"

echo "▸ نسخ الناتج إلى ${PUBLIC}…"
# استثناء ملفات لارافيل صراحةً: dist/ لا يحتويها، لكن الحماية أرخص من
# شرح لماذا اختفى .htaccess فتوقّف كل شيء
rsync -a --exclude '.DS_Store' --exclude 'index.php' --exclude '.htaccess' \
      dist/ "$PUBLIC/"

echo
echo "✅ جاهز — $(find "$PUBLIC/assets" -type f | wc -l | tr -d ' ') ملف أصول"
echo
echo "الخطوة التالية:"
echo "   git add -A && git commit -m \"...\" && git push"
echo "   وعلى السيرفر:  git pull"
