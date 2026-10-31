<?php

namespace App\Http\Controllers;

use App\Support\PartnerAccess;
use App\Support\DecimalId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Support\{CatalogStore, CatalogQuery};

class SupplierController extends Controller
{
    public function __construct(private readonly CatalogStore $store) {}

    public function index(Request $request, PartnerAccess $access): JsonResponse
    {
        $filters = $request->validate([
            'chain_id' => ['bail', 'nullable', 'string', DecimalId::rule()],
            'search' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $chainIds = $access->chainIds($request->user(), ['chain_owner', 'branch_manager'])->all();
        abort_if($chainIds === [], 403);
        if (isset($filters['chain_id'])) abort_unless(in_array($filters['chain_id'], array_map('strval', $chainIds), true), 403);

        $filter = ['chain_id' => ['$in' => array_values($chainIds)]];
        if (isset($filters['chain_id'])) $filter['chain_id'] = $filters['chain_id'];
        $chains = $this->store->identity->rows('chains', ['_id' => ['$in' => array_values($chainIds)]], ['name' => 1]);
        $byChain = $chains->keyBy('_id');
        $rows = $this->store->rows('suppliers', $filter, ['name' => 1, 'id_sort' => 1])->map(function ($row) use ($byChain) {
            $chain = $byChain->get($row['chain_id']);
            return $chain ? $this->store->present($row, ['id', 'chain_id', 'name', 'phone', 'address']) + ['chain_name' => $chain['name']] : null;
        })->filter()->values();
        $rows = CatalogQuery::search($rows, $filters['search'] ?? null, ['name', 'phone']);
        return response()->json(CatalogQuery::page($rows, $request, (int) ($filters['per_page'] ?? 20)) + [
            'chains' => $chains->map(fn ($row) => $this->store->identity->publicRow($row, ['id', 'name'])),
            'can_manage' => $access->chainIds($request->user(), ['chain_owner'])->isNotEmpty(),
        ]);
    }

    public function store(Request $request, PartnerAccess $access): JsonResponse
    {
        return $this->store->transaction(function () use ($request, $access) {
            $data = $request->validate([
                'chain_id' => ['bail', 'required', 'string', DecimalId::rule(), $this->store->identity->existsRule('chains')],
                'name' => ['required', 'string', 'max:160'],
                'phone' => ['nullable', 'string', 'max:30'],
                'address' => ['nullable', 'string'],
            ]);
            $this->authorizeChain($request, $access, $data['chain_id']);
            $id = $this->store->insert('suppliers', $data);

            return response()->json(['data' => $this->store->present($this->store->find('suppliers', $id))], 201);
        });
    }

    public function update(Request $request, PartnerAccess $access, string $id): JsonResponse
    {
        return $this->store->transaction(function () use ($request, $access, $id) {
            $supplier = $this->store->find('suppliers', $id);
            abort_if($supplier === null, 404);
            $this->authorizeChain($request, $access, $supplier['chain_id']);
            $data = $request->validate([
                'name' => ['sometimes', 'required', 'string', 'max:160'],
                'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
                'address' => ['sometimes', 'nullable', 'string'],
            ]);
            if ($data !== []) $this->store->update('suppliers', $id, $data);

            return response()->json(['data' => $this->store->present($this->store->find('suppliers', $id))]);
        });
    }

    private function authorizeChain(Request $request, PartnerAccess $access, string $chainId): void
    {
        abort_unless($this->store->identity->find('users', (string) $request->user()->id)['is_active'] ?? false, 403);
        abort_unless($access->chainIds($request->user(), ['chain_owner'])->contains($chainId), 403);
    }
}
