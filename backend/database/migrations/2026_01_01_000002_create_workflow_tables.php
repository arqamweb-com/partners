<?php

/**
 * قوائم الوصول والمحتوى، وجولات الملاحظات، وطلبات التغيير، والملفات.
 * نقل مباشر عن db/schema.sql — الأعمدة كما هي حتى تنتقل البيانات بلا تحويل.
 */

declare(strict_types=1);

use App\Enums\ChangeRequestStatus;
use App\Enums\ContentStatus;
use App\Enums\FeedbackRoundStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('item_order')->default(0);
            $table->string('name');
            $table->text('note')->nullable();
            $table->boolean('is_slow')->default(false);
            $table->boolean('is_done')->default(false);
            // يُكتبان من الجلسة لا من الطلب — انظر AccessItemPolicy
            $table->foreignUuid('provided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('provided_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'item_order']);
        });

        Schema::create('content_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->string('item_group', 16);              // blocking | non_blocking
            $table->unsignedInteger('item_order')->default(0);
            $table->string('name');
            $table->text('acceptance_criteria')->nullable();
            $table->string('status', 16)->default(ContentStatus::Pending->value);
            $table->text('value')->nullable();
            $table->timestamp('due_at')->nullable();

            // تاريخ التقديم الأصلي لا يتحرّك عند إعادة التقديم — عليه يُحسب التأخير
            $table->timestamp('submitted_at')->nullable();
            $table->foreignUuid('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignUuid('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable();
            $table->boolean('auto_accepted')->default(false);
            $table->timestamps();

            $table->index(['project_id', 'item_group', 'item_order']);
        });

        Schema::create('feedback_rounds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('stage_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('round_number')->default(1);
            $table->string('status', 16)->default(FeedbackRoundStatus::Open->value);
            $table->boolean('is_paid')->default(false);
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'round_number']);
        });

        Schema::create('feedback_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('round_id')->constrained('feedback_rounds')->cascadeOnDelete();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->text('description');
            $table->string('page_or_section')->default('');
            // التصنيف قرار فريق أرقام
            $table->string('classification', 16)->nullable();
            $table->timestamp('classified_at')->nullable();
            $table->foreignUuid('classified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('objection_text')->nullable();
            $table->timestamp('objection_at')->nullable();
            $table->string('resolution', 24)->nullable();
            $table->timestamps();

            $table->index('round_id');
            $table->index('project_id');
        });

        Schema::create('change_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('source_feedback_item_id')->nullable()
                  ->constrained('feedback_items')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();

            // التسعير لمن يملكه (أدمن/مدير) — SystemRole::canPrice()
            $table->decimal('price', 12, 2)->default(0);
            $table->string('currency', 8)->default('SAR');
            $table->unsignedInteger('duration_days')->default(0);
            $table->unsignedInteger('delivery_impact_days')->default(0);

            $table->string('status', 16)->default(ChangeRequestStatus::Draft->value);
            $table->timestamp('sent_at')->nullable();
            $table->date('quote_valid_until')->nullable();
            $table->date('decision_deadline')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->foreignUuid('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('decision_note')->nullable();
            $table->foreignUuid('resubmitted_from')->nullable()
                  ->constrained('change_requests')->nullOnDelete();

            // أثر التمديد يُسجَّل مرة واحدة — حارس إضافي فوق قاعدة الحالة النهائية
            $table->timestamp('delivery_extended_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'status']);
            $table->index('resubmitted_from');
        });

        Schema::create('uploads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('original_name');
            $table->string('stored_path');
            $table->string('mime', 127)->default('');
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->timestamps();

            $table->index('project_id');
            $table->index('user_id');
        });

        Schema::create('app_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary()->default(1);
            $table->unsignedInteger('warning_threshold_days')->default(5);
            $table->unsignedInteger('freeze_threshold_days')->default(10);
            $table->decimal('reactivation_fee', 12, 2)->default(1500);
            $table->unsignedInteger('warranty_days')->default(14);
            $table->unsignedInteger('revision_rounds_allowed')->default(2);
            $table->json('stage_defaults')->nullable();
            $table->timestamps();
        });

        Schema::create('cr_price_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->decimal('price', 12, 2)->default(0);
            $table->string('currency', 8)->default('SAR');
            $table->unsignedInteger('duration_days')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cr_price_items');
        Schema::dropIfExists('app_settings');
        Schema::dropIfExists('uploads');
        Schema::dropIfExists('change_requests');
        Schema::dropIfExists('feedback_items');
        Schema::dropIfExists('feedback_rounds');
        Schema::dropIfExists('content_items');
        Schema::dropIfExists('access_items');
    }
};
