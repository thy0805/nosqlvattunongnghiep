<?php

namespace App\Commerce;

use App\Support\{DebtService,SalesStore};
use Brick\Math\BigDecimal;
use Illuminate\Http\Request;

final class Exchanges
{
    public function __construct(private readonly CommerceStore $s,private readonly Orders $orders,private readonly SalesStore $sales) {}
    private function manager(Request $r,array $row): string
    {
        $u=$this->s->admin($r,$row['chain_id'],$row['branch_id']);
        abort_unless($this->s->identity->assignments((string)$u->id,true)->contains(fn($a)=>$a['chain_id']===$row['chain_id']&&($a['role_code']==='chain_owner'||($a['role_code']==='branch_manager'&&$a['branch_id']===$row['branch_id']))),403);
        return (string)$u->id;
    }
    private function source(string $chain,string $id): array
    {
        $ret=$this->s->find('shop_returns',$id);abort_unless($ret&&$ret['chain_id']===$chain,404);
        abort_unless($ret['status']==='received'&&$ret['resolution']==='exchange',409,'Chỉ đổi thay thế sau khi nhận hàng của yêu cầu đổi đã duyệt.');return $ret;
    }
    private function customerRequest(array $ret): Request
    {
        $c=$this->s->find('shop_customers',$ret['customer_id']);abort_unless($c&&$c['is_active'],409,'Tài khoản khách không còn hoạt động.');
        $request=Request::create('/internal/exchange','POST');$session=new \Illuminate\Session\Store('exchange',new \Illuminate\Session\ArraySessionHandler(120));$session->put('shop_customer_id',$c['_id']);$session->put('shop_auth_version',$c['auth_version']);$request->setLaravelSession($session);return $request;
    }
    public function release(array $order): void
    {
        if(!isset($order['exchange_return_id']))return;
        $row=$this->s->first('shop_exchanges',['return_id'=>$order['exchange_return_id'],'replacement_order_id'=>$order['_id'],'status'=>'accepted']);
        if($row)$this->s->update('shop_exchanges',$row,['status'=>'cancelled','cancelled_at'=>CommerceStore::now()]);
    }
    public function available(array $ret): BigDecimal
    {
        $order=$this->s->find('shop_orders',$ret['order_id']);$view=$this->orders->present($order);$used=BigDecimal::zero();
        foreach($this->s->rows('shop_refunds',['return_id'=>$ret['_id']]) as $row)$used=$used->plus((string)$row['amount']);
        foreach($this->s->rows('shop_exchange_transfers',['return_id'=>$ret['_id']]) as $row)$used=$used->plus((string)$row['amount']);
        return BigDecimal::max(BigDecimal::zero(),BigDecimal::min(BigDecimal::of((string)$ret['credit_amount'])->minus($used),BigDecimal::of($view['net_paid_amount'])->minus($view['adjusted_total'])))->toScale(2);
    }
    public function offer(Request $r,string $chain,string $id,array $d): array
    {
        return $this->s->transaction(function()use($r,$chain,$id,$d){
            $ret=$this->source($chain,$id);$actor=$this->manager($r,$ret);$old=$this->s->first('shop_exchanges',['return_id'=>$id]);
            abort_if($old&&in_array($old['status'],['accepted','completed'],true),409,'Đã có đơn thay thế, không tạo lại.');
            $quantity=BigDecimal::zero();foreach($d['items'] as $i)$quantity=$quantity->plus(CommerceStore::quantity($i['quantity']));$eligible=BigDecimal::zero();foreach($ret['items'] as $i)$eligible=$eligible->plus($i['quantity']);abort_unless($quantity->isLessThanOrEqualTo($eligible),422,'Lượng hàng thay thế vượt lượng trả đủ điều kiện.');
            $input=['branch_id'=>$ret['branch_id'],'fulfillment'=>'pickup','payment_method'=>$d['payment_method'],'items'=>$d['items']];$quote=$this->orders->quote($this->customerRequest($ret),$chain,$input);$transfer=BigDecimal::min($this->available($ret),BigDecimal::of($quote['total']))->toScale(2);
            $data=['chain_id'=>$chain,'branch_id'=>$ret['branch_id'],'customer_id'=>$ret['customer_id'],'return_id'=>$id,'source_order_id'=>$ret['order_id'],'status'=>'offered','version'=>($old['version']??0)+1,'input'=>$input,'quote'=>$this->orders->publicQuote($quote),'quote_hash'=>$quote['quote_hash'],'transfer_amount'=>CommerceStore::decimal($transfer),'additional_amount'=>CommerceStore::decimal(BigDecimal::of($quote['total'])->minus($transfer)),'note'=>$d['note'],'offered_by'=>$actor,'replacement_order_id'=>null,'accepted_at'=>null];
            $row=$old?$this->s->update('shop_exchanges',$old,$data):$this->s->insert('shop_exchanges',$data);$this->s->notify($ret['customer_id'],$chain,'exchange:'.$row['_id'].':offer:'.$row['version'],'Có báo giá đổi hàng #'.$id.' cần bạn xác nhận','/shop/orders/'.$ret['order_id']);return CommerceStore::present($row);
        });
    }
    public function accept(Request $r,string $chain,string $id,array $d): array
    {
        return $this->s->transaction(function()use($r,$chain,$id,$d){
            $ret=$this->source($chain,$id);abort_unless($this->s->customer($r)['_id']===$ret['customer_id'],404);$row=$this->s->first('shop_exchanges',['return_id'=>$id]);abort_unless($row&&$row['version']===$d['version'],409,'Báo giá đã thay đổi.');
            if($row['status']==='accepted'||$row['status']==='completed')return CommerceStore::present($row);
            abort_unless($row['status']==='offered',409);abort_unless($this->available($ret)->isGreaterThanOrEqualTo((string)$row['transfer_amount']),409,'Giá trị cấn trừ đã thay đổi. Cần báo giá lại.');
            $order=$this->orders->create($r,$chain,$row['input']+['quote_hash'=>$row['quote_hash'],'request_key'=>'exchange-'.$row['_id'].'-v'.$row['version']]);$source=$this->s->find('shop_orders',$order['id']);$this->s->update('shop_orders',$source,['exchange_return_id'=>$id]);$row=$this->s->update('shop_exchanges',$row,['status'=>'accepted','replacement_order_id'=>$order['id'],'accepted_at'=>CommerceStore::now()]);
            return CommerceStore::present($row);
        });
    }
    public function handover(Request $r,string $chain,string $id,array $d): array
    {
        return $this->s->transaction(function()use($r,$chain,$id,$d){
            $ret=$this->source($chain,$id);$actor=$this->manager($r,$ret);$row=$this->s->first('shop_exchanges',['return_id'=>$id]);abort_unless($row,404);$fingerprint=CommerceStore::fingerprint($d);
            if($row['status']==='completed'){abort_unless($row['handover_fingerprint']===$fingerprint,409,'Đã bàn giao với chứng từ khác.');return CommerceStore::present($row);}
            abort_unless($row['status']==='accepted',409,'Khách cần chấp nhận báo giá trước.');$replacement=$this->s->find('shop_orders',$row['replacement_order_id']);abort_unless($replacement&&$replacement['status']==='packed',409,'Đơn thay thế cần xác nhận và đóng gói trước.');
            $amount=BigDecimal::of((string)$row['transfer_amount']);abort_unless($this->available($ret)->isGreaterThanOrEqualTo($amount),409,'Tiền cấn trừ không còn đủ.');$extra=BigDecimal::of($replacement['total'])->minus($amount);
            if($replacement['payment_method']!=='credit')abort_unless(CommerceStore::money($d['amount'])->isEqualTo($extra),422,'Phải ghi đúng khoản thu thêm thực tế trước bàn giao.');else abort_unless(CommerceStore::money($d['amount'])->isZero(),422,'Đơn đại lý ghi nợ theo hạn mức, không ghi thu trong bước này.');
            $this->orders->action($r,$chain,$replacement['_id'],['action'=>'pickup','exchange_finalize'=>$row['_id']]);$replacement=$this->s->find('shop_orders',$replacement['_id']);$original=$this->s->find('shop_orders',$ret['order_id']);
            if($amount->isGreaterThan(0)){
                $source=$this->sales->rows('debt_transactions',['invoice_id'=>$original['invoice_id'],'transaction_type'=>'sale_charge'])->first();$target=$this->sales->rows('debt_transactions',['invoice_id'=>$replacement['invoice_id'],'transaction_type'=>'sale_charge'])->first();abort_unless($source&&$target,409,'Thiếu bút toán nguồn/thay thế.');
                $this->s->insert('shop_exchange_transfers',['chain_id'=>$chain,'branch_id'=>$ret['branch_id'],'customer_id'=>$ret['customer_id'],'exchange_id'=>$row['_id'],'return_id'=>$id,'source_invoice_id'=>$original['invoice_id'],'target_invoice_id'=>$replacement['invoice_id'],'source_debt_id'=>$source['debt_id'],'target_debt_id'=>$target['debt_id'],'amount'=>CommerceStore::decimal($amount),'created_by'=>$actor]);
                app(DebtService::class)->refreshStatus($source['debt_id']);app(DebtService::class)->refreshStatus($target['debt_id']);
            }
            if($extra->isGreaterThan(0)&&$replacement['payment_method']!=='credit')app(Fulfillment::class)->payment($r,$chain,$replacement['_id'],['kind'=>'bank_transfer','amount'=>(string)$extra->toScale(2),'reference'=>$d['reference'],'request_key'=>'exchange-payment-'.$row['_id']]);
            foreach([$original,$replacement] as $order){$view=$this->orders->present($order);$this->sales->update('invoices',$order['invoice_id'],['status'=>$view['remaining_amount']==='0.00'?'paid':'issued']);}
            $row=$this->s->update('shop_exchanges',$row,['status'=>'completed','handover_fingerprint'=>$fingerprint,'handed_over_at'=>CommerceStore::now(),'handed_over_by'=>$actor,'reference'=>$d['reference']]);$this->s->notify($ret['customer_id'],$chain,'exchange:'.$row['_id'].':completed','Đã bàn giao sản phẩm thay thế cho yêu cầu #'.$id,'/shop/orders/'.$ret['order_id']);return CommerceStore::present($row);
        });
    }
}
