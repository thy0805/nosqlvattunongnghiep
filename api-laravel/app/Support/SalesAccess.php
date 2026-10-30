<?php

namespace App\Support;

use App\Models\User;

class SalesAccess
{
    public function __construct(private readonly IdentityStore $store) {}

    public function canSell(User $user, string $branchId, string $chainId): bool
    {
        return $this->store->assignments((string) $user->id, true)->contains(fn ($row) =>
            ($row['branch_id'] === $branchId && in_array($row['role_code'], ['branch_manager','sales_staff'], true))
            || ($row['chain_id'] === $chainId && $row['role_code'] === 'chain_owner'));
    }
}
