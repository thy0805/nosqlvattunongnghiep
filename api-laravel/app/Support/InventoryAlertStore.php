<?php

namespace App\Support;

use App\Infrastructure\Mongo\{Codec, IdCounter, UnitOfWork};
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class InventoryAlertStore
{
    public function __construct(public readonly PurchaseStore $purchase) {}

    public function rows(string $name, array $filter = [], array $sort = []): Collection
    {
        if (!in_array($name, ['warehouse_product_settings', 'inventory_alert_incidents', 'warehouses', 'branches', 'chains', 'products', 'product_categories', 'units', 'product_lots', 'inventories', 'users', 'roles', 'user_role_assignments'], true)) throw new \LogicException('C06B_COLLECTION_DENIED');
        return collect($this->purchase->identity->runtime->database->selectCollection($name)->find($filter, $this->purchase->options() + ($sort ? ['sort' => $sort] : []))->toArray());
    }

    public function find(string $name, string $id): ?array
    {
        return $this->rows($name, ['_id' => Codec::id($id)])->first();
    }

    public function insert(string $name, array $data): string
    {
        if (!in_array($name, ['warehouse_product_settings', 'inventory_alert_incidents'], true)) throw new \LogicException('C06B_WRITE_DENIED');
        $unit = $this->purchase->unit();
        $id = (new IdCounter($unit->database))->next($name);
        $row = Codec::identity($id) + $data;
        $this->references($name, $row, $unit);
        $unit->insert($name, $row);
        $unit->defer(fn (UnitOfWork $final) => $this->references($name, $final->database->selectCollection($name)->findOne(['_id' => $id], $final->options()), $final));
        return $id;
    }

    public function update(string $name, array $row, array $data): void
    {
        if (!in_array($name, ['warehouse_product_settings', 'inventory_alert_incidents'], true)) throw new \LogicException('C06B_WRITE_DENIED');
        $unit = $this->purchase->unit();
        $this->references($name, array_replace($row, $data), $unit);
        $unit->cas($name, $row['_id'], $row['_version'], $data);
    }

    private function references(string $name, array $row, UnitOfWork $unit): void
    {
        $find = fn ($collection, $id) => $unit->database->selectCollection($collection)->findOne(['_id' => Codec::id($id)], $unit->options());
        $warehouse = $find('warehouses', $row['warehouse_id']);
        $product = $find('products', $row['product_id']);
        if (!$warehouse || !$product) throw ValidationException::withMessages(['warehouse_id' => 'The selected warehouse or product is invalid.']);
        if ($name === 'inventory_alert_incidents') {
            $branch = $find('branches', $row['branch_id']);
            $lot = $row['lot_id'] === null ? null : $find('product_lots', $row['lot_id']);
            if (!$branch || $warehouse['branch_id'] !== $branch['_id'] || ($row['lot_id'] !== null && (!$lot || $lot['product_id'] !== $product['_id']))) throw ValidationException::withMessages(['branch_id' => 'The selected alert references are invalid.']);
        }
    }

    public function data(?array $branchIds = null): array
    {
        $branches = $this->rows('branches')->keyBy('_id');
        $warehouses = $this->rows('warehouses')->keyBy('_id');
        $products = $this->rows('products')->keyBy('_id');
        $units = $this->rows('units')->keyBy('_id');
        $lots = $this->rows('product_lots')->keyBy('_id');
        $stocks = $this->rows('inventories');
        $today = now('Asia/Ho_Chi_Minh')->toDateString();
        $base = function ($warehouse, $product) use ($branches, $units, $branchIds): ?array {
            if (!$warehouse || !$product || !isset($branches[$warehouse['branch_id']]) || ($branchIds !== null && !in_array($warehouse['branch_id'], $branchIds, true))) return null;
            return ['warehouse_id' => $warehouse['_id'], 'warehouse_name' => $warehouse['name'], 'branch_id' => $warehouse['branch_id'], 'branch_name' => $branches[$warehouse['branch_id']]['name'], 'product_id' => $product['_id'], 'product_code' => $product['code'], 'product_name' => $product['name'], 'unit_name' => $units[$product['unit_id']]['name'] ?? null];
        };
        $low = [];
        foreach ($this->rows('warehouse_product_settings') as $setting) {
            $row = $base($warehouses[$setting['warehouse_id']] ?? null, $products[$setting['product_id']] ?? null);
            if (!$row) continue;
            $quantity = BigDecimal::zero();
            foreach ($stocks as $stock) if ($stock['warehouse_id'] === $setting['warehouse_id'] && ($lots[$stock['lot_id']]['product_id'] ?? null) === $setting['product_id']) $quantity = $quantity->plus((string) $stock['quantity']);
            if ($quantity->isLessThan((string) $setting['min_stock_quantity'])) $low[] = ['id' => $setting['_id'], 'min_stock_quantity' => Codec::fixed($setting['min_stock_quantity'], 3)] + $row + ['quantity' => (string) $quantity->toScale(3)];
        }
        usort($low, fn ($a, $b) => strcmp($a['branch_name'], $b['branch_name']) ?: strcmp($a['warehouse_name'], $b['warehouse_name']) ?: strcmp($a['product_name'], $b['product_name']));
        $expiry = [];
        foreach ($stocks as $stock) {
            $lot = $lots[$stock['lot_id']] ?? null;
            $product = $lot ? ($products[$lot['product_id']] ?? null) : null;
            if (!$lot || $lot['expires_on'] === null || !BigDecimal::of((string) $stock['quantity'])->isPositive()) continue;
            $row = $base($warehouses[$stock['warehouse_id']] ?? null, $product);
            if (!$row) continue;
            $days = $product['expiry_warning_days'] ?? 30;
            if ($lot['expires_on'] > now('Asia/Ho_Chi_Minh')->startOfDay()->addDays($days)->toDateString()) continue;
            $expiry[] = ['id' => $stock['_id'], 'quantity' => Codec::fixed($stock['quantity'], 3)] + $row + ['lot_id' => $lot['_id'], 'lot_no' => $lot['lot_no'], 'expires_on' => $lot['expires_on'], 'warning_days' => $days, 'status' => $lot['expires_on'] < $today ? 'expired' : 'expiring'];
        }
        usort($expiry, fn ($a, $b) => strcmp($a['expires_on'], $b['expires_on']) ?: (strlen($a['id']) <=> strlen($b['id']) ?: strcmp($a['id'], $b['id'])));
        return ['low_stock' => $low, 'expiring_lots' => $expiry, 'as_of_date' => $today];
    }

    public function recipients(string $branchId, string $today): array
    {
        $branch = $this->find('branches', $branchId);
        $users = $this->rows('users', ['is_active' => true])->keyBy('_id');
        $roles = $this->rows('roles')->keyBy('_id');
        $branches = $this->rows('branches')->keyBy('_id');
        $ids = [];
        foreach ($this->rows('user_role_assignments', ['status' => 'active', 'starts_on' => ['$lte' => $today]]) as $assignment) {
            $role = $roles[$assignment['role_id']]['code'] ?? null;
            $assigned = $branches[$assignment['branch_id']] ?? null;
            if (!isset($users[$assignment['user_id']]) || !$assigned) continue;
            if (($role === 'chain_owner' && $assigned['chain_id'] === $branch['chain_id']) || (in_array($role, ['branch_manager', 'sales_staff'], true) && $assignment['branch_id'] === $branchId)) $ids[] = $assignment['user_id'];
        }
        return array_values(array_unique($ids));
    }
}
