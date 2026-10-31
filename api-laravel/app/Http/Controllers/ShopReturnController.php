<?php

namespace App\Http\Controllers;

use App\Commerce\Returns;
use Illuminate\Http\Request;

final class ShopReturnController extends Controller
{
    public function __construct(private readonly Returns $returns) {}
    public function listing(Request $r,string $chainId){return response()->json(['data'=>$this->returns->listing($r,$chainId,str_starts_with($r->path(),'api/shop-admin/'))]);}
    public function create(Request $r,string $chainId){$d=$r->validate(['order_id'=>'required|string|regex:/^[1-9][0-9]{0,18}$/','items'=>'required|array|min:1|max:100','items.*.product_id'=>'required|string|distinct|regex:/^[1-9][0-9]{0,18}$/','items.*.quantity'=>'required|string|max:20','reason'=>'required|string|max:1000','description'=>'nullable|string|max:5000','images'=>'present|array|max:5','images.*'=>'string|max:500','resolution'=>'required|in:return,refund,exchange','request_key'=>'required|string|min:16|max:100|regex:/^[A-Za-z0-9_-]+$/']);return response()->json(['data'=>$this->returns->create($r,$chainId,$d)]);}
    public function decide(Request $r,string $chainId,string $id){$d=$r->validate(['action'=>'required|in:approve,reject,request_info','reason'=>'required|string|max:1000']);return response()->json(['data'=>$this->returns->decide($r,$chainId,$id,$d)]);}
    public function receive(Request $r,string $chainId,string $id){$d=$r->validate(['items'=>'required|array|min:1|max:100','items.*.sale_item_id'=>'required|string|distinct|regex:/^[1-9][0-9]{0,18}$/','items.*.quantity'=>'required|string|max:20','items.*.condition'=>'required|in:intact,damaged,expired','items.*.disposition'=>'required|in:restock,not_resellable','note'=>'required|string|max:1000','request_key'=>'required|string|min:16|max:100|regex:/^[A-Za-z0-9_-]+$/']);return response()->json(['data'=>$this->returns->receive($r,$chainId,$id,$d)]);}
    public function refund(Request $r,string $chainId,string $id){$d=$r->validate(['amount'=>'required|string|max:20','method'=>'required|in:cash,bank_transfer','reference'=>'required|string|max:200','request_key'=>'required|string|min:16|max:100|regex:/^[A-Za-z0-9_-]+$/']);return response()->json(['data'=>$this->returns->refund($r,$chainId,$id,$d)]);}
    public function replacement(Request $r,string $chainId,string $id){$d=$r->validate(['items'=>'required|array|min:1|max:100','items.*.product_id'=>'required|string|distinct|regex:/^[1-9][0-9]{0,18}$/','items.*.quantity'=>'required|string|max:20','payment_method'=>'required|in:bank_transfer,credit','note'=>'required|string|max:1000']);return response()->json(['data'=>app(\App\Commerce\Exchanges::class)->offer($r,$chainId,$id,$d)]);}
    public function acceptReplacement(Request $r,string $chainId,string $id){$d=$r->validate(['version'=>'required|integer|min:1']);return response()->json(['data'=>app(\App\Commerce\Exchanges::class)->accept($r,$chainId,$id,$d)]);}
    public function handoverReplacement(Request $r,string $chainId,string $id){$d=$r->validate(['amount'=>'required|string|max:20','reference'=>'required|string|max:200','request_key'=>'required|string|min:16|max:100|regex:/^[A-Za-z0-9_-]+$/']);return response()->json(['data'=>app(\App\Commerce\Exchanges::class)->handover($r,$chainId,$id,$d)]);}
}
