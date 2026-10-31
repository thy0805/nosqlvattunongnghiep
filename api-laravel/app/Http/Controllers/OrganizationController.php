<?php

namespace App\Http\Controllers;

use App\Support\{BranchAccess, DecimalId, IdentityStore, PartnerAccess};
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Validation\ValidationException;

class OrganizationController extends Controller
{
    public function __construct(private readonly IdentityStore $store) {}

    public function index(Request $request, BranchAccess $access): JsonResponse
    {
        $allowed = $access->branchesFor($request->user());
        abort_if($allowed->isEmpty(), 403);
        $branches = $this->store->rows('branches', ['_id' => ['$in' => $allowed->pluck('id')->all()]], ['name' => 1]);
        return response()->json([
            'chains' => $this->store->rows('chains', ['_id' => ['$in' => $branches->pluck('chain_id')->unique()->values()->all()]], ['name' => 1])->map(fn ($r) => $this->store->publicRow($r)),
            'branches' => $branches->map(fn ($r) => $this->store->publicRow($r)),
            'warehouses' => $this->store->rows('warehouses', ['branch_id' => ['$in' => $allowed->pluck('id')->all()]], ['name' => 1])->map(fn ($r) => $this->store->publicRow($r)),
            'manageable_chain_ids' => $this->ownerChains($request, app(PartnerAccess::class)),
        ]);
    }

    public function createChain(Request $request, PartnerAccess $access): JsonResponse
    {
        abort_if($this->ownerChains($request, $access) === [], 403);
        $data = $request->validate(['name' => ['required', 'string', 'max:160'], 'first_branch_code' => ['required', 'string', 'max:40'],
            'first_branch_name' => ['required', 'string', 'max:160'], 'first_branch_address' => ['nullable', 'string']]);
        $created = $this->store->transaction(function () use ($request, $access, $data) {
            abort_if($this->ownerChains($request, $access) === [], 403);
            $chain = $this->store->insert('chains', ['name' => $data['name']]);
            $branch = $this->store->insert('branches', ['chain_id' => $chain, 'code' => $data['first_branch_code'],
                'name' => $data['first_branch_name'], 'address' => $data['first_branch_address'] ?? null]);
            $this->store->insert('user_role_assignments', ['user_id' => (string) $request->user()->id, 'role_id' => $this->store->roleId('chain_owner'),
                'branch_id' => $branch, 'starts_on' => now('Asia/Ho_Chi_Minh')->toDateString(), 'status' => 'active']);
            return ['chain_id' => $chain, 'branch_id' => $branch];
        });
        return response()->json($created, 201);
    }

    public function updateChain(Request $request, PartnerAccess $access, string $id): JsonResponse
    {
        $this->authorizeChain($request, $access, $id);
        $data = $request->validate(['name' => ['sometimes', 'required', 'string', 'max:160'], 'is_active' => ['sometimes', 'boolean']]);
        if (isset($data['is_active'])) $data['is_active'] = (bool) $data['is_active'];
        $this->store->transaction(function () use ($request, $access, $id, $data) {
            $this->authorizeChain($request, $access, $id);
            if ($data) $this->store->update('chains', $id, $data);
        });
        return response()->json(['data' => $this->store->publicRow($this->store->find('chains', $id))]);
    }

    public function createBranch(Request $request, PartnerAccess $access): JsonResponse
    {
        $data = $request->validate(['chain_id' => ['bail', 'required', 'string', DecimalId::rule(), $this->store->existsRule('chains')],
            'code' => ['required', 'string', 'max:40'], 'name' => ['required', 'string', 'max:160'], 'address' => ['nullable', 'string']]);
        $this->authorizeChain($request, $access, $data['chain_id']);
        $id = $this->store->transaction(function () use ($request, $access, $data) {
            $this->authorizeChain($request, $access, $data['chain_id']);
            $this->requireActiveChain($data['chain_id']);
            return $this->store->insert('branches', $data);
        });
        return response()->json(['data' => $this->store->publicRow($this->store->find('branches', $id))], 201);
    }

