<?php

namespace App\Support;

use App\Infrastructure\Mongo\{Codec, IdCounter, UnitOfWork};
use Brick\Math\BigDecimal;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use MongoDB\Driver\Exception\BulkWriteException;

final class CatalogStore
{
    private const COLLECTIONS = ['product_categories', 'units', 'products', 'customers', 'suppliers', 'product_contents'];

    public function __construct(public readonly IdentityStore $identity) {}

    public function collection(string $name): \MongoDB\Collection
    {
        if (!in_array($name, self::COLLECTIONS, true)) throw new \LogicException('C02_COLLECTION_NOT_ALLOWED');
        return $this->identity->runtime->database->selectCollection($name);
    }

    public function options(): array
    {
        return $this->identity->unitOfWork()?->options() ?? [];
    }

    public function rows(string $name, array $filter = [], array $sort = []): Collection
    {
        return collect($this->collection($name)->find($filter, $this->options() + ($sort ? ['sort' => $sort] : []))->toArray());
    }

    public function find(string $name, string $id): ?array
    {
        return $this->collection($name)->findOne(['_id' => Codec::id($id)], $this->options());
    }

    public function transaction(Closure $work): mixed
    {
        return $this->identity->transaction($work);
    }

    public function insert(string $name, array $data): string
    {
        $unit = $this->identity->unitOfWork() ?? throw new \LogicException('C02_UNIT_REQUIRED');
        if ($name === 'product_contents') throw new \LogicException('Content uses product identity');
        $id = (new IdCounter($unit->database))->next($name);
        $defaults = match ($name) {
            'product_categories' => ['parent_id' => null, 'is_active' => true],
            'units' => ['is_active' => true],
            'products' => ['active_ingredient' => null, 'tax_rate' => null, 'expiry_warning_days' => null, 'is_active' => true],
            'customers', 'suppliers' => ['phone' => null, 'address' => null],
            default => throw new \LogicException('C02_COLLECTION_NOT_ALLOWED'),
        };
        $row = Codec::identity($id) + $this->encode($name, $data) + $defaults;
        $this->references($name, $row, $unit);
        try { $unit->insert($name, $row); }
        catch (BulkWriteException $error) { $this->duplicate($error); }
        $this->deferReferences($name, $id, $unit);
        return $id;
    }

    public function update(string $name, string $id, array $data): void
    {
        $unit = $this->identity->unitOfWork() ?? throw new \LogicException('C02_UNIT_REQUIRED');
        $row = $this->find($name, $id);
        abort_if($row === null, 404);
        $data = $this->encode($name, $data);
        $this->references($name, array_replace($row, $data), $unit);
        try { if ($data) $unit->cas($name, $id, $row['_version'], $data); }
        catch (BulkWriteException $error) { $this->duplicate($error); }
        $this->deferReferences($name, $id, $unit);
    }

    private function encode(string $name, array $data): array
    {
        if (array_intersect(['_id', 'id', 'id_sort', '_version'], array_keys($data))) throw new \LogicException('Identity is controlled internally');
        if (array_key_exists('is_active', $data)) $data['is_active'] = (bool) $data['is_active'];
        if ($name === 'products') {
            foreach (['sale_price' => 18, 'tax_rate' => 5] as $field => $precision) {
                if (isset($data[$field])) $data[$field] = Codec::decimal((string) BigDecimal::of((string) $data[$field])->toScale(2), $precision, 2);
            }
            if (isset($data['expiry_warning_days'])) $data['expiry_warning_days'] = (int) $data['expiry_warning_days'];
        }
        return $data;
    }

    private function duplicate(BulkWriteException $error): never
    {
        if ($error->getCode() === 11000) throw ValidationException::withMessages(['code' => 'The code has already been taken.']);
        throw $error;
    }

    private function references(string $name, array $row, UnitOfWork $unit): void
    {
        $links = match ($name) {
            'product_categories' => ['parent_id' => 'product_categories'],
            'products' => ['category_id' => 'product_categories', 'unit_id' => 'units'],
            'customers', 'suppliers' => ['chain_id' => 'chains'],
            'product_contents' => ['product_id' => 'products'],
            default => [],
        };
        foreach ($links as $field => $collection) {
            if (($row[$field] ?? null) === null) continue;
            if (!$unit->database->selectCollection($collection)->findOne(['_id' => Codec::id($row[$field])], $unit->options())) {
                throw ValidationException::withMessages([$field => 'The selected '.$field.' is invalid.']);
            }
        }
        if ($name === 'product_categories') {
            $seen = [$row['_id'] => true]; $id = $row['parent_id'];
            while ($id !== null) {
                if (isset($seen[$id])) throw ValidationException::withMessages(['parent_id' => 'Nhóm cha tạo thành chu kỳ.']);
                $seen[$id] = true;
                $id = $unit->database->selectCollection($name)->findOne(['_id' => $id], $unit->options())['parent_id'] ?? null;
            }
        }
        if ($name === 'product_contents' && $row['_id'] !== $row['product_id']) throw new \LogicException('Content identity mismatch');
    }

    public function deferReferences(string $name, string $id, UnitOfWork $unit): void
    {
        $unit->defer(function (UnitOfWork $unit) use ($name, $id): void {
            $row = $unit->database->selectCollection($name)->findOne(['_id' => $id], $unit->options());
            if ($row) $this->references($name, $row, $unit);
        });
    }

    public function existsRule(string $name): Closure
    {
        return function ($attribute, $value, $fail) use ($name): void {
            if (!$this->find($name, $value)) $fail('The selected '.$attribute.' is invalid.');
        };
    }

    public function uniqueCode(string $name, ?string $except = null): Closure
    {
        return function ($attribute, $value, $fail) use ($name, $except): void {
            if ($this->rows($name, ['code' => $value, '_id' => ['$ne' => $except]])->isNotEmpty()) $fail('The '.$attribute.' has already been taken.');
        };
    }

    public function present(array $row, ?array $fields = null): array
    {
        $row = Codec::present($row, ['sale_price' => 2, 'tax_rate' => 2]);
        return $fields === null ? $row : array_intersect_key($row, array_flip($fields));
    }
}
