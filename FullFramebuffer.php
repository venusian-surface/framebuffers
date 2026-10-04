<?php

namespace Surface\Framebuffers;

/** One whole frame. Keeps its contents after a present; its damage is always the whole surface. */
abstract class FullFramebuffer extends StoreFramebuffer {}
