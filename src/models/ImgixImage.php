<?php

namespace viesrood\imgixkit\models;

use Stringable;

final class ImgixImage implements Stringable
{
    public function __construct(
        public readonly string $url,
        public readonly int $width,
        public readonly int $height,
        public readonly float $aspectRatio,
        public readonly array $params,
        public readonly string $sourcePath,
        /** Fell back to the Craft URL after an error; see UrlService::handleFailure(). */
        public readonly bool $isFallback = false,
        /** Deliberately routed around Imgix, such as an SVG. */
        public readonly bool $isPassthrough = false,
    ) {
    }

    public function __toString(): string
    {
        return $this->url;
    }
}
