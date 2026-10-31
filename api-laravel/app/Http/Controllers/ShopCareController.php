<?php

namespace App\Http\Controllers;

use App\Commerce\CustomerCare;
use Illuminate\Http\Request;

final class ShopCareController extends Controller
{
    public function __construct(private readonly CustomerCare $care) {}
    private function staff(Request $r): bool {return str_starts_with($r->path(),'api/shop-admin/');}
    public function tickets(Request $r,string $chainId){if($r->isMethod('get'))return response()->json(['data'=>$this->care->tickets($r,$chainId,$this->staff($r))]);$d=$r->validate(['branch_id'=>'required|string|regex:/^[1-9][0-9]{0,18}$/','order_id'=>'nullable|string|regex:/^[1-9][0-9]{0,18}$/','subject'=>'required|string|max:160','body'=>'required|string|max:5000','images'=>'sometimes|array|max:5','images.*'=>'string|max:500','request_key'=>'required|string|min:16|max:100|regex:/^[A-Za-z0-9_-]+$/']);return response()->json(['data'=>$this->care->createTicket($r,$chainId,$d)]);}
    public function detail(Request $r,string $chainId,string $id){return response()->json(['data'=>$this->care->detail($r,$chainId,$id,$this->staff($r))]);}
    public function message(Request $r,string $chainId,string $id){$d=$r->validate(['body'=>'required|string|max:5000','images'=>'sometimes|array|max:5','images.*'=>'string|max:500','request_key'=>'required|string|min:16|max:100|regex:/^[A-Za-z0-9_-]+$/']);return response()->json(['data'=>$this->care->message($r,$chainId,$id,$d,$this->staff($r))]);}
    public function state(Request $r,string $chainId,string $id){$d=$r->validate(['action'=>'required|in:close,reopen']);return response()->json(['data'=>$this->care->ticketState($r,$chainId,$id,$d['action'],$this->staff($r))]);}
    public function notifications(Request $r,string $chainId,?string $id=null){return response()->json(['data'=>$this->care->notifications($r,$chainId,$id)]);}
    public function reviews(Request $r,string $chainId,string $productId){return response()->json(['data'=>$this->care->reviews($r,$chainId,$productId)]);}
    public function editReview(Request $r,string $chainId,string $productId,string $id){$d=$r->validate(['rating'=>'required|integer|min:1|max:5','body'=>'required|string|max:3000','images'=>'present|array|max:5','images.*'=>'string|max:500','revision'=>'required|integer|min:0','request_key'=>'required|string|min:16|max:100|regex:/^[A-Za-z0-9_-]+$/']);$d['rating']=(int)$d['rating'];return response()->json(['data'=>$this->care->editReview($r,$chainId,$productId,$id,$d)]);}
    public function review(Request $r,string $chainId,string $productId){$d=$r->validate(['order_id'=>'required|string|regex:/^[1-9][0-9]{0,18}$/','rating'=>'required|integer|min:1|max:5','body'=>'required|string|max:3000','images'=>'sometimes|array|max:5','images.*'=>'string|max:500']);$d['rating']=(int)$d['rating'];return response()->json(['data'=>$this->care->review($r,$chainId,$productId,$d)]);}
    public function question(Request $r,string $chainId,string $productId){$d=$r->validate(['body'=>'required|string|max:3000','request_key'=>'required|string|min:16|max:100|regex:/^[A-Za-z0-9_-]+$/']);return response()->json(['data'=>$this->care->question($r,$chainId,$productId,$d)]);}
    public function reviewSummary(Request $r,string $chainId){return response()->json(['data'=>$this->care->reviewSummary($r,$chainId)]);}
    public function moderation(Request $r,string $chainId,string $kind,?string $id=null){$d=$r->isMethod('get')?null:$r->validate(['status'=>'required|in:pending,published,rejected','answer'=>'nullable|string|max:3000']);return response()->json(['data'=>$this->care->moderation($r,$chainId,$kind,$id,$d)]);}
}
