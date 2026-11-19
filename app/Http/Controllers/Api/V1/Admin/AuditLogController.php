<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\StaffAccount;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $q = AuditLog::orderByDesc('id');
        $q->when($request->query('subject'), fn ($q, $s) => $q->where('subject_type', $s));
        $page = $q->paginate(50);
        $ids = $page->getCollection()->flatMap(fn ($e) => [$e->actor_id, $e->new_data['user_id'] ?? $e->old_data['user_id'] ?? null, $e->new_data['staff_account_id'] ?? $e->old_data['staff_account_id'] ?? null])->filter()->unique();
        // Rider and staff accounts have distinct ids; old rows (before staff accounts) name riders.
        $names = User::whereIn('id', $ids)->pluck('display_name', 'id')->union(StaffAccount::whereIn('id', $ids)->pluck('display_name', 'id'));

        return response()->json($page->through(fn ($e) => [
            'id' => $e->id, 'action' => $e->action, 'subject_type' => $e->subject_type, 'subject_id' => $e->subject_id,
            'actor' => $e->actor_id ? ($names[$e->actor_id] ?? 'Deleted account') : null,
            'actor_type' => $e->actor_type,
            'subject_user' => ($uid = $e->new_data['user_id'] ?? $e->old_data['user_id'] ?? $e->new_data['staff_account_id'] ?? $e->old_data['staff_account_id'] ?? null) ? ($names[$uid] ?? 'Deleted account') : null,
            'old_data' => $e->old_data, 'new_data' => $e->new_data, 'created_at' => $e->created_at?->toIso8601String(),
        ]));
    }
}
