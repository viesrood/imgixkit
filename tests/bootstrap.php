<?php

/**
 * Test bootstrap.
 *
 * Works both from a standalone checkout of this plugin and from inside a Craft
 * project that loads it as a Composer path repository, so the two copies can
 * stay identical.
 *
 * The global Craft class is not part of the PSR-4 autoload of craftcms/cms;
 * normally Craft's own bootstrap requires it. For unit tests only the class
 * itself is needed - no application is started, so Craft::$app stays null and
 * UrlService behaves as it does in devMode.
 */

$vendorDirs = [
    __DIR__ . '/../vendor',          // standalone checkout
    __DIR__ . '/../../../vendor',    // <project>/craftcms/plugins/imgixkit
];

$vendor = null;
foreach ($vendorDirs as $candidate) {
    if (is_file($candidate . '/autoload.php')) {
        $vendor = $candidate;
        break;
    }
}

if ($vendor === null) {
    fwrite(STDERR, "Could not find Composer's autoloader. Run `composer install` first.\n");
    exit(1);
}

require $vendor . '/autoload.php';

if (!class_exists('Yii', false) && is_file($vendor . '/yiisoft/yii2/Yii.php')) {
    require $vendor . '/yiisoft/yii2/Yii.php';
}

if (!class_exists('Craft', false) && is_file($vendor . '/craftcms/cms/src/Craft.php')) {
    require $vendor . '/craftcms/cms/src/Craft.php';
}
