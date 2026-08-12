/**
 * سجل أنواع المشاريع — المصدر الوحيد لتعريف الأنواع وقوالبها.
 *
 * كل نوع بيحدد مساره الكامل: المراحل وبواباتها ومددها، وعناصر الوصول
 * المطلوبة من العميل، وقائمة المحتوى الحاكم. لما الأدمن يعتمد الطلب،
 * القالب ده هو اللي بيتولد منه المشروع.
 *
 * لإضافة نوع جديد: زوّد عنصرًا هنا وأضف معرّفه في api/lib/rules.php
 * (PROJECT_TYPE_IDS) — مفيش أي ملف تاني محتاج تعديل.
 */

import { DEFAULT_ACCESS_ITEMS, DEFAULT_CONTENT_ITEMS, DEFAULT_STAGES } from "@/lib/domain";
import type { UploadedFile } from "@/lib/api";

export type StageTemplate = {
  name: string;
  gate: string | null;
  gate_size: string;
  our: number;
  their: number;
};

export type AccessTemplate = { name: string; note: string; slow: boolean };
export type ContentTemplate = { group: "blocking" | "non_blocking"; name: string; ac: string };

/**
 * حقل من حقول تفاصيل النوع — يُملأ في خطوة «النطاق» من فريق أرقام.
 *
 * كل حقل يقدر يكون له أثر مباشر على المشروع بدل ما يفضل مجرد توثيق:
 *   content : عناصر تتضاف لقائمة المحتوى الحاكم لما الحقل يبقى مفعّلًا
 *   access  : عناصر تتضاف لقائمة الوصول والحسابات
 *   days    : أيام تتضاف لمرحلة بعينها (ثابتة أو محسوبة بالكمية)
 *
 * «مفعّل» تعني: نعم للاختيارات المنطقية، أو قيمة أكبر من صفر للأرقام،
 * أو قيمة غير فارغة للنص والقوائم.
 */
export type DetailField = {
  key: string;
  label: string;
  type: "number" | "boolean" | "select" | "text";
  hint?: string;
  /** القيمة الافتراضية عند إنشاء مشروع جديد. */
  value?: number | boolean | string;
  /** خيارات القائمة المنسدلة. */
  options?: string[];
  /**
   * للأرقام: الحد الذي يُعتبر الحقل «مفعّلًا» فوقه (الافتراضي صفر).
   * مثال: عدد اللغات يبدأ من 1، فالترجمة لا تُطلب إلا فوق ذلك.
   */
  activeAbove?: number;
  /** عناصر محتوى يطلبها هذا الاختيار من العميل. */
  content?: ContentTemplate[];
  /** صلاحيات وصول يحتاجها هذا الاختيار. */
  access?: AccessTemplate[];
  /**
   * أثر الحقل على المدد.
   *   fixed        : أيام ثابتة تُضاف عند التفعيل
   *   per + unit   : تُضاف `per` يوم عن كل `unit` وحدة (للأرقام)
   *   onlyIf       : للقوائم — لا يُطبَّق الأثر إلا عند هذه القيمة
   *   countFrom    : الكمية تُقرأ من حقل آخر بدل قيمة الحقل نفسه — يسمح
   *                  لاختيار من قائمة أن يُسعّر بكمية رقمية في حقل مجاور
   *                  (مثال: من يُدخل المنتجات؟ × عدد المنتجات).
   */
  days?: {
    stage: string;
    fixed?: number;
    per?: number;
    unit?: number;
    onlyIf?: string;
    countFrom?: string;
  };
};

/**
 * حقل من نموذج بيانات العميل — يملأه العميل وقت تسجيل طلبه.
 *
 * الفرق عن DetailField: ده **بيانات ومرفقات** (لوجو، روابط درايف، أرقام
 * تواصل، حسابات سوشيال) بيسلّمها العميل، مش **مواصفات** فنية يقررها
 * فريق أرقام. كلها اختيارية ما لم يُذكر غير ذلك — العميل يسجّل المتاح
 * عنده الآن، والباقي يتابعه فريق أرقام في قائمة المحتوى.
 */
export type IntakeField = {
  key: string;
  label: string;
  /** file = ملف واحد، files = عدة ملفات. الرفع على الاستضافة لا على خدمة خارجية. */
  type: "text" | "textarea" | "url" | "email" | "tel" | "boolean" | "file" | "files";
  /** عنوان القسم الذي يظهر تحته الحقل في النموذج. */
  group: string;
  hint?: string;
  placeholder?: string;
};

export type ProjectType = {
  id: string;
  label: string;
  description: string;
  stages: StageTemplate[];
  accessItems: AccessTemplate[];
  contentItems: ContentTemplate[];
  detailFields: DetailField[];
  /** حقول خاصة بهذا النوع تُضاف بعد الحقول المشتركة. */
  intakeFields: IntakeField[];
};

/** قيم تفاصيل المشروع كما تُحفظ في عمود projects.type_details */
export type TypeDetails = Record<string, number | boolean | string>;

/** بيانات العميل كما تُحفظ في عمود projects.intake_data */
export type IntakeData = Record<string, string | boolean | UploadedFile[]>;

