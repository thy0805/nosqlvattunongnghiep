<?php

namespace App\Support;

use App\Infrastructure\Mongo\{Codec, IdCounter, UnitOfWork};
use Brick\Math\BigDecimal;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\UTCDateTime;

final class TransferStore
{
    private const COLLECTIONS = ['stock_transfers', 'stock_transfer_items'];
    private const SCALES = ['requested_quantity'=>3,'dispatched_quantity'=>3,'received_quantity'=>3,'supplemental_received_quantity'=>3,'returned_quantity'=>3,'lost_quantity'=>3,'transfer_unit_cost'=>6];

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
            'stock_transfers' => ['approved_by'=>null,'requested_at'=>$now,'approved_at'=>null,'status'=>'requested','rejected_by'=>null,'rejected_at'=>null,'rejection_reason'=>null,'dispatched_by'=>null,'dispatched_at'=>null,'received_by'=>null,'received_at'=>null,'reconciled_by'=>null,'reconciled_at'=>null,'reconciliation_reason'=>null],
            'stock_transfer_items' => ['dispatched_quantity'=>'0.000','received_quantity'=>'0.000','transfer_unit_cost'=>null,'supplemental_received_quantity'=>'0.000','returned_quantity'=>'0.000','lost_quantity'=>'0.000'],
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
            'stock_transfers' => ['from_warehouse_id'=>'warehouses','to_warehouse_id'=>'warehouses','requested_by'=>'users','approved_by'=>'users','rejected_by'=>'users','dispatched_by'=>'users','received_by'=>'users','reconciled_by'=>'users'],
            'stock_transfer_items' => ['transfer_id'=>'stock_transfers','lot_id'=>'product_lots'],
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
