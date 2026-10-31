<?php

namespace App\Support;

use App\Models\User;

class ReceiptAccess
{
    public function __construct(private readonly IdentityStore $store) {}

    public function canCreate(User $user, string $branchId): bool
    {
        return $this->store->assignments((string) $user->id, true)
            ->where('branch_id', $branchId)->whereIn('role_code', ['branch_manager', 'sales_staff'])->isNotEmpty();
    }

    public function canApprove(User $user, string $branchId, string $chainId): bool
    {
        return $this->store->assignments((string) $user->id, true)->contains(fn ($row) =>
            ($row['role_code'] === 'branch_manager' && $row['branch_id'] === $branchId)
            || ($row['role_code'] === 'chain_owner' && $row['chain_id'] === $chainId));
    }
}
