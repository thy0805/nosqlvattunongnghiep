<?php

namespace App\Commerce;

use App\Support\{CatalogStore,PurchaseStore,SalesStore,SaleService};
use Brick\Math\{BigDecimal,RoundingMode};
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\UTCDateTime;

final class Orders
{
    public function __construct(private readonly CommerceStore $s,private readonly Storefront $front,private readonly CatalogStore $catalog,private readonly PurchaseStore $stock,private readonly SalesStore $sales) {}
    public function input(Request $r,bool $create=false): array
    {
        $rules=['wholesale_quote_id'=>'nullable|string|regex:/^[1-9][0-9]{0,18}$/','wholesale_quote_version'=>'required_with:wholesale_quote_id|integer|min:1','recurring_request_id'=>'nullable|string|regex:/^[1-9][0-9]{0,18}$/','branch_id'=>'required|string|regex:/^[1-9][0-9]{0,18}$/','fulfillment'=>'required|in:delivery,pickup','address_id'=>'nullable|string|regex:/^[1-9][0-9]{0,18}$/','payment_method'=>'required|in:cod,bank_transfer,credit','note'=>'nullable|string|max:1000','voucher_codes'=>'sometimes|array|max:5','voucher_codes.*'=>'string|min:3|max:40|regex:/^[A-Za-z0-9_-]+$/','season_key'=>'nullable|string|max:80','items'=>'required|array|min:1|max:100','items.*.product_id'=>'required|string|distinct|regex:/^[1-9][0-9]{0,18}$/','items.*.quantity'=>'required|string|max:20'];
        if($create)$rules+=['quote_hash'=>'required|string|size:64','request_key'=>'required|string|min:16|max:100|regex:/^[A-Za-z0-9_-]+$/'];
        return $r->validate($rules);
    }
    public function quote(Request $r,string $chainId,array $d,?string $excludeOrder=null): array
    {
        $c=$this->s->customer($r);$settings=$this->s->settings($chainId);
        $branch=$this->s->identity->find('branches',$d['branch_id']);
        abort_unless($branch&&$branch['chain_id']===$chainId&&$branch['is_active'],422,'Chi nhánh không hợp lệ.');
        $warehouse=$branch['default_sales_warehouse_id']?$this->s->identity->find('warehouses',$branch['default_sales_warehouse_id']):null;
        abort_unless($warehouse&&$warehouse['branch_id']===$branch['_id'],422,'Chi nhánh chưa có kho bán mặc định.');
        $address=null;
        if($d['fulfillment']==='delivery'){
            $a=isset($d['address_id'])?$this->s->find('shop_addresses',$d['address_id']):null;
            abort_unless($a&&$a['customer_id']===$c['_id']&&$a['is_active'],422,'Chọn địa chỉ nhận hàng thuộc tài khoản của bạn.');
            $address=array_intersect_key($a,array_flip(['recipient','phone','address']));
        }
        $negotiated=app(WholesaleQuotes::class)->prices($chainId,$c,$d);app(RecurringPurchases::class)->check($chainId,$c['_id'],$d);
        $products=[];$lines=[];$allocations=[];$gross=$discount=$tax=BigDecimal::zero();
        foreach($d['items'] as $item){
            $qty=CommerceStore::quantity($item['quantity']);$p=$this->front->product($chainId,$item['product_id'],$c);
            $product=$this->catalog->find('products',$p['id']);$display=$this->s->first('shop_products',['chain_id'=>$chainId,'product_id'=>$p['id'],'published'=>true]);
            $products[$p['id']]=$product;$price=$negotiated!==null?BigDecimal::of($negotiated[$p['id']]):app(Pricing::class)->basePrice($product,$chainId,$c,(string)$qty);$base=BigDecimal::of($p['base_price']);
            abort_unless($price->isLessThanOrEqualTo($base)&&$price->isGreaterThanOrEqualTo(0),422,'Chính sách giá không hợp lệ.');
            $remaining=$qty;$lineGross=$lineDiscount=$lineTax=BigDecimal::zero();
            $lots=$this->stock->rows('product_lots',['product_id'=>$p['id']])->all();
            usort($lots,fn($a,$b)=>strcmp($a['expires_on']??'9999-12-31',$b['expires_on']??'9999-12-31')?:strcmp($a['_id'],$b['_id']));
            foreach($lots as $lot){
                if(($lot['expires_on']??null)!==null&&$lot['expires_on']<now('Asia/Ho_Chi_Minh')->toDateString())continue;
                $inv=$this->stock->rows('inventories',['warehouse_id'=>$warehouse['_id'],'lot_id'=>$lot['_id']])->first();
                if(!$inv||$inv['average_unit_cost']===null)continue;
                $free=BigDecimal::of((string)$inv['quantity'])->minus($this->front->held($warehouse['_id'],$lot['_id'],$excludeOrder));
                if($free->isLessThanOrEqualTo(0))continue;
                $take=$free->isLessThan($remaining)?$free:$remaining;
                $g=$base->multipliedBy($take)->toScale(2,RoundingMode::HalfUp);$net=$price->multipliedBy($take)->toScale(2,RoundingMode::HalfUp);$off=$g->minus($net);$t=$net->multipliedBy($p['tax_rate'])->dividedBy('100',2,RoundingMode::HalfUp);
                $allocations[]=['product_id'=>$p['id'],'lot_id'=>$lot['_id'],'quantity'=>(string)$take->toScale(3),'unit_price'=>$p['base_price'],'discount_amount'=>(string)$off->toScale(2),'tax_rate'=>$p['tax_rate'],'tax_amount'=>(string)$t,'net'=>(string)$net];
                $lineGross=$lineGross->plus($g);$lineDiscount=$lineDiscount->plus($off);$lineTax=$lineTax->plus($t);$remaining=$remaining->minus($take);
                if($remaining->isZero())break;
            }
            if(!$remaining->isZero())throw ValidationException::withMessages(['items'=>'Không đủ tồn bán có giá vốn hợp lệ tại chi nhánh cho '.$p['name'].'.']);
            $lines[]=['product_id'=>$p['id'],'name'=>$p['name'],'code'=>$p['code'],'slug'=>$p['slug'],'image'=>$p['images'][0]??null,'unit'=>$p['unit'],'quantity'=>(string)$qty,'base_price'=>$p['base_price'],'unit_price'=>(string)$price->toScale(2),'gross'=>(string)$lineGross->toScale(2),'discount'=>(string)$lineDiscount->toScale(2),'tax_rate'=>$p['tax_rate'],'tax'=>(string)$lineTax->toScale(2),'total'=>(string)$lineGross->minus($lineDiscount)->plus($lineTax)->toScale(2)];
            $gross=$gross->plus($lineGross);$discount=$discount->plus($lineDiscount);$tax=$tax->plus($lineTax);
        }
        $shipping=$d['fulfillment']==='delivery'?BigDecimal::of((string)$settings['shipping_fee']):BigDecimal::zero();
        $promo=$negotiated!==null?['allocations'=>$allocations,'promotions'=>[],'shipping_fee'=>(string)$shipping->toScale(2),'shipping_discount'=>'0.00']:app(Promotions::class)->apply($chainId,$c,$branch['_id'],$allocations,$products,(string)$shipping->toScale(2),$d['voucher_codes']??[],$d['season_key']??'');$allocations=$promo['allocations'];$shipping=BigDecimal::of($promo['shipping_fee']);$discount=$tax=BigDecimal::zero();foreach($lines as &$line){$lineDiscount=$lineTax=$lineNet=BigDecimal::zero();foreach($allocations as $a)if($a['product_id']===$line['product_id']){$lineDiscount=$lineDiscount->plus($a['discount_amount']);$lineTax=$lineTax->plus($a['tax_amount']);$lineNet=$lineNet->plus($a['net']);}$line['discount']=(string)$lineDiscount->toScale(2);$line['tax']=(string)$lineTax->toScale(2);$line['unit_price']=(string)$lineNet->dividedBy($line['quantity'],2,RoundingMode::HalfUp);$line['total']=(string)$lineNet->plus($lineTax)->toScale(2);$discount=$discount->plus($lineDiscount);$tax=$tax->plus($lineTax);}unset($line);$total=$gross->minus($discount)->plus($tax)->plus($shipping)->toScale(2);
        CommerceStore::decimal($total);
        if($d['payment_method']==='credit')app(Dealers::class)->checkCredit($chainId,$c['_id'],(string)$total);
        $quote=['wholesale_quote_id'=>$d['wholesale_quote_id']??null,'wholesale_quote_version'=>isset($d['wholesale_quote_version'])?(int)$d['wholesale_quote_version']:null,'recurring_request_id'=>$d['recurring_request_id']??null,'chain_id'=>$chainId,'branch_id'=>$branch['_id'],'branch_name'=>$branch['name'],'warehouse_id'=>$warehouse['_id'],'customer_id'=>$c['_id'],'fulfillment'=>$d['fulfillment'],'payment_method'=>$d['payment_method'],'address'=>$address,'items'=>$lines,'promotions'=>$promo['promotions'],'shipping_discount'=>$promo['shipping_discount'],'season_key'=>$d['season_key']??null,'gross'=>(string)$gross->toScale(2),'discount'=>(string)$discount->toScale(2),'tax'=>(string)$tax->toScale(2),'shipping_fee'=>(string)$shipping->toScale(2),'total'=>(string)$total,'allocations'=>$allocations];
        $quote['quote_hash']=CommerceStore::fingerprint($quote);
        return $quote;
    }
    public function publicQuote(array $q): array { unset($q['allocations'],$q['warehouse_id'],$q['customer_id']);return $q; }
    public function create(Request $r,string $chainId,array $d): array
    {
        $c=$this->s->customer($r);$fingerprint=CommerceStore::fingerprint($d+['chain_id'=>$chainId]);
        return $this->s->transaction(function()use($r,$chainId,$d,$c,$fingerprint){
            $this->s->customer($r);$old=$this->s->first('shop_orders',['customer_id'=>$c['_id'],'request_key'=>$d['request_key']]);
            if($old){abort_unless($old['fingerprint']===$fingerprint,409,'Mã yêu cầu đã được dùng với nội dung khác.');return $this->present($old)+['replayed'=>true];}
            $q=$this->quote($r,$chainId,$d);abort_unless(hash_equals($q['quote_hash'],$d['quote_hash']),409,'Giá, tồn hoặc thông tin giao hàng đã đổi. Vui lòng kiểm tra lại tổng mới.');
            $expires=new UTCDateTime(now()->addMinutes($this->s->settings($chainId)['reservation_minutes']));
            $history=[['action'=>'placed','actor_type'=>'customer','actor_id'=>$c['_id'],'at'=>CommerceStore::now(),'reason'=>null]];
            $row=$this->s->insert('shop_orders',$q+['request_key'=>$d['request_key'],'fingerprint'=>$fingerprint,'order_no'=>'NG-'.Str::ulid(),'status'=>'awaiting_confirmation','payment_status'=>'unpaid','delivery_status'=>'not_ready','note'=>$d['note']??null,'expires_at'=>$expires,'history'=>$history,'sales_order_id'=>null,'invoice_id'=>null,'posted_by'=>null]);
            app(Promotions::class)->reserve($row);app(Dealers::class)->reserve($row);app(WholesaleQuotes::class)->claim($row);app(RecurringPurchases::class)->claim($row);foreach($q['allocations'] as $a)$this->s->insert('shop_reservations',['chain_id'=>$chainId,'customer_id'=>$c['_id'],'order_id'=>$row['_id'],'warehouse_id'=>$q['warehouse_id'],'lot_id'=>$a['lot_id'],'product_id'=>$a['product_id'],'quantity'=>CommerceStore::decimal($a['quantity'],3),'status'=>'held','expires_at'=>$expires]);
            $cart=$this->s->first('shop_carts',['customer_id'=>$c['_id'],'chain_id'=>$chainId]);
            if($cart){$purchased=array_column($d['items'],'product_id');$items=array_values(array_filter($cart['items'],fn($x)=>!in_array($x['product_id'],$purchased,true)));$this->s->update('shop_carts',$cart,['items'=>$items]);}
            $this->s->notify($c['_id'],$chainId,'order:'.$row['_id'].':placed','Đã tiếp nhận đơn '.$row['order_no'],'/shop/orders/'.$row['_id']);
            return $this->present($row)+['replayed'=>false];
        });
    }
    public function reorder(Request $r,string $chainId,string $id): array
    {
        $order=$this->own($r,$chainId,$id);$c=$this->s->customer($r);$items=[];
        foreach($order['items'] as $line){try{$p=$this->front->product($chainId,$line['product_id'],$c);$items[]=['product_id'=>$p['id'],'name'=>$p['name'],'quantity'=>$line['quantity'],'previous_unit_price'=>$line['unit_price'],'current_unit_price'=>$p['price'],'available_quantity'=>$p['available_quantity'],'available'=>BigDecimal::of($p['available_quantity'])->isGreaterThanOrEqualTo($line['quantity'])];}catch(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException){$items[]=['product_id'=>$line['product_id'],'name'=>$line['name'],'quantity'=>$line['quantity'],'previous_unit_price'=>$line['unit_price'],'current_unit_price'=>null,'available_quantity'=>'0.000','available'=>false];}}
        return ['order_id'=>$id,'items'=>$items,'message'=>'Giá và tồn hiện tại có thể khác đơn cũ. Thêm vào giỏ không giữ tồn hoặc tạo đơn; checkout sẽ kiểm lại tại chi nhánh đã chọn.'];
    }
    public function own(Request $r,string $chainId,string $id): array
    {
        $c=$this->s->customer($r);$row=$this->s->find('shop_orders',$id);abort_unless($row&&$row['chain_id']===$chainId&&$row['customer_id']===$c['_id'],404);return $row;
    }
    public function present(array $order): array
    {
        $shipment=$this->s->first('shop_shipments',['order_id'=>$order['_id']]);
        $order['cancellation_requests']=$this->s->rows('shop_cancellation_requests',['order_id'=>$order['_id']],['id_sort'=>1]);
        $order['shipment']=$shipment?array_intersect_key($shipment,array_flip(['carrier','tracking_no','note','status','items'])):null;
        $order['payments']=$order['invoice_id']?array_map(fn($p)=>array_intersect_key($p,array_flip(['_id','method','amount','paid_at','reference_note'])),$this->sales->rows('payments',['invoice_id'=>$order['invoice_id'],'status'=>'completed'])->all()):[];
        unset($order['fingerprint'],$order['allocations'],$order['quote_hash'],$order['warehouse_id']);
        if($order['invoice_id']){
            $invoice=$this->sales->find('invoices',$order['invoice_id']);$paid=$this->sales->sum($this->sales->rows('payments',['invoice_id'=>$order['invoice_id'],'status'=>'completed']));
            $debt=app(\App\Support\DebtService::class);$credit=$debt->creditFor('invoice_id',$order['invoice_id']);$refund=$debt->refundedFor('invoice_id',$order['invoice_id']);$incoming=$debt->exchangeFor($order['invoice_id'],true);$outgoing=$debt->exchangeFor($order['invoice_id'],false);$order['exchange_received_amount']=(string)$incoming->toScale(2);$order['exchange_transferred_amount']=(string)$outgoing->toScale(2);$netPaid=$paid->minus($refund)->plus($incoming)->minus($outgoing);$adjusted=BigDecimal::of((string)$invoice['total_amount'])->minus($credit);
            abort_unless($adjusted->isGreaterThanOrEqualTo(0)&&$netPaid->isGreaterThanOrEqualTo(0),409,'Dữ liệu điều chỉnh không hợp lệ.');
            $order['paid_amount']=(string)$paid->toScale(2);$order['credit_amount']=(string)$credit->toScale(2);$order['refunded_amount']=(string)$refund->toScale(2);$order['net_paid_amount']=(string)$netPaid->toScale(2);$order['adjusted_total']=(string)$adjusted->toScale(2);$order['remaining_amount']=(string)BigDecimal::max(BigDecimal::zero(),$adjusted->minus($netPaid))->toScale(2);
            $order['refundable_amount']=(string)BigDecimal::max(BigDecimal::zero(),$netPaid->minus($adjusted))->toScale(2);
            $order['payment_status']=$netPaid->isGreaterThanOrEqualTo($adjusted)?'paid':($netPaid->isGreaterThan(0)?'partial':'unpaid');
        }else{$released=in_array($order['status'],['cancelled','rejected','expired'],true);$order['paid_amount']='0.00';$order['remaining_amount']=$released?'0.00':$order['total'];$order['refundable_amount']='0.00';if($released)$order['payment_status']='not_due';}
        return CommerceStore::present($order);
    }
    public function adminRows(Request $r,string $chainId): array
    {
        $out=[];foreach($this->s->rows('shop_orders',['chain_id'=>$chainId],['id_sort'=>-1],500) as $row){try{$this->s->admin($r,$chainId,$row['branch_id']);$out[]=$this->present($row);}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){if($e->getStatusCode()!==403)throw $e;}}
        return $out;
    }
    public function expire(string $chainId,?string $customerId=null,?string $branchId=null): int
    {
        return $this->s->transaction(function()use($chainId,$customerId,$branchId){
            $count=0;$scope=[];if($customerId!==null)$scope['customer_id']=$customerId;if($branchId!==null)$scope['branch_id']=$branchId;
            foreach($this->s->rows('shop_orders',$scope+['chain_id'=>$chainId,'status'=>['$in'=>['awaiting_confirmation']],'expires_at'=>['$lte'=>CommerceStore::now()]],['id_sort'=>1],100) as $row){
                if($row['invoice_id']!==null)continue;
                foreach($this->s->rows('shop_reservations',['order_id'=>$row['_id'],'status'=>'held']) as $hold)$this->s->update('shop_reservations',$hold,['status'=>'expired']);
                $history=$row['history'];$history[]=['action'=>'expired','actor_type'=>'system','actor_id'=>null,'at'=>CommerceStore::now(),'reason'=>'Hết thời hạn giữ hàng'];
                $this->s->update('shop_orders',$row,['status'=>'expired','history'=>$history]);app(Exchanges::class)->release($row);app(Promotions::class)->orderState($row,'expired');app(Dealers::class)->orderState($row,'expired');
                $this->s->notify($row['customer_id'],$chainId,'order:'.$row['_id'].':expired','Đơn '.$row['order_no'].' đã hết hạn giữ hàng','/shop/orders/'.$row['_id']);$count++;
            }
            return $count;
        });
    }
    public function picking(Request $r,string $chainId,string $id): array
    {
        $row=$this->s->find('shop_orders',$id);abort_unless($row&&$row['chain_id']===$chainId,404);$this->s->admin($r,$chainId,$row['branch_id']);
        $lots=[];
        foreach($this->stock->rows('product_lots',['product_id'=>['$in'=>array_column($row['items'],'product_id')]]) as $lot){
            if(($lot['expires_on']??null)!==null&&$lot['expires_on']<now('Asia/Ho_Chi_Minh')->toDateString())continue;
            $inv=$this->stock->rows('inventories',['warehouse_id'=>$row['warehouse_id'],'lot_id'=>$lot['_id']])->first();if(!$inv||$inv['average_unit_cost']===null)continue;
            $qty=BigDecimal::of((string)$inv['quantity'])->minus($this->front->held($row['warehouse_id'],$lot['_id'],$id));
            if($qty->isGreaterThan(0))$lots[]=['lot_id'=>$lot['_id'],'product_id'=>$lot['product_id'],'lot_no'=>$lot['lot_no'],'expires_on'=>$lot['expires_on'],'available'=>(string)$qty->toScale(3)];
        }
        return ['order'=>$this->present($row),'allocations'=>$row['allocations'],'lots'=>$lots];
    }
    public function reallocate(Request $r,string $chainId,string $id,array $picked): array
    {
        $row=$this->s->find('shop_orders',$id);abort_unless($row&&$row['chain_id']===$chainId,404);$this->s->admin($r,$chainId,$row['branch_id']);$this->expire($chainId,$row['customer_id'],$row['branch_id']);
        return $this->s->transaction(function()use($r,$chainId,$id,$picked){
            $detail=$this->picking($r,$chainId,$id);$row=$this->s->find('shop_orders',$id);$actor=(string)$this->s->admin($r,$chainId,$row['branch_id'])->id;
            abort_unless(in_array($row['status'],['confirmed','preparing'],true),409,'Chỉ đổi lô khi đang chuẩn bị đơn chưa ghi sổ.');
            $lotMap=array_column($detail['lots'],null,'lot_id');$lines=array_column($row['items'],null,'product_id');$sums=[];$alloc=[];$total=BigDecimal::zero();
            foreach($picked as $item){
                $lot=$lotMap[$item['lot_id']]??null;abort_unless($lot,422,'Lô không còn hợp lệ.');$q=CommerceStore::quantity($item['quantity']);abort_unless($q->isLessThanOrEqualTo($lot['available']),422,'Lô không đủ tồn khả dụng.');
                $line=$lines[$lot['product_id']];$prior=$sums[$lot['product_id']]??BigDecimal::zero();$sums[$lot['product_id']]=$prior->plus($q);$oldOff=BigDecimal::of($line['discount'])->multipliedBy($prior)->dividedBy($line['quantity'],2,RoundingMode::HalfUp);$newOff=BigDecimal::of($line['discount'])->multipliedBy($prior->plus($q))->dividedBy($line['quantity'],2,RoundingMode::HalfUp);
                $g=BigDecimal::of($line['base_price'])->multipliedBy($q)->toScale(2,RoundingMode::HalfUp);$net=$g->minus($newOff->minus($oldOff));$tax=$net->multipliedBy($line['tax_rate'])->dividedBy('100',2,RoundingMode::HalfUp);
                $alloc[]=['product_id'=>$lot['product_id'],'lot_id'=>$lot['lot_id'],'quantity'=>(string)$q,'unit_price'=>$line['base_price'],'discount_amount'=>(string)$g->minus($net)->toScale(2),'tax_rate'=>$line['tax_rate'],'tax_amount'=>(string)$tax,'net'=>(string)$net];$total=$total->plus($net)->plus($tax);
            }
            foreach($lines as $pid=>$line)abort_unless(isset($sums[$pid])&&$sums[$pid]->isEqualTo($line['quantity']),422,'Số lượng đã soạn phải đúng từng sản phẩm đã đặt.');
            abort_unless($total->plus($row['shipping_fee'])->isEqualTo($row['total']),409,'Đổi lô làm thay đổi làm tròn tiền/thuế. Giữ phương án lô cũ hoặc liên hệ khách đặt lại.');
            foreach($this->s->rows('shop_reservations',['order_id'=>$id,'status'=>'held']) as $h)$this->s->update('shop_reservations',$h,['status'=>'released']);
            foreach($alloc as $a){$old=$this->s->first('shop_reservations',['order_id'=>$id,'lot_id'=>$a['lot_id']]);$data=['chain_id'=>$chainId,'customer_id'=>$row['customer_id'],'order_id'=>$id,'warehouse_id'=>$row['warehouse_id'],'lot_id'=>$a['lot_id'],'product_id'=>$a['product_id'],'quantity'=>CommerceStore::decimal($a['quantity'],3),'status'=>'held','expires_at'=>$row['expires_at']];if($old)$this->s->update('shop_reservations',$old,$data);else $this->s->insert('shop_reservations',$data);}
            $history=$row['history'];$history[]=['action'=>'pick','actor_type'=>'staff','actor_id'=>$actor,'at'=>CommerceStore::now(),'reason'=>'Xác nhận phương án lô đã soạn'];$this->s->update('shop_orders',$row,['allocations'=>$alloc,'history'=>$history]);return $this->picking($r,$chainId,$id);
        });
    }
    public function action(Request $r,string $chainId,string $id,array $d,bool $customer=false): array
    {
        $row=$customer?$this->own($r,$chainId,$id):$this->s->find('shop_orders',$id);abort_unless($row&&$row['chain_id']===$chainId,404);if(!$customer)$this->s->admin($r,$chainId,$row['branch_id']);$this->expire($chainId,$row['customer_id'],$row['branch_id']);
        return $this->s->transaction(function()use($r,$chainId,$id,$d,$customer){
            $row=$customer?$this->own($r,$chainId,$id):$this->s->find('shop_orders',$id);abort_unless($row&&$row['chain_id']===$chainId,404);
            $actor=$customer?$this->s->customer($r)['_id']:(string)$this->s->admin($r,$chainId,$row['branch_id'])->id;
            $action=$d['action'];$reason=$d['reason']??null;
            if($customer)abort_unless($action==='cancel',403);
            $allowed=match($action){'confirm'=>['awaiting_confirmation'],'prepare'=>['confirmed'],'pack'=>['preparing'],'dispatch'=>['packed'],'pickup'=>['packed'],'cancel'=>['awaiting_confirmation','confirmed','preparing','packed'],'reject'=>['awaiting_confirmation','confirmed'],default=>[]};
            abort_unless(in_array($row['status'],$allowed,true),409,'Trạng thái đơn không cho phép thao tác này.');
            if($customer)abort_unless($row['status']==='awaiting_confirmation',409,'Đơn đã xác nhận: gửi yêu cầu hủy để cửa hàng xét duyệt.');
            if(in_array($action,['cancel','reject'],true))abort_unless(is_string($reason)&&trim($reason)!=='',422,'Cần ghi lý do.');
            if(!in_array($action,['cancel','reject'],true)){
                if($row['status']==='awaiting_confirmation')abort_unless($row['expires_at']->toDateTimeImmutable()>now()->toDateTimeImmutable(),409,'Đã hết thời hạn giữ hàng. Vui lòng đặt lại.');
                foreach($row['allocations'] as $allocation){$lot=app(SaleService::class)->lot($allocation['lot_id']);abort_unless($lot&&$lot['product_active']&&$lot['category_active']&&$lot['unit_active']&&($lot['expires_on']===null||$lot['expires_on']>=now('Asia/Ho_Chi_Minh')->toDateString()),409,'Sản phẩm hoặc lô không còn hợp lệ.');$inv=$this->stock->rows('inventories',['warehouse_id'=>$row['warehouse_id'],'lot_id'=>$allocation['lot_id']])->first();abort_unless($inv&&$inv['average_unit_cost']!==null&&BigDecimal::of((string)$inv['quantity'])->minus($this->front->held($row['warehouse_id'],$allocation['lot_id'],$id))->isGreaterThanOrEqualTo($allocation['quantity']),409,'Tồn giữ không còn đủ.');}
            }
            $next=match($action){'confirm'=>'confirmed','prepare'=>'preparing','pack'=>'packed','dispatch'=>'dispatched','pickup'=>'delivered','cancel'=>'cancelled','reject'=>'rejected'};
            $set=['status'=>$next];
            if($action==='confirm'){foreach($this->s->rows('shop_reservations',['order_id'=>$id,'status'=>'held']) as $hold)$this->s->update('shop_reservations',$hold,['expires_at'=>null]);$set['expires_at']=null;}
            if(in_array($action,['dispatch','pickup'],true)){
                if(isset($row['exchange_return_id'])){$exchange=$this->s->first('shop_exchanges',['return_id'=>$row['exchange_return_id'],'replacement_order_id'=>$id,'status'=>'accepted']);abort_unless($exchange&&($d['exchange_finalize']??null)===$exchange['_id'],409,'Dùng thao tác bàn giao đổi hàng để đối chiếu tiền cấn trừ và khoản thu thêm.');}
                abort_unless(($action==='pickup')===($row['fulfillment']==='pickup'),422,'Thao tác giao nhận không đúng hình thức khách đã chọn.');
                if($row['payment_method']==='credit')app(Dealers::class)->checkCredit($chainId,$row['customer_id'],$row['total'],$id);
                $link=$this->s->first('shop_customer_links',['customer_id'=>$row['customer_id'],'chain_id'=>$chainId]);
                if(!$link){$c=$this->s->find('shop_customers',$row['customer_id']);$partner=$this->catalog->insert('customers',['chain_id'=>$chainId,'name'=>$c['name'],'phone'=>$c['phone'],'address'=>$row['address']['address']??null,'customer_type'=>'farmer']);$link=$this->s->insert('shop_customer_links',['customer_id'=>$row['customer_id'],'chain_id'=>$chainId,'partner_id'=>$partner]);}
                $reservations=$this->s->rows('shop_reservations',['order_id'=>$id,'status'=>'held']);abort_unless(count($reservations)===count($row['allocations']),409,'Reservation không còn nguyên vẹn.');
                foreach($reservations as $hold)$this->s->update('shop_reservations',$hold,['status'=>'consumed']);
                $items=array_map(fn($a)=>array_intersect_key($a,array_flip(['lot_id','quantity','discount_amount'])),$row['allocations']);
                $service=app(SaleService::class);$saleId=$service->createDraft(['branch_id'=>$row['branch_id'],'warehouse_id'=>$row['warehouse_id'],'customer_id'=>$link['partner_id'],'items'=>$items],$actor,$id);
                $service->confirm($saleId,$actor,['amount'=>'0.00','method'=>'cash'],['shipping_amount'=>$row['shipping_fee'],'shop_order_id'=>$id]);
                $invoice=$this->sales->rows('invoices',['sales_order_id'=>$saleId])->first();abort_unless($invoice&&BigDecimal::of((string)$invoice['total_amount'])->isEqualTo($row['total']),409,'Tổng chứng từ khác tổng đã xác nhận.');
                app(Promotions::class)->orderState($row,'dispatch');app(Dealers::class)->orderState($row,'dispatch',$invoice['_id']);$set+=['sales_order_id'=>$saleId,'invoice_id'=>$invoice['_id'],'posted_by'=>$actor,'delivery_status'=>$action==='pickup'?'delivered':'handed_over'];
                if($action==='dispatch'){$shipment=$this->s->first('shop_shipments',['order_id'=>$id]);if($shipment)$this->s->update('shop_shipments',$shipment,['status'=>'handed_over']);}
                foreach($row['items'] as $line){$display=$this->s->first('shop_products',['chain_id'=>$chainId,'product_id'=>$line['product_id']]);$this->s->update('shop_products',$display,['sold_quantity'=>(string)BigDecimal::of($display['sold_quantity']??'0')->plus($line['quantity'])->toScale(3)]);}
            }
            if(in_array($action,['cancel','reject'],true))foreach($this->s->rows('shop_reservations',['order_id'=>$id,'status'=>'held']) as $h)$this->s->update('shop_reservations',$h,['status'=>'released']);
            if(in_array($action,['cancel','reject'],true)){app(Exchanges::class)->release($row);app(Promotions::class)->orderState($row,'cancel');app(Dealers::class)->orderState($row,'cancel');}$history=$row['history'];$history[]=['action'=>$action,'actor_type'=>$customer?'customer':'staff','actor_id'=>$actor,'at'=>CommerceStore::now(),'reason'=>$reason];$set['history']=$history;
            $row=$this->s->update('shop_orders',$row,$set);$label=match($next){'confirmed'=>'Cửa hàng đã xác nhận đơn','preparing'=>'Cửa hàng đang chuẩn bị hàng cho đơn','packed'=>'Đơn đã được đóng gói','dispatched'=>'Đơn đã bàn giao vận chuyển','delivered'=>'Đơn đã giao tại cửa hàng','cancelled'=>'Đơn đã hủy','rejected'=>'Cửa hàng đã từ chối đơn'};$this->s->notify($row['customer_id'],$chainId,'order:'.$id.':'.$next,$label.' '.$row['order_no'],'/shop/orders/'.$id);
            return $this->present($row);
        });
    }
}
