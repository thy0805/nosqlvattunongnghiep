<?php

namespace App\Support;

use App\Models\User;

class TransferAccess
{
    public function __construct(private readonly IdentityStore $store) {}

    public function canRequest(User $user, string $branchId): bool
    {
        return $this->store->assignments((string) $user->id, true)->contains(fn ($row) =>
            $row['branch_id'] === $branchId && in_array($row['role_code'], ['branch_manager', 'sales_staff'], true));
    }

    public function canApprove(User $user, string $branchId, string $chainId): bool
    {
        return $this->store->assignments((string) $user->id, true)->contains(fn ($row) =>
            ($row['role_code'] === 'branch_manager' && $row['branch_id'] === $branchId)
            || ($row['role_code'] === 'chain_owner' && $row['chain_id'] === $chainId));
    }

    public function canReceive(User $user, string $branchId): bool { return $this->canRequest($user, $branchId); }

    public function canReconcile(User $user, string $sourceBranchId, string $targetBranchId, string $chainId): bool
    {
        $rows = $this->store->assignments((string) $user->id, true);
        if ($rows->contains(fn ($row) => $row['role_code'] === 'chain_owner' && $row['chain_id'] === $chainId)) return true;
        foreach (array_unique([$sourceBranchId, $targetBranchId]) as $id) {
            if (!$rows->contains(fn ($row) => $row['role_code'] === 'branch_manager' && $row['branch_id'] === $id)) return false;
        }
        return true;
    }
}
