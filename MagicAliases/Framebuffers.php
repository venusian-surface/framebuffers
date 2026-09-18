<?php

namespace Surface\Framebuffers\MagicAliases;

use Voyager\MagicAliases\MagicAlias;

/**
 * @method static \Surface\Contracts\Framebuffers\FramebufferDriver driver(string|null $driver = null)
 * @method static \Surface\Framebuffers\FramebufferManager extend(string $driver, \Closure $callback)
 * @method static string getDefaultDriver()
 *
 * @see \Surface\Framebuffers\FramebufferManager
 */
class Framebuffers extends MagicAlias
{
    protected static function getMagicAliasAccessor(): string
    {
        return 'framebuffers';
    }
}