export function isFileField(field: IntakeField): boolean {
  return field.type === "file" || field.type === "files";
}

/** الملفات المرفوعة في حقل — دائمًا مصفوفة حتى لو الحقل ملف واحد. */
export function fileList(value: unknown): UploadedFile[] {
  return Array.isArray(value) ? (value as UploadedFile[]) : [];
}

/** كل معرّفات الملفات في بيانات مشروع — تُستخدم لربطها به بعد الحفظ. */
export function collectFileIds(typeId: string, data: IntakeData): string[] {
  return intakeFieldsFor(typeId)
    .filter(isFileField)
    .flatMap((f) => fileList(data[f.key]).map((x) => x.id));
}

// ---------------------------------------------------------------------------
// الحقول المشتركة بين كل الأنواع
// ---------------------------------------------------------------------------

const G_BRAND = "الهوية البصرية";
const G_CONTACT = "بيانات التواصل";
const G_SOCIAL = "حسابات التواصل الاجتماعي";
const G_FILES = "الملفات والروابط";
const G_TECH = "الدومين والاستضافة";

const COMMON_INTAKE: IntakeField[] = [
  // ---- الهوية البصرية ----
  {
    key: "has_brand",
    label: "عندكم هوية بصرية جاهزة؟",
    type: "boolean",
    group: G_BRAND,
    hint: "لو لأ، فريق أرقام هيرشّح اتجاهًا بصريًا في مرحلة التصميم.",
  },
  {
    key: "logo_files",
    label: "ملف اللوجو",
    type: "file",
    group: G_BRAND,
    hint: "يُفضَّل SVG. صيغة JPG بخلفية بيضاء غير مقبولة.",
  },
  {
    key: "brand_guide_files",
    label: "دليل الهوية (ألوان وخطوط)",
    type: "file",
    group: G_BRAND,
  },
  {
    key: "brand_images_files",
    label: "صور البراند",
    type: "files",
    group: G_BRAND,
    hint: "صور المقر أو المنتجات أو الفريق — لو متاحة.",
  },
  {
    key: "brand_colors",
    label: "الألوان الأساسية",
    type: "text",
    group: G_BRAND,
    placeholder: "#1B4D3E، #C9A227",
  },

  // ---- بيانات التواصل ----
  { key: "phone", label: "رقم الهاتف", type: "tel", group: G_CONTACT },
  { key: "whatsapp", label: "رقم واتساب", type: "tel", group: G_CONTACT },
  { key: "public_email", label: "البريد الرسمي للنشر", type: "email", group: G_CONTACT },
  { key: "address", label: "العنوان", type: "text", group: G_CONTACT },
  {
    key: "map_link",
    label: "رابط الموقع على الخريطة",
    type: "url",
    group: G_CONTACT,
    placeholder: "https://maps.google.com/…",
  },
  {
    key: "working_hours",
    label: "مواعيد العمل",
    type: "text",
    group: G_CONTACT,
    placeholder: "الأحد – الخميس، 9 ص – 5 م",
  },

  // ---- السوشيال ----
  { key: "instagram", label: "إنستجرام", type: "url", group: G_SOCIAL },
  { key: "facebook", label: "فيسبوك", type: "url", group: G_SOCIAL },
  { key: "x_twitter", label: "إكس (تويتر)", type: "url", group: G_SOCIAL },
  { key: "linkedin", label: "لينكدإن", type: "url", group: G_SOCIAL },
  { key: "tiktok", label: "تيك توك", type: "url", group: G_SOCIAL },
  { key: "youtube", label: "يوتيوب", type: "url", group: G_SOCIAL },
  { key: "snapchat", label: "سناب شات", type: "url", group: G_SOCIAL },

  // ---- الملفات والروابط ----
  {
    key: "extra_files",
    label: "ملفات إضافية",
    type: "files",
    group: G_FILES,
    hint: "أي ملف آخر يخص المشروع.",
  },
  {
    key: "current_website",
    label: "الموقع الحالي (إن وُجد)",
    type: "url",
    group: G_FILES,
  },
  {
    key: "reference_sites",
    label: "مواقع تعجبكم كمرجع",
    type: "textarea",
    group: G_FILES,
    hint: "رابط في كل سطر، ويُفضَّل ذكر ما يعجبك في كل واحد.",
  },
  {
    key: "extra_notes",
    label: "أي ملاحظات إضافية",
    type: "textarea",
    group: G_FILES,
  },

  // ---- الدومين والاستضافة ----
  {
    key: "domain_name",
    label: "اسم النطاق المطلوب",
    type: "text",
    group: G_TECH,
    placeholder: "example.com",
  },
  { key: "has_domain", label: "النطاق مسجَّل بالفعل؟", type: "boolean", group: G_TECH },
  { key: "has_hosting", label: "الاستضافة جاهزة؟", type: "boolean", group: G_TECH },
];

// نسخ قابلة للتعديل من الثوابت العامة (الأصل معرّف `as const`)
const baseStages = DEFAULT_STAGES.map((s) => ({ ...s })) as StageTemplate[];
const baseContent = DEFAULT_CONTENT_ITEMS.map((c) => ({ ...c })) as ContentTemplate[];
const baseAccess = DEFAULT_ACCESS_ITEMS.map((a) => ({ ...a })) as AccessTemplate[];

