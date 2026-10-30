<?php

namespace App\Http\Controllers;

use App\Commerce\Uploads;
use Illuminate\Http\Request;

final class ShopUploadController extends Controller
{
    public function __construct(private readonly Uploads $uploads) {}
    public function create(Request $r,string $chainId){return response()->json(['data'=>$this->uploads->create($r,$chainId)],201);}
    public function show(Request $r,string $id){$file=$this->uploads->read($r,$id);return response()->file($file['path'],['Content-Type'=>$file['mime'],'Content-Disposition'=>'inline; filename="'.$file['name'].'"','X-Content-Type-Options'=>'nosniff','Cache-Control'=>'private, no-store']);}
}
