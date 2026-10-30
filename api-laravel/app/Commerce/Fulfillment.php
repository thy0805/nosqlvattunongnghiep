<?php

namespace App\Commerce;

use App\Support\{DebtService,SalesStore};
use Brick\Math\BigDecimal;
use Illuminate\Http\Request;

final class Fulfillment
{
    public function __construct(private readonly CommerceStore $s,private readonly Orders $orders,private readonly SalesStore $sales) {}

    private function scoped(Request $r,string $chain,string $id): array
    {
        $row=$this->s->find('shop_orders',$id);abort_unless($row&&$row['chain_id']===$chain,404);
        $this->s->admin($r,$chain,$row['branch_id']);return $row;
    }

    public function shipment(Request $r,string $chain,string $id,array $d): array
    {
        return $this->s->transaction(function()use($r,$chain,$id,$d){
            $row=$this->scoped($r,$chain,$id);abort_unless($row['fulfillment']==='delivery'&&in_array($row['status'],['confirmed','preparing','packed','dispatched'],true),409,'Đơn chưa sẵn sàng lập phiếu giao.');
            abort_unless(CommerceStore::money($d['shipping_fee'])->isEqualTo($row['shipping_fee']),409,'Phí giao phải đúng báo giá khách đã xác nhận.');
            $expected=array_column($row['items'],'quantity','product_id');$given=[];
            foreach($d['items'] as $i)$given[$i['product_id']]=(string)CommerceStore::quantity($i['quantity']);
            ksort($expected);ksort($given);abort_unless($expected===$given,422,'Bàn giao phải đủ đúng từng sản phẩm đã soạn.');
            $old=$this->s->first('shop_shipments',['order_id'=>$id]);
            abort_if($old&&$old['status']!=='waiting_handover',409,'Không sửa phiếu đã bàn giao.');
            $data=['carrier'=>$d['carrier'],'tracking_no'=>$d['tracking_no']??null,'note'=>$d['note']??null,'address'=>$row['address'],'shipping_fee'=>CommerceStore::decimal($row['shipping_fee']),'items'=>$d['items']];
            $shipment=$old?$this->s->update('shop_shipments',$old,$data):$this->s->insert('shop_shipments',$data+['chain_id'=>$chain,'customer_id'=>$row['customer_id'],'order_id'=>$id,'status'=>$row['status']==='dispatched'?'handed_over':'waiting_handover','history'=>[]]);
            $this->history($row,$r,'shipping','Đã lưu phiếu giao: '.$d['carrier']);
            return CommerceStore::present($shipment);
        });
    }

    public function transition(Request $r,string $chain,string $id,array $d): array
    {
        return $this->s->transaction(function()use($r,$chain,$id,$d){
            $row=$this->scoped($r,$chain,$id);$shipment=$this->s->first('shop_shipments',['order_id'=>$id]);
            abort_unless($shipment&&$row['invoice_id']&&$row['fulfillment']==='delivery',409,'Cần xuất hàng và có phiếu giao trước.');
            $fp=CommerceStore::fingerprint($d);$history=$shipment['history'];
            foreach($history as $h)if($h['request_key']===$d['request_key']){abort_unless($h['fingerprint']===$fp,409,'Mã cập nhật đã được dùng với nội dung khác.');return $this->orders->present($row);}
            $allowed=match($d['status']){'in_transit'=>['handed_over','failed'],'delivered'=>['handed_over','in_transit'],'failed'=>['handed_over','in_transit'],'returned'=>['failed'],default=>[]};
            abort_unless(in_array($shipment['status'],$allowed,true),409,'Chuyển trạng thái giao nhận không hợp lệ.');
            if(in_array($d['status'],['failed','returned'],true))abort_unless(trim($d['reason']??'')!=='',422,'Cần lý do giao thất bại hoặc hoàn về.');
            $history[]=['status'=>$d['status'],'at'=>CommerceStore::now(),'actor_id'=>(string)$r->user()->id,'reason'=>$d['reason']??null,'request_key'=>$d['request_key'],'fingerprint'=>$fp];
            $this->s->update('shop_shipments',$shipment,['status'=>$d['status'],'history'=>$history]);
            $status=match($d['status']){'delivered'=>'delivered','failed'=>'delivery_failed','returned'=>'shipment_returned',default=>'dispatched'};
            $this->history($row,$r,'shipping',$d['reason']??$d['status'],['status'=>$status,'delivery_status'=>$d['status']]);
            $this->s->notify($row['customer_id'],$chain,'order:'.$id.':shipment:'.$d['request_key'],'Cập nhật giao nhận đơn '.$row['order_no'],'/shop/orders/'.$id);
            return $this->orders->present($this->s->find('shop_orders',$id));
        });
    }

