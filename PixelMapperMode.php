<?php

namespace Surface\Framebuffers;

enum PixelMapperMode: string
{
    case MONO = 'mono';
    case GREY = 'grey';
    case PLANAR = 'planar';
    case INDEX = 'index';
    case RGB = 'rgb';
}
