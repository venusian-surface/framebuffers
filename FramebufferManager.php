<?php

namespace Surface\Framebuffers;

use Surface\Contracts\Framebuffers\FramebufferDriver;
use Surface\Contracts\Framebuffers\FramebufferException;
use Surface\Framebuffers\Extended\ExtendedFramebufferDriver;
use Surface\Framebuffers\Native\NativeFramebufferDriver;
use Voyager\NutsAndBolts\Manager;

/**
 * Where framebuffer bytes live. 'native' keeps them in PHP and is always
 * there; 'extended' keeps them in C and needs ext-fb. A caller asks a driver
 * for a kind and never names a buffer class:
 *
 *     app('framebuffers')->driver()->dirty(FormatSpec::rgba8(), 320, 240);
 *
 * @method FramebufferDriver driver(?string $driver = null)
 */
class FramebufferManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return $this->config->get('framebuffers.default', 'native');
    }

    public function createNativeDriver(): FramebufferDriver
    {
        return new NativeFramebufferDriver();
    }

    /** @throws FramebufferException When ext-fb is not loaded in this PHP. */
    public function createExtendedDriver(): FramebufferDriver
    {
        return new ExtendedFramebufferDriver();
    }
}
