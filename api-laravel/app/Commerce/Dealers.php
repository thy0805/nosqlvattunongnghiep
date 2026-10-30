<?php

namespace App\Commerce;

use App\Support\{DebtService,SalesStore,CatalogStore};
use Brick\Math\BigDecimal;
use Illuminate\Http\Request;
use MongoDB\BSON\UTCDateTime;

final class Dealers
{
    public function __construct(private readonly CommerceStore $s,private readonly SalesStore $sales) {}
    public function approved(string $chain,string $customer): array
    {
        return $this->s->first('shop_dealers',['chain_id'=>$chain,'customer_id'=>$customer,'status'=>'approved'])??abort(403,'Cần tư cách đại lý đang được duyệt trong chuỗi.');
    }
    public function profile(Request $r,string $chain): ?array
    {
        $c=$this->s->customer($r);$this->s->settings($chain);$p=$this->s->first('shop_dealers',['chain_id'=>$chain,'customer_id'=>$c['_id']]);if($r->isMethod('get'))return $p?CommerceStore::present($p)+['credit'=>$this->capacity($chain,$c['_id'])]:null;
        $d=$r->validate(['company'=>'required|string|max:180','representative'=>'required|string|max:160','phone'=>'required|string|max:30','email'=>'required|email:rfc|max:190','address'=>'required|string|max:1000','tax_no'=>'nullable|string|max:40','industry'=>'required|string|max:300','documents'=>'present|array|max:5','documents.*'=>'string|max:500']);
        return $this->s->transaction(function()use($r,$chain,$c,$d){$this->s->customer($r);$d['documents']=app(Uploads::class)->references($chain,$c['_id'],'dealer',$d['documents']);$old=$this->s->first('shop_dealers',['chain_id'=>$chain,'customer_id'=>$c['_id']]);if($old){$same=true;foreach($d as $k=>$v)if(($old[$k]??null)!==$v)$same=false;if($same)return CommerceStore::present($old);abort_unless(in_array($old['status'],['needs_information','rejected','pending'],true),409,'Hồ sơ đã duyệt/tạm ngừng cần chủ chuỗi xử lý.');}$h=$old['history']??[];$h[]=['action'=>'submitted','at'=>CommerceStore::now(),'actor_id'=>$c['_id'],'reason'=>null];$data=$d+['status'=>'pending','history'=>$h];$row=$old?$this->s->update('shop_dealers',$old,$data):$this->s->insert('shop_dealers',$data+['chain_id'=>$chain,'customer_id'=>$c['_id'],'credit_limit'=>CommerceStore::decimal('0'),'credit_days'=>0,'credit_starts_on'=>null,'credit_ends_on'=>null,'credit_blocked'=>true]);return CommerceStore::present($row);});
    }
    public function admin(Request $r,string $chain,?string $id=null): array
    {
        $this->s->admin($r,$chain,null,true);if($id===null)return array_map(fn($p)=>CommerceStore::present($p)+['credit'=>$this->capacity($chain,$p['customer_id'])],$this->s->rows('shop_dealers',['chain_id'=>$chain]));
        $d=$r->validate(['action'=>'required|in:approve,request_info,reject,suspend','reason'=>'required|string|max:1000']);return $this->s->transaction(function()use($r,$chain,$id,$d){$this->s->admin($r,$chain,null,true);$p=$this->s->find('shop_dealers',$id);abort_unless($p&&$p['chain_id']===$chain,404);$state=['approve'=>'approved','request_info'=>'needs_information','reject'=>'rejected','suspend'=>'suspended'][$d['action']];if($p['status']===$state)return CommerceStore::present($p);$allowed=$d['action']==='suspend'?['approved']:['pending','needs_information','suspended','rejected'];abort_unless(in_array($p['status'],$allowed,true),409);$h=$p['history'];$h[]=['action'=>$d['action'],'reason'=>$d['reason'],'actor_id'=>(string)$r->user()->id,'at'=>CommerceStore::now()];$p=$this->s->update('shop_dealers',$p,['status'=>$state,'history'=>$h]);$this->s->notify($p['customer_id'],$chain,'dealer:'.$id.':'.$p['_version'],'Hồ sơ đại lý: '.$state,'/shop/business');return CommerceStore::present($p);});
    }
    public function policy(Request $r,string $chain,string $id): array
    {
        $d=$r->validate(['credit_limit'=>'required|string|max:20','credit_days'=>'required|integer|min:1|max:365','credit_starts_on'=>'required|date_format:Y-m-d','credit_ends_on'=>'required|date_format:Y-m-d|after_or_equal:credit_starts_on','credit_blocked'=>'required|boolean','reason'=>'required|string|max:1000']);$d['credit_limit']=CommerceStore::decimal(CommerceStore::money($d['credit_limit']));$d['credit_days']=(int)$d['credit_days'];return $this->s->transaction(function()use($r,$chain,$id,$d){$this->s->admin($r,$chain,null,true);$p=$this->s->find('shop_dealers',$id);abort_unless($p&&$p['chain_id']===$chain&&$p['status']==='approved',404);$h=$p['history'];$h[]=['action'=>'credit_policy','reason'=>$d['reason'],'actor_id'=>(string)$r->user()->id,'at'=>CommerceStore::now(),'credit_limit'=>$d['credit_limit'],'credit_days'=>$d['credit_days'],'starts_on'=>$d['credit_starts_on'],'ends_on'=>$d['credit_ends_on'],'blocked'=>$d['credit_blocked']];unset($d['reason']);$p=$this->s->update('shop_dealers',$p,$d+['history'=>$h]);return CommerceStore::present($p)+['credit'=>$this->capacity($chain,$p['customer_id'])];});
    }
    public function prices(Request $r,string $chain,?string $productId=null): array
    {
        $this->s->admin($r,$chain,null,true);if($r->isMethod('get'))return CommerceStore::present($this->s->rows('shop_price_lists',['chain_id'=>$chain]));
        $d=$r->validate(['is_active'=>'required|boolean','tiers'=>'required|array|min:1|max:20','tiers.*.min_quantity'=>'required|string|max:20','tiers.*.price'=>'required|string|max:20']);$previous=BigDecimal::zero();foreach($d['tiers'] as &$t){$q=CommerceStore::quantity($t['min_quantity']);abort_unless($q->isGreaterThan($previous),422,'Bậc số lượng phải tăng dần.');$previous=$q;$t=['min_quantity'=>(string)$q,'price'=>(string)CommerceStore::money($t['price'])];}unset($t);return $this->s->transaction(function()use($r,$chain,$productId,$d){$this->s->admin($r,$chain,null,true);$p=app(CatalogStore::class)->find('products',$productId);abort_unless($p&&$p['is_active'],404);foreach($d['tiers'] as $t)abort_unless(BigDecimal::of($t['price'])->isLessThanOrEqualTo((string)$p['sale_price']),422,'Giá sỉ không vượt giá gốc.');$old=$this->s->first('shop_price_lists',['chain_id'=>$chain,'product_id'=>$productId]);return CommerceStore::present($old?$this->s->update('shop_price_lists',$old,$d):$this->s->insert('shop_price_lists',$d+['chain_id'=>$chain,'product_id'=>$productId]));});
    }
    public function capacity(string $chain,string $customer,?string $exclude=null): array
    {
        $dealer=$this->s->first('shop_dealers',['chain_id'=>$chain,'customer_id'=>$customer]);$outstanding=$reserved=BigDecimal::zero();$overdue=false;$link=$this->s->first('shop_customer_links',['chain_id'=>$chain,'customer_id'=>$customer]);$debt=app(DebtService::class);
        if($link){foreach($this->sales->rows('debts',['customer_id'=>$link['partner_id'],'debt_type'=>'receivable']) as $row)$outstanding=$outstanding->plus((string)$row['opening_balance']);foreach($this->sales->rows('debt_transactions',['transaction_type'=>'sale_charge']) as $charge){$record=$this->sales->find('debts',$charge['debt_id']);if(($record['customer_id']??null)!==$link['partner_id'])continue;$remaining=BigDecimal::max(BigDecimal::zero(),BigDecimal::of((string)$charge['amount'])->minus($debt->creditFor('invoice_id',$charge['invoice_id']))->minus($debt->paidFor($charge['debt_id'],'invoice_id',$charge['invoice_id'])));$outstanding=$outstanding->plus($remaining);$commit=$this->s->first('shop_credit_commitments',['invoice_id'=>$charge['invoice_id'],'state'=>'posted']);if($commit&&$remaining->isGreaterThan(0)&&$commit['due_at']->toDateTimeImmutable()<new \DateTimeImmutable())$overdue=true;}}
        foreach($this->s->rows('shop_credit_commitments',['chain_id'=>$chain,'customer_id'=>$customer,'state'=>'reserved']) as $commit){$order=$this->s->find('shop_orders',$commit['order_id']);abort_unless($order&&$order['chain_id']===$chain&&$order['customer_id']===$customer,409,'Cam kết công nợ thiếu đơn nguồn hợp lệ.');if($commit['order_id']!==$exclude&&in_array($order['status'],['awaiting_confirmation','confirmed','preparing','packed'],true)&&($order['expires_at']===null||$order['expires_at']->toDateTimeImmutable()>now()->toDateTimeImmutable()))$reserved=$reserved->plus((string)$commit['amount']);}$limit=BigDecimal::of((string)($dealer['credit_limit']??'0'));
        return ['limit'=>(string)$limit->toScale(2),'outstanding'=>(string)$outstanding->toScale(2),'reserved'=>(string)$reserved->toScale(2),'available'=>(string)BigDecimal::max(BigDecimal::zero(),$limit->minus($outstanding)->minus($reserved))->toScale(2),'overdue'=>$overdue];
    }
    public function checkCredit(string $chain,string $customer,string $amount,?string $exclude=null): array
    {
        $p=$this->approved($chain,$customer);$today=now('Asia/Ho_Chi_Minh')->toDateString();abort_unless(!$p['credit_blocked']&&$p['credit_starts_on']!==null&&$p['credit_starts_on']<=$today&&$p['credit_ends_on']>=$today,422,'Chính sách mua chịu chưa có hiệu lực hoặc đang khóa.');$capacity=$this->capacity($chain,$customer,$exclude);abort_unless(!$capacity['overdue'],422,'Có khoản mua chịu quá hạn.');abort_unless(BigDecimal::of($amount)->isLessThanOrEqualTo($capacity['available']),422,'Vượt hạn mức khả dụng sau công nợ và đơn đang cam kết.');return $p;
    }
    public function reserve(array $order): void
    {
        if($order['payment_method']!=='credit')return;$p=$this->checkCredit($order['chain_id'],$order['customer_id'],$order['total']);$this->s->insert('shop_credit_commitments',['chain_id'=>$order['chain_id'],'customer_id'=>$order['customer_id'],'order_id'=>$order['_id'],'amount'=>CommerceStore::decimal($order['total']),'state'=>'reserved','credit_days'=>$p['credit_days'],'invoice_id'=>null,'due_at'=>null]);
    }
    public function orderState(array $order,string $action,?string $invoice=null): void
    {
        $c=$this->s->first('shop_credit_commitments',['order_id'=>$order['_id'],'state'=>'reserved']);if(!$c)return;$set=['state'=>$action==='dispatch'?'posted':'released'];if($action==='dispatch')$set+=['invoice_id'=>$invoice,'due_at'=>new UTCDateTime(now('Asia/Ho_Chi_Minh')->addDays($c['credit_days'])->endOfDay())];$this->s->update('shop_credit_commitments',$c,$set);
    }
}
