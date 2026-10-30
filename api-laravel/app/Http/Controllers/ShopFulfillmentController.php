<?php

namespace App\Http\Controllers;

use App\Commerce\Fulfillment;
use Illuminate\Http\Request;

final class ShopFulfillmentController extends Controller
{
    public function __construct(private readonly Fulfillment $service) {}
    public function shipment(Request $r,string $chainId,string $id){$d=$r->validate(['carrier'=>'required|string|max:160','tracking_no'=>'nullable|string|max:160','note'=>'nullable|string|max:1000','shipping_fee'=>'required|string|max:20','items'=>'required|array|min:1|max:100','items.*.product_id'=>'required|string|distinct|regex:/^[1-9][0-9]{0,18}$/','items.*.quantity'=>'required|string|max:20']);return response()->json(['data'=>$this->service->shipment($r,$chainId,$id,$d)]);}
    public function transition(Request $r,string $chainId,string $id){$d=$r->validate(['status'=>'required|in:in_transit,delivered,failed,returned','reason'=>'nullable|string|max:1000','request_key'=>'required|string|min:16|max:100|regex:/^[A-Za-z0-9_-]+$/']);return response()->json(['data'=>$this->service->transition($r,$chainId,$id,$d)]);}
    public function payment(Request $r,string $chainId,string $id){$d=$r->validate(['kind'=>'required|in:cash,cod_settlement,bank_transfer','amount'=>'required|string|max:20','reference'=>'required|string|max:200','request_key'=>'required|string|min:16|max:100|regex:/^[A-Za-z0-9_-]+$/']);return response()->json(['data'=>$this->service->payment($r,$chainId,$id,$d)]);}
    public function complete(Request $r,string $chainId,string $id){return response()->json(['data'=>$this->service->complete($r,$chainId,$id)]);}
}
