<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Access\AccessAttendanceBridge;
use App\Services\Access\NullAccessAttendanceBridge;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(AccessAttendanceBridge::class, NullAccessAttendanceBridge::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::before(function (User $user): ?bool {
            return $user->hasRole('admin') ? true : null;
        });

        Gate::define('permission', fn (User $user, string $permission): bool => $user->hasPermission($permission));
    }
}
