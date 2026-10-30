<?php

namespace App\Support;

use App\Infrastructure\Mongo\{Codec, IdCounter, UnitOfWork};
use Brick\Math\BigDecimal;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\UTCDateTime;

final class PurchaseStore
{
    private const COLLECTIONS = ['product_lots', 'purchase_receipts', 'purchase_receipt_items', 'inventories', 'stock_movements', 'debts', 'debt_transactions', 'outbox_events'];
    private const SCALES = ['quantity' => 3, 'quantity_delta' => 3, 'unit_cost' => 2, 'line_total' => 2, 'total_amount' => 2, 'supplier_invoice_total' => 2, 'average_unit_cost' => 6, 'opening_balance' => 2, 'amount' => 2];

    public function __construct(public readonly IdentityStore $identity, public readonly CatalogStore $catalog) {}

    public function collection(string $name): \MongoDB\Collection
    {
        if (!in_array($name, self::COLLECTIONS, true)) throw new \LogicException('C03_COLLECTION_NOT_ALLOWED');
        return $this->identity->runtime->database->selectCollection($name);
    }

    public function unit(): UnitOfWork
    {
        return $this->identity->unitOfWork() ?? throw new \LogicException('C03_UNIT_REQUIRED');
    }

    public function options(): array
    {
        return $this->identity->unitOfWork()?->options() ?? [];
    }

    public function transaction(Closure $work): mixed
    {
        return $this->identity->transaction($work);
    }

    public function rows(string $name, array $filter = [], array $sort = []): Collection
    {
        return collect($this->collection($name)->find($filter, $this->options() + ($sort ? ['sort' => $sort] : []))->toArray());
    }

    public function find(string $name, string $id): ?array
    {
        return $this->collection($name)->findOne(['_id' => Codec::id($id)], $this->options());
    }

    public function insert(string $name, array $data): string
    {
        $unit = $this->unit();
        $id = (new IdCounter($unit->database))->next($name);
        $now = new UTCDateTime($unit->clock);
        $defaults = match ($name) {
            'product_lots' => ['manufactured_on' => null, 'expires_on' => null],
            'purchase_receipts' => ['approved_by' => null, 'received_at' => $now, 'approved_at' => null, 'status' => 'draft', 'total_amount' => '0.00', 'submitted_at' => null, 'rejected_by' => null, 'rejected_at' => null, 'rejection_reason' => null, 'supplier_invoice_no' => null, 'supplier_invoice_date' => null, 'supplier_invoice_total' => null],
            'inventories' => ['quantity' => '0.000', 'average_unit_cost' => null, 'updated_at' => $now],
            'stock_movements' => ['purchase_receipt_id' => null, 'sales_order_id' => null, 'stock_transfer_id' => null, 'occurred_at' => $now],
            'debts' => ['customer_id' => null, 'supplier_id' => null, 'opening_balance' => '0.00', 'status' => 'open'],
            'debt_transactions' => ['payment_id' => null, 'invoice_id' => null, 'purchase_receipt_id' => null, 'branch_id' => null, 'warehouse_id' => null, 'created_by' => null, 'season_label' => null, 'occurred_at' => $now],
            'outbox_events' => ['created_at' => $now, 'published_at' => null, 'status' => 'pending', 'event_version' => 1, 'attempt_count' => 0, 'next_attempt_at' => $now, 'locked_at' => null, 'last_error' => null, 'lease_token' => null, 'lease_until' => null],
            'purchase_receipt_items' => [],
            default => throw new \LogicException('C03_COLLECTION_NOT_ALLOWED'),
        };
        $row = Codec::identity($id) + $this->encode($data + $defaults);
        $this->references($name, $row, $unit);
        $unit->insert($name, $row);
        $this->deferReferences($name, $id, $unit);
        return $id;
    }

    public function update(string $name, string $id, array $data): void
    {
        $unit = $this->unit();
        $row = $this->find($name, $id);
        abort_if($row === null, 404);
        $data = $this->encode($data);
        $this->references($name, array_replace($row, $data), $unit);
        $unit->cas($name, $id, $row['_version'], $data);
        $this->deferReferences($name, $id, $unit);
    }