    public function payment(Request $r,string $chain,string $id,array $d): array
    {
        return $this->s->transaction(function()use($r,$chain,$id,$d){
            $row=$this->scoped($r,$chain,$id);abort_unless($row['invoice_id'],409,'Chưa có hóa đơn để ghi nhận thu tiền.');
            $fp=CommerceStore::fingerprint($d);$old=$this->s->first('shop_payment_events',['order_id'=>$id,'request_key'=>$d['request_key']]);
            if($old){abort_unless($old['fingerprint']===$fp,409,'Mã thu tiền đã dùng với nội dung khác.');return $this->orders->present($row)+['replayed'=>true];}
            abort_unless(in_array($row['status'],['dispatched','delivered','completed','delivery_failed'],true),409,'Trạng thái đơn không cho thu tiền.');
            if($row['payment_method']==='credit')abort_unless(in_array($d['kind'],['cash','bank_transfer'],true),422,'Thu nợ đại lý bằng tiền mặt hoặc chuyển khoản thực tế.');
            elseif($row['payment_method']==='bank_transfer')abort_unless($d['kind']==='bank_transfer',422,'Đơn chuyển khoản cần xác nhận khoản chuyển thực tế.');
            elseif($row['fulfillment']==='delivery')abort_unless($d['kind']==='cod_settlement'&&$row['delivery_status']==='delivered',422,'COD chỉ ghi thu sau giao hàng và xác nhận đối soát thực tế.');
            else abort_unless($d['kind']==='cash'&&$row['delivery_status']==='delivered',422,'Ghi thu tiền mặt khi khách đã nhận tại cửa hàng.');
            abort_unless(trim($d['reference'])!=='',422,'Cần ghi nhận chứng cứ thu/đối soát thực tế.');
            $amount=CommerceStore::money($d['amount']);$present=$this->orders->present($row);$remaining=BigDecimal::of($present['remaining_amount']);
            abort_unless($amount->isGreaterThan(0)&&$amount->isLessThanOrEqualTo($remaining),422,'Số thu phải dương và không vượt số còn phải trả.');
            abort_unless($amount->isEqualTo($remaining)||$this->s->settings($chain)['allow_partial_payment'],422,'Cửa hàng không cho thanh toán một phần.');
            $charge=$this->sales->rows('debt_transactions',['invoice_id'=>$row['invoice_id'],'transaction_type'=>'sale_charge'])->first();abort_unless($charge,409,'Thiếu chứng từ công nợ của hóa đơn.');
            $method=$d['kind']==='bank_transfer'?'bank_transfer':'cash';
            $paid=app(DebtService::class)->payCharge($charge['_id'],$r->user(),['amount'=>(string)$amount,'method'=>$method,'request_key'=>'shop-'.hash('sha256',$id.':'.$d['request_key']),'reference_note'=>$d['reference']]);
            $this->s->insert('shop_payment_events',['chain_id'=>$chain,'customer_id'=>$row['customer_id'],'order_id'=>$id,'request_key'=>$d['request_key'],'fingerprint'=>$fp,'payment_id'=>$paid['id'],'kind'=>$d['kind'],'amount'=>CommerceStore::decimal($amount),'reference'=>$d['reference'],'created_by'=>(string)$r->user()->id]);
            $this->history($row,$r,'payment','Đã xác nhận '.(string)$amount.' qua '.$d['kind']);
            $this->s->notify($row['customer_id'],$chain,'order:'.$id.':payment:'.$paid['id'],'Đã ghi nhận thanh toán đơn '.$row['order_no'],'/shop/orders/'.$id);
            return $this->orders->present($this->s->find('shop_orders',$id))+['replayed'=>false];
        });
    }

    public function complete(Request $r,string $chain,string $id): array
    {
        return $this->s->transaction(function()use($r,$chain,$id){
            $row=$this->scoped($r,$chain,$id);$present=$this->orders->present($row);
            if($row['status']==='completed')return $present;
            abort_unless($row['status']==='delivered'&&$row['delivery_status']==='delivered'&&$present['payment_status']==='paid',409,'Chỉ hoàn thành khi đã giao và đã thu/đối soát đủ tiền.');
            $this->history($row,$r,'complete',null,['status'=>'completed']);
            $this->s->notify($row['customer_id'],$chain,'order:'.$id.':completed','Đơn '.$row['order_no'].' đã hoàn thành','/shop/orders/'.$id);
            return $this->orders->present($this->s->find('shop_orders',$id));
        });
    }

    private function history(array $row,Request $r,string $action,?string $reason,array $set=[]): void
    {
        $history=$row['history'];$history[]=['action'=>$action,'actor_type'=>'staff','actor_id'=>(string)$r->user()->id,'at'=>CommerceStore::now(),'reason'=>$reason];
        $this->s->update('shop_orders',$row,$set+['history'=>$history]);
    }
}
