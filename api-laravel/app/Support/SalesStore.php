<?php

namespace App\Support;

use App\Infrastructure\Mongo\{Codec, IdCounter, UnitOfWork};
use Brick\Math\BigDecimal;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\UTCDateTime;

final class SalesStore
{
    private const COLLECTIONS = ['sales_orders', 'sales_order_items', 'invoices', 'payments'];
    private const SCALES = ['quantity' => 3, 'unit_price' => 2, 'discount_amount' => 2, 'line_total' => 2, 'unit_cost_snapshot' => 6, 'cost_total' => 2, 'tax_rate_snapshot' => 2, 'tax_amount' => 2, 'shipping_amount' => 2, 'subtotal' => 2, 'total_amount' => 2, 'amount' => 2];

    public function __construct(public readonly PurchaseStore $purchase) {}

    public function unit(): UnitOfWork { return $this->purchase->unit(); }
    public function transaction(Closure $work): mixed { return $this->purchase->transaction($work); }
    public function options(): array { return $this->purchase->options(); }

    public function rows(string $name, array $filter = [], array $sort = []): Collection
    {
        if (!in_array($name, self::COLLECTIONS, true)) return $this->purchase->rows($name, $filter, $sort);
        return collect($this->purchase->identity->runtime->database->selectCollection($name)->find($filter, $this->options() + ($sort ? ['sort' => $sort] : []))->toArray());
    }

    public function find(string $name, string $id): ?array
    {
        if (!in_array($name, self::COLLECTIONS, true)) return $this->purchase->find($name, $id);
        return $this->purchase->identity->runtime->database->selectCollection($name)->findOne(['_id' => Codec::id($id)], $this->options());
    }

    public function insert(string $name, array $data): string
    {
        if (!in_array($name, self::COLLECTIONS, true)) return $this->purchase->insert($name, $data);
        $unit = $this->unit();
        $now = new UTCDateTime($unit->clock);
        $defaults = match ($name) {
            'sales_orders' => ['customer_id' => null, 'season_label' => null, 'sold_at' => $now, 'status' => 'draft', 'total_amount' => '0.00'],
            'sales_order_items' => ['discount_amount' => '0.00', 'unit_cost_snapshot' => null, 'cost_total' => null, 'tax_rate_snapshot' => null, 'tax_amount' => null],
            'invoices' => ['issued_at' => $now, 'discount_amount' => '0.00', 'tax_amount' => '0.00', 'status' => 'issued'],
            'payments' => ['invoice_id' => null, 'purchase_receipt_id' => null, 'created_by' => null, 'request_key' => null, 'reference_note' => null, 'paid_at' => $now, 'status' => 'completed'],
        };
        $id = (new IdCounter($unit->database))->next($name);
        $row = Codec::identity($id) + $this->encode($data + $defaults);
        $this->references($name, $row, $unit);
        $unit->insert($name, $row);
        $this->defer($name, $id, $unit);
        return $id;
    }

    public function update(string $name, string $id, array $data): void
    {
        if (!in_array($name, self::COLLECTIONS, true)) { $this->purchase->update($name, $id, $data); return; }
        $unit = $this->unit();
        $row = $this->find($name, $id);
        abort_if($row === null, 404);
        $data = $this->encode($data);
        $this->references($name, array_replace($row, $data), $unit);
        $unit->cas($name, $id, $row['_version'], $data);
        $this->defer($name, $id, $unit);
    }

    private function encode(array $data): array
    {
        if (array_intersect(['_id','id','id_sort','_version'], array_keys($data))) throw new \LogicException('Identity controlled internally');
        foreach (self::SCALES as $field => $scale) if (isset($data[$field])) $data[$field] = Codec::decimal((string) BigDecimal::of((string) $data[$field])->toScale($scale), 18, $scale);
        return $data;
    }

    public function present(array $row, ?array $fields = null): array
    {
        $row = Codec::present($row, self::SCALES);
        foreach ($row as $k => $v) if ($v instanceof UTCDateTime) $row[$k] = $v->toDateTimeImmutable()->format('Y-m-d H:i:s.vP');
        return $fields === null ? $row : array_intersect_key($row, array_flip($fields));
    }

    public function sum(Collection $rows, string $field = 'amount'): BigDecimal
    {
        $total = BigDecimal::zero();
        foreach ($rows as $row) $total = $total->plus((string) $row[$field]);
        return $total;
    }

    private function references(string $name, array $row, UnitOfWork $unit): void
    {
        $links = match ($name) {
            'sales_orders' => ['branch_id'=>'branches','warehouse_id'=>'warehouses','customer_id'=>'customers','created_by'=>'users'],
            'sales_order_items' => ['order_id'=>'sales_orders','lot_id'=>'product_lots'],
            'invoices' => ['sales_order_id'=>'sales_orders'],
            'payments' => ['invoice_id'=>'invoices','purchase_receipt_id'=>'purchase_receipts','created_by'=>'users'],
        };
        foreach ($links as $field => $target) if (($row[$field] ?? null) !== null && !$unit->database->selectCollection($target)->findOne(['_id'=>Codec::id($row[$field])],$unit->options())) throw ValidationException::withMessages([$field=>'The selected '.$field.' is invalid.']);
    }

    private function defer(string $name, string $id, UnitOfWork $unit): void
    {
        $unit->defer(function (UnitOfWork $unit) use ($name, $id): void {
            $row = $unit->database->selectCollection($name)->findOne(['_id'=>$id],$unit->options());
            if ($row) $this->references($name,$row,$unit);
        });
    }
}
