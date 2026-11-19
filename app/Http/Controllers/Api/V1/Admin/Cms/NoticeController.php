<?php

namespace App\Http\Controllers\Api\V1\Admin\Cms;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Site-wide notices (announcement bar). Draft, published or archived, optionally scheduled. Wording rule: a notice is
 * about the website, never a live road or safety status.
 */
class NoticeController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => DB::table('notices')->orderByRaw("status = 'archived'")->orderByDesc('created_at')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $id = (string) Str::uuid();
        $data = $this->validated($request);
        DB::transaction(function () use ($id, $data, $request) {
            DB::table('notices')->insert($data + ['id' => $id, 'updated_by_staff_id' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            Audit::record('notice.created', 'notices', null, $data, $id);
        });

        return response()->json(['data' => DB::table('notices')->find($id)], 201);
    }

    public function update(Request $request, string $notice): JsonResponse
    {
        $old = DB::table('notices')->find($notice) ?? abort(404);
        $data = $this->validated($request);
        DB::transaction(function () use ($notice, $old, $data, $request) {
            DB::table('notices')->where('id', $notice)->update($data + ['updated_by_staff_id' => $request->user()->id, 'updated_at' => now()]);
            Audit::record('notice.'.$data['status'], 'notices', (array) $old, $data, $notice);
        });

        return response()->json(['data' => DB::table('notices')->find($notice)]);
    }

    public function destroy(string $notice): JsonResponse
    {
        abort_unless(DB::table('notices')->where('id', $notice)->delete() === 1, 404);
        Audit::record('notice.deleted', 'notices', null, null, $notice);

        return response()->json(null, 204);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'body' => ['required', 'string', 'min:5', 'max:300'],
            'level' => ['required', 'in:info,warning'],
            'link_label' => ['nullable', 'required_with:link_target', 'string', 'max:30'],
            'link_target' => ['nullable', 'string', 'max:300', 'regex:#^(/[a-z0-9\-/]*|https://\S+)$#'],
            'status' => ['required', 'in:draft,published,archived'],
            'publish_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after:publish_at'],
        ]);
    }
}
