<?php

namespace App\Models;

use MongoDB\Laravel\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens;

    protected $connection = 'mongodb';

    public $timestamps = false;

    protected $fillable = ['username', 'password_hash', 'is_active'];

    protected $hidden = ['password_hash', 'id_sort', '_version'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'is_catalog_admin' => 'boolean'];
    }

    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    public function save(array $options = [])
    {
        if (!$this->exists || array_diff(array_keys($this->getDirty()), ['password_hash'])) throw new \LogicException('Use the C01 repository for identity mutations');
        $store = app(\App\Support\IdentityStore::class);
        $store->transaction(fn () => $store->update('users', (string) $this->id, $this->getDirty()));
        $this->syncOriginal();
        return true;
    }

    public function createToken(string $name, array $abilities = ['*'], ?\DateTimeInterface $expiresAt = null)
    {
        $store = app(\App\Support\IdentityStore::class);
        return $store->transaction(function () use ($store, $name, $abilities, $expiresAt) {
            $secret = $this->generateTokenString();
            $now = new \MongoDB\BSON\UTCDateTime;
            $id = $store->insert('personal_access_tokens', ['tokenable_type' => self::class, 'tokenable_id' => (string) $this->id,
                'name' => $name, 'token' => hash('sha256', $secret), 'abilities' => $abilities, 'last_used_at' => null,
                'expires_at' => $expiresAt ? new \MongoDB\BSON\UTCDateTime($expiresAt) : null, 'created_at' => $now, 'updated_at' => $now]);
            $model = (new PersonalAccessToken)->newFromBuilder($store->find('personal_access_tokens', $id));
            return new \Laravel\Sanctum\NewAccessToken($model, $id.'|'.$secret);
        });
    }
}
