<?php

namespace App\Http\Controllers;

use App\Support\{BranchAccess, DebtAccess, DebtService, DecimalId, SalesStore};
use Brick\Math\BigDecimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class DebtController extends Controller
{
    public function __construct(private readonly SalesStore $store) {}

    public function index(Request $request, BranchAccess $branches, DebtAccess $access, DebtService $service): JsonResponse
    {
        $allowed = $branches->branchesFor($request->user());
        abort_if($allowed->isEmpty(),403);
        $data = $request->validate(['type'=>['nullable','in:receivable,payable'],'branch_id'=>['nullable','string'],'status'=>['nullable','in:open,paid'],'page'=>['nullable','integer','min:1']]);
        if(isset($data['branch_id'])) {
            $data['branch_id']=DecimalId::parse($data['branch_id'],'branch_id');
            abort_unless(in_array($data['branch_id'],$allowed->pluck('id')->all(),true),403);
        }
        $filter=['transaction_type'=>['$in'=>['sale_charge','purchase_charge']],'branch_id'=>isset($data['branch_id'])?$data['branch_id']:['$in'=>$allowed->pluck('id')->all()]];
        $rows=$this->store->rows('debt_transactions',$filter,['id_sort'=>-1])->map(function($charge) use($request,$access,$service,$data){
            $debt=$this->store->find('debts',$charge['debt_id']);
            $branch=$this->store->purchase->identity->find('branches',$charge['branch_id']);
            if(!$debt || !$branch || (isset($data['type'])&&$debt['debt_type']!==$data['type']) || !$access->canView($request->user(),$debt['debt_type'],$charge['branch_id'],$branch['chain_id'])) return null;
            $customer=$debt['customer_id']===null?null:$this->store->purchase->catalog->find('customers',$debt['customer_id']);
            $supplier=$debt['supplier_id']===null?null:$this->store->purchase->catalog->find('suppliers',$debt['supplier_id']);
            $invoice=$charge['invoice_id']===null?null:$this->store->find('invoices',$charge['invoice_id']);
            $receipt=$charge['purchase_receipt_id']===null?null:$this->store->find('purchase_receipts',$charge['purchase_receipt_id']);
            $source=$charge['invoice_id']!==null?'invoice_id':'purchase_receipt_id';
            $paid=$service->paidFor($charge['debt_id'],$source,$charge[$source]);
            $credit=$service->creditFor($source,$charge[$source]);
            $remaining=BigDecimal::max(BigDecimal::zero(),BigDecimal::of((string)$charge['amount'])->minus($credit)->minus($paid))->toScale(2);
            $fields=['id','debt_id','invoice_id','purchase_receipt_id','branch_id','warehouse_id','amount','occurred_at','season_label'];
            return $this->store->purchase->present($charge,$fields)+['chain_id'=>$branch['chain_id'],'branch_name'=>$branch['name'],'debt_type'=>$debt['debt_type'],'customer_name'=>$customer['name']??null,'supplier_name'=>$supplier['name']??null,'invoice_no'=>$invoice['invoice_no']??null,'receipt_no'=>$receipt['receipt_no']??null,'credit_amount'=>(string)$credit->toScale(2),'paid_amount'=>$paid->isZero()?'0':(string)$paid->toScale(2),'remaining_amount'=>(string)$remaining,'can_pay'=>$access->canPay($request->user(),$debt['debt_type'],$charge['branch_id'],$branch['chain_id'])&&$remaining->isGreaterThan(0)];
        })->filter()->values();
        if(isset($data['status']))$rows=$rows->filter(fn($r)=>($r['remaining_amount']==='0.00'?'paid':'open')===$data['status'])->values();
        $page=(int)($data['page']??1);
        return response()->json(['data'=>$rows->forPage($page,20)->values(),'total'=>$rows->count(),'page'=>$page]);
    }

    public function pay(Request $request, DebtService $service, string $id): JsonResponse
    {
        $id = DecimalId::parse($id, 'id');
        $data = $request->validate([
            'amount' => ['required', 'regex:/^\d{1,16}(\.\d{1,2})?$/'],
            'method' => ['required', 'in:cash,bank_transfer'],
            'request_key' => ['required', 'string', 'min:8', 'max:80'],
            'reference_note' => ['nullable', 'string', 'max:200'],
        ]);
        if (array_diff(array_keys($request->all()), ['amount', 'method', 'request_key', 'reference_note']) !== []) {
            throw ValidationException::withMessages(['amount' => 'Chỉ nhận thông tin của lần thu hoặc chi này.']);
        }
        if (\Brick\Math\BigDecimal::of($data['amount'])->isLessThanOrEqualTo(0)) {
            throw ValidationException::withMessages(['amount' => 'Số tiền phải lớn hơn 0.']);
        }

        return response()->json($service->payCharge($id, $request->user(), $data));
    }
}
