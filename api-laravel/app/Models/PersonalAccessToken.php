<?php

namespace App\Models;

use App\Support\IdentityStore;
use MongoDB\Laravel\Eloquent\DocumentModel;

class PersonalAccessToken extends \Laravel\Sanctum\PersonalAccessToken
{
    use DocumentModel;

    protected $connection = 'mongodb';
    protected $keyType = 'string';
    protected $hidden = ['token', 'id_sort', '_version'];
    protected $casts = ['abilities' => 'array', 'last_used_at' => 'datetime', 'expires_at' => 'datetime', 'created_at' => 'datetime', 'updated_at' => 'datetime'];

    public function save(array $options = [])
    {
        if (!$this->exists || array_diff(array_keys($this->getDirty()), ['last_used_at'])) throw new \LogicException('Use C01 token issuance');
        $store = app(IdentityStore::class);
        $store->transaction(function () use ($store) {
            $row = $store->find('personal_access_tokens', (string) $this->id);
            if ($row) $store->update('personal_access_tokens', (string) $this->id, [
                'last_used_at' => new \MongoDB\BSON\UTCDateTime, 'updated_at' => new \MongoDB\BSON\UTCDateTime]);
        });
        $this->syncOriginal();
        return true;
    }

    public function delete()
    {
        app(IdentityStore::class)->deleteToken((string) $this->id);
        $this->exists = false;
        return true;
    }
}