/** عناصر الوصول بدون ما يخص المتاجر — الموقع التعريفي مالوش دفع ولا شحن. */
const accessWithout = (...names: string[]) => baseAccess.filter((a) => !names.includes(a.name));

/** إدراج مرحلة بعد مرحلة باسم محدد. */
function stagesWith(insertAfter: string, ...added: StageTemplate[]): StageTemplate[] {
  const out = baseStages.map((s) => ({ ...s }));
  const at = out.findIndex((s) => s.name === insertAfter);
  out.splice(at < 0 ? out.length : at + 1, 0, ...added);
  return out;
}

export const PROJECT_TYPES: ProjectType[] = [
  // ---------------------------------------------------------------------
  {
    id: "brochure",
    label: "موقع تعريفي",
    description: "موقع شركة أو صفحات تعريفية مع مدونة ونموذج تواصل. بدون متجر.",
    stages: baseStages,
    accessItems: accessWithout("بوابة الدفع", "شركة الشحن"),
    contentItems: baseContent,
    detailFields: [
      {
        key: "pages",
        label: "عدد الصفحات",
        type: "number",
        value: 8,
        hint: "الصفحات الثابتة فقط، بدون صفحات المدونة.",
        days: { stage: "البرمجة والتطوير", per: 1, unit: 4 },
      },
      {
        key: "languages",
        label: "عدد اللغات",
        type: "number",
        value: 1,
        activeAbove: 1,
        hint: "لغة واحدة تعني الموقع بالعربية فقط.",
        days: { stage: "البرمجة والتطوير", per: 4, unit: 1 },
        content: [
          {
            group: "blocking",
            name: "ترجمة المحتوى",
            ac: "نص مترجم نهائي لكل صفحة ولكل لغة إضافية، مراجَع بشريًا",
          },
        ],
      },
      {
        key: "blog",
        label: "مدونة",
        type: "boolean",
        value: true,
        days: { stage: "البرمجة والتطوير", fixed: 2 },
        content: [
          {
            group: "non_blocking",
            name: "تصنيفات المدونة وأول 3 مقالات",
            ac: "أسماء التصنيفات ونصوص المقالات الأولى",
          },
        ],
      },
      { key: "contact_form", label: "نموذج تواصل", type: "boolean", value: true },
      {
        key: "gallery",
        label: "معرض أعمال",
        type: "boolean",
        days: { stage: "البرمجة والتطوير", fixed: 2 },
        content: [
          {
            group: "blocking",
            name: "صور ومحتوى معرض الأعمال",
            ac: "صور كل عمل مع اسم العميل ووصف مختصر",
          },
        ],
      },
      {
        key: "newsletter",
        label: "اشتراك النشرة البريدية",
        type: "boolean",
        access: [
          {
            name: "خدمة النشرة البريدية",
            note: "حساب Mailchimp أو ما يعادله ومفاتيح الربط.",
            slow: false,
          },
        ],
      },
      { key: "cms", label: "لوحة تحكم لإدارة المحتوى", type: "boolean", value: true },
      {
        key: "seo",
        label: "إعداد SEO أساسي",
        type: "boolean",
        value: true,
        hint: "عناوين ووصف وخريطة موقع. لا يشمل حملات أو تحسين مستمر.",
      },
    ],
    intakeFields: [
      {
        key: "services_files",
        label: "ملف الخدمات",
        type: "files",
        group: "محتوى الموقع",
        hint: "ملف يشرح كل خدمة باسمها ووصفها النهائي.",
      },
      {
        key: "about_files",
        label: "ملف «من نحن» والرؤية",
        type: "files",
        group: "محتوى الموقع",
      },
      {
        key: "pages_list",
        label: "الصفحات المطلوبة",
        type: "textarea",
        group: "محتوى الموقع",
        hint: "صفحة في كل سطر.",
        placeholder: "الرئيسية\nمن نحن\nالخدمات\nتواصل معنا",
      },
      {
        key: "team_info",
        label: "فريق العمل (أسماء وصور ومناصب)",
        type: "textarea",
        group: "محتوى الموقع",
      },
      {
        key: "testimonials",
        label: "آراء العملاء أو شعارات الشركاء",
        type: "textarea",
        group: "محتوى الموقع",
      },
    ],
  },

  // ---------------------------------------------------------------------
  {
    id: "woocommerce",
    label: "متجر WooCommerce",
    description: "ووردبريس ووكومرس: منتجات وسلة ودفع وشحن. بوابة الدفع تحتاج بدءًا مبكرًا.",
    stages: stagesWith("التصميم", {
      name: "إعداد المتجر والمنتجات",
      gate: "Store Setup Lock",
      gate_size: "big",
      our: 5,
      their: 7,
    }),
    accessItems: baseAccess,
    contentItems: [
      ...baseContent,
      {
        group: "blocking",
        name: "ملف المنتجات",
        ac: "ملف Excel أو CSV فيه الاسم والوصف والسعر والوزن وأكواد المنتجات",
      },
      {
        group: "blocking",
        name: "صور المنتجات",
        ac: "صورة رئيسية لكل منتج على الأقل، خلفية موحّدة، 1000px عرض كحد أدنى",
      },
      {
        group: "blocking",
        name: "سياسة الشحن والاسترجاع",
        ac: "مناطق الشحن وتكلفتها ومدة التوصيل وشروط الاسترجاع",
      },
      {
        group: "non_blocking",
        name: "أكواد الخصم",
        ac: "قائمة الأكواد ونسب الخصم وتواريخ الصلاحية",
      },
    ],
    detailFields: [
      {
        key: "products",
        label: "عدد المنتجات",
        type: "number",
        value: 50,
        hint: "العدد التقريبي عند الإطلاق. الإدخال على العميل ما لم يُتفق غير ذلك.",
        days: { stage: "إعداد المتجر والمنتجات", per: 1, unit: 50 },
      },
      {
        key: "products_entry",
        label: "إضافة المنتجات على الموقع",
        type: "select",
        value: "من خلالكم",
        options: ["من خلالكم", "من خلال فريق أرقام ويب"],
        hint: "«من خلالكم» تعني أن إدخال المنتجات على العميل. اختيار فريق أرقام ويب يضيف مدة إدخال على المرحلة.",
        // إدخالنا للمنتجات شغل فعلي بنفس معدّل الإدخال الأساسي — يوم لكل 50 منتج
        days: {
          stage: "إعداد المتجر والمنتجات",
          per: 1,
          unit: 50,
          onlyIf: "من خلال فريق أرقام ويب",
          countFrom: "products",
        },
      },
      {
        key: "variations",
        label: "منتجات بمتغيرات (مقاس / لون)",
        type: "boolean",
        days: { stage: "إعداد المتجر والمنتجات", fixed: 3 },
        content: [
          {
            group: "blocking",
            name: "جدول المتغيرات والأسعار",
            ac: "لكل منتج: المقاسات والألوان والسعر والكمية وكود المنتج",
          },
        ],
      },
      {
        key: "pay_online",
        label: "دفع أونلاين (بطاقات / مدى)",
        type: "boolean",
        value: true,
        hint: "بوابة الدفع تستغرق 3–6 أسابيع للاعتماد — تُبدأ من اليوم الأول.",
        days: { stage: "إعداد المتجر والمنتجات", fixed: 3 },
        access: [
          {
            name: "بوابة الدفع",
            note: "تستغرق عادة من 3 إلى 6 أسابيع — يجب البدء فيها من اليوم الأول.",
            slow: true,
          },
        ],
        content: [
          {
            group: "blocking",
            name: "السجل التجاري وبيانات المنشأة",
            ac: "المستندات المطلوبة لفتح حساب بوابة الدفع",
          },
        ],
      },
      { key: "pay_cod", label: "الدفع عند الاستلام", type: "boolean", value: true },
      {
        key: "shipping",
        label: "ربط شركة شحن",
        type: "boolean",
        days: { stage: "إعداد المتجر والمنتجات", fixed: 2 },
        access: [{ name: "شركة الشحن", note: "العقد ومفاتيح الربط مع شركة الشحن.", slow: false }],
        content: [
          {
            group: "blocking",
            name: "سياسة الشحن والاسترجاع",
            ac: "مناطق الشحن وتكلفتها ومدة التوصيل وشروط الاسترجاع",
          },
        ],
      },
      { key: "coupons", label: "كوبونات خصم", type: "boolean", value: true },
      {
        key: "accounts",
        label: "حسابات العملاء وتتبع الطلبات",
        type: "boolean",
        value: true,
      },
      {
        key: "multi_lang",
        label: "متجر متعدد اللغات",
        type: "boolean",
        days: { stage: "إعداد المتجر والمنتجات", fixed: 5 },
        content: [
          {
            group: "blocking",
            name: "ترجمة أسماء ووصف المنتجات",
            ac: "ملف يقابل كل منتج بترجمته النهائية",
          },
        ],
      },
      {
        key: "multi_currency",
        label: "متعدد العملات",
        type: "boolean",
        days: { stage: "إعداد المتجر والمنتجات", fixed: 2 },
      },
      {
        key: "erp",
        label: "ربط نظام مخزون أو ERP",
        type: "boolean",
        days: { stage: "البرمجة والتطوير", fixed: 6 },
        access: [
          {
            name: "واجهة نظام المخزون",
            note: "توثيق الـ API ومفاتيح الربط من مزوّد النظام.",
            slow: true,
          },
        ],
      },
    ],
    intakeFields: [
      {
        key: "products_file",
        label: "ملف المنتجات (Excel / CSV)",
        type: "file",
        group: "بيانات المتجر",
        hint: "لكل منتج: الاسم والوصف والسعر والوزن وكود المنتج.",
      },
      {
        key: "product_images_files",
        label: "صور المنتجات",
        type: "files",
        group: "بيانات المتجر",
        hint: "صورة رئيسية لكل منتج على الأقل، 1000px عرض كحد أدنى. الملفات الكثيرة تُرفع مضغوطة ZIP.",
      },
      {
        key: "categories_list",
        label: "تصنيفات المنتجات",
        type: "textarea",
        group: "بيانات المتجر",
        hint: "تصنيف في كل سطر، مع التصنيفات الفرعية إن وُجدت.",
      },
      {
        key: "shipping_policy",
        label: "سياسة الشحن",
        type: "textarea",
        group: "سياسات المتجر",
        hint: "مناطق الشحن وتكلفتها ومدة التوصيل.",
      },
      {
        key: "return_policy",
        label: "سياسة الاسترجاع والاستبدال",
        type: "textarea",
        group: "سياسات المتجر",
      },
      {
        key: "id_card_files",
        label: "بطاقة الهوية",
        type: "files",
        group: "سياسات المتجر",
        hint: "اختياري — صورة بطاقة صاحب النشاط، من مستندات فتح حساب بوابة الدفع.",
      },
      {
        key: "commercial_record_files",
        label: "السجل التجاري وبيانات المنشأة",
        type: "files",
        group: "سياسات المتجر",
        hint: "اختياري — مطلوب لفتح حساب بوابة الدفع.",
      },
      {
        key: "tax_card_files",
        label: "البطاقة الضريبية",
        type: "files",
        group: "سياسات المتجر",
        hint: "اختياري — من مستندات فتح حساب بوابة الدفع.",
      },
    ],
  },

  // ---------------------------------------------------------------------
  {
    id: "laravel",
    label: "تطبيق Laravel مخصص",
    description: "نظام مبني من الأول: لوحة تحكم وصلاحيات وتكاملات. يبدأ بتحليل متطلبات.",
    stages: [
      baseStages[0]!,
      {
        name: "تحليل المتطلبات",
        gate: "Requirements Lock",
        gate_size: "big",
        our: 5,
        their: 4,
      },
      ...baseStages.slice(1, 6).map((s) => ({ ...s })),
      {
        name: "اختبار القبول الفني",
        gate: "QA Sign-off",
        gate_size: "big",
        our: 4,
        their: 2,
      },
      ...baseStages.slice(6).map((s) => ({ ...s })),
    ],
    accessItems: [
      ...baseAccess,
      {
        name: "بيانات السيرفر",
        note: "وصول SSH وقاعدة البيانات وبيئة النشر.",
        slow: false,
      },
      {
        name: "الأنظمة المراد الربط بها",
        note: "توثيق واجهات الأنظمة الخارجية ومفاتيحها.",
        slow: true,
      },
    ],
    contentItems: [
      ...baseContent,
      {
        group: "blocking",
        name: "أدوار المستخدمين وصلاحياتهم",
        ac: "جدول يوضّح كل دور وما يستطيع رؤيته وتعديله",
      },
      {
        group: "blocking",
        name: "نماذج البيانات والتقارير",
        ac: "الحقول المطلوبة في كل شاشة وشكل التقارير النهائية",
      },
    ],
    detailFields: [
      {
        key: "screens",
        label: "عدد الشاشات",
        type: "number",
        value: 10,
        hint: "كل شاشة = واجهة مستقلة لها منطقها الخاص.",
        days: { stage: "البرمجة والتطوير", per: 1, unit: 2 },
      },
      {
        key: "roles",
        label: "عدد أدوار المستخدمين",
        type: "number",
        value: 3,
        days: { stage: "تحليل المتطلبات", per: 1, unit: 3 },
      },
      {
        key: "auth",
        label: "طريقة تسجيل الدخول",
        type: "select",
        value: "بريد وكلمة مرور",
        options: ["بريد وكلمة مرور", "رمز تحقق بالجوال (OTP)", "تسجيل دخول موحّد (SSO)"],
        days: { stage: "البرمجة والتطوير", fixed: 4, onlyIf: "تسجيل دخول موحّد (SSO)" },
      },
      {
        key: "sms",
        label: "إشعارات برسائل الجوال",
        type: "boolean",
        access: [
          {
            name: "مزوّد الرسائل النصية",
            note: "حساب المزوّد ومفاتيح الربط والرصيد.",
            slow: false,
          },
        ],
      },
      {
        key: "integrations",
        label: "عدد التكاملات الخارجية",
        type: "number",
        hint: "أي نظام خارجي يتبادل معه التطبيق بيانات.",
        days: { stage: "البرمجة والتطوير", per: 3, unit: 1 },
        access: [
          {
            name: "توثيق الأنظمة الخارجية",
            note: "وثائق الـ API ومفاتيح الاختبار والإنتاج.",
            slow: true,
          },
        ],
      },
      {
        key: "reports",
        label: "تقارير وتصدير Excel",
        type: "boolean",
        value: true,
        days: { stage: "البرمجة والتطوير", fixed: 3 },
        content: [
          {
            group: "blocking",
            name: "نماذج التقارير المطلوبة",
            ac: "شكل كل تقرير وأعمدته ومعايير التصفية",
          },
        ],
      },
      { key: "uploads", label: "رفع ملفات ومستندات", type: "boolean", value: true },
      {
        key: "api",
        label: "واجهة API للعملاء",
        type: "boolean",
        days: { stage: "البرمجة والتطوير", fixed: 5 },
      },
      {
        key: "mobile",
        label: "تطبيق موبايل مصاحب",
        type: "boolean",
        hint: "يُسعَّر ويُخطَّط كمسار منفصل — يُضاف هنا للتوثيق فقط.",
        days: { stage: "البرمجة والتطوير", fixed: 10 },
      },
    ],
    intakeFields: [
      {
        key: "requirements_files",
        label: "وثيقة المتطلبات",
        type: "files",
        group: "متطلبات النظام",
        hint: "أي مستند يشرح المطلوب — ولو مسودّة.",
      },
      {
        key: "current_system",
        label: "النظام الحالي المستخدم",
        type: "textarea",
        group: "متطلبات النظام",
        hint: "اسم النظام ومشاكله وسبب الاستبدال.",
      },
      {
        key: "roles_desc",
        label: "أدوار المستخدمين وصلاحياتهم",
        type: "textarea",
        group: "متطلبات النظام",
        hint: "كل دور وما يستطيع رؤيته وتعديله.",
      },
      {
        key: "workflow_desc",
        label: "دورة العمل المطلوبة",
        type: "textarea",
        group: "متطلبات النظام",
        hint: "من يبدأ، ومن يعتمد، وما الذي يحدث بعد كل خطوة.",
      },
      {
        key: "sample_data_files",
        label: "بيانات نموذجية",
        type: "files",
        group: "متطلبات النظام",
      },
      {
        key: "integrations_files",
        label: "وثائق الأنظمة المراد الربط بها",
        type: "files",
        group: "متطلبات النظام",
      },
    ],
  },

  // ---------------------------------------------------------------------
  {
    id: "landing",
    label: "لاندينج بيج",
    description: "صفحة واحدة لحملة إعلانية أو حجز موعد. مسار مختصر وسريع.",
    stages: [
      { name: "اجتماع الاستلام", gate: "ميثاق موقَّع", gate_size: "small", our: 1, their: 1 },
      { name: "المحتوى والأصول", gate: "Content Lock", gate_size: "big", our: 0, their: 3 },
      { name: "التصميم", gate: "Design Lock", gate_size: "big", our: 3, their: 2 },
      { name: "البرمجة والتطوير", gate: "Build Complete", gate_size: "small", our: 4, their: 0 },
      { name: "الإطلاق والتسليم", gate: "Go Live", gate_size: "big", our: 1, their: 1 },
      { name: "الضمان", gate: null, gate_size: "small", our: 14, their: 0 },
    ],
    accessItems: accessWithout("بوابة الدفع", "شركة الشحن", "SMTP"),
    contentItems: [
      { group: "blocking", name: "الرسالة الأساسية", ac: "العرض والجملة التسويقية الرئيسية" },
      { group: "blocking", name: "اللوجو", ac: "بصيغة AI أو SVG أو EPS" },
      { group: "blocking", name: "دليل الألوان والخطوط", ac: "أكواد الألوان وأسماء الخطوط" },
      {
        group: "blocking",
        name: "حقول نموذج التسجيل",
        ac: "الحقول المطلوبة وجهة استقبال البيانات",
      },
      { group: "blocking", name: "بيانات التواصل", ac: "تليفون وواتساب وإيميل" },
      { group: "non_blocking", name: "الصور", ac: "1500px عرض كحد أدنى" },
    ],
    detailFields: [
      { key: "sections", label: "عدد أقسام الصفحة", type: "number", value: 6 },
      {
        key: "form_fields",
        label: "عدد حقول نموذج التسجيل",
        type: "number",
        value: 4,
      },
      {
        key: "crm",
        label: "ربط النموذج بـ CRM أو Google Sheet",
        type: "boolean",
        value: true,
        access: [
          {
            name: "حساب استقبال البيانات",
            note: "صلاحية على الـ CRM أو الشيت الذي تصل إليه التسجيلات.",
            slow: false,
          },
        ],
      },
      { key: "whatsapp", label: "زر واتساب مباشر", type: "boolean", value: true },
      {
        key: "countdown",
        label: "عدّاد تنازلي للعرض",
        type: "boolean",
        days: { stage: "البرمجة والتطوير", fixed: 1 },
      },
      {
        key: "video",
        label: "فيديو ترويجي",
        type: "boolean",
        content: [
          {
            group: "blocking",
            name: "الفيديو الترويجي",
            ac: "الفيديو النهائي بصيغة MP4، أو رابطه على يوتيوب",
          },
        ],
      },
      {
        key: "ab_test",
        label: "نسختان لاختبار A/B",
        type: "boolean",
        days: { stage: "التصميم", fixed: 2 },
      },
    ],
    intakeFields: [
      {
        key: "offer_text",
        label: "العرض والرسالة الأساسية",
        type: "textarea",
        group: "محتوى الحملة",
        hint: "ما الذي تقدّمه الصفحة بالظبط، وما الجملة التي تلخّصه.",
      },
      {
        key: "target_audience",
        label: "الجمهور المستهدف",
        type: "textarea",
        group: "محتوى الحملة",
        hint: "الفئة العمرية والمنطقة والاهتمامات.",
      },
      {
        key: "cta_text",
        label: "نص زر الإجراء",
        type: "text",
        group: "محتوى الحملة",
        placeholder: "احجز موعدك الآن",
      },
      {
        key: "form_fields_list",
        label: "حقول نموذج التسجيل",
        type: "textarea",
        group: "محتوى الحملة",
        hint: "حقل في كل سطر.",
        placeholder: "الاسم\nرقم الجوال\nالمدينة",
      },
      {
        key: "video_link",
        label: "رابط الفيديو الترويجي",
        type: "url",
        group: "محتوى الحملة",
      },
      {
        key: "lead_destination",
        label: "جهة استقبال التسجيلات",
        type: "text",
        group: "محتوى الحملة",
        hint: "بريد إلكتروني أو حساب CRM أو Google Sheet.",
      },
    ],
  },
];

