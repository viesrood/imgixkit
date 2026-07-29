<?php

namespace viesrood\imgixkit\utilities;

use Craft;
use craft\base\Utility;
use viesrood\imgixkit\Plugin;

final class ImgixKitUtility extends Utility
{
    public static function displayName(): string
    {
        return 'ImgixKit';
    }

    public static function id(): string
    {
        return 'imgixkit';
    }

    public static function icon(): ?string
    {
        return 'image';
    }

    public static function contentHtml(): string
    {
        return Craft::$app->getView()->renderTemplate('imgixkit/_utility.twig', [
            'report' => Plugin::getInstance()->diagnostics->report(),
        ]);
    }
}
