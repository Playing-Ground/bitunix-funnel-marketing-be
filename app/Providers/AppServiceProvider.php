<?php

namespace App\Providers;

use App\Services\Google\GoogleCredentialsFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(GoogleCredentialsFactory::class, function (): GoogleCredentialsFactory {
            return new GoogleCredentialsFactory(
                (string) config('services.google.service_account_path'),
            );
        });
    }

    public function boot(): void
    {
        // Surface unguarded mass assignment / lazy loading bugs in dev.
        Model::shouldBeStrict(! app()->isProduction());
    }
}
