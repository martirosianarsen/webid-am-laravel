<?php

namespace Webid\Laravel;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Webid\Laravel\Http\Middleware\EndSignedOutSessions;

/**
 * Sign in with webid: reads WEBID_KEY, adds the /webid routes and the <x-webid::button /> and <x-webid::chat />
 * components.
 *
 * php artisan vendor:publish --tag=webid-config      config/webid.php
 * php artisan vendor:publish --tag=webid-migrations  the users.webid_sub column
 */
class WebidServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/webid.php', 'webid');
        $this->app->singleton(Webid::class, fn ($app): Webid => new Webid(
            ConnectionKey::parse(config('webid.key')),
            $app->make(Http::class),
            $app->make(Cache::class),
            config('webid.api_url'),
            config('webid.chat_url'),
        ));
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/webid.php');
        // Sessions of a person webid signed out end on their next request, whatever page it is.
        $this->app->make(Kernel::class)->appendMiddlewareToGroup('web', EndSignedOutSessions::class);
        Blade::anonymousComponentPath(__DIR__.'/../resources/views/components', 'webid');

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/webid.php' => config_path('webid.php')], 'webid-config');
            $this->publishesMigrations([__DIR__.'/../database/migrations' => database_path('migrations')], 'webid-migrations');
        }
    }
}
