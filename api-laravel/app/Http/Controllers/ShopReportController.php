<?php

namespace App\Http\Controllers;

use App\Commerce\CommerceReports;
use Illuminate\Http\Request;

final class ShopReportController extends Controller
{
    public function index(Request $r,string $chainId,CommerceReports $reports){return response()->json(['data'=>$reports->report($r,$chainId)]);}
}