export const DEFAULT_PROJECT_TYPE = "brochure";

export function projectType(id: string | null | undefined): ProjectType {
  return PROJECT_TYPES.find((t) => t.id === id) ?? PROJECT_TYPES[0]!;
}

export function projectTypeLabel(id: string | null | undefined): string {
  return PROJECT_TYPES.find((t) => t.id === id)?.label ?? "غير محدد";
}

// ---------------------------------------------------------------------------
// نموذج بيانات العميل
// ---------------------------------------------------------------------------

/** كل حقول النموذج لهذا النوع: المشتركة ثم الخاصة به. */
export function intakeFieldsFor(typeId: string): IntakeField[] {
  return [...COMMON_INTAKE, ...projectType(typeId).intakeFields];
}

/** الحقول مجمّعة بأقسامها، بترتيب ظهورها. */
export function intakeGroups(typeId: string): { group: string; fields: IntakeField[] }[] {
  const out: { group: string; fields: IntakeField[] }[] = [];
  for (const f of intakeFieldsFor(typeId)) {
    const found = out.find((g) => g.group === f.group);
    if (found) found.fields.push(f);
    else out.push({ group: f.group, fields: [f] });
  }
  return out;
}

/** يقرأ البيانات المحفوظة ويتجاهل أي مفتاح لا يخص هذا النوع. */
export function readIntake(typeId: string, saved: unknown): IntakeData {
  const fields = intakeFieldsFor(typeId);
  const out: IntakeData = {};
  for (const f of fields) {
    out[f.key] = isFileField(f) ? [] : f.type === "boolean" ? false : "";
  }
  if (!saved || typeof saved !== "object" || Array.isArray(saved)) return out;

  const byKey = new Map(fields.map((f) => [f.key, f]));
  for (const [k, v] of Object.entries(saved as Record<string, unknown>)) {
    const field = byKey.get(k);
    if (!field) continue;

    if (isFileField(field)) {
      // نقبل الملفات كاملة البيانات فقط — أي شكل آخر يُهمَل
      if (Array.isArray(v)) {
        out[k] = v.filter(
          (x): x is UploadedFile =>
            !!x && typeof x === "object" && typeof (x as UploadedFile).id === "string",
        );
      }
    } else if (typeof v === "string" || typeof v === "boolean") {
      out[k] = v;
    }
  }
  return out;
}

