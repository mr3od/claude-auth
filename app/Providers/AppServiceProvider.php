<?php

namespace App\Providers;

use App\Services\Registry;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Registry::class, fn () => new Registry(
            home: config('claude-auth.home'),
            credentialsFile: config('claude-auth.claude_credentials_file'),
            claudeJsonFile: config('claude-auth.claude_json_file'),
            maxBackups: config('claude-auth.max_backups'),
        ));
    }
}
