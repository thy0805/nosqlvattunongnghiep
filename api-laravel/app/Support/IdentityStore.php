<?php

namespace App\Support;

use App\Infrastructure\Mongo\{Codec, IdCounter, Runtime, Transactions, UnitOfWork};
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\Exception\BulkWriteException;

final class IdentityStore
{
    private ?UnitOfWork $unit = null;
    private const COLLECTIONS = ['users', 'roles', 'chains', 'branches', 'warehouses', 'user_role_assignments', 'personal_access_tokens'];

    public function __construct(public readonly Runtime $runtime) {}

    private function collection(string $name): \MongoDB\Collection
    {
        if (!in_array($name, self::COLLECTIONS, true)) throw new \LogicException('C01_COLLECTION_NOT_ALLOWED');
        return $this->runtime->database->selectCollection($name);
    }

    public function rows(string $name, array $filter = [], array $sort = []): Collection
    {
        return collect($this->collection($name)->find($filter, ($this->unit?->options() ?? []) + ($sort ? ['sort' => $sort] : []))->toArray());
    }

    public function find(string $name, string $id): ?array
    {
        return $this->collection($name)->findOne(['_id' => Codec::id($id)], $this->unit?->options() ?? []);
    }

    public function transaction(Closure $work): mixed
    {
        if ($this->unit) return $work();
        return (new Transactions($this->runtime))->run(function (UnitOfWork $unit) use ($work) {
            $this->unit = $unit;
            try { return $work(); } finally { $this->unit = null; }
        });
    }

    public function unitOfWork(): ?UnitOfWork
    {
        return $this->unit;
    }

    public function insert(string $name, array $data): string
    {
        if (!$this->unit) throw new \LogicException('C01_UNIT_REQUIRED');
        $id = (new IdCounter($this->runtime->database))->next($name);
        $defaults = match ($name) {
            'users' => ['is_active' => true, 'is_catalog_admin' => false, 'created_at' => new UTCDateTime($this->unit->clock)],
            'chains' => ['is_active' => true],
            'branches' => ['address' => null, 'default_sales_warehouse_id' => null, 'is_active' => false],
            default => [],
        };
        $document = Codec::identity($id) + $data + $defaults;
        $this->references($name, $document);
        try { $this->unit->insert($name, $document); }
        catch (BulkWriteException $error) {
            if ($error->getCode() === 11000 && $name === 'users') throw ValidationException::withMessages(['username' => 'The username has already been taken.']);
            throw $error;
        }
        $this->deferReferences($name, $id);
        return $id;
    }

    public function update(string $name, string $id, array $data): void
    {
        if (!$this->unit) throw new \LogicException('C01_UNIT_REQUIRED');
        $row = $this->find($name, $id);
        abort_if($row === null, 404);
        $this->references($name, array_replace($row, $data));
        if ($data) $this->unit->cas($name, $id, $row['_version'], $data);
        $this->deferReferences($name, $id);
    }

    public function deleteToken(string $id): void
    {
        $this->transaction(fn () => $this->collection('personal_access_tokens')->deleteOne(['_id' => Codec::id($id)], $this->unit->options()));
    }

    private function deferReferences(string $name, string $id): void
    {
        if (!in_array($name, ['branches', 'warehouses', 'user_role_assignments', 'personal_access_tokens'], true)) return;
        $this->unit->defer(function (UnitOfWork $unit) use ($name, $id): void {
            $row = $unit->database->selectCollection($name)->findOne(['_id' => $id], $unit->options());
            if ($row) $this->references($name, $row, $unit);
        });
    }

    private function references(string $name, array $row, ?UnitOfWork $unit = null): void
    {
        $find = fn ($collection, $id) => $unit
            ? $unit->database->selectCollection($collection)->findOne(['_id' => $id], $unit->options())
            : $this->find($collection, $id);
        $links = match ($name) {
            'branches' => ['chain_id' => 'chains', 'default_sales_warehouse_id' => 'warehouses'],
            'warehouses' => ['branch_id' => 'branches'],
            'user_role_assignments' => ['user_id' => 'users', 'role_id' => 'roles', 'branch_id' => 'branches'],
            'personal_access_tokens' => ['tokenable_id' => 'users'],
            default => [],
        };
        foreach ($links as $field => $collection) {
            if (($row[$field] ?? null) === null) continue;
            if (!$find($collection, $row[$field])) throw ValidationException::withMessages([$field => 'The selected '.$field.' is invalid.']);
        }
        if ($name === 'branches' && $row['default_sales_warehouse_id'] !== null
            && $find('warehouses', $row['default_sales_warehouse_id'])['branch_id'] !== $row['_id']) {
            throw ValidationException::withMessages(['default_sales_warehouse_id' => 'Kho mặc định phải thuộc chi nhánh này.']);
        }
    }

    public function assignments(string $userId, bool $effective = false): Collection
    {
        $filter = ['user_id' => $userId];
        if ($effective) $filter += ['status' => 'active', 'starts_on' => ['$lte' => now('Asia/Ho_Chi_Minh')->toDateString()]];
        return $this->joinedAssignments($filter);
    }

    public function joinedAssignments(array $filter = []): Collection
    {
        return $this->rows('user_role_assignments', $filter)->map(function ($row) {
            $role = $this->find('roles', $row['role_id']);
            $branch = $row['branch_id'] === null ? null : $this->find('branches', $row['branch_id']);
            if (!$role || !$branch) return null;
            return $row + ['role_code' => $role['code'], 'branch_name' => $branch['name'], 'chain_id' => $branch['chain_id']];
        })->filter()->values();
    }

    public function chains(string $userId, array $roles): Collection
    {
        return $this->assignments($userId, true)->whereIn('role_code', $roles)->pluck('chain_id')->unique()->values();
    }

    public function roleId(string $code): string
    {
        $role = $this->rows('roles', ['code' => $code])->first();
        abort_if(!$role, 500);
        return $role['_id'];
    }

    public function otherOwner(string $chainId, ?string $excludedUser = null, ?string $excludedAssignment = null): bool
    {
        return $this->joinedAssignments(['status' => 'active', 'starts_on' => ['$lte' => now('Asia/Ho_Chi_Minh')->toDateString()]])
            ->contains(function ($row) use ($chainId, $excludedUser, $excludedAssignment) {
                return $row['chain_id'] === $chainId && $row['role_code'] === 'chain_owner'
                    && $row['user_id'] !== $excludedUser && $row['_id'] !== $excludedAssignment
                    && ($this->find('users', $row['user_id'])['is_active'] ?? false);
            });
    }

    public function guardLastOwner(string $userId, array $chainIds, ?string $assignmentId = null): void
    {
        foreach ($chainIds as $chainId) {
            $has = $this->assignments($userId)->contains(fn ($a) => $a['status'] === 'active' && $a['role_code'] === 'chain_owner'
                && $a['chain_id'] === $chainId && ($assignmentId === null || $a['_id'] === $assignmentId));
            if ($has && !$this->otherOwner($chainId, $assignmentId === null ? $userId : null, $assignmentId)) {
                throw ValidationException::withMessages(['role_code' => 'Chuỗi phải còn ít nhất một Chủ chuỗi đang hoạt động.']);
            }
        }
    }

    public function publicRow(array $row, ?array $fields = null): array
    {
        $row = Codec::present($row);
        return $fields === null ? $row : array_intersect_key($row, array_flip($fields));
    }

    public function existsRule(string $collection): Closure
    {
        return function ($attribute, $value, $fail) use ($collection): void {
            if (!$this->find($collection, $value)) $fail('The selected '.$attribute.' is invalid.');
        };
    }
}
