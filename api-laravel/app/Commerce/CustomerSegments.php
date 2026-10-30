<?php

namespace App\Commerce;

use Illuminate\Http\Request;

final class CustomerSegments
{
    public function __construct(private readonly CommerceStore $s) {}
    private function known(string $chain,string $id): bool
    {
        foreach(['shop_orders','shop_dealers','shop_customer_links','shop_tickets','shop_customer_segments'] as $name)if($this->s->first($name,['chain_id'=>$chain,'customer_id'=>$id]))return true;
        return false;
    }
    public function listing(Request $r,string $chain): array
    {
        $this->s->admin($r,$chain,null,true);$ids=[];
        foreach(['shop_orders','shop_dealers','shop_customer_links','shop_tickets','shop_customer_segments'] as $name){$filter=['chain_id'=>$chain];abort_if($this->s->collection($name)->countDocuments($filter)>5000,422,'Cần bộ lọc khách hàng hẹp hơn trước khi xử lý dữ liệu lớn.');foreach($this->s->rows($name,$filter,['id_sort'=>-1],5000) as $row)$ids[$row['customer_id']]=true;}
        abort_if(count($ids)>500,422,'Cần bộ lọc khách hàng hẹp hơn trước khi xử lý dữ liệu lớn.');$out=[];
        foreach(array_keys($ids) as $id){$id=(string)$id;$c=$this->s->find('shop_customers',$id);if(!$c)continue;$segment=$this->s->first('shop_customer_segments',['chain_id'=>$chain,'customer_id'=>$id]);$out[]=['customer_id'=>$id,'name'=>$c['name'],'email'=>$c['email'],'is_vip'=>(bool)($segment['is_vip']??false),'revision'=>$segment['revision']??0,'history'=>array_slice($segment['history']??[],-20)];}
        return CommerceStore::present($out);
    }
    public function update(Request $r,string $chain,string $id): array
    {
        $d=$r->validate(['is_vip'=>'required|boolean','revision'=>'required|integer|min:0','reason'=>'required|string|max:1000']);
        return $this->s->transaction(function()use($r,$chain,$id,$d){$actor=$this->s->admin($r,$chain,null,true);abort_unless($this->s->find('shop_customers',$id)&&$this->known($chain,$id),404,'Khách hàng chưa có quan hệ với chuỗi này.');$old=$this->s->first('shop_customer_segments',['chain_id'=>$chain,'customer_id'=>$id]);abort_unless(($old['revision']??0)===$d['revision'],409,'Phân nhóm vừa thay đổi. Tải lại trước khi lưu.');if($old&&$old['is_vip']===$d['is_vip'])return CommerceStore::present($old);$history=$old['history']??[];abort_unless(count($history)<1000,422,'Lịch sử phân nhóm đã đạt giới hạn.');$history[]=['is_vip'=>$d['is_vip'],'reason'=>$d['reason'],'actor_id'=>(string)$actor->id,'at'=>CommerceStore::now()];$data=['chain_id'=>$chain,'customer_id'=>$id,'is_vip'=>$d['is_vip'],'revision'=>($old['revision']??0)+1,'history'=>$history];$row=$old?$this->s->update('shop_customer_segments',$old,$data):$this->s->insert('shop_customer_segments',$data);$this->s->notify($id,$chain,'segment:'.$row['_id'].':'.$row['revision'],$d['is_vip']?'Bạn đã được xếp nhóm VIP tại cửa hàng.':'Phân nhóm ưu đãi của bạn đã được cập nhật.','/shop/offers');return CommerceStore::present($row);});
    }
}
