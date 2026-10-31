<?php

namespace App\Http\Controllers;

use App\Commerce\{CommerceStore,CustomerAccounts,Storefront};
use Illuminate\Http\Request;

final class ShopController extends Controller
{
    public function __construct(private readonly CommerceStore $s,private readonly Storefront $front,private readonly CustomerAccounts $accounts) {}
    public function chains() { return response()->json(['data'=>$this->front->chains()]); }
    public function home(Request $r,string $chainId)
    {
        $settings=$this->s->settings($chainId);$now=CommerceStore::now();
        $banners=$this->s->rows('shop_banners',['chain_id'=>$chainId,'is_active'=>true,'starts_at'=>['$lte'=>$now],'ends_at'=>['$gt'=>$now]],['position'=>1,'id_sort'=>1],20);
        $products=$this->front->products($chainId,[],$this->s->customer($r,false));
        $categories=[];foreach($products as $p)if(!isset($categories[$p['category_id']]))$categories[$p['category_id']]=['id'=>$p['category_id'],'name'=>$p['category_name'],'image'=>$p['images'][0]??null];
        $best=$products;usort($best,fn($a,$b)=>\Brick\Math\BigDecimal::of($b['sold_quantity'])->compareTo($a['sold_quantity']));
        $best=array_values(array_filter($best,fn($p)=>\Brick\Math\BigDecimal::of($p['sold_quantity'])->isGreaterThan(0)));
        $offers=array_values(array_filter($products,fn($p)=>\Brick\Math\BigDecimal::of($p['price'])->isLessThan($p['base_price'])));
        return response()->json(['data'=>CommerceStore::present(['name'=>$settings['name'],'description'=>$settings['description']??'','contact'=>$settings['contact']??'','banners'=>$banners,'categories'=>array_values($categories),'branches'=>$this->front->branches($chainId),'products'=>array_slice($products,0,12),'bestselling'=>array_slice($best,0,5),'offers'=>array_slice($offers,0,10)])]);
    }
    public function products(Request $r,string $chainId)
    {
        $d=$r->validate(['search'=>'nullable|string|max:120','category_id'=>'nullable|string|regex:/^[1-9][0-9]{0,18}$/','brand'=>'nullable|string|max:120','origin'=>'nullable|string|max:120','min_price'=>'nullable|string|max:20','max_price'=>'nullable|string|max:20','in_stock'=>'nullable|boolean','sort'=>'nullable|in:newest,bestselling,price_asc,price_desc','page'=>'nullable|integer|min:1','per_page'=>'nullable|integer|min:1|max:48']);
        $rows=$this->front->products($chainId,$d,$this->s->customer($r,false));$page=(int)($d['page']??1);$per=(int)($d['per_page']??12);
        return response()->json(['data'=>CommerceStore::present(array_slice($rows,($page-1)*$per,$per)),'pagination'=>['current_page'=>$page,'per_page'=>$per,'total'=>count($rows),'last_page'=>max(1,(int)ceil(count($rows)/$per))]]);
    }
    public function product(Request $r,string $chainId,string $slug)
    {
        $p=$this->front->product($chainId,$slug,$this->s->customer($r,false));
        $related=array_values(array_filter($this->front->products($chainId,['category_id'=>$p['category_id']],$this->s->customer($r,false)),fn($v)=>$v['id']!==$p['id']));
        $reviews=$this->s->rows('shop_reviews',['chain_id'=>$chainId,'product_id'=>$p['id'],'status'=>'published'],['created_at'=>-1],50);
        $questions=$this->s->rows('shop_questions',['chain_id'=>$chainId,'product_id'=>$p['id'],'status'=>'published'],['created_at'=>-1],50);
        $reviews=array_map(fn($row)=>array_intersect_key($row,array_flip(['_id','rating','body','display_name','verified_purchase','reply','images','created_at','updated_at'])),$reviews);
        $questions=array_map(fn($row)=>array_intersect_key($row,array_flip(['_id','body','display_name','answer','created_at'])),$questions);
        return response()->json(['data'=>CommerceStore::present($p+['related'=>array_slice($related,0,4),'reviews'=>$reviews,'questions'=>$questions])]);
    }
    public function account(Request $r,string $action,?string $id=null)
    {
        $value=match($action){
            'register'=>$this->accounts->register($r),'login'=>$this->accounts->login($r),'logout'=>$this->accounts->logout($r),
            'profile'=>$this->accounts->profile($r),'password'=>$this->accounts->password($r),'forgot'=>$this->accounts->forgot($r),'reset'=>$this->accounts->reset($r),
            'addresses'=>$this->accounts->addresses($r,$id),default=>abort(404),
        };
        return response()->json(['data'=>$value]);
    }
    public function addresses(Request $r,?string $id=null) { return response()->json(['data'=>$this->accounts->addresses($r,$id)]); }
    public function cart(Request $r,string $chainId) { return response()->json(['data'=>$this->accounts->cart($r,$chainId)]); }
    public function wishlist(Request $r,string $chainId) { return response()->json(['data'=>$this->accounts->wishlist($r,$chainId)]); }
    public function page(Request $r,string $chainId,string $slug)
    {
        $this->s->settings($chainId);$page=$this->s->first('shop_pages',['chain_id'=>$chainId,'slug'=>$slug,'published'=>true]);abort_unless($page,404);
        return response()->json(['data'=>CommerceStore::present(array_intersect_key($page,array_flip(['_id','slug','title','body','updated_at'])))]);
    }
    public function assistant(Request $r,string $chainId)
    {
        $d=$r->validate(['search'=>'nullable|string|max:120']);$c=$this->s->customer($r,false);$this->s->settings($chainId);
        $policies=$this->s->rows('shop_pages',['chain_id'=>$chainId,'published'=>true],['id_sort'=>1],20);
        $pages=array_map(fn($p)=>['slug'=>$p['slug'],'title'=>$p['title'],'body'=>$p['body']],$policies);
        $context=$c?['name'=>$c['name'],'cart'=>$this->accounts->cart($r,$chainId),'orders'=>count($this->s->rows('shop_orders',['customer_id'=>$c['_id'],'chain_id'=>$chainId]))]:null;
        return response()->json(['data'=>['provider_status'=>'disabled_by_owner','message'=>'Trợ lý AI chưa được kết nối. Bạn có thể tìm vật tư, xem chính sách hoặc gửi yêu cầu cho nhân viên.','products'=>CommerceStore::present(array_slice($this->front->products($chainId,$d,$c),0,4)),'policies'=>$pages,'customer_context'=>$context]]);
    }
}
