<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Public contact form and the staff inbox. Messages are never exposed publicly; only staff with contact.view read them. */
class ContactController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'subject' => ['required', 'string', 'min:3', 'max:150'],
            'body' => ['required', 'string', 'min:20', 'max:5000'],
            'website' => ['nullable', 'string', 'max:0'], // honeypot: people never see this field
        ], ['website.max' => 'Leave this field empty.']);
        DB::table('contact_messages')->insert(['id' => (string) Str::uuid(), 'name' => trim($data['name']), 'email' => Str::lower(trim($data['email'])),
            'subject' => trim($data['subject']), 'body' => trim($data['body']), 'status' => 'new',
            'ip_hash' => hash_hmac('sha256', (string) $request->ip(), (string) config('app.key')), 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['message' => 'Thanks. Your message reached the team.'], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate(['status' => ['nullable', 'in:new,open,done,spam'], 'q' => ['nullable', 'string', 'max:100']]);
        $q = DB::table('contact_messages')->orderByDesc('created_at');
        $q->when($f['status'] ?? null, fn ($q, $s) => $q->where('status', $s), fn ($q) => $q->where('status', '!=', 'spam'));
        if ($s = trim($f['q'] ?? '')) {
            $like = '%'.addcslashes($s, '%_\\').'%';
            $q->where(fn ($w) => $w->whereLike('subject', $like)->orWhereLike('name', $like)->orWhereLike('email', $like)->orWhereLike('body', $like));
        }
        $page = $q->paginate(25, ['id', 'name', 'email', 'subject', 'body', 'status', 'read_at', 'created_at']);

        return response()->json(['data' => $page->items(), 'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
            'unread' => DB::table('contact_messages')->whereNull('read_at')->where('status', '!=', 'spam')->count()]]);
    }

    /** Mark read/unread or change the status. */
    public function update(Request $request, string $message): JsonResponse
    {
        $data = $request->validate(['status' => ['sometimes', 'in:new,open,done,spam'], 'read' => ['sometimes', 'boolean']]);
        $old = DB::table('contact_messages')->where('id', $message)->first(['status', 'read_at']) ?? abort(404);
        $changes = ['updated_at' => now(), 'handled_by_staff_id' => $request->user()->id];
        if (array_key_exists('read', $data)) {
            $changes['read_at'] = $data['read'] ? now() : null;
        }
        if (isset($data['status'])) {
            abort_unless($request->user()->hasPermission('contact.manage'), 403);
            $changes['status'] = $data['status'];
        }
        DB::transaction(function () use ($message, $changes, $old, $data) {
            DB::table('contact_messages')->where('id', $message)->update($changes);
            if (isset($data['status'])) {
                Audit::record('contact.'.$data['status'], 'contact_messages', ['status' => $old->status], ['status' => $data['status']], $message);
            }
        });

        return response()->json(['data' => DB::table('contact_messages')->find($message)]);
    }

    public function destroy(string $message): JsonResponse
    {
        abort_unless(DB::table('contact_messages')->where('id', $message)->delete() === 1, 404);
        Audit::record('contact.deleted', 'contact_messages', null, null, $message);

        return response()->json(null, 204);
    }
}
