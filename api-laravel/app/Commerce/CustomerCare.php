<?php

namespace App\Commerce;

use Illuminate\Http\Request;

final class CustomerCare
{
    public function __construct(private readonly CommerceStore $s,private readonly Storefront $front,private readonly Orders $orders) {}

    private function ticket(Request $r,string $chain,string $id,bool $staff): array
    {
        $t=$this->s->find('shop_tickets',$id);abort_unless($t&&$t['chain_id']===$chain,404);
        if($staff)$this->s->admin($r,$chain,$t['branch_id']);else abort_unless($t['customer_id']===$this->s->customer($r)['_id'],404);
        return $t;
    }

    public function tickets(Request $r,string $chain,bool $staff=false): array
    {
        $filter=['chain_id'=>$chain];
        if($staff){$user=$r->user();abort_unless($user,401);$assign=$this->s->identity->assignments((string)$user->id,true)->filter(fn($a)=>$a['chain_id']===$chain);abort_unless($assign->isNotEmpty(),403);if(!$assign->contains(fn($a)=>$a['role_code']==='chain_owner'))$filter['branch_id']=['$in'=>$assign->pluck('branch_id')->filter()->all()];}
        else $filter['customer_id']=$this->s->customer($r)['_id'];
        return array_map(fn($t)=>$this->publicTicket($t),$this->s->rows('shop_tickets',$filter,['id_sort'=>-1],100));
    }

    public function createTicket(Request $r,string $chain,array $d): array
    {
        return $this->s->transaction(function()use($r,$chain,$d){
            $c=$this->s->customer($r);$this->s->settings($chain);$input=$d;$d['images']=app(Uploads::class)->references($chain,$c['_id'],'ticket',$d['images']??[]);if(!$d['images'])unset($input['images']);else $input['images']=$d['images'];$fp=CommerceStore::fingerprint($input);
            $old=$this->s->first('shop_tickets',['customer_id'=>$c['_id'],'request_key'=>$d['request_key']]);
            if($old){abort_unless($old['chain_id']===$chain&&$old['fingerprint']===$fp,409,'Mã yêu cầu hỗ trợ đã được dùng.');return $this->publicTicket($old)+['replayed'=>true];}
            $branch=$this->s->identity->find('branches',$d['branch_id']);abort_unless($branch&&$branch['chain_id']===$chain&&$branch['is_active'],422,'Chi nhánh hỗ trợ không hợp lệ.');
            if(!empty($d['order_id'])){$order=$this->orders->own($r,$chain,$d['order_id']);abort_unless($order['branch_id']===$branch['_id'],422,'Chọn chi nhánh xử lý đúng đơn nguồn.');}
            $t=$this->s->insert('shop_tickets',['chain_id'=>$chain,'branch_id'=>$branch['_id'],'customer_id'=>$c['_id'],'order_id'=>$d['order_id']??null,'subject'=>$d['subject'],'status'=>'open','request_key'=>$d['request_key'],'fingerprint'=>$fp,'history'=>[]]);
            $this->s->insert('shop_messages',['chain_id'=>$chain,'customer_id'=>$c['_id'],'ticket_id'=>$t['_id'],'sender_type'=>'customer','sender_id'=>$c['_id'],'request_key'=>$d['request_key'],'body'=>$d['body'],'images'=>$d['images'],'fingerprint'=>CommerceStore::fingerprint(['body'=>$d['body']]+($d['images']?['images'=>$d['images']]:[]))]);
            $this->s->notify($c['_id'],$chain,'ticket:'.$t['_id'].':opened','Đã tiếp nhận yêu cầu '.$t['subject'],'/shop/support');
            return $this->publicTicket($t)+['replayed'=>false];
        });
    }

    private function publicTicket(array $t): array {unset($t['fingerprint'],$t['request_key']);return CommerceStore::present($t);}

    public function detail(Request $r,string $chain,string $id,bool $staff=false): array
    {
        $t=$this->ticket($r,$chain,$id,$staff);$messages=$this->s->rows('shop_messages',['ticket_id'=>$id],['id_sort'=>1],500);
        foreach($messages as &$m)unset($m['fingerprint'],$m['request_key'],$m['sender_id']);unset($m);
        return ['ticket'=>$this->publicTicket($t),'messages'=>CommerceStore::present($messages)];
    }

