<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\StaffRole;
use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Staff roles built from the permission registry. System roles cannot be deleted; the admin role's
 * permissions are fixed (all of them). Nobody can put a permission into a role that they do not hold.
 */
class RoleController extends Controller
{
    public function index(): JsonResponse
    {
        $members = DB::table('staff_account_roles')->select('role', DB::raw('count(*) as n'))->groupBy('role')->pluck('n', 'role');

        return response()->json(['data' => DB::table('roles')->orderByDesc('is_system')->orderBy('name')->get()->map(fn ($r) => [
            'slug' => $r->slug, 'name' => $r->name, 'description' => $r->description, 'is_system' => (bool) $r->is_system,
            'permissions' => $this->permissionsOf($r->slug), 'members' => (int) ($members[$r->slug] ?? 0),
        ])]);
    }

    /** The registry, grouped by module, for the role editor. */
    public function permissions(): JsonResponse
    {
        return response()->json(['data' => DB::table('permissions')->orderBy('module')->orderBy('key')->get(['key', 'module', 'action', 'description'])]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $slug = Str::of($data['name'])->lower()->ascii()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->limit(40, '')->toString();
        if (! preg_match('/^[a-z][a-z0-9_]{1,39}$/', $slug) || DB::table('roles')->where('slug', $slug)->exists()) {
            throw ValidationException::withMessages(['name' => ['Choose a different name (it must start with a letter and be unique).']]);
        }
        $this->assertHolds($request, $data['permissions']);
        DB::transaction(function () use ($slug, $data) {
            DB::table('roles')->insert(['slug' => $slug, 'name' => $data['name'], 'description' => $data['description'] ?? null, 'is_system' => false, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('permission_role')->insert(array_map(fn ($k) => ['role_slug' => $slug, 'permission_key' => $k], $data['permissions']));
            Audit::record('role.created', 'roles', null, ['slug' => $slug, 'name' => $data['name'], 'permissions' => $data['permissions']], $slug);
        });

        return response()->json(['data' => ['slug' => $slug]], 201);
    }

    public function update(Request $request, string $role): JsonResponse
    {
        $row = DB::table('roles')->where('slug', $role)->first() ?? abort(404);
        $data = $this->validated($request, $row);
        if ($role === StaffRole::Admin->value) {
            throw ValidationException::withMessages(['permissions' => ['The administrator role always has every permission.']]);
        }
        $old = $this->permissionsOf($role);
        // Taking a permission away is as sensitive as giving it: both need you to hold it.
        $this->assertHolds($request, array_values(array_unique([...$old, ...$data['permissions']])));
        DB::transaction(function () use ($role, $row, $data, $old) {
            DB::table('roles')->where('slug', $role)->update([
                'name' => $row->is_system ? $row->name : $data['name'], 'description' => $data['description'] ?? $row->description, 'updated_at' => now(),
            ]);
            DB::table('permission_role')->where('role_slug', $role)->delete();
            DB::table('permission_role')->insert(array_map(fn ($k) => ['role_slug' => $role, 'permission_key' => $k], $data['permissions']));
            Audit::record('role.updated', 'roles', ['name' => $row->name, 'permissions' => $old], ['name' => $data['name'], 'permissions' => $data['permissions']], $role);
        });

        return response()->json(['data' => ['slug' => $role, 'permissions' => $this->permissionsOf($role)]]);
    }

    public function destroy(string $role): JsonResponse
    {
        $row = DB::table('roles')->where('slug', $role)->first() ?? abort(404);
        if ($row->is_system) {
            throw ValidationException::withMessages(['role' => ['Built-in roles cannot be deleted.']]);
        }
        $members = DB::table('staff_account_roles')->where('role', $role)->count();
        if ($members > 0) {
            throw ValidationException::withMessages(['role' => ["Remove this role from {$members} ".($members === 1 ? 'person' : 'people').' first.']]);
        }
        DB::transaction(function () use ($role, $row) {
            DB::table('roles')->where('slug', $role)->delete();
            Audit::record('role.deleted', 'roles', ['name' => $row->name], null, $role);
        });

        return response()->json(null, 204);
    }

    /** @return list<string> */
    private function permissionsOf(string $slug): array
    {
        return $slug === StaffRole::Admin->value ? Permissions::keys()
            : DB::table('permission_role')->where('role_slug', $slug)->orderBy('permission_key')->pluck('permission_key')->all();
    }

    private function validated(Request $request, ?object $existing = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:60', Rule::unique('roles', 'name')->ignore($existing?->slug, 'slug')],
            'description' => ['nullable', 'string', 'max:300'],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', 'distinct', Rule::in(Permissions::keys())],
        ], ['permissions.*.in' => 'Unknown permission.']);
    }

    private function assertHolds(Request $request, array $keys): void
    {
        if ($missing = array_diff($keys, $request->user()->permissions())) {
            throw ValidationException::withMessages(['permissions' => ['You can only work with permissions you have yourself: '.implode(', ', $missing).'.']]);
        }
    }
}
