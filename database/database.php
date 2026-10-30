<?php
declare(strict_types=1);

use App\Infrastructure\Mongo\{Codec, Runtime, Schema};
use App\Support\IdentityStore;
use MongoDB\Client;

$root = dirname(__DIR__);
$state = $root.'/state';
$privatePath = $state.'/install.private.json';
$mode = $argv[1] ?? '';
require $root.'/api-laravel/vendor/autoload.php';
function saveJson(string $path, mixed $value): void {
    if (file_put_contents($path, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) === false) throw new RuntimeException('WRITE_FAILED');
}
function readPrivate(): array {
    global $root, $privatePath;
    $p = json_decode(file_get_contents($privatePath), true, flags: JSON_THROW_ON_ERROR);
    if ($p['root'] !== $root || $p['database'] !== 'nosql186_web_test_c00_20261016_f72e93' || !preg_match('/^[a-f0-9]{32}$/D',$p['install_id'])) throw new RuntimeException('INSTALL_TARGET_REJECTED');
    return $p;
}
function clientFor(array $p, string $user = 'root', bool $direct = false): Client {
    $db = $user === 'root' ? 'admin' : $p['database'];
    $username = $user === 'root' ? $p['root_user'] : $user;
    $password = $user === 'root' ? $p['root_password'] : $p['accounts'][$user];
    $uri = 'mongodb://'.rawurlencode($username).':'.rawurlencode($password).'@localhost:27028/'.$db.'?authSource='.$db.'&'.($direct ? 'directConnection=true' : 'replicaSet=nosql186_rs');
    return new Client($uri, ['serverSelectionTimeoutMS'=>2000, 'connectTimeoutMS'=>2000], ['typeMap'=>['root'=>'array','document'=>'array','array'=>'array']]);
}
function topology(Client $client): void {
    $h = $client->selectDatabase('admin')->command(['hello'=>1])->toArray()[0];
    if (empty($h['isWritablePrimary']) || ($h['setName'] ?? '') !== 'nosql186_rs' || ($h['hosts'] ?? []) !== ['localhost:27028']) throw new RuntimeException('TOPOLOGY_REJECTED');
}
function bootApplication(): mixed {
    global $root;
    $app = require $root.'/api-laravel/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    return $app;
}
function checkSchema(array $p): array {
    $rootClient = clientFor($p);
    topology($rootClient);
    $db = $rootClient->selectDatabase($p['database']);
    $read = Schema::readback($db);
    $manifest = Schema::manifest();
    $indexes = $validators = 0;
    foreach ($manifest['collections'] as $name=>$spec) {
        $options = $read[$name]['options'];
        if (($options['validator'] ?? null) != $spec['validator'] || ($options['validationLevel'] ?? 'strict') !== $spec['validationLevel'] || ($options['validationAction'] ?? 'error') !== $spec['validationAction']) throw new RuntimeException('VALIDATOR_MISMATCH');
        $validators++;
        $actual = [];
        foreach ($read[$name]['indexes'] as $index) { $actual[$index['name']] = $index; $indexes++; }
        if (count($actual) !== count($spec['indexes'])+1) throw new RuntimeException('INDEX_COUNT_MISMATCH');
        foreach ($spec['indexes'] as $expected) {
            $got = $actual[$expected['name']] ?? null;
            foreach ($expected as $key=>$value) {
                $observed = $key === 'collation' ? ($got[$key] ?? $options['collation'] ?? ['locale'=>'simple']) : ($got[$key] ?? null);
                if ($observed != $value) throw new RuntimeException('INDEX_OPTIONS_MISMATCH');
            }
        }
    }
    if (count($read) !== count($manifest['collections']) || $validators !== count($manifest['collections']) || $indexes !== array_sum(array_map(fn($spec)=>count($spec['indexes'])+1,$manifest['collections']))) throw new RuntimeException('SCHEMA_COUNTS_REJECTED');
    foreach (array_keys($p['accounts']) as $user) {
        $roles = $db->command(['rolesInfo'=>$user.'_role', 'showPrivileges'=>true])->toArray()[0]['roles'];
        foreach ($roles as $role) foreach ($role['privileges'] as $grant) if (($grant['resource']['db'] ?? '') !== $p['database']) throw new RuntimeException('CROSS_DB_GRANT_REJECTED');
    }
    $app = bootApplication();
    $runtime = $app->make(Runtime::class);
    $runtime->assertSchema();
    $counts = [];
    foreach (array_keys($manifest['collections']) as $collection) $counts[$collection] = $db->selectCollection($collection)->countDocuments([]);
    return ['ok'=>true, 'database'=>$p['database'], 'replica_set'=>'nosql186_rs', 'port'=>27028, 'collections'=>count($read), 'validators'=>$validators, 'indexes'=>$indexes, 'manifest_sha256'=>Schema::hash(), 'counts'=>$counts, 'php_binary'=>PHP_BINARY, 'mongodb_extension'=>phpversion('mongodb')];
}
try {
    if ($mode === 'prepare') {
        if (is_file($privatePath) || is_file($root.'/api-laravel/.env') || count(glob($state.'/data/*'))) throw new RuntimeException('EXISTING_STATE_REJECTED');
        $p = ['root'=>$root,'install_id'=>bin2hex(random_bytes(16)), 'database'=>'nosql186_web_test_c00_20261016_f72e93', 'root_user'=>'offline_bootstrap', 'root_password'=>bin2hex(random_bytes(32)), 'accounts'=>[], 'demo_accounts'=>[]];
        foreach (['nosql186_app','nosql186_worker','nosql186_schema'] as $name) $p['accounts'][$name] = bin2hex(random_bytes(32));
        foreach (['owner','manager','staff'] as $name) $p['demo_accounts'][$name] = ['username'=>$name, 'password'=>'123'];
        saveJson($privatePath, $p);
        file_put_contents($state.'/replica.key', base64_encode(random_bytes(64)));
        $f = fn(string $path): string => str_replace('\\','/',$path);
        $config = 'storage:'."\n".'  dbPath: '.json_encode($f($state.'/data'))."\n".'  wiredTiger:'."\n".'    engineConfig:'."\n".'      cacheSizeGB: 0.25'."\n".'systemLog:'."\n".'  destination: file'."\n".'  path: '.json_encode($f($state.'/logs/mongod.log'))."\n".'  logAppend: true'."\n".'net:'."\n".'  bindIp: 127.0.0.1'."\n".'  port: 27028'."\n".'replication:'."\n".'  replSetName: nosql186_rs'."\n".'security:'."\n".'  authorization: enabled'."\n".'  keyFile: '.json_encode($f($state.'/replica.key'))."\n";
        file_put_contents($state.'/mongod.cfg', $config);
        $uri = 'mongodb://nosql186_app:'.$p['accounts']['nosql186_app'].'@localhost:27028/'.$p['database'].'?authSource='.$p['database'].'&replicaSet=nosql186_rs&readPreference=primary&retryWrites=true';
        $env = file_get_contents($root.'/api-laravel/.env.example');
        $env = preg_replace('/^MONGODB_DATABASE=.*$/m', 'MONGODB_DATABASE='.$p['database'], $env);
        $env = preg_replace('/^MONGODB_URI=.*$/m', 'MONGODB_URI='.$uri, $env);
        $env = preg_replace('/^APP_KEY=.*$/m', 'APP_KEY=base64:'.base64_encode(random_bytes(32)), $env);
        file_put_contents($root.'/api-laravel/.env', $env);
        $workerUri = str_replace('nosql186_app:'.$p['accounts']['nosql186_app'], 'nosql186_worker:'.$p['accounts']['nosql186_worker'], $uri);
        file_put_contents($state.'/worker.private.env', 'MONGODB_URI='.$workerUri."\nMONGODB_DATABASE=".$p['database']."\nMONGODB_REPLICA_SET=nosql186_rs\n");
        $text = "TÀI KHOẢN TRÌNH DIỄN CỤC BỘ\r\nhttp://nosql.localhost:3310/login\r\nKHÔNG gửi tệp này hoặc thư mục state cho người khác.\r\n\r\n";
        foreach ($p['demo_accounts'] as $name=>$account) $text .= $name.': '.$account['username'].' / '.$account['password']."\r\n";
        file_put_contents($state.'/DEMO_ACCOUNTS.private.txt', $text);
        echo '{"ok":true,"prepared":true}';
        exit;
    }
    $p = readPrivate();
    if ($mode === 'provision') {
        if (is_file($state.'/provision-complete.json')) throw new RuntimeException('NO_SCHEMA_REAPPLY');
        $bootstrap = new Client('mongodb://localhost:27028/?directConnection=true', ['serverSelectionTimeoutMS'=>2000], ['typeMap'=>['root'=>'array','document'=>'array','array'=>'array']]);
        $admin = $bootstrap->selectDatabase('admin');
        $until = microtime(true)+45;
        do {
            try { $admin->command(['ping'=>1])->toArray(); break; } catch (Throwable) { usleep(250000); }
        } while (microtime(true)<$until);
        $admin->command(['replSetInitiate'=>['_id'=>'nosql186_rs','members'=>[['_id'=>0,'host'=>'localhost:27028']]]])->toArray();
        do {
            try { topology($bootstrap); break; } catch (Throwable) { usleep(250000); }
        } while (microtime(true)<$until);
        topology($bootstrap);
        $admin->command(['createUser'=>$p['root_user'],'pwd'=>$p['root_password'],'roles'=>[['role'=>'root','db'=>'admin']]])->toArray();
        $rootClient = clientFor($p);
        topology($rootClient);
        $db = $rootClient->selectDatabase($p['database']);
        if (iterator_count($db->listCollections()) !== 0) throw new RuntimeException('EXISTING_DATABASE_REJECTED');
        $manifest = Schema::manifest();
        $privileges = ['nosql186_app'=>[], 'nosql186_worker'=>[], 'nosql186_schema'=>[['resource'=>['db'=>$p['database'],'collection'=>''],'actions'=>['find','insert','update','remove','createCollection','collMod','createIndex','listCollections','listIndexes']]]];
        foreach ($manifest['collections'] as $name=>$spec) {
            $actions = ['find','insert','update'];
            if (in_array($name,['personal_access_tokens','purchase_receipt_items','sales_order_items','stock_transfer_items','product_contents'],true)) $actions[]='remove';
            if (in_array($name,['audit_logs','dashboard_snapshots'],true)) $actions=['find'];
            $privileges['nosql186_app'][]=['resource'=>['db'=>$p['database'],'collection'=>$name],'actions'=>$actions];
            $actions=in_array($name,['outbox_events','coordination_guards','notifications','audit_logs','dashboard_snapshots'],true)?['find','insert','update']:['find'];
            $privileges['nosql186_worker'][]=['resource'=>['db'=>$p['database'],'collection'=>$name],'actions'=>$actions];
        }
        foreach ($privileges as $user=>$grants) {
            $db->command(['createRole'=>$user.'_role','privileges'=>$grants,'roles'=>[]])->toArray();
            $db->command(['createUser'=>$user,'pwd'=>$p['accounts'][$user],'roles'=>[['role'=>$user.'_role','db'=>$p['database']]]])->toArray();
        }
        $read = Schema::apply(clientFor($p,'nosql186_schema')->selectDatabase($p['database']));
        saveJson($state.'/schema-readback.json', $read);
        saveJson($state.'/provision-complete.json', ['ok'=>true,'database'=>$p['database'],'manifest_sha256'=>Schema::hash()]);
        echo '{"ok":true,"provisioned":true}';
        exit;
    }
    if ($mode === 'commerce-upgrade') {
        $rootClient=clientFor($p);topology($rootClient);$db=$rootClient->selectDatabase($p['database']);
        $specs=\App\Commerce\CommerceSchema::specs();
        $marker=$db->selectCollection('coordination_guards')->findOne(['_id'=>'commerce:schema:v1']);
        if (!$marker) {
            $roles=$db->command(['rolesInfo'=>'nosql186_app_role','showPrivileges'=>true])->toArray()[0]['roles'];
            if (count($roles)!==1) throw new RuntimeException('COMMERCE_ROLE_REJECTED');
            $grants=$roles[0]['privileges'];
            foreach ($grants as $grant) if (($grant['resource']['db']??'')!==$p['database']) throw new RuntimeException('CROSS_DB_GRANT_REJECTED');
            $resources=array_column(array_column($grants,'resource'),'collection');
            foreach ($specs as $name=>$spec) if (!in_array($name,$resources,true)) $grants[]=['resource'=>['db'=>$p['database'],'collection'=>$name],'actions'=>['find','insert','update']];
            \App\Commerce\CommerceSchema::apply($db);
            $db->command(['updateRole'=>'nosql186_app_role','privileges'=>$grants])->toArray();
            $db->selectCollection('coordination_guards')->insertOne(['_id'=>'commerce:schema:v1','version'=>new \MongoDB\BSON\Int64('1')]);
        }
        $versionKey='commerce:schema:v'.\App\Commerce\CommerceSchema::VERSION;
        if(!$db->selectCollection('coordination_guards')->findOne(['_id'=>$versionKey])){
            $roles=$db->command(['rolesInfo'=>'nosql186_app_role','showPrivileges'=>true])->toArray()[0]['roles'];
            if(count($roles)!==1)throw new RuntimeException('COMMERCE_ROLE_REJECTED');
            $grants=$roles[0]['privileges'];$resources=array_column(array_column($grants,'resource'),'collection');
            foreach($grants as $grant)if(($grant['resource']['db']??'')!==$p['database'])throw new RuntimeException('CROSS_DB_GRANT_REJECTED');
            foreach($specs as $name=>$spec)if(!in_array($name,$resources,true))$grants[]=['resource'=>['db'=>$p['database'],'collection'=>$name],'actions'=>['find','insert','update']];
            \App\Commerce\CommerceSchema::apply($db);
            $db->command(['updateRole'=>'nosql186_app_role','privileges'=>$grants])->toArray();
            $db->selectCollection('coordination_guards')->insertOne(['_id'=>$versionKey,'version'=>new \MongoDB\BSON\Int64('1')]);
        }
        $invoiceMarker=$db->selectCollection('coordination_guards')->findOne(['_id'=>'commerce:invoice_shipping:v1']);
        if (!$invoiceMarker) {
            $invoiceSpec=Schema::manifest()['collections']['invoices'];
            $db->command(['collMod'=>'invoices','validator'=>$invoiceSpec['validator'],'validationLevel'=>'strict','validationAction'=>'error'])->toArray();
            $db->selectCollection('coordination_guards')->insertOne(['_id'=>'commerce:invoice_shipping:v1','version'=>new \MongoDB\BSON\Int64('1')]);
        }
        $indexReadback=[];
        foreach($specs as $name=>$spec){$actual=[];foreach($db->selectCollection($name)->listIndexes() as $index)$actual[$index->getName()]=$index->__debugInfo();foreach($spec['indexes'] as $expected){$got=$actual[$expected['name']]??[];foreach($expected as $key=>$value)if(($got[$key]??null)!=$value)throw new RuntimeException('COMMERCE_INDEX_MISMATCH');}$indexReadback[$name]=count($actual);}
        echo json_encode(['ok'=>true,'commerce_schema'=>\App\Commerce\CommerceSchema::VERSION,'collections'=>count($specs),'index_counts'=>$indexReadback],JSON_THROW_ON_ERROR);exit;
    }
    if ($mode === 'bootstrap-demo') {
        if (is_file($state.'/demo-bootstrap.json')) throw new RuntimeException('NO_DEMO_REPLAY');
        $app = bootApplication();
        $store = $app->make(IdentityStore::class);
        foreach (array_keys(Schema::manifest()['collections']) as $name) if (!in_array($name,['id_counters','coordination_guards'],true) && $store->runtime->database->selectCollection($name)->countDocuments([])!==0) throw new RuntimeException('DOMAIN_NOT_EMPTY');
        $hash = Illuminate\Support\Facades\Hash::make($p['demo_accounts']['owner']['password']);
        $ids = $store->transaction(function () use ($store,$p,$hash): array {
            $roles=[];
            foreach (['chain_owner'=>'Chủ chuỗi','branch_manager'=>'Quản lý chi nhánh','sales_staff'=>'Nhân viên bán hàng'] as $code=>$name) $roles[$code]=$store->insert('roles',compact('code','name'));
            $chain=$store->insert('chains',['name'=>'Agrovia — Vụ Xanh']);
            $branch=$store->insert('branches',['chain_id'=>$chain,'code'=>'CN-TT','name'=>'Chi nhánh Trung tâm']);
            $warehouse=$store->insert('warehouses',['branch_id'=>$branch,'code'=>'KHO-TT','name'=>'Kho Trung tâm','warehouse_type'=>'sales']);
            $store->update('branches',$branch,['default_sales_warehouse_id'=>$warehouse,'is_active'=>true]);
            $user=$store->insert('users',['username'=>$p['demo_accounts']['owner']['username'],'password_hash'=>$hash,'is_catalog_admin'=>true]);
            $assignment=$store->insert('user_role_assignments',['user_id'=>$user,'role_id'=>$roles['chain_owner'],'branch_id'=>$branch,'starts_on'=>'2020-01-01','status'=>'active']);
            return compact('roles','chain','branch','warehouse','user','assignment');
        });
        saveJson($state.'/demo-bootstrap.json',['ok'=>true,'demoRunId'=>$p['install_id'],'ids'=>$ids,'source'=>'Synthetic IAM via accepted IdentityStore transaction; all subsequent business via Laravel HTTP API']);
        echo '{"ok":true,"bootstrap_demo":true}';
        exit;
    }
    if ($mode === 'bootstrap-demo-users') {
        if (is_file($state.'/setup-complete.json') || is_file($state.'/demo-users-complete.json')) throw new RuntimeException('NO_ACCOUNT_REPLAY');
        $marker=json_decode(file_get_contents($state.'/demo-started.json'),true,flags:JSON_THROW_ON_ERROR);
        $bootstrap=json_decode(file_get_contents($state.'/demo-bootstrap.json'),true,flags:JSON_THROW_ON_ERROR);
        if (($marker['demoRunId'] ?? null)!==$p['install_id'] || ($marker['stage'] ?? null)!=='in_progress' || ($bootstrap['demoRunId'] ?? null)!==$p['install_id']) throw new RuntimeException('BOOTSTRAP_SESSION_REJECTED');
        foreach (['owner','manager','staff'] as $name) if ($p['demo_accounts'][$name]!==['username'=>$name,'password'=>'123']) throw new RuntimeException('BOOTSTRAP_ACCOUNTS_REJECTED');
        $managerBranch=Codec::id($argv[2] ?? '');
        $staffBranch=Codec::id($argv[3] ?? '');
        if ($staffBranch!==$bootstrap['ids']['branch'] || $managerBranch===$staffBranch) throw new RuntimeException('BOOTSTRAP_BRANCH_REJECTED');
        $app=bootApplication();
        $store=$app->make(IdentityStore::class);
        $hashes=[];
        foreach (['manager','staff'] as $name) $hashes[$name]=Illuminate\Support\Facades\Hash::make($p['demo_accounts'][$name]['password']);
        $ids=$store->transaction(function () use ($store,$bootstrap,$managerBranch,$staffBranch,$hashes): array {
            if ($store->rows('users')->count()!==1 || $store->rows('user_role_assignments')->count()!==1) throw new RuntimeException('BOOTSTRAP_NOT_EMPTY');
            $owner=$store->find('users',$bootstrap['ids']['user']);
            if (($owner['username'] ?? null)!=='owner' || !$owner['is_active'] || !$owner['is_catalog_admin']) throw new RuntimeException('BOOTSTRAP_OWNER_REJECTED');
            $ids=[];
            foreach (['manager'=>['branch_manager',$managerBranch],'staff'=>['sales_staff',$staffBranch]] as $name=>[$role,$branchId]) {
                $branch=$store->find('branches',$branchId);
                if (($branch['chain_id'] ?? null)!==$bootstrap['ids']['chain'] || !$branch['is_active']) throw new RuntimeException('BOOTSTRAP_SCOPE_REJECTED');
                $id=$store->insert('users',['username'=>$name,'password_hash'=>$hashes[$name]]);
                $store->insert('user_role_assignments',['user_id'=>$id,'role_id'=>$store->roleId($role),'branch_id'=>$branchId,'starts_on'=>now('Asia/Ho_Chi_Minh')->toDateString(),'status'=>'active']);
                $ids[$name.'User']=$id;
            }
            return $ids;
        });
        saveJson($state.'/demo-users-complete.json',['ok'=>true,'demoRunId'=>$p['install_id'],'ids'=>$ids]);
        echo json_encode(['ok'=>true,'ids'=>$ids],JSON_THROW_ON_ERROR);
        exit;
    }
    if ($mode === 'check') {
        $until=microtime(true)+30;
        do {
            try { $result=checkSchema($p); echo json_encode($result,JSON_THROW_ON_ERROR); exit; }
            catch (MongoDB\Driver\Exception\ConnectionException|RuntimeException $e) { if(microtime(true)>=$until) throw $e; usleep(400000); }
        } while (true);
    }
    if ($mode === 'readback') {
        $app=bootApplication();
        $runtime=$app->make(Runtime::class);
        $result=[];
        foreach(array_keys(Schema::manifest()['collections']) as $name) {
            $result[$name]=[];
            foreach($runtime->database->selectCollection($name)->find([]) as $row) {
                $row=App\Infrastructure\Mongo\Codec::present($row);
                unset($row['password_hash'],$row['token']);
                $result[$name][]=$row;
            }
        }
        echo json_encode(['ok'=>true,'database'=>$p['database'],'data'=>$result],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($mode === 'shutdown') {
        $client=clientFor($p);
        topology($client);
        try { $client->selectDatabase('admin')->command(['shutdown'=>1])->toArray(); } catch (MongoDB\Driver\Exception\ConnectionException) {}
        echo '{"ok":true,"shutdown_requested":true}';
        exit;
    }
    throw new RuntimeException('UNKNOWN_HELPER_MODE');
} catch (Throwable $e) {
    file_put_contents($state.'/logs/helper-errors.log',date(DATE_ATOM).' '.$mode.' '.get_class($e).' code='.$e->getCode().' label='.($e instanceof RuntimeException?$e->getMessage():'redacted')."\n",FILE_APPEND);
    fwrite(STDERR,"Offline helper failed; inspect state/logs/helper-errors.log. No automatic POST retry or reset.\n");
    exit(1);
}
