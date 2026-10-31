<?php

namespace App\Http\Controllers;

use App\Support\{BranchAccess, CatalogQuery, CatalogStore, DecimalId};
use App\Commerce\CommerceStore;
use Illuminate\Http\{JsonResponse, Request};

class CatalogController extends Controller
{
    public function index(Request $request, BranchAccess $access, CatalogStore $store, CommerceStore $commerce): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'category_id' => ['bail', 'nullable', 'string', DecimalId::rule()],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'active_only' => ['nullable', 'boolean'],
        ]);
        $branches = $access->branchesFor($request->user());
        abort_if($branches->isEmpty(), 403);
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
        $page = CatalogQuery::page($products, $request, (int) ($filters['per_page'] ?? 20));
        $images = collect($commerce->collection('shop_products')->find([
            'product_id' => ['$in' => $page['data']->pluck('id')->all()],
            'chain_id' => ['$in' => $branches->pluck('chain_id')->unique()->values()->all()],
        ], $commerce->options() + ['projection' => array_fill_keys(['product_id', 'chain_id', 'images', 'slug', 'published', 'brand', 'origin', 'packaging', 'description', 'uses', 'instructions', 'storage', 'safety'], 1), 'sort' => ['chain_id' => 1, 'id_sort' => 1]])->toArray())->groupBy('product_id');
        $chainNames = $branches->pluck('chain_id')->unique()->mapWithKeys(fn ($id) => [$id => $commerce->identity->find('chains', $id)['name'] ?? 'Chuỗi #'.$id])->all();
        $ownerChains = $commerce->identity->assignments((string) $request->user()->id, true)->where('role_code', 'chain_owner')->pluck('chain_id')->all();
        $page['data'] = $page['data']->map(fn ($row) => $row + ['shop_details' => $images->get($row['id'], collect())->map(fn ($shop) => array_intersect_key($shop, array_fill_keys(['chain_id', 'slug', 'published', 'brand', 'origin', 'packaging', 'description', 'uses', 'instructions', 'storage', 'safety'], true)) + ['chain_name' => $chainNames[$shop['chain_id']] ?? 'Chuỗi #'.$shop['chain_id'], 'can_manage' => in_array($shop['chain_id'], $ownerChains, true)])->values()->all(), 'shop_images' => $images->get($row['id'], collect())->map(fn ($shop) => [
            'chain_id' => $shop['chain_id'], 'images' => array_values($shop['images'] ?? []),
        ])->values()->all()]);
        return response()->json($page + [
            'categories' => $categories->map(fn ($row) => $store->present($row)),
            'units' => $units->map(fn ($row) => $store->present($row)),
        ]);
    }
}
