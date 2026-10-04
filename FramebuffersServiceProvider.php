<?php

namespace Surface\Framebuffers;

use ReflectionException;
use Voyager\Contracts\Core\FrameworkCore;
use Voyager\NutsAndBolts\ServiceProvider;

class FramebuffersServiceProvider extends ServiceProvider
{
    /**
     * @return void
     * @throws ReflectionException
     */
    public function register(): void
    {
        $this->app->registerSingleton('framebuffers', fn (FrameworkCore $app) => new FramebufferManager($app));
        $this->app->alias('framebuffers', FramebufferManager::class);
    }

    public function boot(): void
    {

    }
}
