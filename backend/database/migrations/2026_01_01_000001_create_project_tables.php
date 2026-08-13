<?php

/**
 * المشاريع والمراحل — نقل مباشر عن db/schema.sql مع تعديلين جوهريين:
 *
 *   1. project_members.role  — دور العضو داخل المشروع. هذا ما يحل محل
 *      is_admin() الثنائية: صار الوصول بيانات لا استنتاجًا.
 *   2. project_members.invited_email — الدعوة والعضوية صف واحد بدل جدولين،
 *      فتُربط تلقائيًا عند التسجيل بنفس البريد.
 */

declare(strict_types=1);

use App\Enums\ProjectRole;
use App\Enums\ProjectStatus;
use App\Enums\StageStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('end_client_name')->default('');
            $table->string('partner_agency')->default('')->index();
            $table->string('project_type', 32)->default('brochure')->index();
            $table->json('type_details')->nullable();
            $table->json('intake_data')->nullable();

            $table->foreignUuid('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('owner_name')->default('');

            $table->string('track', 16)->default('normal');
            $table->string('status', 24)->default(ProjectStatus::Active->value)->index();

            // بنود تعاقدية — يكتبها من يملك التسعير (أدمن/مدير) لا العميل
            $table->date('original_delivery_date')->nullable();
            $table->unsignedInteger('client_delay_days')->default(0);
            $table->date('adjusted_delivery_date')->nullable();
            $table->unsignedInteger('warranty_days')->default(14);
            $table->unsignedInteger('revision_rounds_allowed')->default(2);
            $table->unsignedInteger('revision_rounds_used')->default(0);

            $table->text('scope')->nullable();
            $table->text('out_of_scope')->nullable();
            $table->text('notes')->nullable();
            $table->text('supported_devices')->nullable();
            $table->text('supported_browsers')->nullable();
            $table->text('supported_screens')->nullable();
            $table->json('payment_milestones')->nullable();

            $table->date('queue_slot_date')->nullable();
            $table->decimal('reactivation_fee', 12, 2)->default(0);
            $table->timestamp('reactivated_at')->nullable();
            $table->decimal('credit_amount', 12, 2)->default(0);
            $table->date('credit_expires_at')->nullable();
            $table->timestamp('frozen_at')->nullable();

            $table->timestamps();
        });

        /**
         * عضوية المشروع — مصدر الحقيقة الوحيد لمن يصل لماذا.
         *
         * user_id فارغ + invited_email موجود = دعوة تنتظر التسجيل.
         * عند إنشاء حساب بنفس البريد تُربط تلقائيًا (ClaimInvites).
         */
        Schema::create('project_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('invited_email')->nullable();
            $table->string('role', 16)->default(ProjectRole::Client->value);
            $table->foreignUuid('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'user_id']);
            $table->unique(['project_id', 'invited_email']);
            $table->index(['user_id', 'role']);
            $table->index('invited_email');
        });

        Schema::create('stages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('stage_index');
            $table->boolean('is_parallel')->default(false);
            $table->string('name');
            $table->string('gate_name')->nullable();
            $table->string('gate_size', 32)->default('small');
            $table->unsignedInteger('our_duration_days')->default(0);
            $table->unsignedInteger('their_duration_days')->default(0);

            $table->string('ball_in_court', 8)->default('us');
            $table->string('status', 24)->default(StageStatus::Pending->value);

            $table->timestamp('started_at')->nullable();
            $table->timestamp('due_at')->nullable();

            // دورة الاعتماد في الاتجاهين
            $table->timestamp('submitted_at')->nullable();
            $table->foreignUuid('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('submission_note')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->foreignUuid('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('rejection_count')->default(0);

            $table->timestamp('locked_at')->nullable();
            $table->foreignUuid('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('deliverables')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'stage_index']);
            $table->index(['project_id', 'status']);
        });

        /** اعتمادات البوابات — يكتبها السيرفر وحده، لا تُعدَّل ولا تُحذف. */
        Schema::create('gate_approvals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('stage_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('approver_name');
            $table->string('approver_role', 16)->nullable();   // بأي صفة اعتمد
            $table->text('acknowledgement_text');
            $table->boolean('is_silent')->default(false);
            $table->timestamp('approved_at');

            $table->index(['project_id', 'stage_id']);
        });

        /** سجل التدقيق — إضافة فقط، والفاعل يُكتب من الجلسة لا من الطلب. */
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name')->default('');
            $table->string('actor_role', 16)->nullable();
            $table->string('event_type', 64);
            $table->text('description');
            $table->timestamp('created_at');

            $table->index(['project_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('gate_approvals');
        Schema::dropIfExists('stages');
        Schema::dropIfExists('project_members');
        Schema::dropIfExists('projects');
    }
};
