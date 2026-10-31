<?php

namespace App\Http\Controllers;

use App\Support\BranchAccess;
use App\Support\CatalogPermission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function show(Request $request, BranchAccess $access, CatalogPermission $catalogPermission): JsonResponse
    {
        $branches = $access->branchesFor($request->user());
        $reportBranches = $access->branchesFor($request->user(), ['branch_manager']);
        abort_if($branches->isEmpty(), 403);
        $store = app(\App\Support\IdentityStore::class);
        $canManageSync = $store->rows('user_role_assignments', ['user_id' => (string) $request->user()->id,
            'status' => 'active', 'starts_on' => ['$lte' => now('Asia/Ho_Chi_Minh')->toDateString()],
            'role_id' => $store->roleId('chain_owner')])->isNotEmpty();

        return response()->json([
            'user' => [
                'id' => $request->user()->id,
                'username' => $request->user()->username,
                'can_manage_catalog' => $catalogPermission->canManage($request->user()),
                'can_manage_sync' => $canManageSync,
                'can_view_reports' => $reportBranches->isNotEmpty(),
            ],
            'branches' => $branches,
            'chains' => $store->rows('chains', ['_id' => ['$in' => $branches->pluck('chain_id')->unique()->values()->all()]], ['name' => 1])->map(fn ($c) => $store->publicRow($c, ['id', 'name'])),
            'report_chains' => $store->rows('chains', ['_id' => ['$in' => $reportBranches->pluck('chain_id')->unique()->values()->all()]], ['name' => 1])->map(fn ($c) => $store->publicRow($c, ['id', 'name'])),
        ]);
    }
}
