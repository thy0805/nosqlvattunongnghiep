<?php

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use MongoDB\BSON\UTCDateTime;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SaleService
{
    public function __construct(private readonly SalesStore $store) {}

    public function actor(string $id): \App\Models\User
    {
        $row = $this->store->purchase->identity->find('users',$id);
        abort_unless($row && $row['is_active'],403);
        return (new \App\Models\User)->newFromBuilder($row);
    }

    public function assertDraftScope(array $data, string $actorId): void
    {
        $identity = $this->store->purchase->identity;
        $branch = $identity->find('branches',$data['branch_id']);
        $chain = $branch ? $identity->find('chains',$branch['chain_id']) : null;
        abort_if(!$branch || !$chain,422);
        abort_unless(app(SalesAccess::class)->canSell($this->actor($actorId),$branch['_id'],$chain['_id']),403);
        if (!$branch['is_active'] || !$chain['is_active']) throw ValidationException::withMessages(['branch_id'=>'Chi nhánh hoặc chuỗi đã ngừng hoạt động.']);
        $warehouse = $identity->find('warehouses',$data['warehouse_id']);
        if (!$warehouse || $warehouse['branch_id'] !== $branch['_id']) throw ValidationException::withMessages(['warehouse_id'=>'Kho không thuộc chi nhánh bán.']);
        if (isset($data['customer_id'])) {
            $customer = $this->store->purchase->catalog->find('customers',$data['customer_id']);
            if (!$customer || $customer['chain_id'] !== $chain['_id']) throw ValidationException::withMessages(['customer_id'=>'Khách không thuộc chuỗi bán.']);
        }
        if (isset($data['season_label']) && empty($data['customer_id'])) throw ValidationException::withMessages(['season_label'=>'Chọn khách hàng trước khi ghi mùa vụ công nợ.']);
    }

    public function lot(string $id): ?array
    {
        $lot = $this->store->find('product_lots',$id);
        $catalog = $this->store->purchase->catalog;
        $product = $lot ? $catalog->find('products',$lot['product_id']) : null;
        $category = $product ? $catalog->find('product_categories',$product['category_id']) : null;
        $unit = $product ? $catalog->find('units',$product['unit_id']) : null;
        if (!$lot || !$product || !$category || !$unit) return null;
        return $lot + ['sale_price'=>$product['sale_price'],'tax_rate'=>$product['tax_rate'],'product_active'=>$product['is_active'],'category_active'=>$category['is_active'],'unit_active'=>$unit['is_active'],'product_id'=>$product['_id'],'code'=>$product['code'],'name'=>$product['name'],'unit_name'=>$unit['name']];
    }

    public function createDraft(array $data, string $creatorId, ?string $shopOrderId = null): string
    {
        return $this->store->transaction(function () use ($data, $creatorId, $shopOrderId): string {
            $this->assertDraftScope($data,$creatorId);
            $orderId = $this->store->insert('sales_orders',[
                'branch_id' => $data['branch_id'],
                'warehouse_id' => $data['warehouse_id'],
                'customer_id' => $data['customer_id'] ?? null,
                'season_label' => $data['customer_id'] ?? null ? ($data['season_label'] ?? null) : null,
                'created_by' => $creatorId,
                'order_no' => 'BH-'.Str::ulid(),
                'status' => 'draft',
                'total_amount' => '0.00',
            ]);

            $shop=$shopOrderId!==null?app(\App\Commerce\CommerceStore::class)->find('shop_orders',$shopOrderId):null;
            if($shopOrderId!==null)abort_unless($shop&&$shop['status']==='packed'&&$shop['warehouse_id']===$data['warehouse_id']&&$shop['branch_id']===$data['branch_id']&&count($shop['allocations'])===count($data['items']),409,'Snapshot đơn online không hợp lệ.');
            $seen = [];
            foreach ($data['items'] as $index => $item) {
                $lotId = $item['lot_id'];
                if (isset($seen[$lotId])) {
                    throw ValidationException::withMessages(["items.{$index}.lot_id" => 'Mỗi lô chỉ được chọn một lần.']);
                }
                $seen[$lotId] = true;
                $joined = $this->lot($lotId);
                $product = $joined && $joined['product_active'] && $joined['category_active'] && $joined['unit_active'] ? (object) $joined : null;
                if ($product === null || ($product->expires_on !== null && $product->expires_on < now('Asia/Ho_Chi_Minh')->toDateString())) {
                    throw ValidationException::withMessages(["items.{$index}.lot_id" => 'Lô không còn hợp lệ để bán.']);
                }
                if ($product->tax_rate === null) {
                    throw ValidationException::withMessages(["items.{$index}.lot_id" => 'Vật tư chưa được cấu hình thuế suất.']);
                }
                $snapshot=$shop?collect($shop['allocations'])->firstWhere('lot_id',$lotId):null;
                if($shop){abort_unless($snapshot&&BigDecimal::of($snapshot['quantity'])->isEqualTo($item['quantity'])&&BigDecimal::of($snapshot['discount_amount'])->isEqualTo($item['discount_amount']??'0'),409,'Phân bổ khác snapshot.');$product->sale_price=$snapshot['unit_price'];$product->tax_rate=$snapshot['tax_rate'];}
                $gross = BigDecimal::of((string) $product->sale_price)->multipliedBy((string) $item['quantity'])->toScale(2, RoundingMode::HalfUp);
                DecimalLimit::assertFits($gross, 16, 2, "items.{$index}.quantity");
                $discount = BigDecimal::of((string) ($item['discount_amount'] ?? '0'));
                if ($discount->isGreaterThan($gross)) {
                    throw ValidationException::withMessages(["items.{$index}.discount_amount" => 'Chiết khấu vượt tiền dòng.']);
                }
                $this->store->insert('sales_order_items',[
                    'order_id' => $orderId,
                    'lot_id' => $lotId,
                    'quantity' => (string) BigDecimal::of((string) $item['quantity'])->toScale(3),
                    'unit_price' => (string) $product->sale_price,
                    'discount_amount' => (string) $discount->toScale(2),
                    'line_total' => (string) $gross->minus($discount),
                    'tax_rate_snapshot' => (string) $product->tax_rate,
                    'tax_amount' => (string) $gross->minus($discount)->multipliedBy((string) $product->tax_rate)->dividedBy('100', 2, RoundingMode::HalfUp),
                ]);
            }

            return (string) $orderId;
        });
    }

    public function confirm(string $orderId, string $actorId, array $payment, array $commerce = []): void
    {
        $this->store->transaction(function () use ($orderId, $actorId, $payment, $commerce): void {
            $row = $this->store->find('sales_orders',$orderId);
            abort_if($row === null,404);
            abort_unless($row['created_by'] === $actorId,403);
            $identity = $this->store->purchase->identity;
            $branch = $identity->find('branches',$row['branch_id']);
            $chain = $branch ? $identity->find('chains',$branch['chain_id']) : null;
            abort_unless($branch && $chain && app(SalesAccess::class)->canSell($this->actor($actorId),$branch['_id'],$chain['_id']),403);
            $order = (object) $row;
            abort_unless($order->status === 'draft',409);
            if (!$branch['is_active'] || !$chain['is_active']) throw ValidationException::withMessages(['branch_id'=>'Chi nhánh hoặc chuỗi đã ngừng hoạt động.']);
            $items = $this->store->rows('sales_order_items',['order_id'=>$orderId])->sort(fn($a,$b)=>(int)$a['lot_id']<=>(int)$b['lot_id'])->map(fn($x)=>(object)$x);
            abort_if($items->isEmpty(),422);
            $subtotal = BigDecimal::zero();
            $discountTotal = BigDecimal::zero();
            $taxTotal = BigDecimal::zero();
            foreach ($items as $item) {
                $i = $this->store->rows('inventories',['warehouse_id'=>$order->warehouse_id,'lot_id'=>$item->lot_id])->first();
                $inventory = $i ? (object) $i : null;
                $l = $this->lot($item->lot_id);
                $lot = $l ? (object) $l : null;
                if ($lot === null || ! $lot->product_active || ! $lot->category_active || ! $lot->unit_active
                    || ($lot->expires_on !== null && $lot->expires_on < now('Asia/Ho_Chi_Minh')->toDateString())) {
                    throw ValidationException::withMessages(['items' => 'Lô hoặc vật tư không còn hợp lệ.']);
                }
                if ($item->tax_rate_snapshot === null || $item->tax_amount === null) {
                    throw ValidationException::withMessages(['items' => 'Đơn thiếu dữ liệu thuế đã chụp; cần lập lại trước khi xác nhận.']);
                }
                if(isset($commerce['shop_order_id'])){$shop=app(\App\Commerce\CommerceStore::class)->find('shop_orders',$commerce['shop_order_id']);$snapshot=$shop?collect($shop['allocations'])->firstWhere('lot_id',$item->lot_id):null;abort_unless($shop&&$shop['status']==='packed'&&$shop['warehouse_id']===$order->warehouse_id&&$shop['branch_id']===$order->branch_id&&count($shop['allocations'])===$items->count()&&$snapshot,409,'Đơn online không khớp chứng từ.');foreach(['quantity'=>'quantity','unit_price'=>'unit_price','discount_amount'=>'discount_amount','tax_rate'=>'tax_rate_snapshot','tax_amount'=>'tax_amount'] as $source=>$field)abort_unless(BigDecimal::of($snapshot[$source])->isEqualTo((string)$item->$field),409,'Chứng từ khác snapshot đã chốt.');}
                elseif ($lot->tax_rate === null || BigDecimal::of((string) $lot->sale_price)->compareTo((string) $item->unit_price) !== 0
                    || BigDecimal::of((string) $lot->tax_rate)->compareTo((string) $item->tax_rate_snapshot) !== 0) {
                    throw ValidationException::withMessages(['items' => 'Giá hoặc thuế suất đã thay đổi; cần lập lại đơn trước khi xác nhận.']);
                }
                $quantity = BigDecimal::of((string) $item->quantity);
                $held = app(\App\Commerce\Storefront::class)->held($order->warehouse_id, $item->lot_id);
                if ($inventory !== null && BigDecimal::of((string) $inventory->quantity)->minus($held)->isLessThan($quantity)) throw ValidationException::withMessages(['items'=>'Tồn khả dụng đang được giữ cho đơn trực tuyến.']);
                if ($inventory === null || BigDecimal::of((string) $inventory->quantity)->isLessThan($quantity)) {
                    throw ValidationException::withMessages(['items' => 'Tồn kho không đủ cho lô đã chọn.']);
                }
                if ($inventory->average_unit_cost === null) {
                    throw ValidationException::withMessages(['items' => 'Lô chưa có giá vốn đã xác minh.']);
                }
                $costTotal = $quantity->multipliedBy((string) $inventory->average_unit_cost)->toScale(2, RoundingMode::HalfUp);
                DecimalLimit::assertFits($costTotal, 16, 2, 'items');
                $this->store->update('sales_order_items',$item->_id,[
                    'unit_cost_snapshot' => $inventory->average_unit_cost,
                    'cost_total' => (string) $costTotal,
                ]);
                $this->store->update('inventories',$inventory->_id,[
                    'quantity' => (string) BigDecimal::of((string) $inventory->quantity)->minus($quantity)->toScale(3),
                    'updated_at' => new UTCDateTime($this->store->unit()->clock),
                ]);
                $this->store->insert('stock_movements',[
                    'warehouse_id' => $order->warehouse_id,
                    'lot_id' => $item->lot_id,
                    'sales_order_id' => $orderId,
                    'movement_type' => 'sale_out',
                    'quantity_delta' => (string) $quantity->negated()->toScale(3),
                ]);
                $subtotal = $subtotal->plus(BigDecimal::of((string) $item->unit_price)->multipliedBy((string) $item->quantity)->toScale(2, RoundingMode::HalfUp));
                $discountTotal = $discountTotal->plus((string) $item->discount_amount);
                $taxTotal = $taxTotal->plus((string) $item->tax_amount);
            }
            $shipping = \App\Commerce\CommerceStore::money($commerce['shipping_amount'] ?? '0.00');
            $total = $subtotal->minus($discountTotal)->plus($taxTotal)->plus($shipping)->toScale(2);
            DecimalLimit::assertFits($subtotal, 16, 2, 'items');
            DecimalLimit::assertFits($discountTotal, 16, 2, 'items');
            DecimalLimit::assertFits($taxTotal, 16, 2, 'items');
            DecimalLimit::assertFits($total, 16, 2, 'items');
            $paid = BigDecimal::of((string) $payment['amount'])->toScale(2);
            if ($paid->isGreaterThan($total)) {
                throw ValidationException::withMessages(['amount' => 'Số tiền thu vượt tổng hóa đơn.']);
            }
            if ($order->customer_id === null && $paid->isLessThan($total)) {
                throw ValidationException::withMessages(['amount' => 'Khách lẻ phải thanh toán đủ trước khi xác nhận.']);
            }
            $this->store->update('sales_orders',$orderId,['status' => 'confirmed', 'sold_at' => new UTCDateTime($this->store->unit()->clock), 'total_amount' => (string) $total]);
            $invoiceId = $this->store->insert('invoices',[
                'sales_order_id' => $orderId,
                'invoice_no' => 'HD-'.Str::ulid(),
                'subtotal' => (string) $subtotal->toScale(2),
                'discount_amount' => (string) $discountTotal->toScale(2),
                'tax_amount' => (string) $taxTotal->toScale(2),
                'total_amount' => (string) $total,
                'status' => $paid->isEqualTo($total) ? 'paid' : 'issued',
                'shipping_amount' => (string) $shipping,
            ]);
            $debtId = app(DebtService::class)->recordReceivable($invoiceId, $order, (string) $total, $actorId);
            if ($paid->isGreaterThan(0)) {
                $paymentId = $this->store->insert('payments',[
                    'invoice_id' => $invoiceId,
                    'method' => $payment['method'],
                    'amount' => (string) $paid,
                    'status' => 'completed',
                    'created_by' => $actorId,
                    'reference_note' => $payment['reference_note'] ?? null,
                ]);
                if ($debtId !== null) {
                    $this->store->insert('debt_transactions',[
                        'debt_id' => $debtId,
                        'payment_id' => $paymentId,
                        'invoice_id' => $invoiceId,
                        'branch_id' => $order->branch_id,
                        'warehouse_id' => $order->warehouse_id,
                        'created_by' => $actorId,
                        'transaction_type' => 'customer_payment',
                        'amount' => (string) $paid,
                    ]);
                }
            }
            if ($debtId !== null) app(DebtService::class)->refreshStatus($debtId);
            app(OutboxService::class)->record('sales_order', $orderId, 'sale.confirmed', [
                'actor_id' => $actorId, 'branch_id' => (string) $order->branch_id,
                'warehouse_id' => (string) $order->warehouse_id,
                'invoice_id' => $invoiceId, 'customer_id' => $order->customer_id === null ? null : (string) $order->customer_id,
                'total_amount' => (string) $total, 'paid_amount' => (string) $paid,
            ]);
        });
    }
}
