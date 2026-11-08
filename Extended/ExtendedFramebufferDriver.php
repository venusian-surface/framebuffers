<?php

namespace Surface\Framebuffers\Extended;

use FbBuffer;
use Surface\Contracts\Framebuffers\DamageTrackingFramebuffer;
use Surface\Contracts\Framebuffers\ePaperFramebuffer;
use Surface\Contracts\Framebuffers\FormatSpec;
use Surface\Contracts\Framebuffers\Framebuffer;
use Surface\Contracts\Framebuffers\FramebufferDriver;
use Surface\Contracts\Framebuffers\FramebufferException;
use Surface\Contracts\Framebuffers\PagedFramebuffer;
use Surface\Contracts\Framebuffers\RingFramebuffer;

/** The five kinds with their bytes in C, through ext-fb. */
class ExtendedFramebufferDriver implements FramebufferDriver
{
    /** The oldest ext-fb this driver runs on: 0.11.1 paints an image's tint, window and mask. */
    public const string MINIMUM = '0.11.1';

    /** The FbBuffer methods the driver calls that arrived after 0.11.1 was first built: an older build of the same version lacks them. */
    public const array REQUIRED_METHODS = ['paintGradient', 'filter'];

    /** Whether this PHP has an ext-fb of at least MINIMUM loaded, built with every method the driver calls. */
    public static function available(): bool
    {
        return class_exists(FbBuffer::class, false) && version_compare((string) phpversion('fb'), self::MINIMUM, '>=') && self::missingMethods() === [];
    }

    /** The filter steps that arrived after 0.11.1 was first built, by the step list that asks for each. */
    public const array REQUIRED_STEPS = [
        'field' => ['field', 0, 0.0, 0.0, 1.0, 1.0, 0.0, 0.0, 0.0, 0.0, 0.0, 1.0, null],
        'table' => ['table', null, null, null, null, null, null],
    ];

    /** @var array<string, list<string>> */
    private static array $probed = [];

    /** @return list<string> The REQUIRED_METHODS the class lacks, and the filter steps (as "filter(field)") its build does not know. */
    public static function missingMethods(string $class = FbBuffer::class): array
    {
        $missing = array_values(array_filter(self::REQUIRED_METHODS, fn (string $method): bool => ! method_exists($class, $method)));
        if ($missing === [] && $class === FbBuffer::class) {
            self::$probed[$class] ??= self::probeSteps($class);
            $missing = self::$probed[$class];
        }

        return $missing;
    }

    /** @return list<string> */
    private static function probeSteps(string $class): array
    {
        $missing = [];
        foreach (self::REQUIRED_STEPS as $name => $step) {
            try {
                $buffer = new $class(FbFormats::from(FormatSpec::rgba8()), 1, 1);
                $buffer->filter([$step], [], 0, 0, 1, 1);
            } catch (\ValueError) {
                $missing[] = "filter({$name})";
            }
        }

        return $missing;
    }

    /** @throws FramebufferException When this PHP has no ext-fb, or one older than MINIMUM. */
    public function __construct()
    {
        if (class_exists(FbBuffer::class, false) && version_compare((string) phpversion('fb'), self::MINIMUM, '>=') && ($missing = self::missingMethods()) !== []) {
            throw FramebufferException::extensionStale('fb', self::MINIMUM, $missing);
        }
        if (! self::available()) {
            throw FramebufferException::extensionMissing('fb', self::MINIMUM);
        }
    }

    public function driver(): string
    {
        return 'extended';
    }

    public function full(FormatSpec $format, int $width, int $height): Framebuffer
    {
        return new ExtendedFullFramebuffer($format, $width, $height);
    }

    public function dirty(FormatSpec $format, int $width, int $height): DamageTrackingFramebuffer
    {
        return new ExtendedDirtyFramebuffer($format, $width, $height);
    }

    public function epaper(FormatSpec $format, int $width, int $height): ePaperFramebuffer
    {
        return new ExtendedePaperFramebuffer($format, $width, $height);
    }

    public function paged(FormatSpec $format, int $width, int $height, int $page_rows): PagedFramebuffer
    {
        return new ExtendedPagedFramebuffer($format, $width, $height, $page_rows);
    }

    public function ring(FormatSpec $format, int $width, int $height, int $frames): RingFramebuffer
    {
        return new ExtendedRingFramebuffer($format, $width, $height, $frames);
    }
}
