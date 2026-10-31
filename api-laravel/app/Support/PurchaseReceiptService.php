<?php

namespace App\Support;

use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\Exception\BulkWriteException;

class PurchaseReceiptService
{
    public function __construct(private readonly PurchaseStore $store, private readonly ReceiptAccess $access) {}

    public function actor(string $id): User
    {
        $row = $this->store->identity->find('users', $id);
        abort_unless($row && $row['is_active'], 403);
        return (new User)->newFromBuilder($row);
    }

    public function assertScope(array $data, User $actor): array
    {
        $scope = $this->store->scope($data['warehouse_id']);
        abort_if($scope === null, 422);
        abort_unless($this->access->canCreate($actor, $scope['branch']['_id']), 403);
        $this->assertActive($scope);
        $supplier = $this->store->catalog->find('suppliers', $data['supplier_id']);
        if (($supplier['chain_id'] ?? null) !== $scope['branch']['chain_id']) throw ValidationException::withMessages(['supplier_id' => 'Nhà cung cấp phải thuộc cùng chuỗi với kho nhập.']);
        return $scope;
    }

    public function assertProducts(array $items): void
    {
        foreach ($items as $index => $item) {
            $product = $this->store->catalog->find('products', $item['product_id']);
            $category = $product ? $this->store->catalog->find('product_categories', $product['category_id']) : null;
            $unit = $product ? $this->store->catalog->find('units', $product['unit_id']) : null;
            if (!($product['is_active'] ?? false) || !($category['is_active'] ?? false) || !($unit['is_active'] ?? false)) throw ValidationException::withMessages(["items.{$index}.product_id" => 'Vật tư không còn được sử dụng.']);
        }
    }

    public function saveDraft(array $data, string $creatorId, ?string $receiptId = null): string
    {
        try {
            return $this->store->transaction(function () use ($data, $creatorId, $receiptId): string {
                $actor = $this->actor($creatorId);
                $this->assertProducts($data['items']);
                $this->assertScope($data, $actor);
                if ($receiptId !== null) {
                    $receipt = $this->store->find('purchase_receipts', $receiptId);
                    abort_if($receipt === null, 404);
                    abort_unless($receipt['status'] === 'draft' && $receipt['created_by'] === $creatorId, 403);
                    abort_unless($receipt['warehouse_id'] === $data['warehouse_id'], 422);
                } else {
                    $receiptId = $this->store->insert('purchase_receipts', ['supplier_id' => $data['supplier_id'], 'warehouse_id' => $data['warehouse_id'], 'created_by' => $creatorId, 'receipt_no' => 'PN-'.Str::ulid()]);
                }
                $total = BigDecimal::zero(); $lines = []; $seen = [];
                foreach ($data['items'] as $item) {
                    $lotId = $this->findOrCreateLot($item);
                    if (isset($seen[$lotId])) throw ValidationException::withMessages(['items' => 'Một lô chỉ xuất hiện một lần trong phiếu.']);
                    $seen[$lotId] = true;
                    $lineTotal = BigDecimal::of((string) $item['quantity'])->multipliedBy((string) $item['unit_cost'])->toScale(2, RoundingMode::HalfUp);
                    DecimalLimit::assertFits($lineTotal, 16, 2, 'items');
                    $total = $total->plus($lineTotal);
                    $lines[] = ['lot_id' => $lotId, 'quantity' => (string) BigDecimal::of((string) $item['quantity'])->toScale(3), 'unit_cost' => (string) BigDecimal::of((string) $item['unit_cost'])->toScale(2), 'line_total' => (string) $lineTotal];
                }
                DecimalLimit::assertFits($total, 16, 2, 'items');
                $invoiceNo = isset($data['supplier_invoice_no']) ? trim($data['supplier_invoice_no']) : null;
                if ($invoiceNo === '') $invoiceNo = null;
                if ($invoiceNo !== null && $this->store->rows('purchase_receipts', ['supplier_id' => $data['supplier_id'], 'supplier_invoice_no_normalized' => mb_strtolower($invoiceNo), '_id' => ['$ne' => $receiptId]])->isNotEmpty()) $this->duplicateInvoice();
                $this->store->replaceItems($receiptId, $lines);
                $this->store->update('purchase_receipts', $receiptId, ['supplier_id' => $data['supplier_id'], 'total_amount' => (string) $total->toScale(2), 'supplier_invoice_no' => $invoiceNo, 'supplier_invoice_date' => $data['supplier_invoice_date'] ?? null, 'supplier_invoice_total' => $data['supplier_invoice_total'] ?? null]);
                return $receiptId;
            });
        } catch (BulkWriteException $error) {
            if ($error->getCode() === 11000 && str_contains($error->getMessage(), 'ix_purchase_receipts_03')) $this->duplicateInvoice();
            throw $error;
        }
    }

