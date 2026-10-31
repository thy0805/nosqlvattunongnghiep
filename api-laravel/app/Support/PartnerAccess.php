<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Collection;

class PartnerAccess
{
    public function chainIds(User $user, array $roles): Collection
    {
        return app(IdentityStore::class)->chains((string) $user->id, $roles);
    }
}
