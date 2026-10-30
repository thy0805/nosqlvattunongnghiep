<?php

namespace App\Commerce;

use App\Support\{DebtService,PurchaseStore,SalesStore};
use Brick\Math\{BigDecimal,RoundingMode};
use Illuminate\Http\Request;

final class Returns
{
    public function __construct(private readonly CommerceStore $s,private readonly Orders $orders,private readonly SalesStore $sales,private readonly PurchaseStore $stock) {}

    private function scoped(Request $r,string $chain,string $id,bool $staff): array
    {
        $row=$this->s->find('shop_returns',$id);abort_unless($row&&$row['chain_id']===$chain,404);
        if($staff)$this->s->admin($r,$chain,$row['branch_id']);else abort_unless($this->s->customer($r)['_id']===$row['customer_id'],404);
        return $row;
    }

    private function manager(Request $r,array $row): void
    {
        $u=$this->s->admin($r,$row['chain_id'],$row['branch_id']);
        abort_unless($this->s->identity->assignments((string)$u->id,true)->contains(fn($a)=>$a['chain_id']===$row['chain_id']&&($a['role_code']==='chain_owner'||($a['role_code']==='branch_manager'&&$a['branch_id']===$row['branch_id']))),403,'Duyệt trả hàng và hoàn tiền cần chủ chuỗi hoặc quản lý chi nhánh.');
    }