    private function duplicateInvoice(): never
    {
        throw ValidationException::withMessages(['supplier_invoice_no' => 'Số hóa đơn này đã được ghi nhận cho nhà cung cấp.']);
    }

    public function submit(string $id, string $actorId): void
    {
        $this->store->transaction(function () use ($id, $actorId): void {
            $receipt = $this->store->find('purchase_receipts', $id);
            abort_if($receipt === null, 404);
            abort_unless($receipt['status'] === 'draft', 409);
            abort_unless($receipt['created_by'] === $actorId, 403);
            $scope = $this->store->scope($receipt['warehouse_id']);
            $this->assertActive($scope);
            abort_unless($this->access->canCreate($this->actor($actorId), $scope['branch']['_id']), 403);
            abort_if($this->store->rows('purchase_receipt_items', ['receipt_id' => $id])->isEmpty(), 422);
            $this->assertInvoiceComplete((object) $receipt);
            $this->store->update('purchase_receipts', $id, ['status' => 'submitted', 'submitted_at' => new UTCDateTime($this->store->unit()->clock)]);
        });
    }

    public function reject(string $id, string $actorId, ?string $reason): void
    {
        $this->store->transaction(function () use ($id, $actorId, $reason): void {
            $receipt = $this->store->find('purchase_receipts', $id);
            abort_if($receipt === null, 404);
            abort_if($receipt['created_by'] === $actorId, 403);
            abort_unless($receipt['status'] === 'submitted', 409);
            $scope = $this->store->scope($receipt['warehouse_id']);
            abort_unless($scope && $this->access->canApprove($this->actor($actorId), $scope['branch']['_id'], $scope['chain']['_id']), 403);
            $this->store->update('purchase_receipts', $id, ['status' => 'rejected', 'rejected_by' => $actorId, 'rejected_at' => new UTCDateTime($this->store->unit()->clock), 'rejection_reason' => $reason]);
        });
    }

