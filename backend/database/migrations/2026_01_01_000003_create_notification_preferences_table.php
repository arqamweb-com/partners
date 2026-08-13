<?php

/**
 * تفضيلات الإشعارات لكل مستخدم.
 *
 * جدول notifications نفسه يولّده لارافيل (قناة database). هذا الجدول يجيب
 * على سؤال مختلف: مين عايز يتبلّغ بإيه وعلى أي قناة — وهو ما يمنع إغراق
 * المدير بإشعار كل حركة في كل مشروع.
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();

            // مفتاح الحدث: stage.submitted، cr.sent، content.rejected …
            // '*' تعني الافتراضي لكل ما لم يُذكر صراحةً
            $table->string('event_key', 64);

            $table->boolean('in_app')->default(true);
            $table->boolean('email')->default(true);

            // ملخّص يومي بدل رسالة لكل حدث
            $table->boolean('digest_only')->default(false);

            $table->timestamps();

            $table->unique(['user_id', 'event_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
