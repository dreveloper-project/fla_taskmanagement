<?php

namespace App\Providers;

use App\Models\User;
use App\Policies\ServicePolicy;
use App\Services\TaskServiceRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\DevCommands;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        if ($this->app->environment('local')) {
            DevCommands::artisan('schedule:work --no-interaction', 'scheduler');
        }

        Gate::define('access-system', fn (User $user): bool => $user->isActive() && ($user->isAdmin() || $user->isStaff() || $user->isFinance()));
        Gate::define('access-admin', fn (User $user): bool => $user->isActive() && $user->isAdmin());
        Gate::define('access-finance', fn (User $user): bool => $user->isActive() && ($user->isAdmin() || $user->isFinance()));
        Gate::define('access-reports', fn (User $user): bool => $user->isActive() && ($user->isAdmin() || $user->isFinance()));
        Gate::define('manage-services', fn (User $user): bool => $user->isActive() && ($user->isAdmin() || $user->isStaff()));
        Gate::define('manage-vessels', fn (User $user): bool => $user->isActive() && ($user->isAdmin() || $user->isStaff()));

        foreach (TaskServiceRegistry::TYPES as $model) {
            Gate::policy($model, ServicePolicy::class);
        }

        RateLimiter::for('registration', fn (Request $request): Limit => Limit::perMinute(5)->by($request->ip()));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
