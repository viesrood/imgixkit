<?php

namespace viesrood\imgixkit;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\elements\Asset;
use craft\events\ModelEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterElementActionsEvent;
use craft\events\ReplaceAssetEvent;
use craft\services\Assets;
use craft\services\Utilities;
use craft\web\twig\variables\CraftVariable;
use viesrood\imgixkit\elements\actions\PurgeAssets;
use viesrood\imgixkit\models\Settings;
use viesrood\imgixkit\services\DiagnosticsService;
use viesrood\imgixkit\services\PurgeService;
use viesrood\imgixkit\services\UrlService;
use viesrood\imgixkit\utilities\ImgixKitUtility;
use viesrood\imgixkit\variables\ImgixKitVariable;
use yii\base\Event;

/**
 * @property UrlService $urls
 * @property PurgeService $purge
 * @property DiagnosticsService $diagnostics
 */
final class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.0.0';

    public function init(): void
    {
        parent::init();

        $this->setComponents([
            'urls' => UrlService::class,
            'purge' => PurgeService::class,
            'diagnostics' => DiagnosticsService::class,
        ]);

        Event::on(CraftVariable::class, CraftVariable::EVENT_INIT, static function(Event $event): void {
            $event->sender->set('imgixkit', ImgixKitVariable::class);
        });

        Event::on(Utilities::class, Utilities::EVENT_REGISTER_UTILITIES, static function(RegisterComponentTypesEvent $event): void {
            $event->types[] = ImgixKitUtility::class;
        });

        Event::on(Asset::class, Element::EVENT_REGISTER_ACTIONS, static function(RegisterElementActionsEvent $event): void {
            $event->actions[] = PurgeAssets::class;
        });

        Event::on(Assets::class, Assets::EVENT_AFTER_REPLACE_ASSET, static function(ReplaceAssetEvent $event): void {
            Plugin::getInstance()->purge->enqueueAsset($event->asset);
        });

        Event::on(Asset::class, Element::EVENT_BEFORE_DELETE, static function(Event $event): void {
            Plugin::getInstance()->purge->enqueueAsset($event->sender);
        });

        Event::on(Asset::class, Element::EVENT_BEFORE_SAVE, static function(ModelEvent $event): void {
            /** @var Asset $asset */
            $asset = $event->sender;
            if ($asset->getScenario() === Asset::SCENARIO_FILEOPS || $asset->getScenario() === Asset::SCENARIO_MOVE) {
                Plugin::getInstance()->purge->enqueueAsset($asset);
            }
        });

        Event::on(Asset::class, Element::EVENT_AFTER_SAVE, static function(ModelEvent $event): void {
            /** @var Asset $asset */
            $asset = $event->sender;
            if ($asset->getScenario() === Asset::SCENARIO_FILEOPS || $asset->getScenario() === Asset::SCENARIO_MOVE) {
                Plugin::getInstance()->purge->enqueueAsset($asset);
            }
        });

        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            // Lets `php craft imgixkit` and `php craft imgixkit/purge` resolve
            // without the plugin-handle prefix Craft would otherwise require.
            Craft::$app->controllerMap['imgixkit'] = \viesrood\imgixkit\console\controllers\ImgixKitController::class;
        }
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }
}
