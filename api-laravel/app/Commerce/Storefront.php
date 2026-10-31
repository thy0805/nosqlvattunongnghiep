<?php

namespace App\Commerce;

use Brick\Math\BigDecimal;
use MongoDB\BSON\Regex;
use App\Support\{CatalogStore,PurchaseStore};

final class Storefront
{
    public function __construct(public readonly CommerceStore $store,private readonly CatalogStore $catalog,private readonly PurchaseStore $stock) {}

    public function chains(): array
    {
        $out=[];
        foreach ($this->store->rows('shop_settings',['is_open'=>true]) as $s) {
            $chain=$this->store->identity->find('chains',$s['chain_id']);
            if ($chain&&$chain['is_active']) $out[]=['id'=>$s['chain_id'],'name'=>$s['name']??$chain['name'],'description'=>$s['description']??''];
        }
        return $out;
    }
    public function products(string $chainId,array $filters=[],?array $customer=null): array
    {
        $this->store->settings($chainId);
        $where=['chain_id'=>$chainId,'published'=>true];
        $public=$this->store->rows('shop_products',$where,['created_at'=>-1],5000);$rows=[];
        foreach ($public as $display) {
            $product=$this->catalog->find('products',$display['product_id']);
            $category=$product?$this->catalog->find('product_categories',$product['category_id']):null;
            $unit=$product?$this->catalog->find('units',$product['unit_id']):null;
            if (!$product||!$category||!$unit||!$product['is_active']||!$category['is_active']||!$unit['is_active']||$product['tax_rate']===null) continue;
            $q=trim((string)($filters['search']??''));
            if ($q!==''&&mb_stripos(implode(' ',[$product['name'],$product['code'],$product['active_ingredient']??'',$display['brand']??'',$display['uses']??'']),$q)===false) continue;
            foreach (['brand','origin'] as $key) if (!empty($filters[$key])&&($display[$key]??'')!==$filters[$key]) continue 2;
            if (!empty($filters['category_id'])&&$product['category_id']!==$filters['category_id']) continue;
            $price=app(Pricing::class)->productPrice($product,$display,$chainId,$customer,'1.000');
            if (isset($filters['min_price'])&&$price->isLessThan(CommerceStore::money($filters['min_price']))) continue;
            if (isset($filters['max_price'])&&$price->isGreaterThan(CommerceStore::money($filters['max_price']))) continue;
            $available=$this->available($chainId,$product['_id']);
            if (!empty($filters['in_stock'])&&$available->isZero()) continue;
            $content=$this->catalog->find('product_contents',$product['_id']);
            $row=['id'=>$product['_id'],'slug'=>$display['slug'],'chain_id'=>$chainId,'code'=>$product['code'],'name'=>$product['name'],'category_id'=>$category['_id'],'category_name'=>$category['name'],'unit'=>$unit['name'],'base_price'=>(string)$product['sale_price'],'price'=>(string)$price->toScale(2),'tax_rate'=>(string)$product['tax_rate'],'in_stock'=>$available->isGreaterThan(0),'available_quantity'=>(string)$available->toScale(3),'images'=>$display['images']??$content['images']??[],'brand'=>$display['brand']??'','origin'=>$display['origin']??'','packaging'=>$display['packaging']??$unit['name'],'active_ingredient'=>$product['active_ingredient'],'uses'=>$display['uses']??'','instructions'=>$display['instructions']??$content['usage_instructions']??'','storage'=>$display['storage']??'','safety'=>$display['safety']??'','description'=>$display['description']??'','published_at'=>$display['created_at'],'sold_quantity'=>$display['sold_quantity']??'0.000'];
            $rows[]=$row;
        }
        $sort=$filters['sort']??'newest';
        if (in_array($sort,['price_asc','price_desc'],true)) usort($rows,fn($a,$b)=>BigDecimal::of($a['price'])->compareTo($b['price'])*($sort==='price_desc'?-1:1)?:strcmp($a['id'],$b['id']));
        if ($sort==='bestselling') usort($rows,fn($a,$b)=>BigDecimal::of($b['sold_quantity'])->compareTo($a['sold_quantity']));
        return $rows;
    }
    public function product(string $chainId,string $slugOrId,?array $customer=null): array
    {
        foreach ($this->products($chainId,[],$customer) as $row) if ($row['id']===$slugOrId||$row['slug']===$slugOrId) return $row;
        abort(404,'Sản phẩm chưa công khai hoặc đã ngừng bán.');
    }
    public function available(string $chainId,string $productId,?string $warehouseId=null,?string $excludeOrder=null): BigDecimal
    {
        $sum=BigDecimal::zero();
        $branches=$this->store->identity->rows('branches',['chain_id'=>$chainId,'is_active'=>true])->pluck('_id')->all();
        $warehouses=$this->store->identity->rows('warehouses',['branch_id'=>['$in'=>$branches]])->pluck('_id')->all();
        if ($warehouseId!==null) $warehouses=in_array($warehouseId,$warehouses,true)?[$warehouseId]:[];
        foreach ($this->stock->rows('product_lots',['product_id'=>$productId]) as $lot) {
            if (($lot['expires_on']??null)!==null&&$lot['expires_on']<now('Asia/Ho_Chi_Minh')->toDateString()) continue;
            foreach ($this->stock->rows('inventories',['lot_id'=>$lot['_id'],'warehouse_id'=>['$in'=>$warehouses]]) as $i) {
                $qty=BigDecimal::of((string)$i['quantity'])->minus($this->held($i['warehouse_id'],$lot['_id'],$excludeOrder));
                if ($qty->isGreaterThan(0)) $sum=$sum->plus($qty);
            }
        }
        return $sum;
    }
    public function held(string $warehouseId,string $lotId,?string $excludeOrder=null): BigDecimal
    {
        $filter=['warehouse_id'=>$warehouseId,'lot_id'=>$lotId,'status'=>'held','$or'=>[['expires_at'=>null],['expires_at'=>['$gt'=>CommerceStore::now()]]]];
        if ($excludeOrder!==null) $filter['order_id']=['$ne'=>$excludeOrder];
        $sum=BigDecimal::zero();foreach ($this->store->rows('shop_reservations',$filter) as $r) $sum=$sum->plus((string)$r['quantity']);return $sum;
    }
    public function branches(string $chainId): array
    {
        return $this->store->identity->rows('branches',['chain_id'=>$chainId,'is_active'=>true])->map(fn($b)=>['id'=>$b['_id'],'name'=>$b['name'],'address'=>$b['address'],'warehouse_id'=>$b['default_sales_warehouse_id']])->values()->all();
    }
}
