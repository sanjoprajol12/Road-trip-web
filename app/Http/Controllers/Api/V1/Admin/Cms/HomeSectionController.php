<?php

namespace App\Http\Controllers\Api\V1\Admin\Cms;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Support\HomeContent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Homepage sections. Staff edit drafts (data, order, visibility, schedule); "publish" copies every draft to the
 * public website at once. Archived sections are kept but never shown.
 */
class HomeSectionController extends Controller
{
    public function index(): JsonResponse
    {
        $rows = DB::table('home_sections')->orderByRaw('archived_at is not null')->orderBy('position')->get();

        return response()->json([
            'data' => $rows->map(fn ($s) => self::out($s)),
            'types' => collect(config('home.types'))->map(fn ($t, $k) => ['type' => $k, 'label' => $t['label']])->values(),
            'bundled_photos' => array_keys(config('home.bundled_photos')),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['type' => ['required', Rule::in(array_keys(config('home.types')))]]);
        $id = (string) Str::uuid();
        DB::transaction(function () use ($id, $data, $request) {
            DB::table('home_sections')->insert(['id' => $id, 'type' => $data['type'], 'position' => (int) DB::table('home_sections')->max('position') + 1,
                'is_visible' => false, 'draft_data' => json_encode(config("home.defaults.{$data['type']}")), 'updated_by_staff_id' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            Audit::record('home.section_created', 'home_sections', null, ['type' => $data['type']], $id);
        });

        return response()->json(['data' => self::out(DB::table('home_sections')->find($id))], 201);
    }

    public function update(Request $request, string $section): JsonResponse
    {
        $row = DB::table('home_sections')->find($section) ?? abort(404);
        $input = $request->validate([
            'data' => ['sometimes', 'array'],
            'is_visible' => ['sometimes', 'boolean'],
            'publish_at' => ['sometimes', 'nullable', 'date'],
            'expires_at' => ['sometimes', 'nullable', 'date', 'after:publish_at'],
        ]);
        $changes = [];
        if (array_key_exists('data', $input)) {
            $changes['draft_data'] = json_encode(HomeContent::validate($row->type, $input['data']));
        }
        foreach (['is_visible', 'publish_at', 'expires_at'] as $k) {
            if (array_key_exists($k, $input)) {
                $changes[$k] = $input[$k];
            }
        }
        DB::transaction(function () use ($row, $changes, $request) {
            DB::table('home_sections')->where('id', $row->id)->update($changes + ['updated_by_staff_id' => $request->user()->id, 'updated_at' => now()]);
            Audit::record('home.section_edited', 'home_sections', null, array_keys($changes), $row->id);
        });

        return response()->json(['data' => self::out(DB::table('home_sections')->find($row->id))]);
    }

    public function reorder(Request $request): JsonResponse
    {
        $ids = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['uuid', 'distinct', 'exists:home_sections,id']])['ids'];
        DB::transaction(function () use ($ids) {
            foreach ($ids as $i => $id) {
                DB::table('home_sections')->where('id', $id)->update(['position' => $i, 'updated_at' => now()]);
            }
            Audit::record('home.reordered', 'home_sections', null, ['ids' => $ids], 'all');
        });

        return response()->json(null, 204);
    }

    public function archive(Request $request, string $section, bool $restore = false): JsonResponse
    {
        $n = DB::table('home_sections')->where('id', $section)->update(['archived_at' => $restore ? null : now(), 'updated_by_staff_id' => $request->user()->id, 'updated_at' => now()]);
        abort_unless($n === 1, 404);
        Audit::record($restore ? 'home.section_restored' : 'home.section_archived', 'home_sections', null, null, $section);

        return response()->json(['data' => self::out(DB::table('home_sections')->find($section))]);
    }

    public function restore(Request $request, string $section): JsonResponse
    {
        return $this->archive($request, $section, true);
    }

    /** Copies every draft (data, order, visibility) to the public website in one step. */
    public function publish(Request $request): JsonResponse
    {
        $count = DB::transaction(function () {
            $n = DB::update('update home_sections set published_data = draft_data, published_position = position, published_visible = (is_visible and archived_at is null), published_at = ? ', [now()]);
            Audit::record('home.published', 'home_sections', null, ['sections' => $n], 'all');

            return $n;
        });

        return response()->json(['published' => $count]);
    }

    private static function out(object $s): array
    {
        return [
            'id' => $s->id, 'type' => $s->type, 'position' => $s->position, 'is_visible' => (bool) $s->is_visible,
            'data' => json_decode($s->draft_data, true),
            'published' => $s->published_data !== null,
            'changed' => $s->published_data === null || json_decode($s->draft_data, true) != json_decode($s->published_data, true)
                || $s->position !== $s->published_position || (bool) $s->is_visible !== (bool) $s->published_visible,
            'publish_at' => $s->publish_at, 'expires_at' => $s->expires_at, 'archived' => $s->archived_at !== null,
            'published_at' => $s->published_at,
        ];
    }
}
