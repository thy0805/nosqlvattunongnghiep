<?php

namespace App\Commerce;

use Brick\Math\{BigDecimal,RoundingMode};

final class Promotions
{
    public function __construct(private readonly CommerceStore $s) {}
    private function collection(string $kind): string {return $kind==='campaign'?'shop_campaign_redemptions':'shop_voucher_redemptions';}
    private function target(array $p,array $product): bool {return (!$p['product_ids']||in_array($product['_id'],$p['product_ids'],true))&&(!$p['category_ids']||in_array($product['category_id'],$p['category_ids'],true));}
    private function active(array $p): bool {$now=now()->toDateTimeImmutable();return in_array($p['status'],['active','scheduled'],true)&&$p['starts_at']->toDateTimeImmutable()<=$now&&$p['ends_at']->toDateTimeImmutable()>$now;}
    private function audience(array $p,string $chain,?array $c,string $season): bool
    {
        if($p['customer_ids']&&(!$c||!in_array($c['_id'],$p['customer_ids'],true)))return false;
        return match($p['audience']){
            'all'=>true,'vip'=>$c&&($this->s->first('shop_customer_segments',['chain_id'=>$chain,'customer_id'=>$c['_id']])['is_vip']??false),
            'dealer'=>$c&&$this->s->first('shop_dealers',['chain_id'=>$chain,'customer_id'=>$c['_id'],'status'=>'approved'])!==null,
            'new'=>$c&&!$this->s->first('shop_orders',['chain_id'=>$chain,'customer_id'=>$c['_id'],'status'=>['$nin'=>['cancelled','rejected','expired']]]),
            'birthday'=>$c&&!empty($c['birthday'])&&substr($c['birthday'],5)===now('Asia/Ho_Chi_Minh')->format('m-d'),
            'season'=>$season!==''&&$season===$p['season_key'],default=>false};
    }
    private function usage(string $kind,array $p,?string $customer=null): array
    {
        $filter=['promotion_id'=>$p['_id'],'state'=>['$in'=>['reserved','used','forfeited']]];if($customer!==null)$filter['customer_id']=$customer;
        $rows=$this->s->collection($this->collection($kind))->aggregate([['$match'=>$filter],['$group'=>['_id'=>null,'count'=>['$sum'=>['$cond'=>[['$ifNull'=>['$quota_restored',false]],0,1]]],'amount'=>['$sum'=>['$subtract'=>['$amount',['$ifNull'=>['$budget_released',CommerceStore::decimal('0')]]]]]]]],$this->s->options())->toArray();return ['count'=>$rows?(int)(string)$rows[0]['count']:0,'amount'=>BigDecimal::of($rows?(string)$rows[0]['amount']:'0')];
    }
    private function quota(string $kind,array $p,?array $c,BigDecimal|string $amount='0'): bool
    {
        $all=$this->usage($kind,$p);if($p['max_uses']!==null&&$all['count']>=$p['max_uses'])return false;
        if($c&&$p['per_customer_limit']!==null&&$this->usage($kind,$p,$c['_id'])['count']>=$p['per_customer_limit'])return false;
        return $p['budget']===null||$all['amount']->plus($amount)->isLessThanOrEqualTo($p['budget']);
    }
    private function matches(array $p,string $chain,?array $c,?string $branch,string $season,BigDecimal $subtotal): bool
    {
        return $this->active($p)&&$this->audience($p,$chain,$c,$season)&&(!$p['branch_ids']||($branch!==null&&in_array($branch,$p['branch_ids'],true)))&&$subtotal->isGreaterThanOrEqualTo($p['minimum_order']);
    }
    private function targets(array $p,array $alloc,array $products): array
    {
        $qty=[];foreach($alloc as $a)$qty[$a['product_id']]=($qty[$a['product_id']]??BigDecimal::zero())->plus($a['quantity']);
        foreach($p['combo'] as $item)if(!isset($qty[$item['product_id']])||$qty[$item['product_id']]->isLessThan($item['quantity']))return [];
        $indices=[];foreach($alloc as $i=>$a)if($this->target($p,$products[$a['product_id']])&&$qty[$a['product_id']]->isGreaterThanOrEqualTo($p['minimum_quantity']))$indices[]=$i;return $indices;
    }
    private function capped(array $p,BigDecimal $amount,BigDecimal $base): BigDecimal
    {
        if($p['maximum_discount']!==null)$amount=BigDecimal::min($amount,BigDecimal::of($p['maximum_discount']));return BigDecimal::max(BigDecimal::zero(),BigDecimal::min($amount,$base))->toScale(2,RoundingMode::HalfUp);
    }
    private function split(BigDecimal $amount,array $indices,array $alloc): array
    {
        $base=BigDecimal::zero();foreach($indices as $i)$base=$base->plus($alloc[$i]['net']);$out=[];$remaining=$amount;
        foreach($indices as $i){$net=BigDecimal::of($alloc[$i]['net']);$take=$base->isZero()?BigDecimal::zero():$remaining->multipliedBy($net)->dividedBy($base,2,RoundingMode::Down);$out[$i]=(string)$take->toScale(2);$remaining=$remaining->minus($take);$base=$base->minus($net);}abort_unless($remaining->isZero(),409,'Không phân bổ được ưu đãi chính xác.');return $out;
    }
    private function snapshot(string $kind,array $p,array $amounts,BigDecimal $amount): array
    {
        return ['kind'=>$kind,'promotion_id'=>$p['_id'],'code'=>$p['code'],'name'=>$p['name'],'amount'=>(string)$amount->toScale(2),'allocations'=>$amounts,'allow_vouchers'=>$p['allow_vouchers'],'allow_auto'=>$p['allow_auto'],'allow_other_vouchers'=>$p['allow_other_vouchers'],'restore_on_cancel'=>$p['restore_on_cancel'],'restore_on_partial_return'=>$p['restore_on_partial_return']];
    }
    public function apply(string $chain,?array $c,?string $branch,array $alloc,array $products,string $shipping,array $codes=[],string $season=''): array
    {
        $subtotal=BigDecimal::zero();foreach($alloc as $a)$subtotal=$subtotal->plus($a['net']);$snapshots=[];$best=[];
        $campaigns=$this->s->rows('shop_campaigns',['chain_id'=>$chain],['id_sort'=>1],1000);
        foreach($campaigns as $p){if(!$this->matches($p,$chain,$c,$branch,$season,$subtotal)||!$this->quota('campaign',$p,$c))continue;$indices=$this->targets($p,$alloc,$products);$amounts=[];$total=BigDecimal::zero();foreach($indices as $i){$a=$alloc[$i];$net=BigDecimal::of($a['net']);$off=match($p['kind']){'percent'=>$net->multipliedBy($p['value'])->dividedBy('100',2,RoundingMode::HalfUp),'fixed'=>BigDecimal::of($p['value'])->multipliedBy($a['quantity'])->toScale(2,RoundingMode::HalfUp),'price'=>$net->minus(BigDecimal::of($p['value'])->multipliedBy($a['quantity'])->toScale(2,RoundingMode::HalfUp)),default=>BigDecimal::zero()};$off=$this->capped($p,$off,$net);$amounts[$i]=$off;$total=$total->plus($off);}if($p['maximum_discount']!==null&&$total->isGreaterThan($p['maximum_discount'])){$basis=[];foreach($amounts as $i=>$off)$basis[$i]=['net'=>(string)$off];$total=BigDecimal::of($p['maximum_discount']);$amounts=array_map(fn($v)=>BigDecimal::of($v),$this->split($total,array_keys($amounts),$basis));}if(!$this->quota('campaign',$p,$c,$total))continue;foreach($amounts as $i=>$off)if($off->isGreaterThan($best[$i]['amount']??'0'))$best[$i]=['program'=>$p,'amount'=>$off];}
        $group=[];foreach($best as $i=>$b){$id=$b['program']['_id'];$group[$id]??=['program'=>$b['program'],'amounts'=>[],'total'=>BigDecimal::zero()];$group[$id]['amounts'][$i]=(string)$b['amount'];$group[$id]['total']=$group[$id]['total']->plus($b['amount']);$alloc[$i]['net']=(string)BigDecimal::of($alloc[$i]['net'])->minus($b['amount'])->toScale(2);}
        foreach($group as $g)$snapshots[]=$this->snapshot('campaign',$g['program'],$g['amounts'],$g['total']);
        $normal=array_map(fn($code)=>mb_strtoupper(trim($code)),$codes);abort_unless(count(array_unique($normal))===count($normal),422,'Không nhập lặp voucher.');$prior=[];$shipping=CommerceStore::money($shipping);$shippingDiscount=BigDecimal::zero();
        foreach($normal as $code){$p=$this->s->first('shop_vouchers',['chain_id'=>$chain,'code'=>$code]);abort_unless($p&&$this->matches($p,$chain,$c,$branch,$season,$subtotal),422,'Voucher '.$code.' không đủ điều kiện hoặc đã hết hạn.');if($group){abort_unless($p['allow_auto'],422,'Voucher không kết hợp giảm giá tự động.');foreach($group as $g)abort_unless($g['program']['allow_vouchers'],422,'Chiến dịch tự động không cho cộng voucher.');}if($prior){abort_unless($p['allow_other_vouchers'],422,'Voucher không cho cộng mã khác.');foreach($prior as $old)abort_unless($old['allow_other_vouchers'],422,'Các voucher không cho cộng với nhau.');}$prior[]=$p;
            $indices=$this->targets($p,$alloc,$products);abort_unless($indices,422,'Không có dòng hàng phù hợp voucher '.$code.'.');$base=BigDecimal::zero();foreach($indices as $i)$base=$base->plus($alloc[$i]['net']);$off=match($p['kind']){'percent'=>$base->multipliedBy($p['value'])->dividedBy('100',2,RoundingMode::HalfUp),'fixed'=>BigDecimal::of($p['value']),'freeship'=>$shipping,default=>BigDecimal::zero()};$off=$this->capped($p,$off,$p['kind']==='freeship'?$shipping:$base);abort_unless($off->isGreaterThan(0)&&$this->quota('voucher',$p,$c,$off),422,'Voucher '.$code.' không còn lượt/ngân sách hoặc không phát sinh ưu đãi.');$amounts=$p['kind']==='freeship'?[]:$this->split($off,$indices,$alloc);foreach($amounts as $i=>$amount)$alloc[$i]['net']=(string)BigDecimal::of($alloc[$i]['net'])->minus($amount)->toScale(2);if($p['kind']==='freeship'){$shipping=$shipping->minus($off);$shippingDiscount=$shippingDiscount->plus($off);}$snapshots[]=$this->snapshot('voucher',$p,$amounts,$off);
        }
        foreach($alloc as &$a){$net=BigDecimal::of($a['net']);$gross=BigDecimal::of($a['unit_price'])->multipliedBy($a['quantity'])->toScale(2,RoundingMode::HalfUp);$a['discount_amount']=(string)$gross->minus($net)->toScale(2);$a['tax_amount']=(string)$net->multipliedBy($a['tax_rate'])->dividedBy('100',2,RoundingMode::HalfUp);}unset($a);
        return ['allocations'=>$alloc,'promotions'=>$snapshots,'shipping_fee'=>(string)$shipping->toScale(2),'shipping_discount'=>(string)$shippingDiscount->toScale(2)];
    }
    public function previewPrice(array $product,string $chain,?array $c,string $quantity,BigDecimal $base): BigDecimal
    {
        $net=$base->multipliedBy($quantity)->toScale(2,RoundingMode::HalfUp);
        $result=$this->apply($chain,$c,null,[['product_id'=>$product['_id'],'quantity'=>$quantity,'unit_price'=>(string)$base,'net'=>(string)$net,'tax_rate'=>(string)$product['tax_rate']]],[$product['_id']=>$product],'0.00');
        return BigDecimal::of($result['allocations'][0]['net'])->dividedBy($quantity,2,RoundingMode::HalfUp);
    }

