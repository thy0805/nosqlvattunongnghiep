<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Collection;

class BranchAccess
{
    public function __construct(private readonly IdentityStore $store) {}

    public function branchesFor(User $user, array $directRoles = ['branch_manager', 'sales_staff']): Collection
    {
        $assignments = $this->store->assignments((string) $user->id, true);
        $direct = $assignments->whereIn('role_code', $directRoles)->pluck('branch_id')->all();
        $chains = $assignments->where('role_code', 'chain_owner')->pluck('chain_id')->all();
        return $this->store->rows('branches', ['$or' => [['_id' => ['$in' => $direct]], ['chain_id' => ['$in' => $chains]]]], ['name' => 1])
            ->map(fn ($b) => (object) $this->store->publicRow($b, ['id', 'chain_id', 'code', 'name']));
    }
}
