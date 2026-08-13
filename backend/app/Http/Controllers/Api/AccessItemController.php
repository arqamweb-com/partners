<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccessItem;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccessItemController extends Controller
{
    public function index(Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json(['data' => $project->accessItems]);
    }

    /**
     * تعليم البند مُسلَّمًا أو إرجاعه.
     * provided_by و provided_at من الجلسة والسيرفر — لا يُنسب التسليم لغير فاعله.
     */
    public function toggle(Request $request, AccessItem $accessItem): JsonResponse
    {
        $this->authorize('toggleDone', $accessItem);

        $done = ! $accessItem->is_done;

        $accessItem->update([
            'is_done'     => $done,
            'provided_by' => $done ? $request->user()->id : null,
            'provided_at' => $done ? now() : null,
        ]);

        return response()->json(['data' => $accessItem->fresh()]);
    }

    public function update(Request $request, AccessItem $accessItem): JsonResponse
    {
        $this->authorize('manage', $accessItem);

        $accessItem->update($request->validate([
            'name'    => ['sometimes', 'string', 'max:255'],
            'note'    => ['sometimes', 'nullable', 'string', 'max:4000'],
            'is_slow' => ['sometimes', 'boolean'],
        ]));

        return response()->json(['data' => $accessItem->fresh()]);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $item = $project->accessItems()->create([
            ...$request->validate([
                'name'    => ['required', 'string', 'max:255'],
                'note'    => ['nullable', 'string', 'max:4000'],
                'is_slow' => ['boolean'],
            ]),
            'item_order' => (int) $project->accessItems()->max('item_order') + 1,
        ]);

        return response()->json(['data' => $item], 201);
    }
}
