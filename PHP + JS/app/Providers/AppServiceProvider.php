<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\ActivityLogger;
use App\Services\ImageProcessor;
use App\Services\Progress\ProgressCache;
use App\Services\Progress\ProgressService;
use App\Services\XP\XPService;
use Illuminate\Http\Request;
use Intervention\Image\ImageManager;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(ProgressCache::class, fn () => new ProgressCache());

        $this->app->scoped(ProgressService::class, fn ($app) => new ProgressService($app->make(ProgressCache::class)));

        $this->app->singleton(XPService::class, fn () => new XPService());

        $this->app->scoped(ActivityLogger::class, fn ($app) => new ActivityLogger($app->make(Request::class)));

        $this->app->singleton(ImageProcessor::class, function ($app) {
            $manager = new ImageManager(['driver' => 'gd']);

            return new ImageProcessor(
                $manager,
                (string) config('family.uploads_dir'),
                (string) config('family.thumbs_dir')
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