    public function message(Request $r,string $chain,string $id,array $d,bool $staff=false): array
    {
        return $this->s->transaction(function()use($r,$chain,$id,$d,$staff){
            $t=$this->ticket($r,$chain,$id,$staff);$sender=$staff?(string)$r->user()->id:$t['customer_id'];$type=$staff?'staff':'customer';$d['images']=$staff?[]:app(Uploads::class)->references($chain,$t['customer_id'],'ticket',$d['images']??[]);$fp=CommerceStore::fingerprint(['body'=>$d['body']]+($d['images']?['images'=>$d['images']]:[]));
            $old=$this->s->first('shop_messages',['ticket_id'=>$id,'sender_type'=>$type,'sender_id'=>$sender,'request_key'=>$d['request_key']]);
            if($old){abort_unless($old['fingerprint']===$fp,409,'Mã tin nhắn đã được dùng với nội dung khác.');return $this->detail($r,$chain,$id,$staff)+['replayed'=>true];}
            abort_unless($t['status']!=='closed',409,'Yêu cầu đã đóng. Mở lại để trao đổi tiếp.');
            abort_unless($this->s->collection('shop_messages')->countDocuments(['ticket_id'=>$id],$this->s->options())<500,422,'Cuộc trao đổi đã đạt giới hạn. Tạo yêu cầu mới và dẫn mã cũ.');
            $this->s->insert('shop_messages',['chain_id'=>$chain,'customer_id'=>$t['customer_id'],'ticket_id'=>$id,'sender_type'=>$type,'sender_id'=>$sender,'request_key'=>$d['request_key'],'body'=>$d['body'],'images'=>$d['images'],'fingerprint'=>$fp]);
            $this->s->update('shop_tickets',$t,['status'=>$staff?'answered':'open']);
            if($staff)$this->s->notify($t['customer_id'],$chain,'ticket:'.$id.':reply:'.$d['request_key'],'Cửa hàng đã trả lời: '.$t['subject'],'/shop/support');
            return $this->detail($r,$chain,$id,$staff)+['replayed'=>false];
        });
    }

    public function ticketState(Request $r,string $chain,string $id,string $action,bool $staff=false): array
    {
        return $this->s->transaction(function()use($r,$chain,$id,$action,$staff){$t=$this->ticket($r,$chain,$id,$staff);$status=$action==='close'?'closed':'open';if($t['status']!==$status){$h=$t['history'];$h[]=['action'=>$action,'actor_type'=>$staff?'staff':'customer','at'=>CommerceStore::now()];$t=$this->s->update('shop_tickets',$t,['status'=>$status,'history'=>$h]);}return $this->publicTicket($t);});
    }

    public function notifications(Request $r,string $chain,?string $id=null): array
    {
        $c=$this->s->customer($r);
        if($id!==null)$this->s->transaction(function()use($c,$chain,$id){$n=$this->s->find('shop_notifications',$id);abort_unless($n&&$n['customer_id']===$c['_id']&&$n['chain_id']===$chain,404);if($n['read_at']===null)$this->s->update('shop_notifications',$n,['read_at'=>CommerceStore::now()]);});
        return ['items'=>CommerceStore::present($this->s->rows('shop_notifications',['customer_id'=>$c['_id'],'chain_id'=>$chain],['id_sort'=>-1],50)),'unread'=>$this->s->collection('shop_notifications')->countDocuments(['customer_id'=>$c['_id'],'chain_id'=>$chain,'read_at'=>null],$this->s->options())];
    }

    public function reviews(Request $r,string $chain,string $product): array
    {
        $c=$this->s->customer($r);return CommerceStore::present($this->s->rows('shop_reviews',['chain_id'=>$chain,'product_id'=>$product,'customer_id'=>$c['_id']],['id_sort'=>-1],100));
    }
    public function review(Request $r,string $chain,string $product,array $d): array
    {
        return $this->s->transaction(function()use($r,$chain,$product,$d){$c=$this->s->customer($r);$this->front->product($chain,$product,$c);$d['images']=app(Uploads::class)->references($chain,$c['_id'],'review',$d['images']??[]);$order=$this->orders->own($r,$chain,$d['order_id']);abort_unless(in_array($order['status'],['delivered','completed'],true)&&in_array($product,array_column($order['items'],'product_id'),true),422,'Chỉ đánh giá sản phẩm thuộc đơn đã nhận.');$old=$this->s->first('shop_reviews',['chain_id'=>$chain,'customer_id'=>$c['_id'],'product_id'=>$product,'order_id'=>$order['_id']]);if($old){abort_unless($old['rating']===$d['rating']&&$old['body']===$d['body']&&($old['images']??[])===$d['images'],409,'Đánh giá đã gửi; dùng chức năng chỉnh sửa để cập nhật.');return CommerceStore::present($old)+['replayed'=>true];}return CommerceStore::present($this->s->insert('shop_reviews',['chain_id'=>$chain,'customer_id'=>$c['_id'],'product_id'=>$product,'order_id'=>$order['_id'],'rating'=>$d['rating'],'body'=>$d['body'],'images'=>$d['images'],'revision'=>0,'edit_count'=>0,'history'=>[],'display_name'=>'Khách đã mua hàng','verified_purchase'=>true,'status'=>'pending','reply'=>null]));});
    }
    public function editReview(Request $r,string $chain,string $product,string $id,array $d): array
    {
        return $this->s->transaction(function()use($r,$chain,$product,$id,$d){$c=$this->s->customer($r);$old=$this->s->find('shop_reviews',$id);abort_unless($old&&$old['chain_id']===$chain&&$old['product_id']===$product&&$old['customer_id']===$c['_id'],404);$d['images']=app(Uploads::class)->references($chain,$c['_id'],'review',$d['images']??[]);$fp=CommerceStore::fingerprint($d);if(($old['edit_key']??null)===$d['request_key']){abort_unless(($old['edit_fingerprint']??null)===$fp,409);return CommerceStore::present($old)+['replayed'=>true];}abort_unless(($old['revision']??0)===$d['revision'],409,'Đánh giá vừa thay đổi. Tải lại trước khi sửa.');abort_unless($old['created_at']->toDateTimeImmutable()>=now()->subDays(7)->toDateTimeImmutable()&&($old['edit_count']??0)<3,422,'Được sửa tối đa3 lần trong7 ngày từ khi gửi.');$h=$old['history']??[];$h[]=['rating'=>$old['rating'],'body'=>$old['body'],'images'=>$old['images']??[],'at'=>CommerceStore::now()];$row=$this->s->update('shop_reviews',$old,['rating'=>$d['rating'],'body'=>$d['body'],'images'=>$d['images'],'status'=>'pending','reply'=>null,'revision'=>($old['revision']??0)+1,'edit_count'=>($old['edit_count']??0)+1,'history'=>$h,'edit_key'=>$d['request_key'],'edit_fingerprint'=>$fp]);return CommerceStore::present($row);});
    }

