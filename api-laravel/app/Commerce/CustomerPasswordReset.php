<?php

namespace App\Commerce;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Hash,Mail};
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\UTCDateTime;

final class CustomerPasswordReset
{
    public function __construct(private readonly CommerceStore $s) {}
    private function mailer(): ?string
    {
        $name=config('shop_reset.mailer');
        if($name==='array'&&app()->runningInConsole()&&app()->environment('testing'))return 'array';
        return $name==='smtp'&&config('mail.mailers.smtp.host')&&config('shop_reset.from')?'smtp':null;
    }
    public function request(Request $r): array
    {
        $d=$r->validate(['email'=>'required|email|max:190']);$email=mb_strtolower(trim($d['email']));$mailer=$this->mailer();
        $response=['message'=>'Nếu email khớp tài khoản đang hoạt động và kênh gửi thư sẵn sàng, cửa hàng sẽ gửi liên kết đặt lại mật khẩu. Liên kết có hiệu lực 30 phút.','delivery_status'=>$mailer?'requested':'not_configured'];
        if(!$mailer){$response['message']='Chưa cấu hình kênh email. Liên hệ cửa hàng để được hỗ trợ khôi phục. Các chức năng Shop khác vẫn sử dụng bình thường.';return $response;}
        $token=bin2hex(random_bytes(32));$hash=hash('sha256',$token);$now=CommerceStore::now();
        $row=$this->s->transaction(function()use($email,$hash,$now){
            $c=$this->s->first('shop_customers',['email'=>$email,'is_active'=>true]);if(!$c)return null;
            foreach($this->s->rows('shop_password_resets',['customer_id'=>$c['_id'],'used_at'=>null]) as $old)$this->s->update('shop_password_resets',$old,['used_at'=>$now]);
            return $this->s->insert('shop_password_resets',['customer_id'=>$c['_id'],'email'=>$email,'token_hash'=>$hash,'auth_version'=>$c['auth_version'],'expires_at'=>new UTCDateTime(now()->addMinutes(30)),'used_at'=>null]);
        });
        if($row){
            $url=rtrim((string)config('shop_reset.url'),'/').'#'.http_build_query(['token'=>$token,'email'=>$email]);
            try{Mail::mailer($mailer)->raw("Bạn vừa yêu cầu khôi phục tài khoản khách Agrovia. Mở liên kết sau trong 30 phút:\n".$url."\nNếu không yêu cầu, bạn có thể bỏ qua thư này.",function($message)use($email){$message->from(config('shop_reset.from'),'Agrovia')->to($email)->subject('Đặt lại mật khẩu tài khoản khách Agrovia');});}
            catch(\Throwable){$this->s->transaction(function()use($row){$fresh=$this->s->find('shop_password_resets',$row['_id']);if($fresh&&$fresh['used_at']===null)$this->s->update('shop_password_resets',$fresh,['used_at'=>CommerceStore::now()]);});}
        }
        return $response;
    }
    public function reset(Request $r): array
    {
        $d=$r->validate(['email'=>'required|email|max:190','token'=>'required|string|size:64|regex:/^[a-f0-9]+$/','password'=>'required|string|min:10|max:128|confirmed']);$email=mb_strtolower(trim($d['email']));$hash=Hash::make($d['password']);
        $this->s->transaction(function()use($d,$email,$hash){
            $row=$this->s->first('shop_password_resets',['token_hash'=>hash('sha256',$d['token']),'email'=>$email]);$c=$row?$this->s->find('shop_customers',$row['customer_id']):null;
            if(!$row||$row['used_at']!==null||$row['expires_at']->toDateTimeImmutable()<=now()->toDateTimeImmutable()||!$c||!$c['is_active']||$c['auth_version']!==$row['auth_version'])throw ValidationException::withMessages(['token'=>'Liên kết không hợp lệ hoặc đã hết hạn. Vui lòng yêu cầu lại.']);
            $this->s->update('shop_customers',$c,['password_hash'=>$hash,'auth_version'=>$c['auth_version']+1]);$this->s->update('shop_password_resets',$row,['used_at'=>CommerceStore::now()]);
        });
        return ['message'=>'Đã đổi mật khẩu. Các phiên cũ đã bị thu hồi; vui lòng đăng nhập lại.'];
    }
}