    private function productAmounts(array $p,array $order): array
    {
        $amounts=[];foreach($p['allocations'] as $i=>$off){$pid=$order['allocations'][$i]['product_id'];$amounts[$pid]=(string)BigDecimal::of($amounts[$pid]??'0')->plus($off)->toScale(2);}return $amounts;
    }
    public function reserve(array $order): void
    {
        $c=$this->s->find('shop_customers',$order['customer_id']);foreach($order['promotions']??[] as $p){$kind=$p['kind'];$program=$this->s->find($kind==='campaign'?'shop_campaigns':'shop_vouchers',$p['promotion_id']);abort_unless($program&&$this->quota($kind,$program,$c,$p['amount']),409,'Ưu đãi vừa hết lượt hoặc ngân sách. Kiểm tra lại báo giá.');$this->s->insert($this->collection($kind),['chain_id'=>$order['chain_id'],'customer_id'=>$order['customer_id'],'order_id'=>$order['_id'],'promotion_id'=>$p['promotion_id'],($kind==='campaign'?'campaign_id':'voucher_id')=>$p['promotion_id'],'amount'=>CommerceStore::decimal($p['amount']),'budget_released'=>CommerceStore::decimal('0'),'state'=>'reserved','snapshot'=>$p,'product_amounts'=>$this->productAmounts($p,$order),'reason'=>null]);}
    }
    public function orderState(array $order,string $action): void
    {
        foreach(['campaign','voucher'] as $kind)foreach($this->s->rows($this->collection($kind),['order_id'=>$order['_id'],'state'=>'reserved']) as $row){$state=$action==='dispatch'?'used':(($action==='expired'||$row['snapshot']['restore_on_cancel'])?'released':'forfeited');$this->s->update($this->collection($kind),$row,['state'=>$state,'reason'=>$action]);}
    }
    public function walletState(array $p,string $chain,array $c): string
    {
        if($p['ends_at']->toDateTimeImmutable()<=now()->toDateTimeImmutable()||$p['status']==='ended')return 'expired';
        return $this->active($p)&&$this->audience($p,$chain,$c,'')&&$this->quota('voucher',$p,$c)?'available':'not_eligible';
    }
    public function returned(array $order): void
    {
        $returned=[];foreach($this->s->rows('shop_returns',['order_id'=>$order['_id'],'status'=>'received']) as $ret)foreach($ret['items'] as $i)$returned[$i['product_id']]=($returned[$i['product_id']]??BigDecimal::zero())->plus($i['quantity']);$full=true;foreach($order['items'] as $i)if(($returned[$i['product_id']]??BigDecimal::zero())->isLessThan($i['quantity']))$full=false;
        foreach(['campaign','voucher'] as $kind)foreach($this->s->rows($this->collection($kind),['order_id'=>$order['_id'],'state'=>'used']) as $row){if(!$full&&!$row['snapshot']['restore_on_partial_return'])continue;$released=BigDecimal::zero();foreach($row['product_amounts']??[] as $pid=>$amount){$line=collect($order['items'])->firstWhere('product_id',(string)$pid);abort_unless($line,409,'Snapshot ưu đãi không khớp sản phẩm.');$ratio=BigDecimal::min($returned[$pid]??BigDecimal::zero(),BigDecimal::of($line['quantity']));$released=$released->plus(BigDecimal::of($amount)->multipliedBy($ratio)->dividedBy($line['quantity'],2,RoundingMode::HalfUp));}$this->s->update($this->collection($kind),$row,['state'=>'used','quota_restored'=>true,'reason'=>$full?'full_return':'partial_return','budget_released'=>CommerceStore::decimal(BigDecimal::min($released,BigDecimal::of((string)$row['amount'])))]);}
    }
}
