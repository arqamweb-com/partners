# أرقام ويب — بوابة سير عمل المشاريع

تطبيق إدارة مشاريع الوكالات: سير عمل مقفول باتجاه واحد، عدّاد تأخير مزدوج،
وسجل تدقيق غير قابل للتعديل.

## البنية

| الطبقة | التقنية | مكانها |
|---|---|---|
| الواجهة | React 19 + TanStack Router + Tailwind 4 (SPA ثابت) | `src/` → `dist/` |
| الـ API | Laravel 13 على PHP 8.3+ | `backend/` |
| قاعدة البيانات | MySQL 8 / MariaDB 10.6+ | `backend/database/migrations/` |

Node.js مطلوب **وقت البناء فقط**. السيرفر يحتاج PHP و MySQL و Composer.

> **ملاحظة لمن يعرف النسخة القديمة:** كان الـ API في `api/` بـ PHP بلا إطار،
> والمخطط في `db/schema.sql`، وكان فيه مسار عام `POST /api/db` يسمّي فيه
> المتصفح الجدول والأعمدة. أُعيدت كتابته كله في `backend/`: كل فعل مسار
> باسمه ووراءه سياسة، والمخطط في هجرات لارافيل. أي تعليمات تشير إلى `api/`
> أو `db/` أو `php api/bin/admin.php` قديمة ولا تنطبق — والمجلدان محذوفان
> من المستودع، وفي تاريخ git لمن احتاجهما.

## التشغيل محليًا (MAMP)

**1. قاعدة البيانات**

MAMP يشغّل MySQL على المنفذ `8889` لا `3306`:

```sh
MYSQL=/Applications/MAMP/Library/bin/mysql80/bin/mysql
$MYSQL -u root -proot -P 8889 -h 127.0.0.1 \
  -e "CREATE DATABASE arqam_flow_v2 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
      CREATE DATABASE arqam_flow_v2_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

قاعدة `_test` منفصلة لأن الاختبارات تمسح ما فيها في كل تشغيل.

**2. الباك**

```sh
cd backend
composer install
cp .env.example .env
php artisan key:generate
```

ثم اضبط في `backend/.env`:

```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=8889
DB_DATABASE=arqam_flow_v2
DB_USERNAME=root
DB_PASSWORD=root
```

```sh
php artisan migrate
```

**3. أول حساب أدمن**

التسجيل من الموقع ينشئ حساب **عميل** دائمًا. أول أدمن من التيرمينال:

```sh
php artisan arqam:user create you@site.com --role=admin --name="اسمك"
```

كلمة المرور تُطلب أثناء التشغيل ولا تُكتب في الأمر — حتى لا تدخل في
`history`. بعد أول أدمن، الباقي يُدار من الواجهة: **الحسابات** في الشريط
العلوي (إنشاء، أدوار، إيقاف، كلمة مرور).

**4. التشغيل — تيرمينالان**

```sh
cd backend && php artisan serve      # http://127.0.0.1:8000
npm run dev                          # http://127.0.0.1:5173
```

افتح `5173`. خادم vite يمرّر `/api` إلى `8000` (انظر `vite.config.ts`).
**502 على `/api` معناه أن لارافيل واقف** — ما فيش سبب تاني.

أباتشي بتاع MAMP غير مطلوب إطلاقًا في التطوير؛ لازم بس أن MySQL شغّال.

## الأوامر

```sh
# الحسابات
php artisan arqam:user create you@site.com --role=manager --name="اسمك"
php artisan arqam:user role   you@site.com --role=supervisor
php artisan arqam:user passwd you@site.com
php artisan arqam:user list   --role=admin
php artisan arqam:user disable|enable you@site.com

# الالتزامات الزمنية (تعمل تلقائيًا بالكرون — انظر routes/console.php)
php artisan arqam:auto-accept --dry-run              # قبول المحتوى بعد المهلة
php artisan arqam:expire-change-requests --dry-run   # إسقاط عروض التغيير المنتهية

