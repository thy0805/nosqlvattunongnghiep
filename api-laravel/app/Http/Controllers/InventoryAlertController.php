<?php

namespace App\Http\Controllers;

use App\Infrastructure\Mongo\Codec;
use App\Support\{BranchAccess, DecimalId, DecimalLimit, InventoryAlertStore};
use Brick\Math\BigDecimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class InventoryAlertController extends Controller
{
    public function index(Request $request, BranchAccess $access, InventoryAlertStore $store): JsonResponse
    {
        $filters = $request->validate(['branch_id' => ['nullable', 'string'], 'low_page' => ['nullable', 'integer', 'min:1'], 'expiry_page' => ['nullable', 'integer', 'min:1']]);
        $branches = $access->branchesFor($request->user());
        $allowed = $branches->pluck('id')->all();
        abort_if($allowed === [], 403);
        if (isset($filters['branch_id'])) {
            $id = DecimalId::parse($filters['branch_id'], 'branch_id');
            abort_unless(in_array($id, $allowed, true), 403);
            $allowed = [$id];
        }
        $data = $store->data($allowed);
        $response = ['branches' => $branches, 'as_of_date' => $data['as_of_date']];
        foreach (['low_stock' => 'low', 'expiring_lots' => 'expiry'] as $key => $prefix) {
            $page = (int) ($filters[$prefix.'_page'] ?? 1);
            $total = count($data[$key]);
            $response[$key] = array_slice($data[$key], ($page - 1) * 20, 20);
            $response[$prefix.'_pagination'] = ['current_page' => $page, 'last_page' => max(1, (int) ceil($total / 20)), 'total' => $total];
        }
        return response()->json($response);
    }

    public function thresholds(Request $request, BranchAccess $access, InventoryAlertStore $store): JsonResponse
    {
        $branches = $access->branchesFor($request->user());
        abort_if($branches->isEmpty(), 403);
        $allowed = $branches->pluck('id')->all();
        $assignments = $store->purchase->identity->assignments((string) $request->user()->id, true);
        $warehouses = [];
        foreach ($store->rows('warehouses') as $warehouse) {
            $scope = $store->purchase->scope($warehouse['_id']);
            if (!$scope || !in_array($scope['branch']['_id'], $allowed, true) || !$scope['branch']['is_active'] || !$scope['chain']['is_active']) continue;
            $warehouses[] = ['id' => $warehouse['_id'], 'name' => $warehouse['name'], 'branch_id' => $scope['branch']['_id'], 'branch_name' => $scope['branch']['name'], 'chain_id' => $scope['chain']['_id'], 'can_manage' => $this->canManage($assignments, $scope)];
        }
        usort($warehouses, fn ($a, $b) => strcmp($a['branch_name'], $b['branch_name']) ?: strcmp($a['name'], $b['name']));
        $settings = $store->rows('warehouse_product_settings', ['warehouse_id' => ['$in' => array_column($warehouses, 'id')]], ['warehouse_id' => 1, 'product_id' => 1])->map(fn ($row) => ['id' => $row['_id'], 'warehouse_id' => $row['warehouse_id'], 'product_id' => $row['product_id'], 'min_stock_quantity' => Codec::fixed($row['min_stock_quantity'], 3)])->all();
        usort($settings, fn ($a, $b) => $this->compareId($a['warehouse_id'], $b['warehouse_id']) ?: $this->compareId($a['product_id'], $b['product_id']));
        $products = [];
        foreach ($store->rows('products', ['is_active' => true], ['name' => 1]) as $product) {
            $category = $store->find('product_categories', $product['category_id']);
            $unit = $store->find('units', $product['unit_id']);
            if (!$category || !$category['is_active'] || !$unit || !$unit['is_active']) continue;
            $products[] = ['id' => $product['_id'], 'code' => $product['code'], 'name' => $product['name'], 'unit_name' => $unit['name']];
        }
        return response()->json(['warehouses' => $warehouses, 'settings' => $settings, 'products' => $products]);
    }

    public function setThreshold(Request $request, BranchAccess $access, InventoryAlertStore $store, string $warehouseId, string $productId): JsonResponse
    {
        $warehouseId = DecimalId::parse($warehouseId, 'warehouse_id');
        $productId = DecimalId::parse($productId, 'product_id');
        $data = $request->validate(['min_stock_quantity' => ['required', 'numeric', 'min:0', 'decimal:0,3', DecimalLimit::rule(15, 3)]]);
        $store->purchase->transaction(function () use ($request, $access, $store, $warehouseId, $productId, $data): void {
            $identity = $store->purchase->identity;
            abort_unless($identity->find('users', (string) $request->user()->id)['is_active'] ?? false, 403);
            $scope = $store->purchase->scope($warehouseId);
            abort_if($scope === null, 404);
            abort_unless(in_array($scope['branch']['_id'], $access->branchesFor($request->user())->pluck('id')->all(), true), 403);
            abort_unless($this->canManage($identity->assignments((string) $request->user()->id, true), $scope), 403);
            if (!$scope['branch']['is_active'] || !$scope['chain']['is_active']) throw ValidationException::withMessages(['warehouse_id' => 'Kho thuộc chi nhánh hoặc chuỗi đã ngừng hoạt động.']);
            $product = $store->find('products', $productId);
            $category = $product ? $store->find('product_categories', $product['category_id']) : null;
            $unit = $product ? $store->find('units', $product['unit_id']) : null;
            if (!$product || !$product['is_active'] || !$category || !$category['is_active'] || !$unit || !$unit['is_active']) throw ValidationException::withMessages(['product_id' => 'Vật tư không còn được sử dụng.']);
            $value = Codec::decimal((string) BigDecimal::of((string) $data['min_stock_quantity'])->toScale(3), 18, 3);
            $row = $store->rows('warehouse_product_settings', ['warehouse_id' => $warehouseId, 'product_id' => $productId])->first();
            if ($row) $store->update('warehouse_product_settings', $row, ['min_stock_quantity' => $value]);
            else $store->insert('warehouse_product_settings', ['warehouse_id' => $warehouseId, 'product_id' => $productId, 'min_stock_quantity' => $value]);
        });
        return response()->json(['warehouse_id' => $warehouseId, 'product_id' => $productId, 'min_stock_quantity' => $data['min_stock_quantity']]);
    }

    private function canManage($assignments, array $scope): bool
    {
        return $assignments->contains(fn ($a) => ($a['role_code'] === 'branch_manager' && $a['branch_id'] === $scope['branch']['_id']) || ($a['role_code'] === 'chain_owner' && $a['chain_id'] === $scope['chain']['_id']));
    }

    private function compareId(string $a, string $b): int
    {
        return strlen($a) <=> strlen($b) ?: strcmp($a, $b);
    }
}
