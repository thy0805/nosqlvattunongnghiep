<?php

namespace App\Providers;

use App\Infrastructure\Mongo\Runtime;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(\App\Support\IdentityStore::class);
        $this->app->singleton(\App\Commerce\CommerceStore::class);
        $this->app->singleton(\App\Support\CatalogStore::class);
        $this->app->singleton(\App\Support\PurchaseStore::class);
        $this->app->singleton(\App\Support\SalesStore::class);
        $this->app->singleton(\App\Support\TransferStore::class);
        $this->app->singleton(\App\Support\OutboxStore::class);
        $this->app->singleton(\App\Support\InventoryAlertStore::class);
        $this->app->singleton(\App\Support\NotificationStore::class);
        $this->app->singleton(Runtime::class, fn () => new Runtime(config('nosql')));
    }

    public function boot(): void
    {
        \Laravel\Sanctum\Sanctum::usePersonalAccessTokenModel(\App\Models\PersonalAccessToken::class);
        $this->app->make(Runtime::class)->assertSchema();
    }
}
