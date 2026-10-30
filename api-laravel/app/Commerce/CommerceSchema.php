<?php

namespace App\Commerce;

use MongoDB\Database;
use MongoDB\BSON\Int64;

final class CommerceSchema
{
    public const VERSION = 10;

    public static function specs(): array
    {
        $indexes = [
            'shop_customers' => [['email'=>1]],
            'shop_customer_links' => [['customer_id'=>1,'chain_id'=>1]],
            'shop_customer_segments' => [['customer_id'=>1,'chain_id'=>1]],
            'shop_uploads' => [['file_key'=>1]],
            'shop_addresses' => [],
            'shop_password_resets' => [['token_hash'=>1]],
            'shop_products' => [['chain_id'=>1,'product_id'=>1],['chain_id'=>1,'slug'=>1]],
            'shop_settings' => [['chain_id'=>1]],
            'shop_banners' => [],
            'shop_pages' => [['chain_id'=>1,'slug'=>1]],
            'shop_carts' => [['customer_id'=>1,'chain_id'=>1]],
            'shop_wishlist' => [['customer_id'=>1,'chain_id'=>1,'product_id'=>1]],
            'shop_orders' => [['customer_id'=>1,'request_key'=>1],['order_no'=>1]],
            'shop_reservations' => [['order_id'=>1,'lot_id'=>1]],
            'shop_cancellation_requests' => [['order_id'=>1,'request_key'=>1]],
            'shop_shipments' => [['order_id'=>1]],
            'shop_payment_events' => [['order_id'=>1,'request_key'=>1]],
            'shop_exchanges' => [['return_id'=>1]],
            'shop_exchange_transfers' => [['exchange_id'=>1]],
            'shop_returns' => [['order_id'=>1,'request_key'=>1]],
            'shop_refunds' => [['return_id'=>1,'request_key'=>1]],
            'shop_return_movements' => [['return_id'=>1,'sale_item_id'=>1]],
            'shop_campaigns' => [['chain_id'=>1,'code'=>1]],
            'shop_vouchers' => [['chain_id'=>1,'code'=>1]],
            'shop_voucher_claims' => [['customer_id'=>1,'voucher_id'=>1]],
            'shop_campaign_redemptions' => [['order_id'=>1,'campaign_id'=>1]],
            'shop_voucher_redemptions' => [['order_id'=>1,'voucher_id'=>1]],
            'shop_dealers' => [['customer_id'=>1,'chain_id'=>1]],
            'shop_price_lists' => [['chain_id'=>1,'product_id'=>1]],
            'shop_quotes' => [['customer_id'=>1,'request_key'=>1]],
            'shop_credit_commitments' => [['order_id'=>1]],
            'shop_recurring' => [],
            'shop_recurring_requests' => [['schedule_id'=>1,'due_on'=>1]],
            'shop_reviews' => [['customer_id'=>1,'product_id'=>1,'order_id'=>1]],
            'shop_questions' => [['customer_id'=>1,'request_key'=>1]],
            'shop_notifications' => [['customer_id'=>1,'event_key'=>1]],
            'shop_tickets' => [['customer_id'=>1,'request_key'=>1]],
            'shop_messages' => [['ticket_id'=>1,'sender_type'=>1,'sender_id'=>1,'request_key'=>1]],
        ];
        $specs=[];
        foreach ($indexes as $name=>$unique) {
            $list=[];
            foreach ($unique as $i=>$key) $list[]=['key'=>$key,'name'=>'commerce_u'.$i,'unique'=>true];
            if($name==='shop_credit_commitments') $list[]=['key'=>['chain_id'=>1,'customer_id'=>1,'state'=>1],'name'=>'commerce_credit_usage'];
            if(in_array($name,['shop_questions','shop_tickets'],true))foreach($list as &$index)$index['partialFilterExpression']=['request_key'=>['$type'=>'string']];unset($index);
            if($name==='shop_cancellation_requests')$list[]=['key'=>['order_id'=>1],'name'=>'commerce_one_pending','unique'=>true,'partialFilterExpression'=>['status'=>'pending']];
            $list[]=['key'=>['chain_id'=>1,'created_at'=>-1],'name'=>'commerce_scope_time'];
            $list[]=['key'=>['customer_id'=>1,'created_at'=>-1],'name'=>'commerce_customer_time'];
            if(in_array($name,['shop_campaign_redemptions','shop_voucher_redemptions'],true)) $list[]=['key'=>['promotion_id'=>1,'state'=>1,'customer_id'=>1],'name'=>'commerce_promotion_usage'];
            if ($name==='shop_reservations') $list[]=['key'=>['warehouse_id'=>1,'lot_id'=>1,'status'=>1,'expires_at'=>1],'name'=>'commerce_stock_hold'];
            $properties=['_id'=>['bsonType'=>'string','pattern'=>'^[1-9][0-9]{0,18}$'],'_version'=>['bsonType'=>'long'],'id_sort'=>['bsonType'=>'long'],'created_at'=>['bsonType'=>'date'],'updated_at'=>['bsonType'=>'date'],'chain_id'=>['bsonType'=>['string','null']],'customer_id'=>['bsonType'=>['string','null']]];
            if ($name==='shop_customers') $properties+=['email'=>['bsonType'=>'string'],'password_hash'=>['bsonType'=>'string'],'is_active'=>['bsonType'=>'bool']];
            $required=['_id','_version','id_sort','created_at','updated_at'];
            if ($name==='shop_customers') $required=array_merge($required,['email','password_hash','is_active']);
            $specs[$name]=['category'=>'domain','validator'=>['$jsonSchema'=>['bsonType'=>'object','required'=>$required,'properties'=>$properties]],'validationLevel'=>'strict','validationAction'=>'error','indexes'=>$list];
        }
        return $specs;
    }

    public static function apply(Database $db): void
    {
        $existing=iterator_to_array($db->listCollectionNames());
        foreach (self::specs() as $name=>$spec) {
            if (!in_array($name,$existing,true)) $db->createCollection($name,array_intersect_key($spec,array_flip(['validator','validationLevel','validationAction'])));
            foreach ($spec['indexes'] as $index) { $key=$index['key'];unset($index['key']);$db->selectCollection($name)->createIndex($key,$index); }
            $db->selectCollection('id_counters')->updateOne(['_id'=>$name],['$setOnInsert'=>['value'=>new Int64('0')]],['upsert'=>true]);
        }
    }
}
