<?php

namespace App\Http\Controllers;

use App\Support\{BranchAccess, CatalogQuery, DecimalId, PurchaseStore};
use Brick\Math\BigDecimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    public function __construct(private readonly PurchaseStore $store) {}

    private function scope(Request $request, BranchAccess $access): array
    {
        $filters = $request->validate(['branch_id' => ['nullable','string'], 'search' => ['nullable','string','max:120'], 'per_page' => ['nullable','integer','min:1','max:50']]);
        $branches = $access->branchesFor($request->user());
        abort_if($branches->isEmpty(), 403);
        $ids = $branches->pluck('id')->all();
        $branchId = isset($filters['branch_id']) ? DecimalId::parse($filters['branch_id'], 'branch_id') : null;
        abort_if($branchId !== null && !in_array($branchId, $ids, true), 403);
        $warehouses = $this->store->identity->rows('warehouses', ['branch_id' => ['$in' => $branchId === null ? $ids : [$branchId]]])->pluck('_id')->all();
        return [$filters, $branches, $warehouses];
    }

    private function joined(array $row): ?array
    {
        $scope = $this->store->scope($row['warehouse_id']);
        $lot = $this->store->find('product_lots', $row['lot_id']);
        $product = $lot ? $this->store->catalog->find('products', $lot['product_id']) : null;
        $unit = $product ? $this->store->catalog->find('units', $product['unit_id']) : null;
        if (!$scope || !$lot || !$product || !$unit) return null;
        return $this->store->present($row) + ['branch_id' => $scope['branch']['_id'], 'branch_name' => $scope['branch']['name'], 'warehouse_name' => $scope['warehouse']['name'], 'lot_no' => $lot['lot_no'], 'expires_on' => $lot['expires_on'], 'product_code' => $product['code'], 'product_name' => $product['name'], 'expiry_warning_days' => $product['expiry_warning_days'], 'unit_name' => $unit['name']];
    }

    public function index(Request $request, BranchAccess $access): JsonResponse
    {
        [$filters, $branches, $warehouses] = $this->scope($request, $access);
        $scope = $this->store->rows('inventories', ['warehouse_id' => ['$in' => $warehouses]], ['id_sort' => 1]);
        $rows = $scope->map(fn ($row) => $this->joined($row))->filter()->values();
        $today = now('Asia/Ho_Chi_Minh')->toDateString();
        $expiring = $rows->filter(fn ($row) => BigDecimal::of($row['quantity'])->isGreaterThan(0) && $row['expires_on'] !== null && $row['expires_on'] >= $today && $row['expires_on'] <= now('Asia/Ho_Chi_Minh')->startOfDay()->addDays($row['expiry_warning_days'] ?? 30)->toDateString())->pluck('lot_id')->unique()->count();
        $stockBranches = $scope->filter(fn ($row) => BigDecimal::of((string) $row['quantity'])->isGreaterThan(0))->map(fn ($row) => $this->store->identity->find('warehouses', $row['warehouse_id'])['branch_id'])->unique()->count();
        $rows = CatalogQuery::search($rows, $filters['search'] ?? null, ['product_code','product_name','lot_no'])
            ->sortBy([['product_name','asc'], ['lot_no','asc'], fn ($a, $b) => (int) $a['id'] <=> (int) $b['id']])->values()
            ->map(function ($row) use ($today) {
                $warning = now('Asia/Ho_Chi_Minh')->startOfDay()->addDays($row['expiry_warning_days'] ?? 30)->toDateString();
                $row['status'] = match (true) { $row['expires_on'] === null => 'Không có hạn dùng', $row['expires_on'] < $today => 'Hết hạn', $row['expires_on'] <= $warning => 'Gần hết hạn', default => 'Bình thường' };
                return array_intersect_key($row, array_flip(['id','quantity','product_code','product_name','branch_id','branch_name','warehouse_name','lot_no','expires_on','expiry_warning_days','unit_name','status']));
            });
        return response()->json(CatalogQuery::page($rows, $request, (int) ($filters['per_page'] ?? 20)) + ['branches' => $branches, 'summary' => ['inventory_rows' => $scope->count(), 'expiring_lots' => $expiring, 'branches_with_stock' => $stockBranches]]);
    }

    public function movements(Request $request, BranchAccess $access): JsonResponse
    {
        [$filters, $branches, $warehouses] = $this->scope($request, $access);
        $source=$this->store->rows('stock_movements', ['warehouse_id' => ['$in' => $warehouses]], ['occurred_at' => -1, 'id_sort' => -1]);
        $returned=app(\App\Commerce\CommerceStore::class)->rows('shop_return_movements',['warehouse_id'=>['$in'=>$warehouses]],['occurred_at'=>-1],10000);
        foreach($returned as $movement){$movement['_id']='return-'.$movement['_id'];$movement+=['purchase_receipt_id'=>null,'stock_transfer_id'=>null];$source->push($movement);}
        $rows = $source->sortByDesc(fn($row)=>$row['occurred_at']->toDateTimeImmutable()->format('U.u'))->values()->map(function ($row) {
            $joined = $this->joined($row);
            if (!$joined) return null;
            $receipt = $row['purchase_receipt_id'] === null ? null : $this->store->find('purchase_receipts', $row['purchase_receipt_id']);
            $sale = $row['sales_order_id'] === null ? null : $this->store->identity->runtime->database->selectCollection('sales_orders')->findOne(['_id' => $row['sales_order_id']], $this->store->options());
            $transfer = $row['stock_transfer_id'] === null ? null : $this->store->identity->runtime->database->selectCollection('stock_transfers')->findOne(['_id' => $row['stock_transfer_id']], $this->store->options());
            return $joined + ['receipt_no' => $receipt['receipt_no'] ?? null, 'order_no' => $sale['order_no'] ?? null, 'transfer_no' => $transfer['transfer_no'] ?? null];
        })->filter()->values();
        $rows = CatalogQuery::search($rows, $filters['search'] ?? null, ['product_name','product_code','lot_no','receipt_no','order_no','transfer_no'])->map(fn ($row) => array_intersect_key($row, array_flip(['id','movement_type','quantity_delta','occurred_at','purchase_receipt_id','sales_order_id','stock_transfer_id','warehouse_id','warehouse_name','branch_id','branch_name','lot_id','lot_no','product_code','product_name','unit_name','receipt_no','order_no','transfer_no'])));
        return response()->json(CatalogQuery::page($rows, $request, (int) ($filters['per_page'] ?? 20)) + ['branches' => $branches]);
    }
}
