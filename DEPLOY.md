# النشر على cPanel بـ git pull

الواجهة والـ API على **أصل واحد**: لارافيل يقدّم الاثنين من `backend/public`.
لا نطاق فرعي للـ API ولا CORS.

> النسخة السابقة كانت تُنشر بـ `npm run host` الذي يحزم `api/` و`db/`. المجلدان
> محذوفان والسكربت معهما — كانا معمارية ما قبل لارافيل.

---

## أول مرة

### ١. الاستضافة

| البند | القيمة |
|---|---|
| Document Root | `.../arqam/backend/public` — **لا** جذر المشروع |
| PHP | 8.3 فأعلى (MultiPHP Manager) |
| قاعدة البيانات | MySQL جديدة + مستخدم بصلاحية ALL |

> الـ Document Root على جذر المشروع يجعل `.env` قابلًا للقراءة من المتصفح،
> وفيه بيانات الاتصال بقاعدة البيانات.

### ٢. السحب

من cPanel: **Git Version Control** → Create → رابط المستودع ومساره.
أو من Terminal:

```sh
cd ~ && git clone https://github.com/arqamweb-com/partners.git arqam
```

### ٣. التهيئة

```sh
cd ~/arqam/backend
composer install --no-dev --optimize-autoloader
cp .env.example .env          # ثم عدّله — انظر الجدول أدناه
php artisan key:generate
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan arqam:user create you@yourdomain.com --role=admin --name="اسمك"
chmod -R 775 storage bootstrap/cache
```

### ٤. `.env`

```env
APP_ENV=production
APP_DEBUG=false                          # true يسرّب المسارات وكلمات المرور في الأخطاء
APP_URL=https://app.yourdomain.com
FRONTEND_URL=https://app.yourdomain.com  # نفس النطاق — الأصل واحد

DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=cpaneluser_arqam
DB_USERNAME=cpaneluser_arqam
DB_PASSWORD=...

SESSION_DRIVER=database
QUEUE_CONNECTION=database
CACHE_STORE=database
SESSION_SECURE_COOKIE=true

MAIL_MAILER=smtp
MAIL_HOST=mail.yourdomain.com
MAIL_PORT=465
MAIL_SCHEME=smtps
MAIL_USERNAME=no-reply@yourdomain.com
MAIL_PASSWORD=...
MAIL_FROM_ADDRESS="no-reply@yourdomain.com"
MAIL_FROM_NAME="أرقام ويب"
```

> **`FRONTEND_URL` الخاطئ يعطّل الدخول بلا رسالة خطأ.** `config/sanctum.php`
> يشتق منه المضيف الـ stateful؛ ولو لم يطابق نطاق الموقع عوملت الطلبات
> كطلبات توكن لا جلسة: لا كوكي جلسة ولا CSRF، فيبدو الأمر كأن كلمة المرور
> خاطئة.

### ٥. الكرون — بدونه نصف النظام متوقف

```
* * * * * cd /home/USER/arqam/backend && /usr/local/bin/ea-php83 artisan schedule:run >> /dev/null 2>&1
```

يشغّل: القبول التلقائي للمحتوى، انتهاء مهل طلبات التغيير، تراكم تأخير
العميل، وطابور البريد.

---

## كل تحديث بعد ذلك

### على جهازك

```sh
npm run release                 # يبني وينسخ الناتج إلى backend/public
git add -A
git commit -m "وصف التغيير"
git push
```

`npm run release` يحذف أصول البناء السابق قبل النسخ، فيسجّل git الحذف
والإضافة معًا — ويطبّقهما `pull` على السيرفر بلا أصول ميتة متراكمة.

### على السيرفر

```sh
cd ~/arqam && git pull
```

ثم حسب ما تغيّر:

| ما تغيّر | الأمر |
|---|---|
| الواجهة فقط (`src/`) | لا شيء |
| الباك إند (`app/`، `routes/`، `config/`) | `cd backend && php artisan optimize:clear && php artisan config:cache && php artisan route:cache && php artisan view:cache` |
| هجرة جديدة | زيادةً على ما سبق: `php artisan migrate --force` |
| `composer.json` | `composer install --no-dev --optimize-autoloader` |
| `.env` | `php artisan config:cache` |

> `config:cache` يخزّن الإعدادات على القرص. بعده لا يُقرأ `.env` إطلاقًا حتى
> تعيد الأمر — وهذا أكثر سبب لتغيير «لا يظهر أثره» على السيرفر.

---

## ما لا يصل بالـ pull أبدًا

| العنصر | لماذا | ماذا تفعل |
|---|---|---|
| `backend/.env` | متجاهل — يحمل أسرار الإنتاج | يُعدَّل على السيرفر مباشرة |
| `backend/vendor/` | متجاهل | `composer install` عند تغيّر `composer.json` |
| `backend/storage/app/private/` | ملفات المستخدمين | **ضمّه في النسخ الاحتياطي** |

---

## التحقق

```sh
curl https://app.yourdomain.com/api/health     # {"ok":true,...}
```

ثم افتح `/dashboard` مباشرة وحدّث الصفحة. ظهورها يعني أن
`backend/public/.htaccess` مرفوع — وهو ملف مخفي يسقط أحيانًا من الرفع اليدوي.
