<?php

namespace App\Http\Controllers;

use App\Commerce\{Dealers,WholesaleQuotes,RecurringPurchases};
use Illuminate\Http\Request;

final class ShopBusinessController extends Controller
{
    public function __construct(private readonly Dealers $dealers,private readonly WholesaleQuotes $quotes,private readonly RecurringPurchases $recurring) {}
    public function dealer(Request $r,string $chainId){return response()->json(['data'=>$this->dealers->profile($r,$chainId)]);}
    public function dealers(Request $r,string $chainId,?string $id=null){return response()->json(['data'=>$this->dealers->admin($r,$chainId,$id)]);}
    public function policy(Request $r,string $chainId,string $id){return response()->json(['data'=>$this->dealers->policy($r,$chainId,$id)]);}
    public function prices(Request $r,string $chainId,?string $productId=null){return response()->json(['data'=>$this->dealers->prices($r,$chainId,$productId)]);}
    public function quotes(Request $r,string $chainId){return response()->json(['data'=>$r->isMethod('get')?$this->quotes->listing($r,$chainId,str_starts_with($r->path(),'api/shop-admin/')):$this->quotes->request($r,$chainId)]);}
    public function offer(Request $r,string $chainId,string $id){return response()->json(['data'=>$this->quotes->offer($r,$chainId,$id)]);}
    public function decision(Request $r,string $chainId,string $id){return response()->json(['data'=>$this->quotes->decision($r,$chainId,$id)]);}
    public function schedules(Request $r,string $chainId,?string $id=null){return response()->json(['data'=>$r->isMethod('get')?$this->recurring->list($r,$chainId):$this->recurring->save($r,$chainId,$id)]);}
    public function generate(Request $r,string $chainId,string $id){return response()->json(['data'=>$this->recurring->generate($r,$chainId,$id)]);}
}
