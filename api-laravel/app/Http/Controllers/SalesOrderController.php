<?php

namespace App\Http\Controllers;

use App\Support\{BranchAccess, DecimalId, DecimalLimit, SaleService, SalesAccess, SalesStore};
use Brick\Math\BigDecimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SalesOrderController extends Controller
{
    public function __construct(private readonly SalesStore $store) {}

    public function options(Request $request, BranchAccess $branches, SalesAccess $access, SaleService $service): JsonResponse
    {
        $allowed = $branches->branchesFor($request->user());
        abort_if($allowed->isEmpty(),403);
        $warehouses = $this->store->purchase->identity->rows('warehouses',['branch_id'=>['$in'=>$allowed->pluck('id')->all()]])->map(function($w) use($request,$access){
            $scope = $this->store->purchase->scope($w['_id']);
            if (!$scope || !$scope['branch']['is_active'] || !$scope['chain']['is_active'] || !$access->canSell($request->user(),$scope['branch']['_id'],$scope['chain']['_id'])) return null;
            return ['id'=>$w['_id'],'name'=>$w['name'],'branch_id'=>$scope['branch']['_id'],'chain_id'=>$scope['chain']['_id'],'branch_name'=>$scope['branch']['name']];
        })->filter()->values();
        $customers = $this->store->purchase->catalog->rows('customers',['chain_id'=>['$in'=>$warehouses->pluck('chain_id')->unique()->values()->all()]],['name'=>1])->map(fn($c)=>$this->store->purchase->catalog->present($c,['id','chain_id','name','customer_type']));
        $stock = $this->store->rows('inventories',['warehouse_id'=>['$in'=>$warehouses->pluck('id')->all()]])->map(function($i) use($service){
            $lot = $service->lot($i['lot_id']);
            if (!BigDecimal::of((string)$i['quantity'])->isGreaterThan(0) || !$lot || !$lot['product_active'] || !$lot['category_active'] || !$lot['unit_active'] || ($lot['expires_on']!==null && $lot['expires_on']<now('Asia/Ho_Chi_Minh')->toDateString())) return null;
            return ['warehouse_id'=>$i['warehouse_id'],'lot_id'=>$i['lot_id'],'quantity'=>(string)$i['quantity'],'product_id'=>$lot['product_id'],'code'=>$lot['code'],'name'=>$lot['name'],'sale_price'=>(string)$lot['sale_price'],'tax_rate'=>$lot['tax_rate']===null?null:(string)$lot['tax_rate'],'lot_no'=>$lot['lot_no'],'expires_on'=>$lot['expires_on'],'unit_name'=>$lot['unit_name']];
        })->filter()->sortBy([['name','asc'],['lot_no','asc']])->values();
        return response()->json(compact('warehouses','customers','stock'));
    }

    public function index(Request $request, BranchAccess $branches): JsonResponse
    {
        $allowed = $branches->branchesFor($request->user())->pluck('id')->all();
        abort_if($allowed===[],403);
        $fields = ['id','order_no','status','total_amount','sold_at','branch_id','branch_name','customer_id','customer_name','invoice_id','invoice_no'];
        $orders = $this->store->rows('sales_orders',['branch_id'=>['$in'=>$allowed]],['id_sort'=>-1])->map(fn($r)=>$this->joined($r))->filter()->take(50)->map(fn($r)=>array_intersect_key($r,array_flip($fields)))->values();
        return response()->json(['data'=>$orders]);
    }

    private function joined(array $sale): ?array
    {
        $branch = $this->store->purchase->identity->find('branches',$sale['branch_id']);
        if (!$branch) return null;
        $customer = $sale['customer_id']===null?null:$this->store->purchase->catalog->find('customers',$sale['customer_id']);
        $invoice = $this->store->rows('invoices',['sales_order_id'=>$sale['_id']])->first();
        return $this->store->present($sale)+['branch_name'=>$branch['name'],'branch_address'=>$branch['address'],'customer_name'=>$customer['name']??null,'customer_phone'=>$customer['phone']??null,'customer_address'=>$customer['address']??null,'invoice_id'=>$invoice['_id']??null,'invoice_no'=>$invoice['invoice_no']??null,'issued_at'=>$invoice?$this->store->present($invoice)['issued_at']:null,'subtotal'=>$invoice?(string)$invoice['subtotal']:null,'invoice_discount_amount'=>$invoice?(string)$invoice['discount_amount']:null,'invoice_tax_amount'=>$invoice?(string)$invoice['tax_amount']:null];
    }

    public function show(Request $request, BranchAccess $branches, string $id): JsonResponse
    {
        $id = DecimalId::parse($id,'id');
        $row = $this->store->find('sales_orders',$id);
        abort_unless($row && in_array($row['branch_id'],$branches->branchesFor($request->user())->pluck('id')->all(),true),404);
        $order = $this->joined($row);
        abort_if(!$order,404);
        $items = $this->store->rows('sales_order_items',['order_id'=>$id],['id_sort'=>1])->map(function($i){
            $lot = $this->store->find('product_lots',$i['lot_id']);
            $product = $lot?$this->store->purchase->catalog->find('products',$lot['product_id']):null;
            return $lot && $product ? $this->store->present($i)+['lot_no'=>$lot['lot_no'],'product_code'=>$product['code'],'product_name'=>$product['name']] : null;
        })->filter()->values();
        if ($order['invoice_id']!==null) {
            $paid = $this->store->sum($this->store->rows('payments',['invoice_id'=>$order['invoice_id'],'status'=>'completed']));
            $order['paid_amount']=(string)$paid->toScale(2);
            $order['remaining_amount']=(string)BigDecimal::of($order['total_amount'])->minus($paid)->toScale(2);
        } else { $order['paid_amount']=null;$order['remaining_amount']=null; }
        return response()->json(['data'=>$order,'items'=>$items]);
    }

    public function store(Request $request, SalesAccess $access, SaleService $service): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'string'],
            'warehouse_id' => ['required', 'string'],
            'customer_id' => ['nullable', 'string'],
            'season_label' => ['nullable', 'string', 'max:120'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.lot_id' => ['required', 'string'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,3', DecimalLimit::rule(15, 3)],
            'items.*.discount_amount' => ['nullable', 'numeric', 'min:0', 'decimal:0,2', DecimalLimit::rule(16, 2)],
            'created_by' => ['prohibited'], 'order_no' => ['prohibited'], 'total_amount' => ['prohibited'],
        ]);
        foreach (['branch_id', 'warehouse_id'] as $field) $data[$field] = DecimalId::parse($data[$field], $field);
        if (! empty($data['customer_id'])) $data['customer_id'] = DecimalId::parse($data['customer_id'], 'customer_id');
        foreach ($data['items'] as $index => &$item) $item['lot_id'] = DecimalId::parse($item['lot_id'], "items.{$index}.lot_id");
        unset($item);
        return $this->store->transaction(function() use($data,$request,$service){
            $service->assertDraftScope($data,(string)$request->user()->id);
        foreach ($data['items'] as $index => $item) {
            if (array_diff(array_keys($item), ['lot_id', 'quantity', 'discount_amount']) !== []) {
                throw ValidationException::withMessages(["items.{$index}" => 'Giá, thuế và tổng do hệ thống tính.']);
            }
        }

            return response()->json(['id'=>$service->createDraft($data,(string)$request->user()->id),'status'=>'draft'],201);
        });
    }

    public function confirm(Request $request, BranchAccess $branches, SalesAccess $access, SaleService $service, string $id): JsonResponse
    {
        $id = DecimalId::parse($id,'id');
        return $this->store->transaction(function() use($request,$branches,$access,$service,$id){
            $order = $this->store->find('sales_orders',$id);
            abort_if($order===null,404);
            $branch = $this->store->purchase->identity->find('branches',$order['branch_id']);
            abort_unless($branch && in_array($order['branch_id'],$branches->branchesFor($request->user())->pluck('id')->all(),true) && $access->canSell($service->actor((string)$request->user()->id),$order['branch_id'],$branch['chain_id']),403);
            abort_unless($order['created_by']===(string)$request->user()->id,403);
        $data = $request->validate([
            'amount' => ['required', 'regex:/^\d{1,16}(\.\d{1,2})?$/'],
            'method' => ['nullable', 'in:cash,bank_transfer'],
            'reference_note' => ['nullable', 'string', 'max:200'],
        ]);
        if (\Brick\Math\BigDecimal::of($data['amount'])->isGreaterThan(0) && empty($data['method'])) {
            throw ValidationException::withMessages(['method' => 'Chọn tiền mặt hoặc chuyển khoản thủ công.']);
        }
        if (array_diff(array_keys($request->all()), ['amount', 'method', 'reference_note']) !== []) {
            throw ValidationException::withMessages(['amount' => 'Tổng tiền và dữ liệu chứng từ do hệ thống tính.']);
        }
            $service->confirm($id,(string)$request->user()->id,$data);
            return response()->json(['id'=>$id,'status'=>'confirmed']);
        });
    }
}
