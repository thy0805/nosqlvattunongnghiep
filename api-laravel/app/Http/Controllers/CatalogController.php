<?php

namespace App\Http\Controllers;

use App\Support\{BranchAccess, CatalogQuery, CatalogStore, DecimalId};
use Illuminate\Http\{JsonResponse, Request};

class CatalogController extends Controller
{
    public function index(Request $request, BranchAccess $access, CatalogStore $store): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'category_id' => ['bail', 'nullable', 'string', DecimalId::rule()],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'active_only' => ['nullable', 'boolean'],
        ]);
        abort_if($access->branchesFor($request->user())->isEmpty(), 403);
        $categories = $store->rows('product_categories', [], ['name' => 1]);
        $units = $store->rows('units', [], ['name' => 1]);
        $byCategory = $categories->keyBy('_id'); $byUnit = $units->keyBy('_id');
        $filter = isset($filters['category_id']) ? ['category_id' => $filters['category_id']] : [];
        $products = $store->rows('products', $filter, ['name' => 1, 'id_sort' => 1])->map(function ($row) use ($store, $byCategory, $byUnit, $request) {
            $category = $byCategory->get($row['category_id']); $unit = $byUnit->get($row['unit_id']);
            if (!$category || !$unit) return null;
            if ($request->boolean('active_only') && (!$row['is_active'] || !$category['is_active'] || !$unit['is_active'])) return null;
            return $store->present($row) + ['category_name' => $category['name'], 'category_is_active' => $category['is_active'],
                'unit_code' => $unit['code'], 'unit_name' => $unit['name'], 'unit_is_active' => $unit['is_active']];
        })->filter()->values();
        $products = CatalogQuery::search($products, $filters['search'] ?? null, ['code', 'name', 'active_ingredient']);
        return response()->json(CatalogQuery::page($products, $request, (int) ($filters['per_page'] ?? 20)) + [
            'categories' => $categories->map(fn ($row) => $store->present($row)),
            'units' => $units->map(fn ($row) => $store->present($row)),
        ]);
    }
}
