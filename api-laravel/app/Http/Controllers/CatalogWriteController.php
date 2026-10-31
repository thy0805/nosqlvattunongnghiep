<?php

namespace App\Http\Controllers;

use App\Support\CatalogPermission;
use App\Support\DecimalId;
use App\Support\DecimalLimit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Support\CatalogStore;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class CatalogWriteController extends Controller
{
    public function __construct(private readonly CatalogStore $store) {}

    public function createCategory(Request $request, CatalogPermission $permission): JsonResponse
    {
        return $this->store->transaction(function () use ($request, $permission) {
            $this->authorizeManager($request, $permission);
            $data = $request->validate([
                'code' => ['required', 'string', 'max:40', $this->store->uniqueCode('product_categories')],
                'name' => ['required', 'string', 'max:160'],
                'parent_id' => ['bail', 'nullable', 'string', DecimalId::rule(), $this->store->existsRule('product_categories')],
                'is_active' => ['sometimes', 'boolean'],
            ]);
            $this->requireActive('product_categories', $data['parent_id'] ?? null, 'parent_id');
            $id = $this->store->insert('product_categories', [
                'code' => trim($data['code']),
                'name' => trim($data['name']),
                'parent_id' => $data['parent_id'] ?? null,
                'is_active' => $data['is_active'] ?? true,
            ]);

            return response()->json(['data' => $this->store->present($this->store->find('product_categories', $id))], 201);
        });
    }

    public function updateCategory(Request $request, CatalogPermission $permission, string $id): JsonResponse
    {
        return $this->store->transaction(function () use ($request, $permission, $id) {
            $this->authorizeManager($request, $permission);
            $data = $request->validate([
                'code' => ['sometimes', 'required', 'string', 'max:40', $this->store->uniqueCode('product_categories', $id)],
                'name' => ['sometimes', 'required', 'string', 'max:160'],
                'parent_id' => ['bail', 'sometimes', 'nullable', 'string', DecimalId::rule(), $this->store->existsRule('product_categories')],
                'is_active' => ['sometimes', 'boolean'],
            ]);

            $category = $this->store->transaction(function () use ($id, $data) {
                $categories = $this->store->rows('product_categories')->map(fn ($row) => (object) $this->store->present($row))->keyBy('id');
                $current = $categories->get($id);
                abort_if($current === null, 404);
                $parentId = array_key_exists('parent_id', $data) ? $data['parent_id'] : $current->parent_id;
                $active = array_key_exists('is_active', $data) ? (bool) $data['is_active'] : (bool) $current->is_active;
                $parent = $parentId === null ? null : $categories->get($parentId);

                if ($parentId !== null && ($parent === null || ($active && ! $parent->is_active))) {
                    throw ValidationException::withMessages(['parent_id' => 'Nhóm cha phải còn hoạt động.']);
                }

                $seen = [$id => true];
                while ($parentId !== null) {
                    if (isset($seen[$parentId])) {
                        throw ValidationException::withMessages(['parent_id' => 'Nhóm cha tạo thành chu kỳ.']);
                    }
                    $seen[$parentId] = true;
                    $parentId = $categories->get($parentId)?->parent_id;
                }

                $updates = array_intersect_key($data, array_flip(['code', 'name', 'parent_id', 'is_active']));
                if (isset($updates['code'])) $updates['code'] = trim($updates['code']);
                if (isset($updates['name'])) $updates['name'] = trim($updates['name']);
                if ($updates !== []) $this->store->update('product_categories', $id, $updates);

                return $this->store->find('product_categories', $id);
            });

            return response()->json(['data' => $this->store->present($category)]);
        });
    }

    public function createUnit(Request $request, CatalogPermission $permission): JsonResponse
    {
        return $this->store->transaction(function () use ($request, $permission) {
            $this->authorizeManager($request, $permission);
            $data = $request->validate([
                'code' => ['required', 'string', 'max:40', $this->store->uniqueCode('units')],
                'name' => ['required', 'string', 'max:100'],
                'is_active' => ['sometimes', 'boolean'],
            ]);
            $id = $this->store->insert('units', [
                'code' => trim($data['code']),
                'name' => trim($data['name']),
                'is_active' => $data['is_active'] ?? true,
            ]);

            return response()->json(['data' => $this->store->present($this->store->find('units', $id))], 201);
        });
    }

    public function updateUnit(Request $request, CatalogPermission $permission, string $id): JsonResponse
    {
        return $this->store->transaction(function () use ($request, $permission, $id) {
            $this->authorizeManager($request, $permission);
            $data = $request->validate([
                'code' => ['sometimes', 'required', 'string', 'max:40', $this->store->uniqueCode('units', $id)],
                'name' => ['sometimes', 'required', 'string', 'max:100'],
                'is_active' => ['sometimes', 'boolean'],
            ]);
            abort_if($this->store->find('units', $id) === null, 404);
            if (isset($data['code'])) $data['code'] = trim($data['code']);
            if (isset($data['name'])) $data['name'] = trim($data['name']);
            if ($data !== []) $this->store->update('units', $id, $data);

            return response()->json(['data' => $this->store->present($this->store->find('units', $id))]);
        });
    }

    public function createProduct(Request $request, CatalogPermission $permission): JsonResponse
    {
        return $this->store->transaction(function () use ($request, $permission) {
            $this->authorizeManager($request, $permission);
            $data = $request->validate([
                'code' => ['required', 'string', 'max:60', $this->store->uniqueCode('products')],
                'name' => ['required', 'string', 'max:200'],
                'active_ingredient' => ['nullable', 'string', 'max:200'],
                'sale_price' => ['required', 'numeric', 'min:0', 'decimal:0,2', DecimalLimit::rule(16, 2)],
                'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
                'expiry_warning_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
                'category_id' => ['bail', 'required', 'string', DecimalId::rule(), $this->store->existsRule('product_categories')],
                'unit_id' => ['bail', 'required', 'string', DecimalId::rule(), $this->store->existsRule('units')],
                'is_active' => ['sometimes', 'boolean'],
            ]);
            $this->requireActive('product_categories', $data['category_id'], 'category_id');
            $this->requireActive('units', $data['unit_id'], 'unit_id');
            $id = $this->store->insert('products', [
                'code' => trim($data['code']),
                'name' => trim($data['name']),
                'active_ingredient' => $data['active_ingredient'] ?? null,
                'sale_price' => $data['sale_price'],
                'tax_rate' => $data['tax_rate'] ?? null,
                'expiry_warning_days' => $data['expiry_warning_days'] ?? null,
                'category_id' => $data['category_id'],
                'unit_id' => $data['unit_id'],
                'is_active' => $data['is_active'] ?? true,
            ]);

            return response()->json(['data' => $this->store->present($this->store->find('products', $id))], 201);
        });
    }

    public function updateProduct(Request $request, CatalogPermission $permission, string $id): JsonResponse
    {
        return $this->store->transaction(function () use ($request, $permission, $id) {
            $this->authorizeManager($request, $permission);
            $data = $request->validate([
                'code' => ['sometimes', 'required', 'string', 'max:60', $this->store->uniqueCode('products', $id)],
                'name' => ['sometimes', 'required', 'string', 'max:200'],
                'active_ingredient' => ['sometimes', 'nullable', 'string', 'max:200'],
                'sale_price' => ['sometimes', 'required', 'numeric', 'min:0', 'decimal:0,2', DecimalLimit::rule(16, 2)],
                'tax_rate' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
                'expiry_warning_days' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:3650'],
                'category_id' => ['bail', 'sometimes', 'required', 'string', DecimalId::rule(), $this->store->existsRule('product_categories')],
                'unit_id' => ['bail', 'sometimes', 'required', 'string', DecimalId::rule(), $this->store->existsRule('units')],
                'is_active' => ['sometimes', 'boolean'],
            ]);
            $product = $this->store->find('products', $id);
            abort_if($product === null, 404);
            $active = array_key_exists('is_active', $data) ? (bool) $data['is_active'] : (bool) $product['is_active'];
            if ($active || isset($data['category_id'])) {
                $this->requireActive('product_categories', $data['category_id'] ?? $product['category_id'], 'category_id');
            }
            if ($active || isset($data['unit_id'])) {
                $this->requireActive('units', $data['unit_id'] ?? $product['unit_id'], 'unit_id');
            }
            if (isset($data['code'])) $data['code'] = trim($data['code']);
            if (isset($data['name'])) $data['name'] = trim($data['name']);
            if ($data !== []) $this->store->update('products', $id, $data);

            return response()->json(['data' => $this->store->present($this->store->find('products', $id))]);
        });
    }

    private function authorizeManager(Request $request, CatalogPermission $permission): void
    {
        $row = $this->store->identity->find('users', (string) $request->user()->id);
        abort_unless($row && $permission->canManage((new User)->newFromBuilder($row)), 403);
    }

    private function requireActive(string $table, ?string $id, string $field): void
    {
        if ($id !== null && ! ($this->store->find($table, $id)['is_active'] ?? false)) {
            throw ValidationException::withMessages([$field => 'Chỉ được chọn dữ liệu còn hoạt động.']);
        }
    }
}