/** ما ملأه العميل فعلًا — للعرض على الأدمن. */
export type IntakeItem = {
  label: string;
  value: string;
  isLink: boolean;
  files: UploadedFile[];
};

export function filledIntake(
  typeId: string,
  data: IntakeData,
): { group: string; items: IntakeItem[] }[] {
  const out: { group: string; items: IntakeItem[] }[] = [];

  for (const f of intakeFieldsFor(typeId)) {
    const raw = data[f.key];
    let item: IntakeItem;

    if (isFileField(f)) {
      const files = fileList(raw);
      if (files.length === 0) continue;
      item = { label: f.label, value: "", isLink: false, files };
    } else if (f.type === "boolean") {
      if (raw !== true) continue;
      item = { label: f.label, value: "نعم", isLink: false, files: [] };
    } else {
      if (typeof raw !== "string" || raw.trim() === "") continue;
      item = { label: f.label, value: raw.trim(), isLink: f.type === "url", files: [] };
    }

    const found = out.find((g) => g.group === f.group);
    if (found) found.items.push(item);
    else out.push({ group: f.group, items: [item] });
  }

  return out;
}

/** عدد الحقول التي ملأها العميل من إجمالي الحقول — مؤشر اكتمال. */
export function intakeProgress(
  typeId: string,
  data: IntakeData,
): { filled: number; total: number } {
  const fields = intakeFieldsFor(typeId);
  const filled = fields.filter((f) => {
    const v = data[f.key];
    if (isFileField(f)) return fileList(v).length > 0;
    if (f.type === "boolean") return v === true;
    return typeof v === "string" && v.trim() !== "";
  }).length;

  return { filled, total: fields.length };
}

