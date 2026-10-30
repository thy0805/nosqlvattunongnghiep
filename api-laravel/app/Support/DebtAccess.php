<?php

namespace App\Support;

use App\Models\User;

class DebtAccess
{
    public function __construct(private readonly IdentityStore $store) {}

    public function canView(User $user, string $type, string $branchId, string $chainId): bool { return $this->allows($user,$type,$branchId,$chainId); }
    public function canPay(User $user, string $type, string $branchId, string $chainId): bool { return $this->allows($user,$type,$branchId,$chainId); }

    private function allows(User $user, string $type, string $branchId, string $chainId): bool
    {
        return $this->store->assignments((string) $user->id,true)->contains(fn ($row) =>
            ($row['role_code'] === 'chain_owner' && $row['chain_id'] === $chainId)
            || ($row['branch_id'] === $branchId && in_array($row['role_code'], $type === 'receivable' ? ['branch_manager','sales_staff'] : ['branch_manager'], true)));
    }
}
