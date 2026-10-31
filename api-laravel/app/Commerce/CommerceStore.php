<?php

namespace App\Commerce;

use App\Infrastructure\Mongo\{Codec,IdCounter};
use App\Support\IdentityStore;
use Brick\Math\{BigDecimal,RoundingMode};
use MongoDB\BSON\{Decimal128,Int64,UTCDateTime};
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class CommerceStore
{
    public function __construct(public readonly IdentityStore $identity) {}
    public function collection(string $name): \MongoDB\Collection
    {
        if (!isset(CommerceSchema::specs()[$name])) throw new \LogicException('COMMERCE_COLLECTION_REJECTED');
        return $this->identity->runtime->database->selectCollection($name);
    }
    public function options(): array { return $this->identity->unitOfWork()?->options()??[]; }
    public function transaction(\Closure $work): mixed { return $this->identity->transaction($work); }
    public function rows(string $name,array $filter=[],array $sort=['id_sort'=>-1],int $limit=1000): array
    {
        return $this->collection($name)->find($filter,$this->options()+['sort'=>$sort,'limit'=>$limit])->toArray();
    }
    public function find(string $name,string $id): ?array { return $this->collection($name)->findOne(['_id'=>Codec::id($id)],$this->options()); }
    public function first(string $name,array $filter): ?array { return $this->collection($name)->findOne($filter,$this->options()); }
    public function insert(string $name,array $data): array
    {
        $u=$this->identity->unitOfWork()??throw new \LogicException('COMMERCE_TRANSACTION_REQUIRED');
        $id=(new IdCounter($u->database))->next($name);$now=new UTCDateTime($u->clock);
        $row=Codec::identity($id)+$data+['created_at'=>$now,'updated_at'=>$now];
        $u->insert($name,$row);return $row;
    }
    public function update(string $name,array $row,array $set): array
    {
        $u=$this->identity->unitOfWork()??throw new \LogicException('COMMERCE_TRANSACTION_REQUIRED');
        $set['updated_at']=new UTCDateTime($u->clock);
        $u->cas($name,$row['_id'],$row['_version'],$set);
        return $this->find($name,$row['_id']);
    }
    public function customer(Request $request,bool $required=true): ?array
    {
        $id=$request->session()->get('shop_customer_id');
        $row=is_string($id)?$this->find('shop_customers',$id):null;
        if ($row && (!$row['is_active'] || ($row['auth_version']??0)!==$request->session()->get('shop_auth_version'))) $row=null;
        abort_if($required&&!$row,401,'Đăng nhập tài khoản khách hàng để tiếp tục.');return $row;
    }
    public function admin(Request $request,string $chainId,?string $branchId=null,bool $ownerOnly=false): \App\Models\User
    {
        $user=$request->user();abort_unless($user instanceof \App\Models\User,401);
        $fresh=$this->identity->find('users',(string)$user->id);abort_unless($fresh&&$fresh['is_active'],403);
        $ok=$this->identity->assignments((string)$user->id,true)->contains(fn($a)=>$a['chain_id']===$chainId&&($a['role_code']==='chain_owner'||(!$ownerOnly&&$branchId!==null&&$a['branch_id']===$branchId&&in_array($a['role_code'],['branch_manager','sales_staff'],true))));
        abort_unless($ok,403,'Không có quyền với chuỗi/chi nhánh này.');return $user;
    }
    public function settings(string $chainId): array
    {
        $chain=$this->identity->find('chains',$chainId);abort_unless($chain&&$chain['is_active'],404);
        return $this->first('shop_settings',['chain_id'=>$chainId,'is_open'=>true])??abort(404,'Cửa hàng chưa mở.');
    }
    public static function money(mixed $s): BigDecimal
    {
        if (!is_string($s)||!preg_match('/^[0-9]{1,16}(?:\.[0-9]{1,2})?$/D',$s)) throw ValidationException::withMessages(['amount'=>'Số tiền phải là chuỗi thập phân không âm, tối đa 2 chữ số lẻ.']);
        return BigDecimal::of($s)->toScale(2);
    }
    public static function quantity(mixed $s): BigDecimal
    {
        if (!is_string($s)||!preg_match('/^[0-9]{1,12}(?:\.[0-9]{1,3})?$/D',$s)||BigDecimal::of($s)->isLessThanOrEqualTo(0)) throw ValidationException::withMessages(['quantity'=>'Số lượng phải dương và tối đa 3 chữ số lẻ.']);
        return BigDecimal::of($s)->toScale(3);
    }
    public static function decimal(BigDecimal|string $s,int $scale=2): Decimal128 { return Codec::decimal((string)BigDecimal::of((string)$s)->toScale($scale,RoundingMode::HalfUp),18,$scale); }
    public static function present(mixed $v): mixed
    {
        if ($v instanceof Decimal128||$v instanceof Int64) return (string)$v;
        if ($v instanceof UTCDateTime) return $v->toDateTimeImmutable()->format('Y-m-d\TH:i:s.v\Z');
        if (!is_array($v)) return $v;
        $out=[];foreach ($v as $key=>$value) {if(in_array($key,['id_sort','_version','password_hash','token_hash','auth_version','reset_hash'],true))continue;$out[$key==='_id'?'id':$key]=self::present($value);}return $out;
    }
    public static function now(): UTCDateTime { return new UTCDateTime(now()); }
    public static function fingerprint(array $data): string { return hash('sha256',json_encode($data,JSON_THROW_ON_ERROR)); }
    public function notify(string $customerId,string $chainId,string $eventKey,string $title,string $href): void
    {
        if (!$this->first('shop_notifications',['customer_id'=>$customerId,'event_key'=>$eventKey])) $this->insert('shop_notifications',['customer_id'=>$customerId,'chain_id'=>$chainId,'event_key'=>$eventKey,'title'=>$title,'href'=>$href,'read_at'=>null]);
    }
}
