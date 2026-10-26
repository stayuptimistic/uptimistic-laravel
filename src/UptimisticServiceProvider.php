<?php

namespace Uptimistic\Laravel;

use Composer\Autoload\ClassLoader;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use ReflectionClass;
use Uptimistic\Laravel\Console\ReportCommand;
use Uptimistic\Laravel\Http\ReportController;
use Uptimistic\Laravel\Http\VerifySignature;

class UptimisticServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/uptimistic.php', 'uptimistic');

        $this->app->singleton(Report::class, function ($app) {
            // vendor/composer, wherever the vendor folder is.
            $composerDir = dirname((string) (new ReflectionClass(ClassLoader::class))->getFileName());

            return new Report($app, $composerDir, $app->basePath());
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/uptimistic.php' => config_path('uptimistic.php')], 'uptimistic-config');
            $this->commands([ReportCommand::class]);
        }

        if (! $this->app->routesAreCached()) {
            Route::get((string) config('uptimistic.path', 'uptimistic/report'), ReportController::class)
                ->middleware(array_merge((array) config('uptimistic.middleware', []), [VerifySignature::class]))
                ->name('uptimistic.report');
        }
    }
}
