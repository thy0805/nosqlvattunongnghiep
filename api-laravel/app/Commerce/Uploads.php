<?php

namespace App\Commerce;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class Uploads
{
    public function __construct(private readonly CommerceStore $s) {}
    public function create(Request $r,string $chain): array
    {
        $c=$this->s->customer($r);$this->s->settings($chain);$d=$r->validate(['purpose'=>'required|in:return,review,ticket,dealer','file'=>'required|file|mimes:jpg,jpeg,png,webp|max:2048']);$file=$d['file'];$size=@getimagesize($file->getRealPath());$mime=$file->getMimeType();$types=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];abort_unless($size&&isset($types[$mime])&&$size[0]>0&&$size[1]>0&&$size[0]<=4096&&$size[1]<=4096&&($size['mime']??null)===$mime,422,'Chỉ nhận ảnh JPEG/PNG/WebP tối đa 4096px và2 MiB.');
        $dir=storage_path('app/shop-media');if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new \RuntimeException('MEDIA_DIRECTORY_FAILED');$key=(string)Str::uuid().'.'.$types[$mime];$path=$dir.'/'.$key;$file->move($dir,$key);
        try{return $this->s->transaction(function()use($r,$c,$chain,$d,$mime,$path,$key,$size){$this->s->customer($r);abort_if($this->s->collection('shop_uploads')->countDocuments(['customer_id'=>$c['_id']],$this->s->options())>=100,422,'Tài khoản đã đạt giới hạn100 ảnh.');abort_if($this->s->collection('shop_uploads')->countDocuments(['customer_id'=>$c['_id'],'created_at'=>['$gte'=>new \MongoDB\BSON\UTCDateTime(now()->subHour())]],$this->s->options())>=30,429,'Tối đa30 ảnh mỗi giờ.');$row=$this->s->insert('shop_uploads',['chain_id'=>$chain,'customer_id'=>$c['_id'],'purpose'=>$d['purpose'],'file_key'=>$key,'mime'=>$mime,'bytes'=>filesize($path),'sha256'=>hash_file('sha256',$path),'width'=>$size[0],'height'=>$size[1]]);return ['id'=>$row['_id'],'url'=>'/api/shop-media/'.$row['_id'],'mime'=>$mime,'bytes'=>$row['bytes']];});}catch(\Throwable $e){if(is_file($path))unlink($path);throw $e;}
    }
    public function references(string $chain,string $customer,string $purpose,array $paths): array
    {
        foreach($paths as $path){if(preg_match('~^/api/shop-media/([1-9][0-9]{0,18})$~D',$path,$m)){$row=$this->s->find('shop_uploads',$m[1]);abort_unless($row&&$row['chain_id']===$chain&&$row['customer_id']===$customer&&$row['purpose']===$purpose,422,'Ảnh không thuộc hồ sơ hoặc tài khoản này.');}else{abort_unless(preg_match('~^/images/[A-Za-z0-9/_.,%\-]+$~D',$path)&&!str_contains(rawurldecode($path),'..'),422,'Đường dẫn ảnh không hợp lệ.');$base=realpath(base_path('../web-quan-ly-ban-hang/public/images'));$file=realpath(base_path('../web-quan-ly-ban-hang/public'.$path));abort_unless($base&&$file&&str_starts_with(str_replace('\\','/',$file),str_replace('\\','/',$base).'/'),422,'Ảnh nội bộ không tồn tại.');}}
        return array_values(array_unique($paths));
    }
    public function read(Request $r,string $id): array
    {
        $row=$this->s->find('shop_uploads',$id);abort_unless($row,404);$url='/api/shop-media/'.$id;$own=$this->s->customer($r,false);$allowed=$own&&$own['_id']===$row['customer_id'];
        if(!$allowed&&$row['purpose']==='review')$allowed=$this->s->first('shop_reviews',['chain_id'=>$row['chain_id'],'images'=>$url,'status'=>'published'])!==null;
        if(!$allowed&&$r->user()){foreach(['return'=>['shop_returns','images'],'review'=>['shop_reviews','images'],'ticket'=>['shop_messages','images'],'dealer'=>['shop_dealers','documents']] as $purpose=>[$name,$field]){if($purpose!==$row['purpose'])continue;$record=$this->s->first($name,['chain_id'=>$row['chain_id'],$field=>$url]);if(!$record)continue;if($purpose==='ticket')$record=$this->s->find('shop_tickets',$record['ticket_id']);$this->s->admin($r,$row['chain_id'],$record['branch_id']??null,in_array($purpose,['review','dealer'],true));$allowed=true;}}
        abort_unless($allowed,404);abort_unless(preg_match('/^[a-f0-9-]{36}\.(jpg|png|webp)$/D',$row['file_key']),404);$path=storage_path('app/shop-media/'.$row['file_key']);abort_unless(is_file($path)&&filesize($path)===$row['bytes']&&hash_equals($row['sha256'],hash_file('sha256',$path)),404);return ['path'=>$path,'mime'=>$row['mime'],'name'=>$id.'.'.pathinfo($row['file_key'],PATHINFO_EXTENSION)];
    }
}