    public function replaceItems(string $receiptId, array $items): void
    {
        $this->collection('purchase_receipt_items')->deleteMany(['receipt_id' => Codec::id($receiptId)], $this->unit()->options());
        foreach ($items as $item) $this->insert('purchase_receipt_items', $item + ['receipt_id' => $receiptId]);
    }

    private function encode(array $data): array
    {
        if (array_intersect(['_id', 'id', 'id_sort', '_version', 'supplier_invoice_no_normalized'], array_keys($data))) throw new \LogicException('Identity/normalization controlled internally');
        foreach (self::SCALES as $field => $scale) {
            if (isset($data[$field])) $data[$field] = Codec::decimal((string) BigDecimal::of((string) $data[$field])->toScale($scale), 18, $scale);
        }
        foreach (['manufactured_on', 'expires_on', 'supplier_invoice_date'] as $field) {
            if (isset($data[$field])) $data[$field] = Codec::calendar($data[$field]);
        }
        if (array_key_exists('supplier_invoice_no', $data)) $data['supplier_invoice_no_normalized'] = $data['supplier_invoice_no'] === null ? null : mb_strtolower(trim($data['supplier_invoice_no'], ' '));
        return $data;
    }

    public function present(array $row, ?array $fields = null): array
    {
        $row = Codec::present($row, self::SCALES);
        unset($row['supplier_invoice_no_normalized']);
        foreach ($row as $field => $value) {
            if ($value instanceof UTCDateTime) $row[$field] = $value->toDateTimeImmutable()->format('Y-m-d H:i:s.vP');
        }
        return $fields === null ? $row : array_intersect_key($row, array_flip($fields));
    }

    public function scope(string $warehouseId): ?array
    {
        $warehouse = $this->identity->find('warehouses', $warehouseId);
        $branch = $warehouse ? $this->identity->find('branches', $warehouse['branch_id']) : null;
        $chain = $branch ? $this->identity->find('chains', $branch['chain_id']) : null;
        return $warehouse && $branch && $chain ? ['warehouse' => $warehouse, 'branch' => $branch, 'chain' => $chain] : null;
    }

    private function references(string $name, array $row, UnitOfWork $unit): void
    {
        $links = match ($name) {
            'product_lots' => ['product_id' => 'products'],
            'purchase_receipts' => ['supplier_id' => 'suppliers', 'warehouse_id' => 'warehouses', 'created_by' => 'users', 'approved_by' => 'users', 'rejected_by' => 'users'],
            'purchase_receipt_items' => ['receipt_id' => 'purchase_receipts', 'lot_id' => 'product_lots'],
            'inventories' => ['warehouse_id' => 'warehouses', 'lot_id' => 'product_lots'],
            'stock_movements' => ['warehouse_id' => 'warehouses', 'lot_id' => 'product_lots', 'purchase_receipt_id' => 'purchase_receipts', 'sales_order_id' => 'sales_orders', 'stock_transfer_id' => 'stock_transfers'],
            'debts' => ['customer_id' => 'customers', 'supplier_id' => 'suppliers'],
            'debt_transactions' => ['debt_id' => 'debts', 'payment_id' => 'payments', 'invoice_id' => 'invoices', 'purchase_receipt_id' => 'purchase_receipts', 'branch_id' => 'branches', 'warehouse_id' => 'warehouses', 'created_by' => 'users'],
            default => [],
        };
        foreach ($links as $field => $target) {
            if (($row[$field] ?? null) !== null && !$unit->database->selectCollection($target)->findOne(['_id' => Codec::id($row[$field])], $unit->options())) throw ValidationException::withMessages([$field => 'The selected '.$field.' is invalid.']);
        }
    }

    private function deferReferences(string $name, string $id, UnitOfWork $unit): void
    {
        $unit->defer(function (UnitOfWork $unit) use ($name, $id): void {
            $row = $unit->database->selectCollection($name)->findOne(['_id' => $id], $unit->options());
            if ($row) $this->references($name, $row, $unit);
        });
    }
}