// ---------------------------------------------------------------------------
// تطبيق أثر التفاصيل
// ---------------------------------------------------------------------------

/** القيم الافتراضية لحقول النوع. */
export function defaultDetails(typeId: string): TypeDetails {
  const out: TypeDetails = {};
  for (const f of projectType(typeId).detailFields) {
    out[f.key] = f.value ?? (f.type === "boolean" ? false : f.type === "number" ? 0 : "");
  }
  return out;
}

/** هل الحقل «مفعّل» بقيمته الحالية؟ */
function isActive(field: DetailField, value: unknown): boolean {
  if (field.type === "boolean") return value === true;
  if (field.type === "number") return Number(value) > (field.activeAbove ?? 0);
  return typeof value === "string" && value.trim() !== "";
}

/** الكمية التي يُحسب عليها أثر المدة: قيمة الحقل نفسه أو حقل آخر يشير إليه. */
function daysCount(field: DetailField, details: TypeDetails): number {
  const key = field.days?.countFrom ?? field.key;
  return Number(details[key]) || 0;
}

/** يقرأ التفاصيل المحفوظة ويكمّل الناقص بالقيم الافتراضية. */
export function readDetails(typeId: string, saved: unknown): TypeDetails {
  const base = defaultDetails(typeId);
  if (!saved || typeof saved !== "object" || Array.isArray(saved)) return base;
  for (const [k, v] of Object.entries(saved as Record<string, unknown>)) {
    if (k in base && (typeof v === "number" || typeof v === "boolean" || typeof v === "string")) {
      base[k] = v;
    }
  }
  return base;
}

