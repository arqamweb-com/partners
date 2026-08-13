<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Upload;
use App\Services\ProjectParty;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * رفع الملفات وتنزيلها.
 *
 * الملفات على قرص خاص (storage/app/private/uploads) خارج أي مسار يصله
 * المتصفح، ولا تُقدَّم إلا عبر هذا الـ controller بعد فحص الصلاحية.
 * الاسم المخزَّن عشوائي ولا علاقة له بالاسم الأصلي.
 */
class FileController extends Controller
{
    private const MAX_BYTES = 8 * 1024 * 1024;

    /** الامتدادات المسموحة — الامتداد هو الحكم، والنوع فحص إضافي. */
    private const ALLOWED = [
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv',
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg',
        'zip', 'rar',
    ];

    /** صيغ تُعرض داخل المتصفح؛ ما عداها يُنزَّل. SVG ليست منها عمدًا. */
    private const INLINE = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'];

    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'file' => [
                'required', 'file',
                'max:'.(self::MAX_BYTES / 1024),
                'mimes:'.implode(',', self::ALLOWED),
            ],
        ], [
            'file.max'   => 'الملف أكبر من الحد المسموح (8 ميجابايت).',
            'file.mimes' => 'صيغة غير مسموحة. المسموح: '.implode('، ', self::ALLOWED).'.',
        ]);

        $file = $request->file('file');

        // اسم عشوائي على قرص خاص — لا يمكن تخمين مسار ملف ولا تنفيذه
        $path = $file->store('uploads/'.now()->format('Y/m'), 'private');

        $upload = Upload::create([
            'user_id'       => $request->user()->id,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'stored_path'   => $path,
            'mime'          => $file->getMimeType() ?: 'application/octet-stream',
            'size_bytes'    => $file->getSize(),
        ]);

        return response()->json(['data' => [
            'id'   => $upload->id,
            'name' => $upload->original_name,
            'size' => $upload->size_bytes,
        ]], 201);
    }

    /**
     * يربط ملفات مرفوعة بمشروع بعد حفظه.
     * لا يُقبل إلا ما رفعه المستخدم نفسه ولم يُربط بعد — فلا يسحب أحد
     * ملف غيره إلى مشروعه.
     */
    public function claim(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $data = $request->validate([
            'file_ids'   => ['required', 'array', 'max:50'],
            'file_ids.*' => ['uuid'],
        ]);

        $claimed = Upload::query()
            ->whereIn('id', $data['file_ids'])
            ->where('user_id', $request->user()->id)
            ->whereNull('project_id')
            ->update(['project_id' => $project->id]);

        return response()->json(['data' => ['claimed' => $claimed]]);
    }

    public function download(Request $request, Upload $upload): StreamedResponse
    {
        abort_unless($this->mayRead($request, $upload), 403, 'ليس لديك صلاحية على هذا الملف.');

        $disk = Storage::disk('private');
        abort_unless($disk->exists($upload->stored_path), 404, 'الملف غير موجود على السيرفر.');

        return $disk->response(
            $upload->stored_path,
            $upload->original_name,
            [
                'Content-Type'            => $upload->mime,
                'X-Content-Type-Options'  => 'nosniff',
                // يمنع تنفيذ أي سكربت داخل ملف يُعرض في المتصفح (SVG مثلًا)
                'Content-Security-Policy' => "default-src 'none'; sandbox",
                'Cache-Control'           => 'private, max-age=600',
            ],
            in_array($upload->mime, self::INLINE, true) ? 'inline' : 'attachment',
        );
    }

    public function destroy(Request $request, Upload $upload): JsonResponse
    {
        $user = $request->user();

        abort_unless(
            $user->isSuperUser() || $upload->user_id === $user->id,
            403,
            'ليس لديك صلاحية على هذا الملف.',
        );

        Storage::disk('private')->delete($upload->stored_path);
        $upload->delete();

        return response()->json(['data' => ['ok' => true]]);
    }

    private function mayRead(Request $request, Upload $upload): bool
    {
        $user = $request->user();

        if ($upload->user_id === $user->id || $user->seesAllProjects()) {
            return true;
        }

        return $upload->project_id !== null
            && ProjectParty::for($user, $upload->project)->isMember();
    }
}
