<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;

final class CatalogQuery
{
    public static function search(Collection $rows, ?string $search, array $fields): Collection
    {
        if (empty($search)) return $rows;
        $characters = preg_split('//u', '%'.mb_strtolower(trim($search)).'%', -1, PREG_SPLIT_NO_EMPTY);
        $pattern = '';
        for ($i = 0; $i < count($characters); $i++) {
            $char = $characters[$i];
            if ($char === '\\' && isset($characters[$i + 1])) $pattern .= preg_quote($characters[++$i], '~');
            else $pattern .= match ($char) { '%' => '.*', '_' => '.', default => preg_quote($char, '~') };
        }
        return $rows->filter(function ($row) use ($fields, $pattern) {
            foreach ($fields as $field) {
                if (($row[$field] ?? null) !== null && preg_match('~^'.$pattern.'$~usD', mb_strtolower($row[$field])) === 1) return true;
            }
            return false;
        })->values();
    }

    public static function page(Collection $rows, Request $request, int $perPage): array
    {
        $page = max(1, (int) $request->input('page', 1)); $total = $rows->count();
        return ['data' => $rows->slice(($page - 1) * $perPage, $perPage)->values(),
            'pagination' => ['current_page' => $page, 'last_page' => max(1, (int) ceil($total / $perPage)), 'total' => $total]];
    }
}
