<?php

namespace App\Support;

use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

class DebtService
{
    private readonly SalesStore $store;

    public function __construct(?SalesStore $store = null) { $this->store = $store ?? app(SalesStore::class); }

    public function recordReceivable(string $invoiceId, object $order, string $amount, string $actorId): ?string
    {
        $this->store->unit();
        if ($order->customer_id === null || BigDecimal::of($amount)->isZero()) return null;
        $debt = $this->store->rows('debts',['customer_id'=>(string)$order->customer_id])->first();
        $debtId = $debt['_id'] ?? $this->store->insert('debts',['customer_id'=>(string)$order->customer_id,'debt_type'=>'receivable']);
        $this->store->insert('debt_transactions',['debt_id'=>$debtId,'invoice_id'=>$invoiceId,'branch_id'=>$order->branch_id,'warehouse_id'=>$order->warehouse_id,'created_by'=>$actorId,'transaction_type'=>'sale_charge','amount'=>$amount,'season_label'=>$order->season_label]);
        $this->store->update('debts',$debtId,['status'=>'open']);
        return $debtId;
    }

    public function recordPayable(string $receiptId, object $receipt, string $actorId): ?string
    {
        $store = app(PurchaseStore::class);
        $store->unit();
        if (BigDecimal::of((string) $receipt->total_amount)->isZero()) return null;
        $debt = $store->rows('debts', ['supplier_id' => (string) $receipt->supplier_id])->first();
        $debtId = $debt['_id'] ?? $store->insert('debts', ['supplier_id' => (string) $receipt->supplier_id, 'debt_type' => 'payable']);
        $warehouse = $store->identity->find('warehouses', (string) $receipt->warehouse_id);
        $store->insert('debt_transactions', ['debt_id' => $debtId, 'purchase_receipt_id' => $receiptId, 'branch_id' => $warehouse['branch_id'], 'warehouse_id' => (string) $receipt->warehouse_id, 'created_by' => $actorId, 'transaction_type' => 'purchase_charge', 'amount' => (string) $receipt->total_amount]);
        $store->update('debts', $debtId, ['status' => 'open']);
        return $debtId;
    }

    public function payCharge(string $chargeId, User $actor, array $data): array
    {
        return $this->store->transaction(function () use ($chargeId,$actor,$data): array {
            $actor = app(SaleService::class)->actor((string)$actor->id);
            $charge = $this->store->find('debt_transactions',$chargeId);
            abort_unless($charge && in_array($charge['transaction_type'],['sale_charge','purchase_charge'],true),404);
            $debt = $this->store->find('debts',$charge['debt_id']);
            abort_if(!$debt,404);
            $branch = $this->store->purchase->identity->find('branches',$charge['branch_id']);
            abort_unless($branch && app(DebtAccess::class)->canPay($actor,$debt['debt_type'],$charge['branch_id'],$branch['chain_id']),403);
            $sourceField = $charge['invoice_id'] !== null ? 'invoice_id' : 'purchase_receipt_id';
            $existing = $this->store->rows('payments',['request_key'=>$data['request_key']])->first();
            if ($existing !== null) {
                $same = $existing[$sourceField] === $charge[$sourceField] && $existing['created_by'] === (string)$actor->id && $existing['method'] === $data['method'] && BigDecimal::of((string)$existing['amount'])->isEqualTo($data['amount']);
                abort_unless($same,409);
                return ['id'=>$existing['_id'],'status'=>'completed','replayed'=>true];
            }
            $paid = $this->paidFor($charge['debt_id'],$sourceField,$charge[$sourceField]);
            $remaining = BigDecimal::of((string)$charge['amount'])->minus($this->creditFor($sourceField,$charge[$sourceField]))->minus($paid)->toScale(2);
            $amount = BigDecimal::of($data['amount'])->toScale(2,RoundingMode::HalfUp);
            if ($amount->isGreaterThan($remaining)) throw ValidationException::withMessages(['amount'=>'Số tiền vượt dư nợ còn lại của chứng từ.']);
            $paymentId = $this->store->insert('payments',[$sourceField=>$charge[$sourceField],'method'=>$data['method'],'amount'=>(string)$amount,'status'=>'completed','created_by'=>(string)$actor->id,'request_key'=>$data['request_key'],'reference_note'=>$data['reference_note']??null]);
            $this->store->insert('debt_transactions',['debt_id'=>$debt['_id'],'payment_id'=>$paymentId,$sourceField=>$charge[$sourceField],'branch_id'=>$charge['branch_id'],'warehouse_id'=>$charge['warehouse_id'],'created_by'=>(string)$actor->id,'transaction_type'=>$debt['debt_type']==='receivable'?'customer_payment':'supplier_payment','amount'=>(string)$amount]);
            if ($sourceField==='invoice_id' && $amount->isEqualTo($remaining)) $this->store->update('invoices',$charge['invoice_id'],['status'=>'paid']);
            $this->refreshStatus($debt['_id']);
            app(OutboxService::class)->record('payment',$paymentId,'debt.payment_recorded',[
                'actor_id'=>(string)$actor->id,'debt_id'=>$debt['_id'],'charge_id'=>$chargeId,'branch_id'=>$charge['branch_id'],'source_type'=>$sourceField==='invoice_id'?'invoice':'purchase_receipt','source_id'=>$charge[$sourceField],'method'=>$data['method'],'amount'=>(string)$amount,
            ]);
            return ['id'=>$paymentId,'status'=>'completed','remaining_amount'=>(string)$remaining->minus($amount),'replayed'=>false];
        });
    }