    public function updateBranch(Request $request, PartnerAccess $access, string $id): JsonResponse
    {
        $branch = $this->store->find('branches', $id);
        abort_if($branch === null, 404);
        $this->authorizeChain($request, $access, $branch['chain_id']);
        $data = $request->validate(['code' => ['sometimes', 'required', 'string', 'max:40'], 'name' => ['sometimes', 'required', 'string', 'max:160'],
            'address' => ['sometimes', 'nullable', 'string'],
            'default_sales_warehouse_id' => ['bail', 'sometimes', 'nullable', 'string', DecimalId::rule(), $this->store->existsRule('warehouses')],
            'is_active' => ['sometimes', 'boolean']]);
        if (isset($data['is_active'])) $data['is_active'] = (bool) $data['is_active'];
        $this->store->transaction(function () use ($request, $access, $id, $data) {
            $branch = $this->store->find('branches', $id);
            abort_if(!$branch, 404);
            $this->authorizeChain($request, $access, $branch['chain_id']);
            if (array_key_exists('default_sales_warehouse_id', $data) && $data['default_sales_warehouse_id'] !== null
                && $this->store->find('warehouses', $data['default_sales_warehouse_id'])['branch_id'] !== $id) {
                throw ValidationException::withMessages(['default_sales_warehouse_id' => 'Kho mặc định phải thuộc chi nhánh này.']);
            }
            $active = $data['is_active'] ?? $branch['is_active'];
            $warehouse = array_key_exists('default_sales_warehouse_id', $data) ? $data['default_sales_warehouse_id'] : $branch['default_sales_warehouse_id'];
            if ($active && $warehouse === null) throw ValidationException::withMessages(['default_sales_warehouse_id' => 'Cần chọn kho bán mặc định trước khi kích hoạt chi nhánh.']);
            if ($active) $this->requireActiveChain($branch['chain_id']);
            if ($data) $this->store->update('branches', $id, $data);
        });
        return response()->json(['data' => $this->store->publicRow($this->store->find('branches', $id))]);
    }

    public function createWarehouse(Request $request, PartnerAccess $access): JsonResponse
    {
        $data = $request->validate(['branch_id' => ['bail', 'required', 'string', DecimalId::rule(), $this->store->existsRule('branches')],
            'code' => ['required', 'string', 'max:40'], 'name' => ['required', 'string', 'max:160'], 'warehouse_type' => ['required', 'string', 'max:40']]);
        $branch = $this->store->find('branches', $data['branch_id']);
        $this->authorizeChain($request, $access, $branch['chain_id']);
        $id = $this->store->transaction(function () use ($request, $access, $branch, $data) {
            $this->authorizeChain($request, $access, $branch['chain_id']);
            $this->requireActiveChain($branch['chain_id']);
            return $this->store->insert('warehouses', $data);
        });
        return response()->json(['data' => $this->store->publicRow($this->store->find('warehouses', $id))], 201);
    }

    public function updateWarehouse(Request $request, PartnerAccess $access, string $id): JsonResponse
    {
        $warehouse = $this->store->find('warehouses', $id);
        abort_if(!$warehouse, 404);
        $chain = $this->store->find('branches', $warehouse['branch_id'])['chain_id'];
        $this->authorizeChain($request, $access, $chain);
        $data = $request->validate(['code' => ['sometimes', 'required', 'string', 'max:40'], 'name' => ['sometimes', 'required', 'string', 'max:160'], 'warehouse_type' => ['sometimes', 'required', 'string', 'max:40']]);
        $this->store->transaction(function () use ($request, $access, $chain, $id, $data) {
            $this->authorizeChain($request, $access, $chain);
            if ($data) $this->store->update('warehouses', $id, $data);
        });
        return response()->json(['data' => $this->store->publicRow($this->store->find('warehouses', $id))]);
    }

    private function ownerChains(Request $request, PartnerAccess $access): array
    {
        abort_unless($this->store->find('users', (string) $request->user()->id)['is_active'] ?? false, 403);
        return $access->chainIds($request->user(), ['chain_owner'])->unique()->values()->all();
    }

    private function authorizeChain(Request $request, PartnerAccess $access, string $chainId): void
    {
        abort_unless(in_array($chainId, $this->ownerChains($request, $access), true), 403);
    }

    private function requireActiveChain(string $id): void
    {
        if (!($this->store->find('chains', $id)['is_active'] ?? false)) throw ValidationException::withMessages(['chain_id' => 'Chuỗi đã ngừng hoạt động.']);
    }
}
