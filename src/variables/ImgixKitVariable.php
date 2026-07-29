<?php

namespace viesrood\imgixkit\variables;

use craft\elements\Asset;
use viesrood\imgixkit\models\ImgixImage;
use viesrood\imgixkit\Plugin;

final class ImgixKitVariable
{
    public function image(Asset|string|null $image, array $params = [], ?string $source = null): ?ImgixImage
    {
        return Plugin::getInstance()->urls->image($image, $params, $source);
    }

    public function url(Asset|string|null $image, array $params = [], ?string $source = null): string
    {
        return $this->image($image, $params, $source)?->url ?? '';
    }

    public function srcset(Asset|string|null $image, array $params = [], array $widths = [], ?string $source = null): string
    {
        return Plugin::getInstance()->urls->srcset($image, $params, $widths, $source);
    }

    public function dprSrcset(Asset|string|null $image, array $params, array $dprs = [], ?string $source = null): string
    {
        return Plugin::getInstance()->urls->dprSrcset($image, $params, $dprs, $source);
    }

    public function domain(?string $source = null): string
    {
        return Plugin::getInstance()->urls->domain($source);
    }
}
