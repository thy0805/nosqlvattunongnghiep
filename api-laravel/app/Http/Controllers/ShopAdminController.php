<?php

namespace App\Http\Controllers;

use App\Commerce\{CommerceStore,Storefront};
use App\Support\CatalogStore;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\UTCDateTime;

final class ShopAdminController extends Controller
{
    public function __construct(private readonly CommerceStore $s,private readonly CatalogStore $catalog) {}
    public function scope(Request $r)
    {
        $u=$r->user();abort_unless($u&&($this->s->identity->find('users',(string)$u->id)['is_active']??false),403);
        $assignments=$this->s->identity->assignments((string)$u->id,true)->values()->all();
        $chains=[];foreach($assignments as $a){$c=$this->s->identity->find('chains',$a['chain_id']);if($c)$chains[$c['_id']]=['id'=>$c['_id'],'name'=>$c['name']];}
        return response()->json(['data'=>['chains'=>array_values($chains),'assignments'=>array_map(fn($a)=>['chain_id'=>$a['chain_id'],'branch_id'=>$a['branch_id'],'role'=>$a['role_code']],$assignments)]]);
    }
    public function settings(Request $r,string $chainId)
    {
        $this->s->admin($r,$chainId,null,true);
        if($r->isMethod('get'))return response()->json(['data'=>CommerceStore::present($this->s->first('shop_settings',['chain_id'=>$chainId]))]);
        $d=$r->validate(['name'=>'required|string|max:160','description'=>'nullable|string|max:2000','contact'=>'nullable|string|max:300','is_open'=>'required|boolean','shipping_fee'=>'required|string|max:20','reservation_minutes'=>'required|integer|min:5|max:1440','allow_partial_payment'=>'required|boolean','allow_customer_cancel_confirmed'=>'required|boolean']);
        $d['shipping_fee']=CommerceStore::decimal(CommerceStore::money($d['shipping_fee']));
        $row=$this->s->transaction(function()use($r,$chainId,$d){$this->s->admin($r,$chainId,null,true);$old=$this->s->first('shop_settings',['chain_id'=>$chainId]);return $old?$this->s->update('shop_settings',$old,$d):$this->s->insert('shop_settings',$d+['chain_id'=>$chainId]);});
        return response()->json(['data'=>CommerceStore::present($row)]);
    }
    public function products(Request $r,string $chainId,?string $productId=null)
    {
        $this->s->admin($r,$chainId,null,true);
        if($r->isMethod('get'))return response()->json(['data'=>CommerceStore::present($this->s->rows('shop_products',['chain_id'=>$chainId]))]);
        abort_unless($this->catalog->find('products',$productId),404);
        $d=$r->validate(['slug'=>'required|string|max:180|regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/','published'=>'required|boolean','brand'=>'nullable|string|max:120','origin'=>'nullable|string|max:120','packaging'=>'nullable|string|max:160','description'=>'nullable|string|max:5000','uses'=>'nullable|string|max:3000','instructions'=>'nullable|string|max:5000','storage'=>'nullable|string|max:3000','safety'=>'nullable|string|max:3000','images'=>'present|array|max:10','images.*'=>'string|max:600']);
        foreach($d['images'] as $url)if(!preg_match('~^/(?!/)[A-Za-z0-9/_.,%\-]+$|^https://[^\s<>]+$~D',$url))throw ValidationException::withMessages(['images'=>'Ảnh phải là đường dẫn nội bộ hoặc HTTPS hợp lệ.']);
        foreach($d['images'] as $url)if(str_starts_with($url,'/api/shop-product-images/')){
            $parts=explode('/', $url);
            abort_unless(count($parts)===5&&$parts[3]===$chainId&&is_file(ShopProductImageController::imagePath($chainId,$parts[4])),422);
        }
        $row=$this->s->transaction(function()use($r,$chainId,$productId,$d){
            $this->s->admin($r,$chainId,null,true);
            $collision=$this->s->first('shop_products',['chain_id'=>$chainId,'slug'=>$d['slug']]);if($collision&&$collision['product_id']!==$productId)throw ValidationException::withMessages(['slug'=>'Đường dẫn sản phẩm đã được sử dụng.']);
            $old=$this->s->first('shop_products',['chain_id'=>$chainId,'product_id'=>$productId]);
            return $old?$this->s->update('shop_products',$old,$d):$this->s->insert('shop_products',$d+['chain_id'=>$chainId,'product_id'=>$productId,'sold_quantity'=>'0.000']);
        });return response()->json(['data'=>CommerceStore::present($row)]);
    }
    public function content(Request $r,string $chainId,string $kind,?string $id=null)
    {
        $this->s->admin($r,$chainId,null,true);$collection=match($kind){'pages'=>'shop_pages','banners'=>'shop_banners',default=>abort(404)};
        if($r->isMethod('get'))return response()->json(['data'=>CommerceStore::present($this->s->rows($collection,['chain_id'=>$chainId]))]);
        $d=$kind==='pages'?$r->validate(['slug'=>'required|string|max:180|regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/','title'=>'required|string|max:200','body'=>'required|string|max:20000','published'=>'required|boolean']):$r->validate(['title'=>'required|string|max:200','image'=>'required|string|max:500','href'=>'required|string|max:500','position'=>'required|integer|min:0|max:100','starts_at'=>'required|date','ends_at'=>'required|date|after:starts_at','is_active'=>'required|boolean']);
        if($kind==='banners'){
            abort_unless(str_starts_with($d['href'],'/shop')&&!str_starts_with($d['href'],'//'),422);
            abort_unless(preg_match('~^/(?!/)[A-Za-z0-9/_.,%\-]+$~D',$d['image']),422);
            foreach(['starts_at','ends_at'] as $k)$d[$k]=new UTCDateTime(new \DateTimeImmutable($d[$k]));
        }
        $row=$this->s->transaction(function()use($r,$chainId,$id,$collection,$d){
            $this->s->admin($r,$chainId,null,true);$row=$id?$this->s->find($collection,$id):null;
            if($id)abort_unless($row&&$row['chain_id']===$chainId,404);
            if($collection==='shop_pages'){$other=$this->s->first($collection,['chain_id'=>$chainId,'slug'=>$d['slug']]);abort_if($other&&($row['_id']??null)!==$other['_id'],409);}
            return $row?$this->s->update($collection,$row,$d):$this->s->insert($collection,$d+['chain_id'=>$chainId]);
        });return response()->json(['data'=>CommerceStore::present($row)]);
    }
}
