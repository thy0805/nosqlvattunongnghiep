<?php

namespace App\Support;

use App\Models\User;

class CatalogPermission
{
    public function canManage(User $user): bool
    {
        if (!$user->is_active || !$user->is_catalog_admin) return false;
        $store = app(IdentityStore::class);
        $role = $store->roleId('chain_owner');
        return $store->rows('user_role_assignments', ['user_id' => (string) $user->id, 'role_id' => $role,
            'status' => 'active', 'starts_on' => ['$lte' => now('Asia/Ho_Chi_Minh')->toDateString()]])->isNotEmpty();
    }
}
