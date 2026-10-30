<?php

namespace App\Commerce;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Brick\Math\BigDecimal;

final class CustomerAccounts
{
    public function __construct(private readonly CommerceStore $s,private readonly Storefront $front) {}
    public function register(Request $r): array
    {
        $d=$r->validate(['email'=>'required|string|email|max:190','password'=>'required|string|min:10|max:128|confirmed','name'=>'required|string|max:160','phone'=>'nullable|string|max:30']);
        $email=mb_strtolower(trim($d['email']));
        $hash=Hash::make($d['password']);
        $row=$this->s->transaction(function()use($d,$email,$hash){
            if($this->s->first('shop_customers',['email'=>$email])) throw ValidationException::withMessages(['email'=>'Email đã được sử dụng.']);
            return $this->s->insert('shop_customers',['email'=>$email,'name'=>$d['name'],'phone'=>$d['phone']??null,'password_hash'=>$hash,'is_active'=>true,'auth_version'=>1,'birthday'=>null,'is_vip'=>false]);
        });
        $this->signin($r,$row);$this->mergeGuest($r,$row);return CommerceStore::present($row);
    }
    private function signin(Request $r,array $row): void
    {
        $r->session()->regenerate();$r->session()->put('shop_customer_id',$row['_id']);$r->session()->put('shop_auth_version',$row['auth_version']);
    }
    public function login(Request $r): array
    {
        $d=$r->validate(['email'=>'required|string|max:190','password'=>'required|string|max:128']);
        $row=$this->s->first('shop_customers',['email'=>mb_strtolower(trim($d['email']))]);
        if (!$row||!$row['is_active']||!Hash::check($d['password'],$row['password_hash'])) throw ValidationException::withMessages(['email'=>'Thông tin đăng nhập không đúng.']);
        $this->signin($r,$row);$this->mergeGuest($r,$row);return CommerceStore::present($row);
    }
    public function logout(Request $r): array
    {
        $r->session()->forget(['shop_customer_id','shop_auth_version']);$r->session()->regenerate();return ['message'=>'Đã đăng xuất tài khoản khách.'];
    }
    public function profile(Request $r): array
    {
        $customer=$this->s->customer($r);
        if ($r->isMethod('patch')) {
            $d=$r->validate(['name'=>'sometimes|required|string|max:160','phone'=>'sometimes|nullable|string|max:30','birthday'=>'sometimes|nullable|date_format:Y-m-d|before_or_equal:today']);
            $customer=$this->s->transaction(function()use($customer,$d){return $this->s->update('shop_customers',$this->s->find('shop_customers',$customer['_id']),$d);});
        }
        return CommerceStore::present($customer);
    }
    public function password(Request $r): array
    {
        $c=$this->s->customer($r);$d=$r->validate(['current_password'=>'required|string|max:128','password'=>'required|string|min:10|max:128|confirmed']);
        if (!Hash::check($d['current_password'],$c['password_hash'])) throw ValidationException::withMessages(['current_password'=>'Mật khẩu hiện tại không đúng.']);
        $hash=Hash::make($d['password']);
        $row=$this->s->transaction(function()use($c,$hash){$fresh=$this->s->find('shop_customers',$c['_id']);abort_unless($fresh['auth_version']===$c['auth_version'],409);return $this->s->update('shop_customers',$fresh,['password_hash'=>$hash,'auth_version'=>$fresh['auth_version']+1]);});
        $this->signin($r,$row);return ['message'=>'Đã đổi mật khẩu và thu hồi các phiên cũ.'];
    }
    public function forgot(Request $r): array
    {
        return app(CustomerPasswordReset::class)->request($r);
    }
    public function reset(Request $r): array
    {
        return app(CustomerPasswordReset::class)->reset($r);
    }
    public function addresses(Request $r,?string $id=null): array
    {
        $c=$this->s->customer($r);
        if ($r->isMethod('get')) return CommerceStore::present($this->s->rows('shop_addresses',['customer_id'=>$c['_id'],'is_active'=>true]));
        $d=$r->isMethod('delete')?[]:$r->validate(['recipient'=>'required|string|max:160','phone'=>'required|string|max:30','address'=>'required|string|max:1000','is_default'=>'required|boolean']);
        return $this->s->transaction(function()use($r,$c,$id,$d){
            $row=$id?$this->s->find('shop_addresses',$id):null;
            if ($id) abort_unless($row&&$row['customer_id']===$c['_id']&&$row['is_active'],404);
            if ($r->isMethod('delete')) return CommerceStore::present($this->s->update('shop_addresses',$row,['is_active'=>false,'is_default'=>false]));
            if ($d['is_default']) foreach ($this->s->rows('shop_addresses',['customer_id'=>$c['_id'],'is_default'=>true,'is_active'=>true]) as $old) if (($row['_id']??null)!==$old['_id']) $this->s->update('shop_addresses',$old,['is_default'=>false]);
            $d+=['customer_id'=>$c['_id'],'is_active'=>true];
            return CommerceStore::present($row?$this->s->update('shop_addresses',$row,$d):$this->s->insert('shop_addresses',$d));
        });
    }
    public function wishlist(Request $r,string $chainId): array
    {
        $c=$this->s->customer($r);$this->s->settings($chainId);
        if (!$r->isMethod('get')) {
            $d=$r->validate(['product_id'=>'required|string|regex:/^[1-9][0-9]{0,18}$/']);
            if (!$r->isMethod('delete')) $this->front->product($chainId,$d['product_id'],$c);
            $this->s->transaction(function()use($r,$c,$chainId,$d){
                $row=$this->s->first('shop_wishlist',['customer_id'=>$c['_id'],'chain_id'=>$chainId,'product_id'=>$d['product_id']]);
                if ($row) $this->s->update('shop_wishlist',$row,['saved'=>!$r->isMethod('delete')]);
                elseif(!$r->isMethod('delete')) $this->s->insert('shop_wishlist',['customer_id'=>$c['_id'],'chain_id'=>$chainId,'product_id'=>$d['product_id'],'saved'=>true]);
            });
        }
        $rows=[];foreach($this->s->rows('shop_wishlist',['customer_id'=>$c['_id'],'chain_id'=>$chainId,'saved'=>true]) as $row) {
            try{$rows[]=$this->front->product($chainId,$row['product_id'],$c);}catch(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException){$rows[]=['id'=>$row['product_id'],'unavailable'=>true];}
        }
        return CommerceStore::present($rows);
    }
    public function cart(Request $r,string $chainId): array
    {
        $this->s->settings($chainId);$c=$this->s->customer($r,false);
        if ($r->isMethod('put')) {
            $d=$r->validate(['items'=>'present|array|max:100','items.*.product_id'=>'required|string|distinct|regex:/^[1-9][0-9]{0,18}$/','items.*.quantity'=>'required|string|max:20']);
            foreach ($d['items'] as &$item) { CommerceStore::quantity($item['quantity']);$p=$this->front->product($chainId,$item['product_id'],$c);$product=app(\App\Support\CatalogStore::class)->find('products',$p['id']);$item['saved_unit_price']=(string)app(Pricing::class)->productPrice($product,[],$chainId,$c,$item['quantity']); }unset($item);
            if ($c) $this->s->transaction(function()use($c,$chainId,$d){$cart=$this->s->first('shop_carts',['customer_id'=>$c['_id'],'chain_id'=>$chainId]);if($cart)$this->s->update('shop_carts',$cart,['items'=>$d['items']]);else $this->s->insert('shop_carts',['customer_id'=>$c['_id'],'chain_id'=>$chainId,'items'=>$d['items']]);});
            else $r->session()->put('shop_guest_cart.'.$chainId,$d['items']);
        }
        $items=$c?($this->s->first('shop_carts',['customer_id'=>$c['_id'],'chain_id'=>$chainId])['items']??[]):$r->session()->get('shop_guest_cart.'.$chainId,[]);
        $rows=[];$subtotal=BigDecimal::zero();
        foreach($items as $item){
            try{$p=$this->front->product($chainId,$item['product_id'],$c);$product=app(\App\Support\CatalogStore::class)->find('products',$p['id']);$p['price']=(string)app(Pricing::class)->productPrice($product,[],$chainId,$c,$item['quantity']);$line=BigDecimal::of($p['price'])->multipliedBy($item['quantity'])->toScale(2,\Brick\Math\RoundingMode::HalfUp);$subtotal=$subtotal->plus($line);$rows[]=$item+['price_changed'=>isset($item['saved_unit_price'])&&!BigDecimal::of($item['saved_unit_price'])->isEqualTo($p['price']),'product'=>$p,'line_total'=>(string)$line,'unavailable'=>BigDecimal::of($p['available_quantity'])->isLessThan($item['quantity'])];}
            catch(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException){$rows[]=$item+['unavailable'=>true];}
        }
        return CommerceStore::present(['items'=>$rows,'subtotal'=>(string)$subtotal->toScale(2),'is_authenticated'=>(bool)$c]);
    }
    private function mergeGuest(Request $r,array $customer): void
    {
        foreach($r->session()->get('shop_guest_cart',[]) as $chainId=>$items) {
            $chainId=(string)$chainId;
            $this->s->transaction(function()use($customer,$chainId,$items){
                $cart=$this->s->first('shop_carts',['customer_id'=>$customer['_id'],'chain_id'=>$chainId]);$merged=[];
                foreach(array_merge($cart['items']??[],$items) as $item){$old=$merged[$item['product_id']]??'0.000';$merged[$item['product_id']]=(string)BigDecimal::of($old)->plus($item['quantity'])->toScale(3);}
                $next=[];foreach($merged as $id=>$qty){if(BigDecimal::of($qty)->isLessThanOrEqualTo('999999999999.999'))$next[]=['product_id'=>(string)$id,'quantity'=>$qty];}
                if($cart)$this->s->update('shop_carts',$cart,['items'=>$next]);else $this->s->insert('shop_carts',['customer_id'=>$customer['_id'],'chain_id'=>$chainId,'items'=>$next]);
            });
        }
        $r->session()->forget('shop_guest_cart');
    }
}