# الاختبارات وأدوات الفرونت
php artisan test                                     # 74 اختبارًا
npm run lint && npx tsc --noEmit && npm run build
```

## كيف يعمل النظام

### الأدوار

للمستخدم **دور في النظام**، ودور مستقل **داخل كل مشروع**. الصلاحية الفعلية
تُحسب من الاثنين معًا (`app/Services/ProjectParty.php`) — ولذلك يمكن إسناد
مشاريع لمشرف بلا منحه النظام كله.

| دور النظام | يرى | يسعّر ويعتمد البنود | إعدادات النظام والحسابات |
|---|---|---|---|
| أدمن | كل المشاريع | ✅ | ✅ |
| مدير | كل المشاريع | ✅ | ❌ |
| مشرف | المسند إليه فقط | ❌ | ❌ |
| شريك | مشاريع وكالته | ❌ | ❌ |
| عميل | مشاريعه | ❌ | ❌ |

ودور المشروع (`project_members.role`): **مسؤول تنفيذ**، **منفّذ**، **شريك**،
**عميل**، **مطّلع**. هو ما يحدّد جهة المستخدم في دورة الاعتماد: من ينفّذ
(`us`) ومن يستلم (`them`).

لا وصول لمشروع بلا صف في `project_members` — إلا الأدمن والمدير، فيريان
الكل بلا عضوية. والدعوة بالبريد صف بلا `user_id`، يُربط تلقائيًا لحظة إنشاء
حساب بنفس البريد (سواء سجّل بنفسه أو أنشأه أدمن).

### دورة اعتماد المرحلة (في الاتجاهين)

```
صاحب الدور يقدّم المرحلة  ──►  awaiting_approval، والكرة تنتقل للطرف الآخر
                                        │
                        ┌───────────────┴───────────────┐
                     يعتمد                            يرفض بملاحظات
                        │                                │
              locked (نهائي) وتبدأ            active، والكرة ترجع لصاحبها
              المرحلة التالية                 مع سبب الرفض مسجَّلًا
```

الانتقالات عبر `POST /api/stages/{id}/submit|approve|reject` — لا بتعديل
أعمدة من المتصفح. السيرفر يتحقق أن الكرة في ملعب من ينفّذ الإجراء، فلا
يعتمد أحد عمل نفسه. سبب الرفض إجباري ويُسجَّل في سجل التدقيق.

### ما لا يقبله السيرفر مهما طلب المتصفح

- **الفاعل من الطلب**: `AuditLogger` يأخذه من الجلسة دائمًا.
- **التسعير من العميل**: التحقق في `ProjectController::store` لا يعرف حقول
  التسعير أصلًا، فلا حاجة لتنقيتها.
- **دور غير «عميل» من التسجيل الذاتي**: `SystemRole::selfServiceDefault()`.
- **الأدمن فوق كل شيء**: لا يوجد `Gate::before` — اعتمادات البوابات وسجل
  التدقيق لا تُعدَّل ولا تُحذف من أحد.

## الرفع على cPanel

### المتطلبات

- PHP **8.3+** (من `MultiPHP Manager`)، وامتدادات لارافيل المعتادة
  (`mbstring`, `openssl`, `pdo_mysql`, `tokenizer`, `xml`, `ctype`, `json`).
- MySQL 8 / MariaDB 10.6+
- وصول Terminal في cPanel، أو Composer مرفوع يدويًا.
- Node.js على **جهازك** فقط.

### ١. البناء على جهازك

```sh
npm install && npm run build     # الناتج في dist/
cd backend && composer install --no-dev --optimize-autoloader
```

### ٢. الرفع

ارفع مجلد `backend/` كاملًا **خارج** `public_html` (مثلًا `/home/USER/arqam`)،
ثم انسخ محتويات `dist/` إلى `backend/public/`:

```
/home/USER/arqam/            ← الباك، خارج جذر الويب
├── app/  bootstrap/  config/  database/  routes/  storage/  vendor/
├── .env                     ← يُنشأ على السيرفر، لا يُرفع من جهازك
└── public/                  ← ⬅ اجعل جذر النطاق يشير هنا
    ├── index.php  .htaccess ← من لارافيل
    ├── index.html  assets/  ← من dist/
    └── favicon.ico  robots.txt
