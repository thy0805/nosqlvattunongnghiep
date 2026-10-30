<?php

namespace App\Commerce;

use Illuminate\Http\Request;

final class Cancellations
{
    public function __construct(private readonly CommerceStore $s,private readonly Orders $orders) {}
    public function request(Request $r,string $chain,string $id,array $d): array
    {
        $order=$this->orders->own($r,$chain,$id);$this->orders->expire($chain,$order['customer_id'],$order['branch_id']);
        return $this->s->transaction(function()use($r,$chain,$id,$d){
            $order=$this->orders->own($r,$chain,$id);$old=$this->s->first('shop_cancellation_requests',['order_id'=>$id,'request_key'=>$d['request_key']]);
            if($old){abort_unless($old['reason']===$d['reason'],409,'Mã yêu cầu đã dùng với lý do khác.');return $this->orders->present($order);}
            abort_unless(in_array($order['status'],['confirmed','preparing','packed'],true)&&$order['invoice_id']===null,409,'Mốc giao hàng hiện tại không cho gửi yêu cầu hủy. Liên hệ hỗ trợ.');
            abort_if($this->s->first('shop_cancellation_requests',['order_id'=>$id,'status'=>'pending']),409,'Đã có yêu cầu hủy đang chờ xét duyệt.');
            $request=$this->s->insert('shop_cancellation_requests',['chain_id'=>$chain,'branch_id'=>$order['branch_id'],'customer_id'=>$order['customer_id'],'order_id'=>$id,'request_key'=>$d['request_key'],'reason'=>$d['reason'],'status'=>'pending','decision_reason'=>null,'decided_by'=>null,'decided_at'=>null]);
            $history=$order['history'];$history[]=['action'=>'cancel_requested','actor_type'=>'customer','actor_id'=>$order['customer_id'],'at'=>CommerceStore::now(),'reason'=>$d['reason']];$order=$this->s->update('shop_orders',$order,['history'=>$history]);
            $this->s->notify($order['customer_id'],$chain,'cancel:'.$request['_id'].':pending','Đã tiếp nhận yêu cầu hủy đơn '.$order['order_no'],'/shop/orders/'.$id);
            return $this->orders->present($order);
        });
    }
    public function decide(Request $r,string $chain,string $id,array $d): array
    {
        return $this->s->transaction(function()use($r,$chain,$id,$d){
            $request=$this->s->find('shop_cancellation_requests',$id);abort_unless($request&&$request['chain_id']===$chain,404);
            $order=$this->s->find('shop_orders',$request['order_id']);abort_unless($order&&$order['chain_id']===$chain,404);$actor=(string)$this->s->admin($r,$chain,$order['branch_id'])->id;
            if($request['status']!=='pending'){abort_unless($request['status']===$d['decision']&&$request['decision_reason']===$d['reason'],409,'Yêu cầu đã có quyết định khác.');return $this->orders->present($order);}
            abort_unless(in_array($order['status'],['confirmed','preparing','packed'],true)&&$order['invoice_id']===null,409,'Đơn đã đổi trạng thái; không thể hủy trước xuất. Chuyển hỗ trợ giao nhận/hoàn hàng.');
            if($d['decision']==='approved'){$this->orders->action($r,$chain,$order['_id'],['action'=>'cancel','reason'=>$d['reason']]);$order=$this->s->find('shop_orders',$order['_id']);}
            $request=$this->s->update('shop_cancellation_requests',$request,['status'=>$d['decision'],'decision_reason'=>$d['reason'],'decided_by'=>$actor,'decided_at'=>CommerceStore::now()]);
            $history=$order['history'];$history[]=['action'=>'cancel_'.$d['decision'],'actor_type'=>'staff','actor_id'=>$actor,'at'=>CommerceStore::now(),'reason'=>$d['reason']];$order=$this->s->update('shop_orders',$order,['history'=>$history]);
            $this->s->notify($order['customer_id'],$chain,'cancel:'.$id.':'.$d['decision'],($d['decision']==='approved'?'Đã đồng ý':'Đã từ chối').' yêu cầu hủy đơn '.$order['order_no'].': '.$d['reason'],'/shop/orders/'.$order['_id']);
            return $this->orders->present($order);
        });
    }
}
