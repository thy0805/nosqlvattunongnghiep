<?php

namespace App\Commerce;

use Illuminate\Http\Request;

final class RecurringPurchases
{
    public function __construct(private readonly CommerceStore $s,private readonly Dealers $dealers) {}
    public function list(Request $r,string $chain): array
    {
        $c=$this->s->customer($r);$this->s->settings($chain);return ['schedules'=>CommerceStore::present($this->s->rows('shop_recurring',['chain_id'=>$chain,'customer_id'=>$c['_id']])),'requests'=>CommerceStore::present($this->s->rows('shop_recurring_requests',['chain_id'=>$chain,'customer_id'=>$c['_id']]))];
    }
    public function save(Request $r,string $chain,?string $id=null): array
    {
        $d=$r->validate(['name'=>'required|string|max:160','branch_id'=>'required|string|regex:/^[1-9][0-9]{0,18}$/','cycle'=>'required|in:weekly,monthly','next_on'=>'required|date_format:Y-m-d','is_active'=>'required|boolean','items'=>'required|array|min:1|max:50','items.*.product_id'=>'required|string|distinct|regex:/^[1-9][0-9]{0,18}$/','items.*.quantity'=>'required|string|max:20']);foreach($d['items'] as &$i)$i['quantity']=(string)CommerceStore::quantity($i['quantity']);unset($i);return $this->s->transaction(function()use($r,$chain,$id,$d){$c=$this->s->customer($r);$this->dealers->approved($chain,$c['_id']);$b=$this->s->identity->find('branches',$d['branch_id']);abort_unless($b&&$b['chain_id']===$chain&&$b['is_active'],422);foreach($d['items'] as $i)app(Storefront::class)->product($chain,$i['product_id'],$c);$old=$id?$this->s->find('shop_recurring',$id):null;if($id)abort_unless($old&&$old['customer_id']===$c['_id']&&$old['chain_id']===$chain,404);return CommerceStore::present($old?$this->s->update('shop_recurring',$old,$d):$this->s->insert('shop_recurring',$d+['chain_id'=>$chain,'customer_id'=>$c['_id']]));});
    }
    public function generate(Request $r,string $chain,string $id): array
    {
        $d=$r->validate(['due_on'=>'required|date_format:Y-m-d']);return $this->s->transaction(function()use($r,$chain,$id,$d){$c=$this->s->customer($r);$this->dealers->approved($chain,$c['_id']);$schedule=$this->s->find('shop_recurring',$id);abort_unless($schedule&&$schedule['chain_id']===$chain&&$schedule['customer_id']===$c['_id'],404);abort_unless($schedule['is_active'],422,'Lịch đã tạm ngừng.');$today=now('Asia/Ho_Chi_Minh')->toDateString();$on=$d['due_on'];$old=$this->s->first('shop_recurring_requests',['schedule_id'=>$id,'due_on'=>$on]);if($old)return CommerceStore::present($old)+['replayed'=>true];abort_unless($on===$schedule['next_on']&&$on<=$today,422,'Chưa đến kỳ hoặc ngày yêu cầu không đúng kỳ tiếp theo.');$data=$this->s->insert('shop_recurring_requests',['chain_id'=>$chain,'customer_id'=>$c['_id'],'schedule_id'=>$id,'due_on'=>$on,'branch_id'=>$schedule['branch_id'],'items'=>$schedule['items'],'status'=>'needs_confirmation','order_id'=>null]);$date=new \DateTimeImmutable($on);if($schedule['cycle']==='weekly')$next=$date->modify('+7 days');else{$month=$date->modify('first day of next month');$next=$month->setDate((int)$month->format('Y'),(int)$month->format('m'),min((int)$date->format('d'),(int)$month->format('t')));}$this->s->update('shop_recurring',$schedule,['next_on'=>$next->format('Y-m-d')]);$this->s->notify($c['_id'],$chain,'recurring:'.$id.':'.$on,'Đến kỳ mua: '.$schedule['name'],'/shop/business');return CommerceStore::present($data);});
    }
    public function check(string $chain,string $customer,array $d): ?array
    {
        if(empty($d['recurring_request_id']))return null;$req=$this->s->find('shop_recurring_requests',$d['recurring_request_id']);abort_unless($req&&$req['chain_id']===$chain&&$req['customer_id']===$customer,404);$this->dealers->approved($chain,$customer);abort_unless($req['status']==='needs_confirmation'&&$req['order_id']===null,409,'Yêu cầu kỳ này đã được chuyển thành đơn.');$given=[];foreach($d['items'] as $i)$given[$i['product_id']]=(string)CommerceStore::quantity($i['quantity']);$expected=array_column($req['items'],'quantity','product_id');ksort($given);ksort($expected);abort_unless($given===$expected&&$d['branch_id']===$req['branch_id']&&empty($d['wholesale_quote_id']),422,'Giữ đúng sản phẩm/chi nhánh của yêu cầu kỳ mua.');return $req;
    }
    public function claim(array $order): void
    {
        if(empty($order['recurring_request_id']))return;$req=$this->s->find('shop_recurring_requests',$order['recurring_request_id']);abort_unless($req&&$req['order_id']===null,409);$this->s->update('shop_recurring_requests',$req,['order_id'=>$order['_id'],'status'=>'converted']);
    }
}