/** مراحل النوع بعد إضافة الأيام الناتجة عن التفاصيل. */
export function stagesForType(typeId: string, details: TypeDetails = {}): StageTemplate[] {
  const type = projectType(typeId);
  const stages = type.stages.map((s) => ({ ...s }));

  for (const field of type.detailFields) {
    const value = details[field.key];
    if (!field.days || !isActive(field, value)) continue;
    if (field.days.onlyIf && value !== field.days.onlyIf) continue;

    const target = stages.find((s) => s.name === field.days!.stage);
    if (!target) continue;

    if (field.days.fixed) {
      target.our += field.days.fixed;
    }
    if (field.days.per && field.days.unit) {
      // الوحدة الأولى داخلة في التقدير الأساسي، فنحسب ما زاد عنها
      const extra = Math.max(0, Math.ceil(daysCount(field, details) / field.days.unit) - 1);
      target.our += extra * field.days.per;
    }
  }

  return stages;
}

/** عناصر الوصول: قالب النوع + ما تطلبه التفاصيل، بدون تكرار. */
export function accessForType(typeId: string, details: TypeDetails = {}): AccessTemplate[] {
  const type = projectType(typeId);
  const out = type.accessItems.map((a) => ({ ...a }));

  for (const field of type.detailFields) {
    if (!field.access || !isActive(field, details[field.key])) continue;
    for (const item of field.access) {
      if (!out.some((a) => a.name === item.name)) out.push({ ...item });
    }
  }

  return out;
}

