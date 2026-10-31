<?php

namespace App\Http\Controllers;

use App\Commerce\Marketing;
use Illuminate\Http\Request;

final class ShopMarketingController extends Controller
{
    public function __construct(private readonly Marketing $marketing) {}
    public function programs(Request $r,string $chainId,string $kind,?string $id=null){return response()->json(['data'=>$this->marketing->admin($r,$chainId,$kind,$id)]);}
    public function status(Request $r,string $chainId,string $kind,string $id){return response()->json(['data'=>$this->marketing->status($r,$chainId,$kind,$id)]);}
    public function wallet(Request $r,string $chainId,?string $id=null){return response()->json(['data'=>$this->marketing->wallet($r,$chainId,$id)]);}
    public function customers(Request $r,string $chainId,?string $id=null){$segments=app(\App\Commerce\CustomerSegments::class);return response()->json(['data'=>$id===null?$segments->listing($r,$chainId):$segments->update($r,$chainId,$id)]);}
}