    public function approve(string $receiptId, string $approverId): void
    {
        $this->store->transaction(function () use ($receiptId, $approverId): void {
            $receipt = $this->store->find('purchase_receipts', $receiptId);
            abort_if($receipt === null, 404);
            abort_unless($receipt['status'] === 'submitted', 409);
            abort_if($receipt['created_by'] === $approverId, 403);
            $this->assertInvoiceComplete((object) $receipt);
            $scope = $this->store->scope($receipt['warehouse_id']);
            $this->assertActive($scope);
            abort_unless($this->access->canApprove($this->actor($approverId), $scope['branch']['_id'], $scope['chain']['_id']), 403);
            $items = $this->store->rows('purchase_receipt_items', ['receipt_id' => $receiptId])->sort(fn ($a, $b) => (int) $a['lot_id'] <=> (int) $b['lot_id']);
            abort_if($items->isEmpty(), 422);
            foreach ($items as $item) {
                $inventory = $this->store->rows('inventories', ['warehouse_id' => $receipt['warehouse_id'], 'lot_id' => $item['lot_id']])->first();
                $oldQuantity = BigDecimal::of((string) ($inventory['quantity'] ?? '0'));
                if ($oldQuantity->isGreaterThan(0) && $inventory['average_unit_cost'] === null) throw ValidationException::withMessages(['items' => 'Tồn cũ của lô chưa có giá vốn gốc; cần xác minh trước khi nhập thêm.']);
                $addedQuantity = BigDecimal::of((string) $item['quantity']);
                $newQuantity = $oldQuantity->plus($addedQuantity);
                DecimalLimit::assertFits($newQuantity->toScale(3), 15, 3, 'items');
                $oldValue = $oldQuantity->isZero() ? BigDecimal::zero() : $oldQuantity->multipliedBy((string) $inventory['average_unit_cost']);
                $averageCost = $oldValue->plus($addedQuantity->multipliedBy((string) $item['unit_cost']))->dividedBy($newQuantity, 6, RoundingMode::HalfUp);
                DecimalLimit::assertFits($averageCost, 12, 6, 'items');
                $values = ['quantity' => (string) $newQuantity->toScale(3), 'average_unit_cost' => (string) $averageCost, 'updated_at' => new UTCDateTime($this->store->unit()->clock)];
                if ($inventory) $this->store->update('inventories', $inventory['_id'], $values);
                else $this->store->insert('inventories', ['warehouse_id' => $receipt['warehouse_id'], 'lot_id' => $item['lot_id']] + $values);
                $this->store->insert('stock_movements', ['warehouse_id' => $receipt['warehouse_id'], 'lot_id' => $item['lot_id'], 'purchase_receipt_id' => $receiptId, 'movement_type' => 'purchase_in', 'quantity_delta' => (string) $addedQuantity->toScale(3)]);
            }
            $this->store->update('purchase_receipts', $receiptId, ['status' => 'approved', 'approved_by' => $approverId, 'approved_at' => new UTCDateTime($this->store->unit()->clock)]);
            app(DebtService::class)->recordPayable($receiptId, (object) $receipt, $approverId);
            app(OutboxService::class)->record('purchase_receipt', $receiptId, 'purchase.approved', [
                'actor_id' => $approverId, 'branch_id' => $scope['branch']['_id'], 'warehouse_id' => $receipt['warehouse_id'], 'supplier_id' => $receipt['supplier_id'], 'total_amount' => (string) BigDecimal::of((string) $receipt['total_amount'])->toScale(2),
                'supplier_invoice_no' => $receipt['supplier_invoice_no'], 'supplier_invoice_date' => $receipt['supplier_invoice_date'], 'supplier_invoice_total' => (string) BigDecimal::of((string) $receipt['supplier_invoice_total'])->toScale(2),
            ]);
        });
    }

    public function assertInvoiceComplete(object $receipt): void
    {
        if ($receipt->supplier_invoice_no === null || trim($receipt->supplier_invoice_no) === '' || $receipt->supplier_invoice_date === null || $receipt->supplier_invoice_total === null) throw ValidationException::withMessages(['supplier_invoice_no' => 'Cần đủ số, ngày và tổng hóa đơn nhà cung cấp trước khi gửi duyệt.']);
        if (!BigDecimal::of((string) $receipt->supplier_invoice_total)->isEqualTo((string) $receipt->total_amount)) throw ValidationException::withMessages(['supplier_invoice_total' => 'Tổng hóa đơn nhà cung cấp phải khớp tổng phiếu nhập trước khi gửi duyệt.']);
    }

    private function assertActive(?array $scope): void
    {
        if (!$scope || !$scope['branch']['is_active'] || !$scope['chain']['is_active']) throw ValidationException::withMessages(['warehouse_id' => 'Chi nhánh hoặc chuỗi đã ngừng hoạt động.']);
    }

    private function findOrCreateLot(array $item): string
    {
        $lotNo = trim($item['lot_no']);
        $lot = $this->store->rows('product_lots', ['product_id' => $item['product_id'], 'lot_no' => $lotNo])->first();
        if ($lot === null) {
            $id = $this->store->insert('product_lots', ['product_id' => $item['product_id'], 'lot_no' => $lotNo, 'manufactured_on' => $item['manufactured_on'] ?? null, 'expires_on' => $item['expires_on'] ?? null]);
            $lot = $this->store->find('product_lots', $id);
        }
        foreach (['manufactured_on', 'expires_on'] as $field) {
            if (!empty($item[$field]) && $item[$field] !== $lot[$field]) throw ValidationException::withMessages(['items' => 'Ngày của lô đã tồn tại không khớp dữ liệu nhập.']);
        }
        return $lot['_id'];
    }
}
