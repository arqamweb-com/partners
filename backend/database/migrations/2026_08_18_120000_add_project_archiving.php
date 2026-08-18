<?php

/**
 * أرشفة المشاريع، وربط الإشعار بمشروعه.
 *
 * لماذا حذف ناعم لا حذف مباشر؟ لأن المشروع ليس صفًا في جدول: تحته سجل
 * تدقيق واعتمادات بوابات وطلبات تغيير — وهي مستندات إثبات لا يصح أن
 * تختفي بدوسة زرار واحدة. الأرشفة تخفيه من كل الشاشات فورًا، والحذف
 * النهائي يبقى فعلًا ثانيًا منفصلًا من شاشة الأرشيف.
 *
 * و deleted_by ليس ترفًا: «مين أرشف ده؟» سؤال يُسأل بعد أسبوع، وسجل
 * التدقيق نفسه يُمسح مع الحذف النهائي فلا يصلح جوابًا وحيدًا.
 *
 * أما project_id على الإشعارات فلأن الإشعار كان يحمل رابط المشروع في
 * حقل نصي (data.url) لا يُستعلم عليه. بدونه لا سبيل لمعرفة أي إشعارات
 * تخص مشروعًا نُحذف، فتبقى معلّقة على روابط ميتة.
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->softDeletes();
            $table->foreignUuid('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            // كل الاستعلامات تفلتر على deleted_at الآن (النطاق العام)
            $table->index('deleted_at');
        });

        Schema::table('notifications', function (Blueprint $table) {
            // الحذف النهائي للمشروع يجرّ إشعاراته معه بلا كود
            $table->foreignUuid('project_id')->nullable()->after('notifiable_id')
                  ->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_id');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropIndex(['deleted_at']);
            $table->dropConstrainedForeignId('deleted_by');
            $table->dropSoftDeletes();
        });
    }
};