/** عناصر المحتوى: قالب النوع + ما تطلبه التفاصيل، بدون تكرار. */
export function contentForType(typeId: string, details: TypeDetails = {}): ContentTemplate[] {
  const type = projectType(typeId);
  const out = type.contentItems.map((c) => ({ ...c }));

  for (const field of type.detailFields) {
    if (!field.content || !isActive(field, details[field.key])) continue;
    for (const item of field.content) {
      if (!out.some((c) => c.name === item.name)) out.push({ ...item });
    }
  }

  return out;
}

/**
 * وصف أثر التفاصيل الحالية — يُعرض للأدمن وهو بيملأ الحقول ليشوف نتيجة
 * اختياراته قبل ما يعتمد، بدل ما الأثر يحصل في الخفاء.
 */
export function detailEffectSummary(
  typeId: string,
  details: TypeDetails,
  options: { includeDays?: boolean } = {},
): string[] {
  const { includeDays = true } = options;
  const type = projectType(typeId);
  const content: string[] = [];
  const access: string[] = [];
  // الأيام تتجمّع لكل مرحلة في سطر واحد بدل سطر لكل حقل
  const daysByStage = new Map<string, number>();

  for (const field of type.detailFields) {
    const value = details[field.key];
    if (!isActive(field, value)) continue;

    for (const c of field.content ?? []) {
      content.push(`يُطلب من العميل: ${c.name}`);
    }
    for (const a of field.access ?? []) {
      access.push(`صلاحية مطلوبة: ${a.name}${a.slow ? " (مدة طويلة — يُبدأ فورًا)" : ""}`);
    }

    if (field.days && (!field.days.onlyIf || value === field.days.onlyIf)) {
      let extra = field.days.fixed ?? 0;
      if (field.days.per && field.days.unit) {
        extra +=
          Math.max(0, Math.ceil(daysCount(field, details) / field.days.unit) - 1) * field.days.per;
      }
      if (extra > 0) {
        daysByStage.set(field.days.stage, (daysByStage.get(field.days.stage) ?? 0) + extra);
      }
    }
  }

  // أثر المدد تقدير داخلي لفريق أرقام — لا يُعرض للعميل قبل التسعير
  const days = includeDays
    ? [...daysByStage].map(([stage, d]) => `+${d} يوم عمل على مرحلة «${stage}»`)
    : [];

  return [...days, ...content, ...access];
}

/** ملخّص نصي للتفاصيل — يُعرض في الميثاق وصفحة المشروع. */
export function describeDetails(typeId: string, details: TypeDetails): string[] {
  return projectType(typeId)
    .detailFields.filter((f) => isActive(f, details[f.key]))
    .map((f) => {
      const v = details[f.key];
      if (f.type === "boolean") return f.label;
      return `${f.label}: ${v}`;
    });
}
