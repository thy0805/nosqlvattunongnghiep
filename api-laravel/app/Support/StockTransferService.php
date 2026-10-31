<?php

namespace App\Support;

use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use MongoDB\BSON\UTCDateTime;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StockTransferService
{
    public function __construct(private readonly TransferStore $store) {}

    private function stamp(): UTCDateTime { return new UTCDateTime($this->store->unit()->clock); }

    public function actor(string $id): User
    {
        $row = $this->store->purchase->identity->find('users', $id);
        abort_unless($row && $row['is_active'], 403);
        return (new User)->newFromBuilder($row);
    }

    public function approve(string $id, User $user, TransferAccess $access): void
    {
        $this->store->transaction(function () use ($id, $user, $access): void {
            $user = $this->actor((string) $user->id);
            $transfer = $this->lockTransfer($id);
            abort_unless($transfer->status === 'requested', 409);
            $this->assertActiveBranches($transfer);
            abort_if((string) $transfer->requested_by === (string) $user->id, 403);
            abort_unless($access->canApprove($user, (string) $transfer->from_branch_id, (string) $transfer->chain_id), 403);
            $this->store->update('stock_transfers', $id, [
                'status' => 'approved', 'approved_by' => $user->id, 'approved_at' => $this->stamp(),
            ]);
            $this->recordEvent($id, 'transfer.approved', $user, $transfer);
        });
    }

    public function reject(string $id, User $user, TransferAccess $access, ?string $reason): void
    {
        $this->store->transaction(function () use ($id, $user, $access, $reason): void {
            $user = $this->actor((string) $user->id);
            $transfer = $this->lockTransfer($id);
            abort_unless($transfer->status === 'requested', 409);
            abort_if((string) $transfer->requested_by === (string) $user->id, 403);
            abort_unless($access->canApprove($user, (string) $transfer->from_branch_id, (string) $transfer->chain_id), 403);
            $this->store->update('stock_transfers', $id, [
                'status' => 'rejected', 'rejected_by' => $user->id,
                'rejected_at' => $this->stamp(), 'rejection_reason' => $reason,
            ]);
            $this->recordEvent($id, 'transfer.rejected', $user, $transfer, ['reason' => $reason]);
        });
    }

    public function dispatch(string $id, User $user, TransferAccess $access, array $quantities): void
    {
        $this->store->transaction(function () use ($id, $user, $access, $quantities): void {
            $user = $this->actor((string) $user->id);
            $transfer = $this->lockTransfer($id);
            abort_unless($transfer->status === 'approved', 409);
            abort_unless($access->canRequest($user, (string) $transfer->from_branch_id), 403);
            $this->assertActiveBranches($transfer);
            
            $items = $this->store->rows('stock_transfer_items', ['transfer_id'=>$id])->sort(fn($a,$b)=>strlen($a['lot_id'])<=>strlen($b['lot_id']) ?: strcmp($a['lot_id'],$b['lot_id']))->map(fn($r)=>(object)($r+['id'=>$r['_id']]));
            $this->assertMatchingItems($items, $quantities, 'dispatched_quantity');
            foreach ($items as $item) {
                $amount = BigDecimal::of((string) $quantities[(string) $item->lot_id]);
                if ($amount->isLessThanOrEqualTo(0) || $amount->isGreaterThan((string) $item->requested_quantity)) {
                    throw ValidationException::withMessages(['items' => 'Số xuất phải lớn hơn 0 và không vượt số lượng yêu cầu.']);
                }
                $inventory = $this->inventory($transfer->from_warehouse_id, $item->lot_id);
                if (! $inventory || BigDecimal::of((string) $inventory->quantity)->minus(app(\App\Commerce\Storefront::class)->held($transfer->from_warehouse_id, $item->lot_id))->isLessThan($amount)) {
                    throw ValidationException::withMessages(['items' => 'Kho nguồn không đủ tồn khi ghi xuất.']);
                }
                if ($inventory->average_unit_cost === null) {
                    throw ValidationException::withMessages(['items' => 'Lô tại kho nguồn chưa có giá vốn; cần xác minh trước khi xuất.']);
                }
                $this->store->purchase->update('inventories', $inventory->id, [
                    'quantity' => (string) BigDecimal::of((string) $inventory->quantity)->minus($amount)->toScale(3),
                    'updated_at' => $this->stamp(),
                ]);
                $this->store->update('stock_transfer_items', $item->id, [
                    'dispatched_quantity' => (string) $amount->toScale(3),
                    'transfer_unit_cost' => $inventory->average_unit_cost,
                ]);
                $this->store->purchase->insert('stock_movements', [
                    'warehouse_id' => $transfer->from_warehouse_id, 'lot_id' => $item->lot_id,
                    'stock_transfer_id' => $id, 'movement_type' => 'transfer_out',
                    'quantity_delta' => (string) $amount->negated()->toScale(3),
                ]);
            }
            $this->store->update('stock_transfers', $id, [
                'status' => 'dispatched', 'dispatched_by' => $user->id, 'dispatched_at' => $this->stamp(),
            ]);
            $this->recordEvent($id, 'transfer.dispatched', $user, $transfer, ['quantities' => $quantities]);
        });
    }

    public function receive(string $id, User $user, TransferAccess $access, array $quantities): string
    {
        return $this->store->transaction(function () use ($id, $user, $access, $quantities): string {
            $user = $this->actor((string) $user->id);
            $transfer = $this->lockTransfer($id);
            abort_unless($transfer->status === 'dispatched', 409);
            abort_unless($access->canReceive($user, (string) $transfer->to_branch_id), 403);
            $this->assertActiveBranches($transfer);
            
            $items = $this->store->rows('stock_transfer_items', ['transfer_id'=>$id])->sort(fn($a,$b)=>strlen($a['lot_id'])<=>strlen($b['lot_id']) ?: strcmp($a['lot_id'],$b['lot_id']))->map(fn($r)=>(object)($r+['id'=>$r['_id']]));
            $this->assertMatchingItems($items, $quantities, 'received_quantity');
            $discrepancy = false;
            foreach ($items as $item) {
                $amount = BigDecimal::of((string) $quantities[(string) $item->lot_id]);
                $dispatched = BigDecimal::of((string) $item->dispatched_quantity);
                if ($amount->isLessThan(0) || $amount->isGreaterThan($dispatched)) {
                    throw ValidationException::withMessages(['items' => 'Số nhận phải từ 0 đến số đã xuất.']);
                }
                if ($amount->isLessThan($dispatched)) $discrepancy = true;
                if ($item->transfer_unit_cost === null) {
                    throw ValidationException::withMessages(['items' => 'Phiếu xuất chưa lưu giá vốn chuyển kho.']);
                }
                if ($amount->isGreaterThan(0)) {
                    $this->addToInventory((string) $transfer->to_warehouse_id, (string) $item->lot_id,
                        $amount, (string) $item->transfer_unit_cost, $id, 'transfer_in');
                }
                $this->store->update('stock_transfer_items', $item->id, ['received_quantity' => (string) $amount->toScale(3)]);
            }
            $status = $discrepancy ? 'discrepancy' : 'received';
            $this->store->update('stock_transfers', $id, [
                'status' => $status, 'received_by' => $user->id, 'received_at' => $this->stamp(),
            ]);
            $this->recordEvent($id, 'transfer.received', $user, $transfer, ['quantities' => $quantities, 'status' => $status]);

            return $status;
        });
    }

    public function reconcile(string $id, User $user, TransferAccess $access, array $quantities, string $reason): void
    {
        $this->store->transaction(function () use ($id, $user, $access, $quantities, $reason): void {
            $user = $this->actor((string) $user->id);
            $transfer = $this->lockTransfer($id);
            abort_unless($transfer->status === 'discrepancy', 409);
            abort_unless($access->canReconcile($user, (string) $transfer->from_branch_id,
                (string) $transfer->to_branch_id, (string) $transfer->chain_id), 403);
            $warehouseIds = array_unique([(string) $transfer->from_warehouse_id, (string) $transfer->to_warehouse_id]);
            sort($warehouseIds, SORT_STRING);
            foreach ($warehouseIds as $warehouseId) {
                $this->store->purchase->identity->find('warehouses', $warehouseId);
            }
            $items = $this->store->rows('stock_transfer_items', ['transfer_id'=>$id])->sort(fn($a,$b)=>strlen($a['lot_id'])<=>strlen($b['lot_id']) ?: strcmp($a['lot_id'],$b['lot_id']))->map(fn($r)=>(object)($r+['id'=>$r['_id']]));
            $this->assertMatchingItems($items, $quantities, 'reconciliation');
            foreach ($items as $item) {
                $line = $quantities[(string) $item->lot_id];
                $supplemental = BigDecimal::of($line['supplemental_received_quantity']);
                $returned = BigDecimal::of($line['returned_quantity']);
                $lost = BigDecimal::of($line['lost_quantity']);
                $shortage = BigDecimal::of((string) $item->dispatched_quantity)->minus((string) $item->received_quantity);
                if (! $supplemental->plus($returned)->plus($lost)->isEqualTo($shortage)) {
                    throw ValidationException::withMessages(['items' => 'Tổng nhận bổ sung, hoàn nguồn và hao hụt phải đúng bằng phần thiếu của từng lô.']);
                }
                if ($item->transfer_unit_cost === null) {
                    throw ValidationException::withMessages(['items' => 'Thiếu giá vốn đã chụp lúc xuất.']);
                }
                if ($supplemental->isGreaterThan(0)) {
                    $this->addToInventory((string) $transfer->to_warehouse_id, (string) $item->lot_id,
                        $supplemental, (string) $item->transfer_unit_cost, $id, 'transfer_supplemental_in');
                }
                if ($returned->isGreaterThan(0)) {
                    $this->addToInventory((string) $transfer->from_warehouse_id, (string) $item->lot_id,
                        $returned, (string) $item->transfer_unit_cost, $id, 'transfer_return_in');
                }
                $this->store->update('stock_transfer_items', $item->id, [
                    'supplemental_received_quantity' => (string) $supplemental->toScale(3),
                    'returned_quantity' => (string) $returned->toScale(3),
                    'lost_quantity' => (string) $lost->toScale(3),
                ]);
            }
            $this->store->update('stock_transfers', $id, [
                'status' => 'reconciled', 'reconciled_by' => $user->id,
                'reconciled_at' => $this->stamp(), 'reconciliation_reason' => trim($reason),
            ]);
            $this->recordEvent($id, 'transfer.reconciled', $user, $transfer, ['quantities' => $quantities, 'reason' => trim($reason)]);
        });
    }

    private function recordEvent(string $id, string $event, User $user, object $transfer, array $details = []): void
    {
        app(OutboxService::class)->record('stock_transfer', $id, $event, [
            'actor_id' => (string) $user->id,
            'from_warehouse_id' => (string) $transfer->from_warehouse_id,
            'to_warehouse_id' => (string) $transfer->to_warehouse_id,
            'from_branch_id' => (string) $transfer->from_branch_id,
            'to_branch_id' => (string) $transfer->to_branch_id,
        ] + $details);
    }

    private function addToInventory(string $warehouseId, string $lotId, BigDecimal $amount,
        string $unitCost, string $transferId, string $movementType): void
    {
        $inventory = $this->inventory($warehouseId, $lotId);
        $oldQuantity = BigDecimal::of((string) ($inventory?->quantity ?? '0'));
        if ($oldQuantity->isGreaterThan(0) && $inventory->average_unit_cost === null) {
            throw ValidationException::withMessages(['items' => 'Tồn cũ của kho nhận chưa có giá vốn; cần xác minh trước khi cộng.']);
        }
        $newQuantity = $oldQuantity->plus($amount);
        DecimalLimit::assertFits($newQuantity->toScale(3), 15, 3, 'items');
        $oldValue = $oldQuantity->isZero() ? BigDecimal::zero() : $oldQuantity->multipliedBy((string) $inventory->average_unit_cost);
        $incomingValue = $amount->multipliedBy($unitCost);
        $averageCost = $oldValue->plus($incomingValue)->dividedBy($newQuantity, 6, RoundingMode::HalfUp);
        $values = ['quantity' => (string) $newQuantity->toScale(3),
            'average_unit_cost' => (string) $averageCost, 'updated_at' => $this->stamp()];
        if ($inventory) $this->store->purchase->update('inventories', $inventory->id, $values);
        else $this->store->purchase->insert('inventories', ['warehouse_id' => $warehouseId, 'lot_id' => $lotId] + $values);
        $this->store->purchase->insert('stock_movements', [
            'warehouse_id' => $warehouseId, 'lot_id' => $lotId,
            'stock_transfer_id' => $transferId, 'movement_type' => $movementType,
            'quantity_delta' => (string) $amount->toScale(3),
        ]);
    }

    private function inventory(string $warehouseId, string $lotId): ?object
    {
        $row = $this->store->purchase->rows('inventories', ['warehouse_id'=>$warehouseId,'lot_id'=>$lotId])->first();
        return $row ? (object) ($row + ['id'=>$row['_id']]) : null;
    }

    public function scope(string $warehouseId): ?array { return $this->store->purchase->scope($warehouseId); }

    private function lockTransfer(string $id): object
    {
        $row = $this->store->find('stock_transfers', $id);
        abort_if($row === null, 404);
        $source = $this->scope($row['from_warehouse_id']);
        $target = $this->scope($row['to_warehouse_id']);
        abort_unless($source && $target && $source['chain']['_id'] === $target['chain']['_id'], 409);
        return (object) ($row + ['from_branch_id'=>$source['branch']['_id'],'to_branch_id'=>$target['branch']['_id'],
            'chain_id'=>$source['chain']['_id'],'source_active'=>$source['branch']['is_active'],
            'target_active'=>$target['branch']['is_active'],'chain_active'=>$source['chain']['is_active']]);
    }

    public function request(array $data, string $fromId, string $toId, User $user, TransferAccess $access): string
    {
        return $this->store->transaction(function () use ($data, $fromId, $toId, $user, $access): string {
            $user = $this->actor((string) $user->id);
            $source = $this->scope($fromId); $target = $this->scope($toId);
            if (!$source || !$target) throw ValidationException::withMessages(['to_warehouse_id'=>'Kho nguồn hoặc kho đích không tồn tại.']);
            abort_unless($access->canRequest($user, $source['branch']['_id']), 403);
            if ($source['chain']['_id'] !== $target['chain']['_id']) throw ValidationException::withMessages(['to_warehouse_id'=>'Chỉ điều chuyển giữa các kho cùng chuỗi.']);
            if (!$source['branch']['is_active'] || !$target['branch']['is_active'] || !$source['chain']['is_active'] || !$target['chain']['is_active']) throw ValidationException::withMessages(['to_warehouse_id'=>'Chi nhánh hoặc chuỗi đã ngừng hoạt động.']);
            $seen = []; $lines = [];
            foreach ($data['items'] as $index => $item) {
                $lotId = $item['lot_id'];
                if (isset($seen[$lotId])) throw ValidationException::withMessages(['items'=>'Một lô chỉ được chọn một lần.']);
                $seen[$lotId] = true;
                $inventory = $this->inventory($fromId, $lotId);
                $lot = app(SaleService::class)->lot($lotId);
                if (!$inventory || !$lot || !$lot['product_active'] || !$lot['category_active'] || !$lot['unit_active'] || BigDecimal::of((string) $inventory->quantity)->isLessThan((string) $item['requested_quantity'])) throw ValidationException::withMessages(["items.{$index}.requested_quantity"=>'Kho nguồn không đủ tồn hoạt động cho lô này.']);
                $lines[] = ['lot_id'=>$lotId,'requested_quantity'=>(string) BigDecimal::of((string) $item['requested_quantity'])->toScale(3)];
            }
            $id = $this->store->insert('stock_transfers', ['from_warehouse_id'=>$fromId,'to_warehouse_id'=>$toId,'requested_by'=>(string) $user->id,'transfer_no'=>'DC-'.Str::ulid(),'status'=>'requested']);
            foreach ($lines as $line) $this->store->insert('stock_transfer_items', ['transfer_id'=>$id] + $line);
            app(OutboxService::class)->record('stock_transfer', $id, 'transfer.requested', ['actor_id'=>(string) $user->id,
                'from_branch_id'=>$source['branch']['_id'],'to_branch_id'=>$target['branch']['_id'],
                'from_warehouse_id'=>$fromId,'to_warehouse_id'=>$toId,'items'=>$lines]);
            return $id;
        });
    }

    private function assertActiveBranches(object $transfer): void
    {
        if (! $transfer->source_active || ! $transfer->target_active || ! $transfer->chain_active) {
            throw ValidationException::withMessages(['status' => 'Chuỗi hoặc chi nhánh nguồn/đích đã ngừng hoạt động.']);
        }
    }

    private function assertMatchingItems($items, array $quantities, string $field): void
    {
        if ($items->isEmpty() || count($quantities) !== $items->count()) {
            throw ValidationException::withMessages(['items' => 'Phải khai đủ số lượng thực tế cho mọi lô.']);
        }
        foreach ($items as $item) {
            if (! array_key_exists((string) $item->lot_id, $quantities)) {
                throw ValidationException::withMessages(['items' => "Thiếu {$field} của một lô."]);
            }
        }
    }
}
