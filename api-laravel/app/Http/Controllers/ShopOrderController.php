<?php

namespace App\Http\Controllers;

use App\Commerce\{CommerceStore,Orders};
use Illuminate\Http\Request;

final class ShopOrderController extends Controller
{
    public function __construct(private readonly CommerceStore $s,private readonly Orders $orders) {}
    public function quote(Request $r,string $chainId){$this->s->customer($r);$this->orders->expire($chainId);return response()->json(['data'=>$this->orders->publicQuote($this->orders->quote($r,$chainId,$this->orders->input($r)))]);}
    public function index(Request $r,string $chainId){
        $c=$this->s->customer($r);$this->s->settings($chainId);$this->orders->expire($chainId,$c['_id']);$filter=['chain_id'=>$chainId,'customer_id'=>$c['_id']];
        if($r->filled('request_key'))$filter['request_key']=$r->validate(['request_key'=>'string|max:100'])['request_key'];
        return response()->json(['data'=>array_map(fn($o)=>$this->orders->present($o),$this->s->rows('shop_orders',$filter))]);
    }
    public function create(Request $r,string $chainId){return response()->json(['data'=>$this->orders->create($r,$chainId,$this->orders->input($r,true))]);}
    public function show(Request $r,string $chainId,string $id){$row=$this->orders->own($r,$chainId,$id);$this->orders->expire($chainId,$row['customer_id'],$row['branch_id']);return response()->json(['data'=>$this->orders->present($this->orders->own($r,$chainId,$id))]);}
    public function reorder(Request $r,string $chainId,string $id){return response()->json(['data'=>$this->orders->reorder($r,$chainId,$id)]);}
    public function cancel(Request $r,string $chainId,string $id){$d=$r->validate(['reason'=>'required|string|max:1000']);return response()->json(['data'=>$this->orders->action($r,$chainId,$id,$d+['action'=>'cancel'],true)]);}
    public function cancellation(Request $r,string $chainId,string $id){$d=$r->validate(['reason'=>'required|string|max:1000','request_key'=>'required|string|min:16|max:100|regex:/^[A-Za-z0-9_-]+$/']);return response()->json(['data'=>app(\App\Commerce\Cancellations::class)->request($r,$chainId,$id,$d)]);}
    public function cancellationDecision(Request $r,string $chainId,string $id){$d=$r->validate(['decision'=>'required|in:approved,rejected','reason'=>'required|string|max:1000']);return response()->json(['data'=>app(\App\Commerce\Cancellations::class)->decide($r,$chainId,$id,$d)]);}
    public function adminIndex(Request $r,string $chainId){return response()->json(['data'=>$this->orders->adminRows($r,$chainId)]);}
    public function picking(Request $r,string $chainId,string $id){return response()->json(['data'=>$this->orders->picking($r,$chainId,$id)]);}
    public function reallocate(Request $r,string $chainId,string $id){$d=$r->validate(['items'=>'required|array|min:1|max:100','items.*.lot_id'=>'required|string|distinct|regex:/^[1-9][0-9]{0,18}$/','items.*.quantity'=>'required|string|max:20']);return response()->json(['data'=>$this->orders->reallocate($r,$chainId,$id,$d['items'])]);}
    public function adminAction(Request $r,string $chainId,string $id){$d=$r->validate(['action'=>'required|in:confirm,prepare,pack,dispatch,pickup,cancel,reject','reason'=>'nullable|string|max:1000']);return response()->json(['data'=>$this->orders->action($r,$chainId,$id,$d)]);}
}
