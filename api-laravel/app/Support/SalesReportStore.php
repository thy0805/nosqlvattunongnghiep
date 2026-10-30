<?php

namespace App\Support;

use App\Infrastructure\Mongo\{Codec, Runtime};
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use Generator;
use Illuminate\Support\Collection;
use MongoDB\BSON\Decimal128;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\ReadConcern;
use MongoDB\Driver\WriteConcern;

final class SalesReportStore
{
    public function __construct(private readonly Runtime $runtime) {}

    public function rows(Collection $branches, ?string $branchId, DateTimeImmutable $start, DateTimeImmutable $end): Generator
    {
        $names = [];
        foreach ($branches as $branch) $names[(string) $branch->id] = $branch->name;
        $filter = ['status' => 'confirmed', 'branch_id' => ['$in' => $branchId === null ? array_keys($names) : [Codec::id($branchId)]],
            'sold_at' => ['$gte' => new UTCDateTime($start), '$lt' => new UTCDateTime($end)]];
        $filter['branch_id']['$in'] = array_map('strval', $filter['branch_id']['$in']);
        $session = $this->runtime->client->startSession();
        $session->startTransaction(['readConcern' => new ReadConcern('snapshot'), 'writeConcern' => new WriteConcern('majority')]);
        $options = ['session' => $session];
        $db = $this->runtime->database;
        try {
            foreach ($db->selectCollection('sales_orders')->find($filter, $options + ['sort' => ['sold_at' => 1]]) as $sale) {
                Codec::id($sale['_id']);
                if (!$sale['sold_at'] instanceof UTCDateTime) throw new \UnexpectedValueException('REPORT_INVALID_SOLD_AT');
                $items = $db->selectCollection('sales_order_items')->find(['order_id' => $sale['_id']], $options)->toArray();
                if (!$items) continue;
                $gross = $discount = $tax = $cost = BigDecimal::zero();
                $incompleteCost = $incompleteTax = 0;
                foreach ($items as $item) {
                    $quantity = $this->decimal($item['quantity']);
                    $gross = $gross->plus($quantity->multipliedBy($this->decimal($item['unit_price']))->toScale(2, RoundingMode::HalfUp));
                    $discount = $discount->plus($this->decimal($item['discount_amount']));
                    if (($item['unit_cost_snapshot'] ?? null) === null) $incompleteCost++;
                    else $cost = $cost->plus($quantity->multipliedBy($this->decimal($item['unit_cost_snapshot']))->toScale(2, RoundingMode::HalfUp));
                    if (($item['tax_amount'] ?? null) === null || ($item['tax_rate_snapshot'] ?? null) === null) $incompleteTax++;
                    if (($item['tax_amount'] ?? null) !== null) $tax = $tax->plus($this->decimal($item['tax_amount']));
                }
                foreach ($db->selectCollection('invoices')->find(['sales_order_id' => $sale['_id']], $options) as $invoice) {
                    Codec::id($invoice['_id']);
                    $collected = BigDecimal::zero();
                    foreach ($db->selectCollection('payments')->find(['invoice_id' => $invoice['_id'], 'status' => 'completed'], $options) as $payment) {
                        $collected = $collected->plus($this->decimal($payment['amount']));
                    }
                    yield (object) ['id' => $sale['_id'], 'branch_id' => $sale['branch_id'],
                        'sold_at' => $sale['sold_at']->toDateTime()->format('Y-m-d H:i:s.u'), 'branch_name' => $names[$sale['branch_id']],
                        'recorded_invoice_total' => (string) $this->decimal($invoice['total_amount']),
                        'gross_sales' => (string) $gross->plus(isset($invoice['shipping_amount']) ? $this->decimal($invoice['shipping_amount']) : BigDecimal::zero()), 'total_discount' => (string) $discount, 'total_tax' => (string) $tax,
                        'cogs' => (string) $cost, 'amount_collected' => (string) $collected,
                        'incomplete_cost_items' => $incompleteCost, 'incomplete_tax_items' => $incompleteTax];
                }
            }
            $session->commitTransaction();
        } finally {
            if ($session->isInTransaction()) $session->abortTransaction();
            $session->endSession();
        }
    }

    private function decimal(mixed $value): BigDecimal
    {
        if (!$value instanceof Decimal128 || !preg_match('/^-?\d+(?:\.\d+)?$/D', (string) $value)) {
            throw new \UnexpectedValueException('REPORT_INVALID_DECIMAL');
        }
        return BigDecimal::of((string) $value);
    }
}
