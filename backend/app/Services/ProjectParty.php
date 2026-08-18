<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ProjectRole;
use App\Enums\Side;
use App\Models\Project;
use App\Models\User;

/**
 * جهة المستخدم داخل مشروع بعينه.
 *
 * ═══ لماذا هذا الملف موجود ═══
 *
 * النسخة السابقة كان فيها:
 *
 *     function my_side(): string { return is_admin() ? 'us' : 'them'; }
 *
 * سطر واحد، وكان صحيحًا ما دام في النظام دوران فقط. مع المدير والمشرف
 * والشريك ينهار: المشرف موظف عند أرقام لكنه ليس أدمن، فكان يُحسب «عميلًا»
 * ويعتمد عمل زملائه. والشريك ليس أدمن أيضًا، فكان يُخلط بالعميل النهائي.
 *
 * الحل: الجهة تُشتق من دور المستخدم **في هذا المشروع**، لا من دوره في
 * النظام. ودوره في المشروع صف في project_members — بيانات لا استنتاج.
 *
 * كل ما يخص «مين يقدر يعمل إيه على المراحل» يمر من هنا وحده.
 */
final readonly class ProjectParty
{
    public function __construct(
        public User $user,
        public ?Project $project,
        public ?ProjectRole $role,
    ) {}

    /**
     * المشروع قد يكون null، ومعناه واحد: مؤرشف.
     *
     * الأعمال التابعة — مرحلة، بند محتوى، طلب تغيير — لا تُؤرشف مع
     * مشروعها ولا تختفي، فطلب مباشر بمعرّف أحدها بعد الأرشفة كان يصل
     * إلى سياسته ومعه project = null. وكل السياسات تمر من هنا، فالجواب
     * يُكتب هنا مرة واحدة: لا مشروع = لا دور = لا صلاحية.
     */
    public static function for(User $user, ?Project $project): self
    {
        return new self($user, $project, $project ? $user->roleOn($project) : null);
    }

    /** لا دور = لا وصول أصلًا. */
    public function isMember(): bool
    {
        return $this->role !== null;
    }

    public function side(): ?Side
    {
        return $this->role?->side();
    }

    /** هل الكرة في ملعب هذا المستخدم الآن؟ */
    public function holdsBall(Side $ballInCourt): bool
    {
        return $this->side() === $ballInCourt;
    }

    /**
     * هل يملك تحريك المراحل؟
     *
     * ليس كل من على جهةٍ يتصرّف باسمها: المنفّذ (Contributor) يشتغل ولا
     * يقدّم، والمطّلع (Viewer) لا يفعل شيئًا. هذا ما كان مستحيلًا التعبير
     * عنه في النموذج الثنائي القديم.
     */
    public function canActOnStages(): bool
    {
        return $this->role?->canActOnStages() ?? false;
    }

    /**
     * هل هو صاحب الاعتماد على جهة المستلِم؟
     *
     * حين يكون على المشروع شريك وعميل معًا، الاعتماد للشريك — هو المتعاقد
     * مع أرقام. الترتيب معرَّف في ProjectRole::approvalRank() وحده،
     * فتغييره قرار سطر واحد. انظر التعليق هناك.
     */
    public function isDesignatedApprover(): bool
    {
        if ($this->side() !== Side::Them || ! $this->canActOnStages()) {
            return false;
        }

        $top = $this->project?->approvers()->first();

        // لا معتمِد مسجَّل بعد: أي طرف مستلِم يقدر يعتمد
        if ($top === null) {
            return true;
        }

        return $top->role->approvalRank() <= $this->role->approvalRank();
    }

    /** اسم الجهة كما يظهر في الرسائل وسجل التدقيق. */
    public function sideLabel(): string
    {
        return match ($this->side()) {
            Side::Us   => 'فريق أرقام',
            Side::Them => $this->role === ProjectRole::Partner ? 'الوكالة الشريكة' : 'العميل',
            default    => 'طرف غير محدّد',
        };
    }

    public function roleLabel(): string
    {
        return $this->role?->label() ?? '—';
    }
}
