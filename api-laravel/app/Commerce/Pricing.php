<?php

namespace App\Commerce;

use Brick\Math\BigDecimal;

final class Pricing
{
    public function __construct(private readonly CommerceStore $store) {}
    public function basePrice(array $product,string $chainId,?array $customer,string $quantity): BigDecimal
    {
        $price=BigDecimal::of((string)$product['sale_price']);
        if($customer&&$this->store->first('shop_dealers',['chain_id'=>$chainId,'customer_id'=>$customer['_id'],'status'=>'approved']))foreach($this->store->rows('shop_price_lists',['chain_id'=>$chainId,'is_active'=>true,'product_id'=>$product['_id']]) as $list)foreach($list['tiers']??[] as $tier)if(BigDecimal::of($quantity)->isGreaterThanOrEqualTo($tier['min_quantity']))$price=BigDecimal::min($price,BigDecimal::of((string)$tier['price']));
        return $price->toScale(2);
    }
    public function productPrice(array $product,array $display,string $chainId,?array $customer,string $quantity): BigDecimal
    {
        return app(Promotions::class)->previewPrice($product,$chainId,$customer,$quantity,$this->basePrice($product,$chainId,$customer,$quantity));
    }
}
