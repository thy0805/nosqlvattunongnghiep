<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Support\InventoryAlertScanner;
use App\Support\OutboxService;

Artisan::command('nosql:check', function (): int {
    $runtime = app(\App\Infrastructure\Mongo\Runtime::class);
    $runtime->assertSchema();
    $connection = DB::connection();
    if (!$connection instanceof \MongoDB\Laravel\Connection || $connection->getDatabaseName() !== config('nosql.database')) {
        throw new \RuntimeException('NOSQL_LARAVEL_CONNECTION_REJECTED');
    }
    $connection->getDatabase()->command(['ping' => 1])->toArray();
    $this->line(json_encode(['database' => config('nosql.database'), 'replica_set' => 'nosql186_rs', 'port' => 27028, 'manifest_sha256' => \App\Infrastructure\Mongo\Schema::hash(), 'integration' => get_class($connection)], JSON_THROW_ON_ERROR));
    return 0;
});

Artisan::command('alerts:scan', function (InventoryAlertScanner $scanner, OutboxService $outbox): void {
    $this->line(json_encode($scanner->run($outbox), JSON_THROW_ON_ERROR));
})->purpose('Quét sự cố tồn thấp và cận hạn, ghi outbox cho thông báo');


Artisan::command('klcn:create-user {username} {role} {branch_id}', function (): int {
    if (DB::connection()->getDatabaseName() !== 'klcn186_dev') {
        $this->error('Lệnh này chỉ được chạy với database klcn186_dev.');

        return 1;
    }

    $username = trim((string) $this->argument('username'));
    $roleCode = (string) $this->argument('role');
    $branchId = filter_var($this->argument('branch_id'), FILTER_VALIDATE_INT);

    if (mb_strlen($username) < 3 || mb_strlen($username) > 80 || $branchId === false || $branchId < 1) {
        $this->error('Tên đăng nhập hoặc mã chi nhánh không hợp lệ.');

        return 1;
    }

    $role = DB::table('roles')->where('code', $roleCode)->first();
    $branch = DB::table('branches')->where('id', $branchId)->first();

    if (! $role || ! $branch || ! in_array($roleCode, ['chain_owner', 'branch_manager', 'sales_staff'], true)) {
        $this->error('Vai trò hoặc chi nhánh không tồn tại.');

        return 1;
    }

    if (DB::table('users')->where('username', $username)->exists()) {
        $this->error('Tên đăng nhập đã tồn tại.');

        return 1;
    }

    $password = $this->secret('Mật khẩu');
    $confirmation = $this->secret('Nhập lại mật khẩu');

    if (! $password || $password !== $confirmation || mb_strlen($password) < 10) {
        $this->error('Mật khẩu phải có ít nhất 10 ký tự và hai lần nhập phải trùng nhau.');

        return 1;
    }

    DB::transaction(function () use ($username, $password, $role, $branchId): void {
        $userId = DB::table('users')->insertGetId([
            'username' => $username,
            'password_hash' => Hash::make($password),
            'is_active' => true,
        ]);

        DB::table('user_role_assignments')->insert([
            'user_id' => $userId,
            'role_id' => $role->id,
            'branch_id' => $branchId,
            'starts_on' => now()->toDateString(),
            'status' => 'active',
        ]);
    });

    $this->info('Đã tạo tài khoản và phân quyền chi nhánh trong klcn186_dev.');

    return 0;
})->purpose('Tạo tài khoản thử nghiệm trong klcn186_dev');
