<?php

/**
 * الهوية والأدوار.
 *
 * الفرق الجوهري عن النسخة السابقة: الدور لم يعد ثنائيًا (أدمن / غير أدمن).
 * صار للمستخدم دور في النظام، ودور مستقل داخل كل مشروع — انظر
 * 2026_01_01_000001_create_project_tables.php و app/Services/ProjectParty.php
 */

declare(strict_types=1);

use App\Enums\SystemRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('email')->unique();
            $table->string('password');
            $table->string('full_name')->default('');
            $table->string('agency_name')->nullable();

            // دور النظام — واحد لكل مستخدم. الصلاحيات داخل مشروع بعينه
            // تُحسب منه ومن عضوية المشروع معًا، لا منه وحده.
            $table->string('system_role', 16)->default(SystemRole::Client->value);

            // الشريك يرى مشاريع وكالته. يبقى فارغًا لغير الشركاء.
            $table->string('partner_agency')->nullable()->index();

            $table->boolean('is_active')->default(true);
            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();
            $table->timestamps();

            $table->index('system_role');
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignUuid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        // الأجازات الرسمية — تدخل في حساب أيام العمل
        Schema::create('holidays', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->date('holiday_date')->unique();
            $table->string('label')->default('');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holidays');
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
