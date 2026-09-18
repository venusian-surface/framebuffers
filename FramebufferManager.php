<?php

namespace Surface\Framebuffers;

use Surface\Contracts\Framebuffers\FramebufferDriver;
use Surface\Framebuffers\Php\PhpFramebufferDriver;
use Voyager\NutsAndBolts\Manager;

/**
 * Where the bytes live: 'php' is in-house and always available; 'native' is
 * whatever package bound framebuffer.native (jovian/fb). Reads the injected
 * config repository so it is provable with a fake vessel; a missing native
 * package is the container's own not-found, the same decision as the engine
 * seams.
 */
class FramebufferManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return $this->config->get('framebuffers.default', 'php');
    }

    protected function createPhpDriver(): FramebufferDriver
    {
        $alias = $this->config->get('framebuffers.drivers.php.alias');

        return is_null($alias) || ! $this->vessel->bound($alias) ? new PhpFramebufferDriver() : $this->vessel->get($alias);
    }

    protected function createNativeDriver(): FramebufferDriver
    {
        return $this->vessel->get($this->config->get('framebuffers.drivers.native.alias', 'framebuffer.native'));
    }
}
