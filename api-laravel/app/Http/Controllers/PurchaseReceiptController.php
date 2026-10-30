<?php

namespace App\Http\Controllers;

use App\Support\{BranchAccess, CatalogQuery, DecimalId, DecimalLimit, PurchaseReceiptService, PurchaseStore, ReceiptAccess};
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PurchaseReceiptController extends Controller
{
    public function __construct(private readonly PurchaseStore $store) {}

    public function index(Request $request, BranchAccess $branches, ReceiptAccess $access): JsonResponse
    {
        $filters = $request->validate(['branch_id' => ['bail', 'nullable', 'string', DecimalId::rule()], 'status' => ['nullable', 'in:draft,submitted,approved,rejected'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:50']]);
        $allowed = $branches->branchesFor($request->user());
        $ids = $allowed->pluck('id')->all();
        abort_if($ids === [], 403);
        if (isset($filters['branch_id'])) abort_unless(in_array($filters['branch_id'], $ids, true), 403);
        $fields = ['id','receipt_no','status','total_amount','supplier_invoice_no','supplier_invoice_date','supplier_invoice_total','received_at','created_by','approved_by','rejected_by','branch_id','chain_id','branch_name','warehouse_id','warehouse_name','supplier_id','supplier_name','creator_name'];
        $rows = $this->store->rows('purchase_receipts', isset($filters['status']) ? ['status' => $filters['status']] : [], ['id_sort' => -1])
            ->map(fn ($row) => $this->joined($row, true))->filter(fn ($row) => $row && in_array($row['branch_id'], $ids, true) && (!isset($filters['branch_id']) || $filters['branch_id'] === $row['branch_id']))
            ->map(fn ($row) => $this->withActions((object) array_intersect_key($row, array_flip($fields)), $request, $access))->values();
        return response()->json(CatalogQuery::page($rows, $request, (int) ($filters['per_page'] ?? 20)) + ['branches' => $allowed]);
    }

    public function options(Request $request, BranchAccess $branches, ReceiptAccess $access): JsonResponse
    {
        $allowed = $branches->branchesFor($request->user());
        abort_if($allowed->isEmpty(), 403);
        $warehouses = $this->store->identity->rows('warehouses', ['branch_id' => ['$in' => $allowed->pluck('id')->all()]], ['name' => 1, 'id_sort' => 1])->map(function ($w) use ($request, $access) {
            $scope = $this->store->scope($w['_id']);
            if (!$scope || !$scope['branch']['is_active'] || !$scope['chain']['is_active'] || !$access->canCreate($request->user(), $w['branch_id'])) return null;
            return ['id' => $w['_id'], 'name' => $w['name'], 'branch_id' => $w['branch_id'], 'branch_name' => $scope['branch']['name'], 'chain_id' => $scope['chain']['_id']];
        })->filter()->sortBy('branch_name', SORT_STRING)->values();
        $suppliers = $this->store->catalog->rows('suppliers', ['chain_id' => ['$in' => $warehouses->pluck('chain_id')->unique()->values()->all()]], ['name' => 1, 'id_sort' => 1])->map(fn ($s) => $this->store->catalog->present($s, ['id','chain_id','name']));
        return response()->json(['warehouses' => $warehouses, 'suppliers' => $suppliers]);
    }

    public function show(Request $request, BranchAccess $branches, ReceiptAccess $access, string $id): JsonResponse
    {
        $receipt = $this->scopedReceipt($request, $branches, $id);
        $items = $this->store->rows('purchase_receipt_items', ['receipt_id' => $id], ['id_sort' => 1])->map(function ($row) {
            $lot = $this->store->find('product_lots', $row['lot_id']);
            $product = $lot ? $this->store->catalog->find('products', $lot['product_id']) : null;
            $unit = $product ? $this->store->catalog->find('units', $product['unit_id']) : null;
            if (!$lot || !$product || !$unit) return null;
            return $this->store->present($row, ['id','lot_id','quantity','unit_cost','line_total']) + array_intersect_key($lot, array_flip(['lot_no','manufactured_on','expires_on'])) + ['product_id' => $product['_id'], 'product_code' => $product['code'], 'product_name' => $product['name'], 'unit_name' => $unit['name']];
        })->filter()->values();
        return response()->json(['data' => $this->withActions($receipt, $request, $access), 'items' => $items]);
    }

    public function store(Request $request, PurchaseReceiptService $service, ReceiptAccess $access): JsonResponse
    {
        return $this->store->transaction(function () use ($request, $service) {
            $data = $this->validateDraft($request);
            $service->assertScope($data, $service->actor((string) $request->user()->id));
            return response()->json(['id' => $service->saveDraft($data, (string) $request->user()->id)], 201);
        });
    }

    public function update(Request $request, PurchaseReceiptService $service, ReceiptAccess $access, string $id): JsonResponse
    {
        return $this->store->transaction(function () use ($request, $service, $id) {
            $data = $this->validateDraft($request);
            $service->assertScope($data, $service->actor((string) $request->user()->id));
            $service->saveDraft($data, (string) $request->user()->id, $id);
            return response()->json(['id' => $id]);
        });
    }

    public function submit(Request $request, BranchAccess $branches, ReceiptAccess $access, PurchaseReceiptService $service, string $id): JsonResponse
    {
        return $this->store->transaction(function () use ($request, $branches, $service, $id) {
            $this->scopedReceipt($request, $branches, $id);
            $service->submit($id, (string) $request->user()->id);
            return response()->json(['status' => 'submitted']);
        });
    }

    public function approve(Request $request, BranchAccess $branches, ReceiptAccess $access, PurchaseReceiptService $service, string $id): JsonResponse
    {
        return $this->store->transaction(function () use ($request, $branches, $access, $service, $id) {
            $receipt = $this->scopedReceipt($request, $branches, $id);
            abort_if($receipt->created_by === (string) $request->user()->id, 403);
            abort_unless($access->canApprove($service->actor((string) $request->user()->id), $receipt->branch_id, $receipt->chain_id), 403);
            $service->approve($id, (string) $request->user()->id);
            return response()->json(['status' => 'approved']);
        });
    }

    public function reject(Request $request, BranchAccess $branches, ReceiptAccess $access, string $id): JsonResponse
    {
        $input = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);
        return $this->store->transaction(function () use ($request, $branches, $id, $input) {
            $this->scopedReceipt($request, $branches, $id);
            app(PurchaseReceiptService::class)->reject($id, (string) $request->user()->id, $input['reason'] ?? null);
            return response()->json(['status' => 'rejected']);
        });
    }

    private function validateDraft(Request $request): array
    {
        $data = $request->validate([
            'created_by' => ['prohibited'], 'approved_by' => ['prohibited'], 'receipt_no' => ['prohibited'], 'total_amount' => ['prohibited'],
            'supplier_invoice_no' => ['nullable', 'string', 'max:60'],
            'supplier_invoice_date' => ['nullable', 'date_format:Y-m-d'],
            'supplier_invoice_total' => ['nullable', 'string', 'numeric', 'min:0', 'decimal:0,2', DecimalLimit::rule(16, 2)],
            'supplier_id' => ['bail', 'required', 'string', DecimalId::rule(), $this->store->catalog->existsRule('suppliers')],
            'warehouse_id' => ['bail', 'required', 'string', DecimalId::rule(), $this->store->identity->existsRule('warehouses')],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.product_id' => ['bail', 'required', 'string', DecimalId::rule(), $this->store->catalog->existsRule('products')],
            'items.*.lot_no' => ['required', 'string', 'max:80'],
            'items.*.manufactured_on' => ['nullable', 'date_format:Y-m-d'],
            'items.*.expires_on' => ['nullable', 'date_format:Y-m-d'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,3', DecimalLimit::rule(15, 3)],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0', 'decimal:0,2', DecimalLimit::rule(16, 2)],
            'items.*.line_total' => ['prohibited'],
        ]);
        foreach ($data['items'] as $index => $item) {
            if (! empty($item['manufactured_on']) && ! empty($item['expires_on']) && $item['expires_on'] <= $item['manufactured_on']) {
                throw ValidationException::withMessages(["items.{$index}.expires_on" => 'Hạn dùng phải sau ngày sản xuất.']);
            }
        }
        app(PurchaseReceiptService::class)->assertProducts($data['items']);
        return $data;
    }

    private function joined(array $row, bool $creator = false): ?array
    {
        $scope = $this->store->scope($row['warehouse_id']);
        $supplier = $this->store->catalog->find('suppliers', $row['supplier_id']);
        $user = $creator ? $this->store->identity->find('users', $row['created_by']) : null;
        if (!$scope || !$supplier || ($creator && !$user)) return null;
        $result = $this->store->present($row) + ['branch_id' => $scope['branch']['_id'], 'chain_id' => $scope['branch']['chain_id'], 'branch_name' => $scope['branch']['name'], 'warehouse_name' => $scope['warehouse']['name'], 'supplier_name' => $supplier['name']];
        if ($creator) $result['creator_name'] = $user['username'];
        return $result;
    }

    private function scopedReceipt(Request $request, BranchAccess $branches, string $id): object
    {
        $row = $this->store->find('purchase_receipts', $id);
        $receipt = $row ? $this->joined($row) : null;
        abort_if(!$receipt || !in_array($receipt['branch_id'], $branches->branchesFor($request->user())->pluck('id')->all(), true), 404);
        return (object) $receipt;
    }

    private function withActions(object $receipt, Request $request, ReceiptAccess $access): object
    {
        $isCreator = $receipt->created_by === (string) $request->user()->id;
        $canEdit = $receipt->status === 'draft' && $isCreator && $access->canCreate($request->user(), $receipt->branch_id);
        $canApprove = $receipt->status === 'submitted' && !$isCreator && $access->canApprove($request->user(), $receipt->branch_id, $receipt->chain_id);
        $receipt->can_edit = $canEdit; $receipt->can_submit = $canEdit; $receipt->can_approve = $canApprove; $receipt->can_reject = $canApprove;
        return $receipt;
    }
}
