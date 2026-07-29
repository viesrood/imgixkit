<?php

namespace viesrood\imgixkit\models;

use craft\base\Model;

final class Settings extends Model
{
    public string $defaultSource = 'default';
    public array $sources = [];
    public array $defaultParams = [
        'auto' => 'compress,format',
        'cs' => 'srgb',
    ];
    public array $srcsetWidths = [320, 400, 500, 640, 768, 960, 1200, 1440, 1680, 1920, 2240, 2560];
    public array $dprs = [1, 2, 3];
    public bool $preventUpscale = true;
    public bool $autoPurge = true;
    public bool $fallbackInProduction = true;

    public function rules(): array
    {
        return [
            [['defaultSource'], 'required'],
            [['sources', 'defaultParams', 'srcsetWidths', 'dprs'], 'safe'],
            [['preventUpscale', 'autoPurge', 'fallbackInProduction'], 'boolean'],
        ];
    }
}
