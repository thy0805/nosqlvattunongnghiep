<?php

namespace App\Http\Controllers;

use App\Support\{DecimalId, IdentityStore, PartnerAccess};
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class UserManagementController extends Controller
{
    public function __construct(private readonly IdentityStore $store) {}

    private function ownerChains(Request $request, PartnerAccess $access): array
    {
        abort_unless($this->store->find('users', (string) $request->user()->id)['is_active'] ?? false, 403);
        $ids = $access->chainIds($request->user(), ['chain_owner'])->unique()->values()->all();
        abort_if($ids === [], 403);
        return $ids;
    }

    public function index(Request $request, PartnerAccess $access): JsonResponse
    {
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:80'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:50']]);
        $chainIds = $this->ownerChains($request, $access);
        $all = $this->store->joinedAssignments();
        $visible = $all->whereIn('chain_id', $chainIds)->pluck('user_id')->unique()->values()->all();
        $users = $this->store->rows('users', ['_id' => ['$in' => $visible]], ['username' => 1]);
        if (!empty($filters['search'])) {
            $characters = preg_split('//u', '%'.mb_strtolower(trim($filters['search'])).'%', -1, PREG_SPLIT_NO_EMPTY);
            $pattern = '';
            for ($i = 0; $i < count($characters); $i++) {
                $char = $characters[$i];
                if ($char === '\\' && isset($characters[$i + 1])) $pattern .= preg_quote($characters[++$i], '~');
                else $pattern .= match ($char) { '%' => '.*', '_' => '.', default => preg_quote($char, '~') };
            }
            $users = $users->filter(fn ($u) => preg_match('~^'.$pattern.'$~usD', mb_strtolower($u['username'])) === 1)->values();
        }
        $perPage = (int) ($filters['per_page'] ?? 20);
        $page = max(1, (int) $request->input('page', 1));
        $total = $users->count();
        $items = $users->slice(($page - 1) * $perPage, $perPage)->values()->map(function ($user) use ($all, $chainIds) {
            $assignments = $all->where('user_id', $user['_id'])->whereIn('chain_id', $chainIds)->sortBy('branch_name')->values();
            $outside = $all->where('user_id', $user['_id'])->where('status', 'active')->whereNotIn('chain_id', $chainIds)->isNotEmpty();
            $lastOwner = false;
            $assignments = $assignments->map(function ($a) use ($user, &$lastOwner) {
                $canRevoke = $a['status'] === 'active';
                if ($a['status'] === 'active' && $a['role_code'] === 'chain_owner' && $user['is_active']) {
                    if (!$this->store->otherOwner($a['chain_id'], null, $a['_id'])) $canRevoke = false;
                    if (!$this->store->otherOwner($a['chain_id'], $user['_id'])) $lastOwner = true;
                }
                return $this->store->publicRow($a, ['id', 'user_id', 'branch_id', 'status', 'starts_on', 'role_code', 'branch_name', 'chain_id']) + ['can_revoke' => $canRevoke];
            });
            return $this->store->publicRow($user, ['id', 'username', 'is_active', 'is_catalog_admin']) + [
                'assignments' => $assignments, 'can_toggle_active' => !$outside && (!$user['is_active'] || !$lastOwner),
                'lock_reason' => $outside ? 'Tài khoản còn quyền ở chuỗi khác.' : ($lastOwner && $user['is_active'] ? 'Đây là Chủ chuỗi hoạt động cuối cùng.' : null),
            ];
        });
        return response()->json(['data' => $items,
            'branches' => $this->store->rows('branches', ['chain_id' => ['$in' => $chainIds]], ['name' => 1])->map(fn ($x) => $this->store->publicRow($x, ['id', 'chain_id', 'name', 'code'])),
            'chains' => $this->store->rows('chains', ['_id' => ['$in' => $chainIds]], ['name' => 1])->map(fn ($x) => $this->store->publicRow($x, ['id', 'name'])),
            'roles' => $this->store->rows('roles', ['code' => ['$in' => ['chain_owner', 'branch_manager', 'sales_staff']]], ['id_sort' => 1])->map(fn ($x) => $this->store->publicRow($x, ['code', 'name'])),
            'pagination' => ['current_page' => $page, 'last_page' => max(1, (int) ceil($total / $perPage)), 'total' => $total],
        ]);
    }

    public function store(Request $request, PartnerAccess $access): JsonResponse
    {
        $unique = function ($attribute, $value, $fail): void {
            if ($this->store->rows('users', ['username' => $value])->isNotEmpty()) $fail('The username has already been taken.');
        };
        $data = $request->validate([
            'username' => ['required', 'string', 'alpha_dash', 'min:3', 'max:80', $unique],
            'password' => ['required', 'string', 'min:10', 'max:255'],
            'branch_id' => ['bail', 'required', 'string', DecimalId::rule(), $this->store->existsRule('branches')],
            'role_code' => ['required', 'in:chain_owner,branch_manager,sales_staff'], 'is_catalog_admin' => ['prohibited'],
        ]);
        $chainIds = $this->ownerChains($request, $access);
        $branch = $this->store->find('branches', $data['branch_id']);
        abort_unless(in_array($branch['chain_id'], $chainIds, true), 403);
        $password = Hash::make($data['password']);
        $id = $this->store->transaction(function () use ($data, $branch, $password, $request) {
            $this->ownerChains($request, app(PartnerAccess::class));
            $id = $this->store->insert('users', ['username' => $data['username'], 'password_hash' => $password]);
            $this->store->insert('user_role_assignments', ['user_id' => $id, 'role_id' => $this->store->roleId($data['role_code']),
                'branch_id' => $branch['_id'], 'starts_on' => now('Asia/Ho_Chi_Minh')->toDateString(), 'status' => 'active']);
            return $id;
        });
        return response()->json(['id' => $id], 201);
    }

    public function setActive(Request $request, PartnerAccess $access, string $id): JsonResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $this->ownerChains($request, $access);
        $this->store->transaction(function () use ($request, $access, $id, $data) {
            $chainIds = $this->ownerChains($request, $access);
            $user = $this->store->find('users', $id);
            abort_if($user === null, 404);
            $userChains = $this->store->assignments($id)->where('status', 'active')->pluck('chain_id')->unique()->values()->all();
            abort_unless($userChains !== [] && array_diff($userChains, $chainIds) === [], 403);
            if (!$data['is_active'] && $user['is_active']) $this->store->guardLastOwner($id, $userChains);
            $this->store->update('users', $id, ['is_active' => (bool) $data['is_active']]);
        });
        return response()->json(['id' => $id, 'is_active' => (bool) $data['is_active']]);
    }

    public function grant(Request $request, PartnerAccess $access, string $id): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['bail', 'required', 'string', DecimalId::rule(), $this->store->existsRule('branches')],
            'role_code' => ['required', 'in:chain_owner,branch_manager,sales_staff'],
        ]);
        $chainIds = $this->ownerChains($request, $access);
        $branch = $this->store->find('branches', $data['branch_id']);
        abort_unless(in_array($branch['chain_id'], $chainIds, true), 403);
        abort_unless($this->store->assignments($id)->whereIn('chain_id', $chainIds)->isNotEmpty(), 404);
        $assignmentId = $this->store->transaction(function () use ($request, $access, $id, $data, $branch) {
            $chains = $this->ownerChains($request, $access);
            abort_unless(in_array($branch['chain_id'], $chains, true), 403);
            $role = $this->store->roleId($data['role_code']);
            $existing = $this->store->rows('user_role_assignments', ['user_id' => $id, 'role_id' => $role, 'branch_id' => $branch['_id'], 'status' => 'active'])->first();
            if ($existing) return $existing['_id'];
            return $this->store->insert('user_role_assignments', ['user_id' => $id, 'role_id' => $role, 'branch_id' => $branch['_id'],
                'starts_on' => now('Asia/Ho_Chi_Minh')->toDateString(), 'status' => 'active']);
        });
        return response()->json(['id' => $assignmentId]);
    }

    public function revoke(Request $request, PartnerAccess $access, string $assignmentId): JsonResponse
    {
        $chainIds = $this->ownerChains($request, $access);
        $assignment = $this->store->joinedAssignments(['_id' => $assignmentId])->first();
        abort_if($assignment === null, 404);
        abort_unless(in_array($assignment['chain_id'], $chainIds, true), 403);
        $this->store->transaction(function () use ($request, $access, $assignment, $assignmentId) {
            abort_unless(in_array($assignment['chain_id'], $this->ownerChains($request, $access), true), 403);
            $current = $this->store->find('user_role_assignments', $assignmentId);
            abort_unless($current['status'] === 'active', 409);
            if ($assignment['role_code'] === 'chain_owner') $this->store->guardLastOwner($assignment['user_id'], [$assignment['chain_id']], $assignmentId);
            $this->store->update('user_role_assignments', $assignmentId, ['status' => 'revoked']);
        });
        return response()->json(['status' => 'revoked']);
    }
}