    public function question(Request $r,string $chain,string $product,array $d): array
    {
        return $this->s->transaction(function()use($r,$chain,$product,$d){$c=$this->s->customer($r);$this->front->product($chain,$product,$c);$old=$this->s->first('shop_questions',['customer_id'=>$c['_id'],'request_key'=>$d['request_key']]);if($old){abort_unless($old['chain_id']===$chain&&$old['product_id']===$product&&$old['body']===$d['body'],409);return CommerceStore::present($old)+['replayed'=>true];}return CommerceStore::present($this->s->insert('shop_questions',['chain_id'=>$chain,'customer_id'=>$c['_id'],'product_id'=>$product,'body'=>$d['body'],'request_key'=>$d['request_key'],'display_name'=>'Khách hàng','status'=>'pending','answer'=>null]));});
    }

    public function reviewSummary(Request $r,string $chain): array
    {
        $this->s->admin($r,$chain,null,true);$rows=$this->s->rows('shop_reviews',['chain_id'=>$chain],['id_sort'=>1],20001);abort_unless(count($rows)<=20000,422,'Dữ liệu đánh giá vượt giới hạn tổng hợp.');$status=['pending'=>0,'published'=>0,'rejected'=>0];$ratings=array_fill(1,5,0);$sum=0;$products=[];
        foreach($rows as $row){abort_unless(isset($status[$row['status']])&&isset($ratings[$row['rating']]),409,'Đánh giá có trạng thái hoặc số sao không hợp lệ.');$status[$row['status']]++;$ratings[$row['rating']]++;$sum+=$row['rating'];$id=$row['product_id'];$products[$id]??=['product_id'=>$id,'total'=>0,'published'=>0,'rating_sum'=>0];$products[$id]['total']++;$products[$id]['published']+=(int)($row['status']==='published');$products[$id]['rating_sum']+=$row['rating'];}
        $average=fn($sum,$n)=>$n?(string)\Brick\Math\BigDecimal::of($sum)->dividedBy($n,2,\Brick\Math\RoundingMode::HalfUp):'0.00';foreach($products as &$p){$p['average_rating']=$average($p['rating_sum'],$p['total']);unset($p['rating_sum']);}unset($p);return ['total'=>count($rows),'by_status'=>$status,'by_rating'=>$ratings,'average_rating'=>$average($sum,count($rows)),'products'=>array_values($products),'basis'=>'Tất cả đánh giá của chuỗi, gồm đang chờ và bị từ chối; đây là báo cáo nội bộ.'];
    }
    public function moderation(Request $r,string $chain,string $kind,?string $id=null,?array $d=null): array
    {
        $name=$kind==='reviews'?'shop_reviews':'shop_questions';$this->s->admin($r,$chain,null,true);
        if($d===null)return CommerceStore::present($this->s->rows($name,['chain_id'=>$chain],['id_sort'=>-1],200));
        return $this->s->transaction(function()use($r,$chain,$name,$id,$d){$this->s->admin($r,$chain,null,true);$row=$this->s->find($name,$id);abort_unless($row&&$row['chain_id']===$chain,404);$field=$name==='shop_reviews'?'reply':'answer';$row=$this->s->update($name,$row,['status'=>$d['status'],$field=>$d['answer']??null]);$this->s->notify($row['customer_id'],$chain,$name.':'.$id.':'.$d['status'],'Cửa hàng đã xử lý nội dung bạn gửi','/shop/support');return CommerceStore::present($row);});
    }
}