    public function listing(Request $r,string $chain,bool $staff=false): array
    {
        $out=[];$filter=['chain_id'=>$chain];if(!$staff)$filter['customer_id']=$this->s->customer($r)['_id'];
        else {abort_unless($r->user(),401);abort_unless($this->s->identity->assignments((string)$r->user()->id,true)->contains(fn($a)=>$a['chain_id']===$chain),403);}
        foreach($this->s->rows('shop_returns',$filter,['id_sort'=>-1],500) as $row){try{$this->scoped($r,$chain,$row['_id'],$staff);$out[]=$this->present($row,$staff);}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){if($e->getStatusCode()!==403)throw $e;}}
        return $out;
    }

    public function present(array $row,bool $staff=false): array
    {
        unset($row['fingerprint'],$row['request_key'],$row['receive_key'],$row['receive_fingerprint']);$row['exchange']=$this->s->first('shop_exchanges',['return_id'=>$row['_id']]);if($row['exchange'])unset($row['exchange']['quote_hash'],$row['exchange']['handover_fingerprint']);$row['refunds']=$this->s->rows('shop_refunds',['return_id'=>$row['_id']]);
        foreach($row['refunds'] as &$refund)unset($refund['fingerprint'],$refund['request_key']);unset($refund);
        if($staff){$order=$this->s->find('shop_orders',$row['order_id']);$row['sold_lots']=[];foreach($this->sales->rows('sales_order_items',['order_id'=>$order['sales_order_id']]) as $i){$lot=$this->stock->find('product_lots',$i['lot_id']);$row['sold_lots'][]=['sale_item_id'=>$i['_id'],'product_id'=>$lot['product_id'],'lot_id'=>$lot['_id'],'lot_no'=>$lot['lot_no'],'quantity'=>$i['quantity'],'expires_on'=>$lot['expires_on']];}}
        return CommerceStore::present($row);
    }

    public function create(Request $r,string $chain,array $d): array
    {
        return $this->s->transaction(function()use($r,$chain,$d){$order=$this->orders->own($r,$chain,$d['order_id']);$d['images']=app(Uploads::class)->references($chain,$order['customer_id'],'return',$d['images']);$fp=CommerceStore::fingerprint($d);$old=$this->s->first('shop_returns',['order_id'=>$order['_id'],'request_key'=>$d['request_key']]);if($old){abort_unless($old['fingerprint']===$fp,409);return $this->present($old)+['replayed'=>true];}
            abort_unless($order['invoice_id']&&in_array($order['status'],['delivered','completed','shipment_returned'],true),409,'Chỉ yêu cầu trả khi đã nhận hoặc kiện hàng đã hoàn về.');
            $lines=array_column($order['items'],null,'product_id');$used=[];foreach($this->s->rows('shop_returns',['order_id'=>$order['_id'],'status'=>['$nin'=>['rejected','cancelled']]]) as $ret)foreach($ret['items'] as $i)$used[$i['product_id']]=($used[$i['product_id']]??BigDecimal::zero())->plus($i['quantity']);
            $items=[];foreach($d['items'] as $i){$q=CommerceStore::quantity($i['quantity']);$line=$lines[$i['product_id']]??null;abort_unless($line&&$q->plus($used[$i['product_id']]??'0')->isLessThanOrEqualTo($line['quantity']),422,'Lượng yêu cầu trả vượt lượng đã giao còn đủ điều kiện.');$items[]=['product_id'=>$i['product_id'],'name'=>$line['name'],'quantity'=>(string)$q];}
            $row=$this->s->insert('shop_returns',['chain_id'=>$chain,'customer_id'=>$order['customer_id'],'branch_id'=>$order['branch_id'],'order_id'=>$order['_id'],'items'=>$items,'reason'=>$d['reason'],'description'=>$d['description']??null,'images'=>$d['images']??[],'resolution'=>$d['resolution'],'status'=>'requested','request_key'=>$d['request_key'],'fingerprint'=>$fp,'history'=>[],'credit_amount'=>CommerceStore::decimal('0'),'received_items'=>[]]);
            $this->s->notify($order['customer_id'],$chain,'return:'.$row['_id'].':requested','Đã tiếp nhận yêu cầu trả hàng #'.$row['_id'],'/shop/orders/'.$order['_id']);return $this->present($row);
        });
    }

    public function decide(Request $r,string $chain,string $id,array $d): array
    {
        return $this->s->transaction(function()use($r,$chain,$id,$d){$row=$this->scoped($r,$chain,$id,true);$this->manager($r,$row);$to=['approve'=>'approved','reject'=>'rejected','request_info'=>'needs_information'][$d['action']];if($row['status']===$to)return $this->present($row,true);abort_unless(in_array($row['status'],['requested','needs_information'],true),409);$h=$row['history'];$h[]=['action'=>$d['action'],'reason'=>$d['reason'],'actor_id'=>(string)$r->user()->id,'at'=>CommerceStore::now()];$row=$this->s->update('shop_returns',$row,['status'=>$to,'history'=>$h]);$this->s->notify($row['customer_id'],$chain,'return:'.$id.':'.$to,'Cập nhật yêu cầu trả hàng #'.$id,'/shop/orders/'.$row['order_id']);return $this->present($row,true);});
    }

    public function receive(Request $r,string $chain,string $id,array $d): array
    {
        return $this->s->transaction(function()use($r,$chain,$id,$d){$row=$this->scoped($r,$chain,$id,true);$fp=CommerceStore::fingerprint($d);if($row['status']==='received'){abort_unless(($row['receive_key']??null)===$d['request_key']&&($row['receive_fingerprint']??null)===$fp,409);return $this->present($row,true)+['replayed'=>true];}abort_unless($row['status']==='approved',409,'Cần duyệt trước khi nhận hàng trả.');
            $order=$this->s->find('shop_orders',$row['order_id']);$sold=$this->sales->rows('sales_order_items',['order_id'=>$order['sales_order_id']])->keyBy('_id');$used=[];$returnedQty=[];foreach($this->s->rows('shop_returns',['order_id'=>$order['_id'],'status'=>'received']) as $ret){foreach($ret['received_items'] as $i)$used[$i['sale_item_id']]=($used[$i['sale_item_id']]??BigDecimal::zero())->plus($i['quantity']);foreach($ret['items'] as $i)$returnedQty[$i['product_id']]=($returnedQty[$i['product_id']]??BigDecimal::zero())->plus($i['quantity']);}
            $sum=[];$received=[];foreach($d['items'] as $i){$line=$sold->get($i['sale_item_id']);abort_unless($line,422,'Dòng lô không thuộc phiếu xuất gốc.');$lot=$this->stock->find('product_lots',$line['lot_id']);$q=CommerceStore::quantity($i['quantity']);abort_unless($q->plus($used[$line['_id']]??'0')->isLessThanOrEqualTo((string)$line['quantity']),422,'Lượng nhận trả vượt lô đã bán.');$sum[$lot['product_id']]=($sum[$lot['product_id']]??BigDecimal::zero())->plus($q);$received[]=$i+['product_id'=>$lot['product_id'],'lot_id'=>$lot['_id']];
                if($i['disposition']==='restock'){abort_unless(($lot['expires_on']===null||$lot['expires_on']>=now('Asia/Ho_Chi_Minh')->toDateString())&&$i['condition']==='intact',422,'Hàng hỏng hoặc hết hạn không được nhập lại tồn bán.');abort_unless($line['unit_cost_snapshot']!==null,409,'Thiếu snapshot giá vốn gốc.');$inv=$this->stock->rows('inventories',['warehouse_id'=>$order['warehouse_id'],'lot_id'=>$lot['_id']])->first();$oldQty=BigDecimal::of((string)($inv['quantity']??'0'));abort_unless($oldQty->isZero()||($inv['average_unit_cost']??null)!==null,409,'Tồn hiện có thiếu giá vốn.');$newQty=$oldQty->plus($q);$cost=$oldQty->multipliedBy((string)($inv['average_unit_cost']??'0'))->plus($q->multipliedBy((string)$line['unit_cost_snapshot']))->dividedBy($newQty,6,RoundingMode::HalfUp);if($inv)$this->stock->update('inventories',$inv['_id'],['quantity'=>(string)$newQty->toScale(3),'average_unit_cost'=>(string)$cost]);else $this->stock->insert('inventories',['warehouse_id'=>$order['warehouse_id'],'lot_id'=>$lot['_id'],'quantity'=>(string)$newQty->toScale(3),'average_unit_cost'=>(string)$cost]);$this->s->insert('shop_return_movements',['chain_id'=>$chain,'customer_id'=>$row['customer_id'],'return_id'=>$id,'order_id'=>$order['_id'],'sales_order_id'=>$order['sales_order_id'],'sale_item_id'=>$line['_id'],'warehouse_id'=>$order['warehouse_id'],'lot_id'=>$lot['_id'],'quantity_delta'=>CommerceStore::decimal($q,3),'movement_type'=>'customer_return_in','occurred_at'=>CommerceStore::now(),'unit_cost_snapshot'=>$line['unit_cost_snapshot']]);}
            }
            $expected=array_column($row['items'],'quantity','product_id');abort_unless(count($sum)===count($expected),422,'Nhận đúng tập sản phẩm đã duyệt.');$credit=BigDecimal::zero();$lines=array_column($order['items'],null,'product_id');foreach($expected as $pid=>$qty){abort_unless(isset($sum[$pid])&&$sum[$pid]->isEqualTo($qty),422,'Lượng nhận trả phải khớp yêu cầu đã duyệt.');$line=$lines[$pid];$prior=$returnedQty[$pid]??BigDecimal::zero();$before=BigDecimal::of($line['total'])->multipliedBy($prior)->dividedBy($line['quantity'],2,RoundingMode::HalfUp);$after=BigDecimal::of($line['total'])->multipliedBy($prior->plus($qty))->dividedBy($line['quantity'],2,RoundingMode::HalfUp);$credit=$credit->plus($after->minus($before));}
            $charge=$this->sales->rows('debt_transactions',['invoice_id'=>$order['invoice_id'],'transaction_type'=>'sale_charge'])->first();abort_unless($charge||$credit->isZero(),409,'Thiếu bút toán gốc.');$entry=null;if($credit->isGreaterThan(0))$entry=$this->stock->insert('debt_transactions',['debt_id'=>$charge['debt_id'],'invoice_id'=>$order['invoice_id'],'branch_id'=>$row['branch_id'],'warehouse_id'=>$order['warehouse_id'],'created_by'=>(string)$r->user()->id,'transaction_type'=>'sale_return_credit','amount'=>(string)$credit]);
            $h=$row['history'];$h[]=['action'=>'received','reason'=>$d['note'],'actor_id'=>(string)$r->user()->id,'at'=>CommerceStore::now()];$row=$this->s->update('shop_returns',$row,['status'=>'received','received_items'=>$received,'credit_amount'=>CommerceStore::decimal($credit),'credit_entry_id'=>$entry,'receive_key'=>$d['request_key'],'receive_fingerprint'=>$fp,'history'=>$h]);if($charge)app(DebtService::class)->refreshStatus($charge['debt_id']);$view=$this->orders->present($order);$this->sales->update('invoices',$order['invoice_id'],['status'=>$view['remaining_amount']==='0.00'?'paid':'issued']);app(Promotions::class)->returned($order);$this->s->notify($row['customer_id'],$chain,'return:'.$id.':received','Đã nhận hàng trả và ghi chứng từ điều chỉnh #'.$id,'/shop/orders/'.$order['_id']);return $this->present($row,true);
        });
    }

    public function refund(Request $r,string $chain,string $id,array $d): array
    {
        return $this->s->transaction(function()use($r,$chain,$id,$d){$row=$this->scoped($r,$chain,$id,true);$this->manager($r,$row);$fp=CommerceStore::fingerprint($d);$old=$this->s->first('shop_refunds',['return_id'=>$id,'request_key'=>$d['request_key']]);if($old){abort_unless($old['fingerprint']===$fp,409);return $this->present($row,true)+['replayed'=>true];}abort_unless($row['status']==='received',409,'Chưa có chứng từ điều chỉnh đủ điều kiện hoàn.');$order=$this->s->find('shop_orders',$row['order_id']);$view=$this->orders->present($order);$used=BigDecimal::zero();foreach($this->s->rows('shop_refunds',['return_id'=>$id]) as $rf)$used=$used->plus((string)$rf['amount']);$exchange=$this->s->first('shop_exchanges',['return_id'=>$id]);abort_if($exchange&&$exchange['status']==='accepted',409,'Đơn thay thế đã giữ giá trị cấn trừ. Hoàn tiền sau khi hoàn tất hoặc xử lý đơn thay thế.');foreach($this->s->rows('shop_exchange_transfers',['return_id'=>$id]) as $transfer)$used=$used->plus((string)$transfer['amount']);$creditLeft=BigDecimal::of((string)$row['credit_amount'])->minus($used);$excess=BigDecimal::of($view['net_paid_amount'])->minus($view['adjusted_total']);$amount=CommerceStore::money($d['amount']);abort_unless($amount->isGreaterThan(0)&&$amount->isLessThanOrEqualTo($creditLeft)&&$amount->isLessThanOrEqualTo($excess),422,'Khoản hoàn vượt credit hoặc phần thực thu được phép trả lại.');
            $charge=$this->sales->rows('debt_transactions',['invoice_id'=>$order['invoice_id'],'transaction_type'=>'sale_charge'])->first();$entry=$this->stock->insert('debt_transactions',['debt_id'=>$charge['debt_id'],'invoice_id'=>$order['invoice_id'],'branch_id'=>$row['branch_id'],'warehouse_id'=>$order['warehouse_id'],'created_by'=>(string)$r->user()->id,'transaction_type'=>'customer_refund','amount'=>(string)$amount]);$this->s->insert('shop_refunds',['chain_id'=>$chain,'customer_id'=>$row['customer_id'],'return_id'=>$id,'order_id'=>$order['_id'],'request_key'=>$d['request_key'],'fingerprint'=>$fp,'amount'=>CommerceStore::decimal($amount),'method'=>$d['method'],'reference'=>$d['reference'],'ledger_id'=>$entry,'created_by'=>(string)$r->user()->id]);if($charge)app(DebtService::class)->refreshStatus($charge['debt_id']);$view=$this->orders->present($order);$this->sales->update('invoices',$order['invoice_id'],['status'=>$view['remaining_amount']==='0.00'?'paid':'issued']);$this->s->notify($row['customer_id'],$chain,'return:'.$id.':refund:'.$entry,'Đã ghi nhận khoản hoàn cho yêu cầu #'.$id,'/shop/orders/'.$order['_id']);return $this->present($row,true);
        });
    }
}