    public function paidFor(string $debtId, string $sourceField, string $sourceId): BigDecimal
    {
        return $this->store->sum($this->store->rows('debt_transactions',['debt_id'=>$debtId,$sourceField=>$sourceId,'payment_id'=>['$ne'=>null]]))->minus($this->refundedFor($sourceField,$sourceId))->plus($sourceField==='invoice_id'?$this->exchangeFor($sourceId,true):BigDecimal::zero())->minus($sourceField==='invoice_id'?$this->exchangeFor($sourceId,false):BigDecimal::zero());
    }

    public function creditFor(string $sourceField,string $sourceId): BigDecimal
    {
        return $this->store->sum($this->store->rows('debt_transactions',[$sourceField=>$sourceId,'transaction_type'=>'sale_return_credit']));
    }

    public function refundedFor(string $sourceField,string $sourceId): BigDecimal
    {
        return $this->store->sum($this->store->rows('debt_transactions',[$sourceField=>$sourceId,'transaction_type'=>'customer_refund']));
    }

    public function exchangeFor(string $invoiceId,bool $incoming): BigDecimal
    {
        return $this->store->sum(collect(app(\App\Commerce\CommerceStore::class)->rows('shop_exchange_transfers',[($incoming?'target_invoice_id':'source_invoice_id')=>$invoiceId])));
    }

    public function refreshStatus(string $debtId): void
    {
        $this->store->unit();
        $debt = $this->store->find('debts',$debtId);
        abort_if(!$debt,404);
        $charges = $this->store->sum($this->store->rows('debt_transactions',['debt_id'=>$debtId,'transaction_type'=>['$in'=>['sale_charge','purchase_charge']]]));
        $payments = $this->store->sum($this->store->rows('debt_transactions',['debt_id'=>$debtId,'payment_id'=>['$ne'=>null]]));
        $credits=$this->store->sum($this->store->rows('debt_transactions',['debt_id'=>$debtId,'transaction_type'=>'sale_return_credit']));
        $refunds=$this->store->sum($this->store->rows('debt_transactions',['debt_id'=>$debtId,'transaction_type'=>'customer_refund']));
        $exchange=app(\App\Commerce\CommerceStore::class);$incoming=$this->store->sum(collect($exchange->rows('shop_exchange_transfers',['target_debt_id'=>$debtId])));$outgoing=$this->store->sum(collect($exchange->rows('shop_exchange_transfers',['source_debt_id'=>$debtId])));
        $balance = BigDecimal::of((string)$debt['opening_balance'])->plus($charges)->minus($credits)->minus($payments)->plus($refunds)->minus($incoming)->plus($outgoing);
        $this->store->update('debts',$debtId,['status'=>$balance->isZero()?'closed':'open']);
    }
}
