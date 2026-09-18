<?php

namespace Surface\Framebuffers;

use Surface\Framebuffers\Php\PhpFramebufferDriver;
use Voyager\Contracts\Vessel\Vessel;
use Voyager\NutsAndBolts\ServiceProvider;

class FramebuffersServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__, 3).'/config/framebuffers.php', 'framebuffers');

        $this->app->singleton('framebuffer.php', fn () => new PhpFramebufferDriver());
        $this->app->singleton(FramebufferManager::class, fn (Vessel $app) => new FramebufferManager($app));
        $this->app->alias(FramebufferManager::class, 'framebuffers');
    }

    public function boot(): void
    {
        $this->publishes([
            dirname(__DIR__, 3).'/config/framebuffers.php' => $this->app->configPath('framebuffers.php'),
        ], 'surface-config');
    }
}
