<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\CrPriceItem;
use App\Models\Holiday;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * إعدادات النظام والأجازات وبنود التسعير.
 * القراءة لكل مسجَّل (الفرونت يحتاجها للحسابات)، والكتابة للأدمن وحده.
 */
class SettingsController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json([
            'settings'    => AppSetting::current(),
            'holidays'    => Holiday::orderBy('holiday_date')->get(),
            'price_items' => CrPriceItem::orderBy('name')->get(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $this->authorizeSuperUser($request);

        $settings = AppSetting::current();
        $settings->update($request->validate([
            'warning_threshold_days'  => ['sometimes', 'integer', 'min:0', 'max:365'],
            'freeze_threshold_days'   => ['sometimes', 'integer', 'min:0', 'max:365'],
            'reactivation_fee'        => ['sometimes', 'numeric', 'min:0'],
            'warranty_days'           => ['sometimes', 'integer', 'min:0', 'max:3650'],
            'revision_rounds_allowed' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'stage_defaults'          => ['sometimes', 'nullable', 'array'],
        ]));

        return response()->json(['data' => $settings->fresh()]);
    }

    public function storeHoliday(Request $request): JsonResponse
    {
        $this->authorizeSuperUser($request);

        $holiday = Holiday::create($request->validate([
            'holiday_date' => ['required', 'date', 'unique:holidays,holiday_date'],
            'label'        => ['nullable', 'string', 'max:255'],
        ]));

        // BusinessDays يخزّن الأجازات مؤقتًا — أي تعديل يبطّل الحساب القديم
        Cache::forget('holidays');

        return response()->json(['data' => $holiday], 201);
    }

    public function destroyHoliday(Request $request, Holiday $holiday): JsonResponse
    {
        $this->authorizeSuperUser($request);

        $holiday->delete();
        Cache::forget('holidays');

        return response()->json(['data' => ['ok' => true]]);
    }

    public function storePriceItem(Request $request): JsonResponse
    {
        $this->authorizePricing($request);

        $item = CrPriceItem::create($request->validate([
            'name'          => ['required', 'string', 'max:255'],
            'price'         => ['required', 'numeric', 'min:0'],
            'currency'      => ['nullable', 'string', 'size:3'],
            'duration_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
        ]));

        return response()->json(['data' => $item], 201);
    }

    public function destroyPriceItem(Request $request, CrPriceItem $crPriceItem): JsonResponse
    {
        $this->authorizePricing($request);

        $crPriceItem->delete();

        return response()->json(['data' => ['ok' => true]]);
    }

    private function authorizeSuperUser(Request $request): void
    {
        abort_unless($request->user()->isSuperUser(), 403, 'إعدادات النظام للأدمن وحده.');
    }

    private function authorizePricing(Request $request): void
    {
        abort_unless($request->user()->canPrice(), 403, 'بنود التسعير لمن يملك قرار التسعير.');
    }
}