```

**جذر النطاق (Document Root) لازم يشير إلى `backend/public`** — من
`Domains` في cPanel. لو أشار لمجلد أعلى منه، يصير `.env` والكود كله قابلًا
للتنزيل من المتصفح.

`.htaccess` بتاع لارافيل يكفي وحده: الملفات الموجودة (`assets/…`) يقدّمها
أباتشي، وأي مسار آخر يذهب إلى `index.php`. و`routes/web.php` يردّ
`index.html` لأي مسار ليس `/api` — وهو ما يجعل تحديث الصفحة على
`/dashboard` يعمل بدل 404.

### ٣. قاعدة البيانات والإعداد

من `MySQL® Databases` أنشئ قاعدة ومستخدمًا (cPanel يضيف بادئة حسابك).
ثم على السيرفر:

```sh
cd ~/arqam
cp .env.example .env      # واملأه (تحت)
php artisan key:generate
php artisan migrate --force
php artisan arqam:user create you@site.com --role=admin --name="اسمك"
```

### ٤. قائمة فحص الإنتاج

في `.env` على السيرفر:

```
APP_ENV=production
APP_DEBUG=false                  # ⬅ الأهم: true يكشف المسارات والإعدادات
APP_URL=https://app.example.com
SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true       # الجلسة على HTTPS فقط
QUEUE_CONNECTION=database
MAIL_MAILER=smtp                 # ⬅ log يعني أن استعادة كلمة المرور لا تصل أحدًا
MAIL_HOST=... MAIL_PORT=... MAIL_USERNAME=... MAIL_PASSWORD=...
MAIL_FROM_ADDRESS="no-reply@example.com"
```

ثم:

```sh
php artisan config:cache && php artisan route:cache && php artisan view:cache
chmod -R 775 storage bootstrap/cache
```

> بعد أي تعديل على `.env` شغّل `php artisan config:cache` من جديد، وإلا بقي
> القديم ساريًا.

### ٥. الكرون — غير اختياري

ثلاثة التزامات زمنية معلّقة عليه: القبول التلقائي للمحتوى بعد المهلة،
إسقاط عروض التغيير المنتهية، وإرسال الإشعارات من الطابور. بدونه يبقى النظام
شغّالًا في الظاهر ويتوقف الزمن فيه.

من `Cron Jobs` في cPanel، كل دقيقة:

```
* * * * * cd /home/USER/arqam && /usr/local/bin/php artisan schedule:run >> /dev/null 2>&1
```

### ٦. التحديث لاحقًا

```sh
npm run build                                  # على جهازك
# ارفع dist/ إلى backend/public/ و backend/app|routes|... المعدّلة
cd ~/arqam && php artisan migrate --force && php artisan config:cache
```

## بنية المشروع

```
src/                     الواجهة (SPA)
├── routes/              صفحات TanStack Router
├── components/          مكوّنات مشتركة + ui/ (shadcn)
└── lib/api.ts           عميل الـ API — نداء لكل فعل، لا استعلامات عامة

backend/
├── app/Http/Controllers/Api/    مسار لكل فعل
├── app/Policies/                من يملك ماذا — القرار كله هنا
├── app/Services/                ProjectParty، StageWorkflow، BusinessDays…
├── app/Enums/                   SystemRole، ProjectRole، حالات المراحل…
├── app/Console/Commands/        arqam:user، arqam:auto-accept…
├── database/migrations/         المخطط
└── tests/Feature/               74 اختبارًا، أغلبها مواصفة أمنية
```

## نقاط معروفة (لمن يكمل من هنا)

- أنواع المشاريع معرَّفة مرتين: `backend/resources/project-types.json`
  للسيرفر و`src/lib/project-types.ts` للمتصفح. أي نوع جديد يحتاج تعديل
  الاثنين معًا.
- `notification_preferences` مبني في السيرفر ويحترمه `Notifier`، لكن لا
  مسار API ولا شاشة لضبطه بعد.
- لا توجد صفحة يغيّر فيها المستخدم كلمة مروره وهو داخل — الاستعادة بالبريد
  فقط، أو يعيّنها له أدمن.
- لا يوجد CI. الاختبارات تُشغَّل يدويًا.
