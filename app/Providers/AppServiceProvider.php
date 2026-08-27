<?php

namespace App\Providers;

use App\Listeners\LogSuccessfulLogin;
use App\Models\BillingItem;
use App\Models\Task;
use App\Policies\BillingItemPolicy;
use App\Policies\TaskPolicy;
use App\Services\SettingsService;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(SettingsService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Task::class, TaskPolicy::class);
        Gate::policy(BillingItem::class, BillingItemPolicy::class);

        Event::listen(Login::class, LogSuccessfulLogin::class);
    }
}
