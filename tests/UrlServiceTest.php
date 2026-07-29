<?php

namespace viesrood\imgixkit\tests;

use craft\elements\Asset;
use craft\models\Volume;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use viesrood\imgixkit\exceptions\ImgixException;
use viesrood\imgixkit\models\ImgixImage;
use viesrood\imgixkit\models\Settings;
use viesrood\imgixkit\services\UrlService;

final class UrlServiceTest extends TestCase
{
    private UrlService $service;

    protected function setUp(): void
    {
        $this->service = $this->service();
    }

    // ---------------------------------------------------------------- model

    public function testImageModelIsStringable(): void
    {
        $image = new ImgixImage('https://example.imgix.net/a.jpg?w=400', 400, 225, 16 / 9, ['w' => 400], 'a.jpg');
        self::assertSame($image->url, (string)$image);
        self::assertSame(400, $image->width);
        self::assertSame(225, $image->height);
        self::assertFalse($image->isFallback);
        self::assertFalse($image->isPassthrough);
    }

    // ----------------------------------------------------------- parameters

    public function testParametersAreNormalizedWithoutRestrictingNativeImgixOptions(): void
    {
        $params = $this->invoke('normalizeParams', [[
            'auto' => ['compress', 'format'],
            'fp-debug' => false,
            'txt-align' => 'center,middle',
            'w' => '640',
        ]]);
        self::assertSame('compress,format', $params['auto']);
        self::assertSame(0, $params['fp-debug']);
        self::assertSame(640, $params['w']);
    }

    #[DataProvider('invalidParameterProvider')]
    public function testUnsafeParametersAreRejected(array $params): void
    {
        $this->expectException(ImgixException::class);
        $this->invoke('normalizeParams', [$params]);
    }

    public static function invalidParameterProvider(): array
    {
        return [
            'signature override' => [['s' => 'secret']],
            'library override' => [['ixlib' => 'fake']],
            'invalid name' => [['bad key' => 'value']],
            'oversized width' => [['w' => 9000]],
            'zero width' => [['w' => 0]],
            'object value' => [['txt' => new \stdClass()]],
        ];
    }

    // ------------------------------------------------------ path resolution

    /**
     * The plugin's most important security boundary: anything that could point
     * outside the mapped source has to fail here.
     */
    #[DataProvider('unsafePathProvider')]
    public function testUnsafePathsAreRejected(string $path): void
    {
        $this->expectException(ImgixException::class);
        $this->invoke('resolvePath', [$path, $this->webFolderSource()]);
    }

    public static function unsafePathProvider(): array
    {
        return [
            'leading traversal' => ['../secret.jpg'],
            'traversal in the middle' => ['folder/../../secret.jpg'],
            'trailing traversal' => ['folder/..'],
            'null byte' => ["folder/photo.jpg\0.php"],
            'empty path' => ['   '],
            'slashes only' => ['///'],
            'absolute URL on a web folder' => ['https://elsewhere.example/photo.jpg'],
            'protocol relative' => ['//elsewhere.example/photo.jpg'],
            'other protocol' => ['file:///etc/passwd'],
        ];
    }

    #[DataProvider('safePathProvider')]
    public function testSafePathsAreNormalized(string $path, string $expected): void
    {
        self::assertSame($expected, $this->invoke('resolvePath', [$path, $this->webFolderSource()]));
    }

    public static function safePathProvider(): array
    {
        return [
            'plain path' => ['folder/photo.jpg', 'folder/photo.jpg'],
            'leading slash is stripped' => ['/folder/photo.jpg', 'folder/photo.jpg'],
            'backslashes become slashes' => ['folder\\photo.jpg', 'folder/photo.jpg'],
            'dot in a folder name is kept' => ['folder.v2/photo.jpg', 'folder.v2/photo.jpg'],
            'double dot in a filename is kept' => ['folder/photo..jpg', 'folder/photo..jpg'],
        ];
    }

    public function testWebProxyRequiresAnAbsoluteHttpsUrl(): void
    {
        $source = $this->webProxySource();
        self::assertSame(
            'https://elsewhere.example/photo.jpg',
            $this->invoke('resolvePath', ['https://elsewhere.example/photo.jpg', $source]),
        );

        foreach (['http://elsewhere.example/photo.jpg', 'folder/photo.jpg', 'ftp://elsewhere.example/a.jpg'] as $path) {
            try {
                $this->invoke('resolvePath', [$path, $source]);
                self::fail("Web Proxy wrongly accepted: $path");
            } catch (ImgixException) {
                self::assertTrue(true);
            }
        }
    }

    public function testAssetOnAnUnmappedVolumeIsRejected(): void
    {
        $this->expectException(ImgixException::class);
        $this->expectExceptionMessage('is not mapped');
        $this->invoke('resolvePath', [$this->asset(volumeHandle: 'privateUploads'), $this->webFolderSource()]);
    }

    public function testAssetPathIsPrefixedWithTheVolumeMapping(): void
    {
        $source = $this->webFolderSource(['imageUploads' => 'uploads/']);
        self::assertSame(
            'uploads/folder/photo.jpg',
            $this->invoke('resolvePath', [$this->asset(), $source]),
        );
    }

    public function testNonImageAssetsAreRejected(): void
    {
        $asset = $this->asset();
        $asset->kind = 'pdf';
        $this->expectException(ImgixException::class);
        $this->invoke('resolvePath', [$asset, $this->webFolderSource()]);
    }

    // ------------------------------------------------------------- sources

    public function testUnknownSourceIsRejected(): void
    {
        $this->expectException(ImgixException::class);
        $this->expectExceptionMessage('Unknown Imgix source');
        $this->invoke('source', ['doesnotexist']);
    }

    #[DataProvider('invalidDomainProvider')]
    public function testInvalidDomainsAreRejected(mixed $domain): void
    {
        $service = $this->service(['sources' => ['default' => ['domain' => $domain]]]);
        $this->expectException(ImgixException::class);
        $this->invoke('source', [null], $service);
    }

    public static function invalidDomainProvider(): array
    {
        return [
            'empty' => [''],
            'missing' => [null],
            'with protocol' => ['https://example.imgix.net'],
            'with path' => ['example.imgix.net/map'],
            'without tld' => ['viesrood'],
            'with space' => ['viesrood .imgix.net'],
        ];
    }

    public function testWebProxyWithoutSigningKeyIsRejected(): void
    {
        $service = $this->service(['sources' => ['default' => [
            'domain' => 'example.imgix.net',
            'sourceType' => 'webProxy',
        ]]]);
        $this->expectException(ImgixException::class);
        $this->expectExceptionMessage('requires a signing key');
        $this->invoke('source', [null], $service);
    }

    public function testSourceDefaultsAreFilledIn(): void
    {
        [$name, $source] = $this->invoke('source', [null]);
        self::assertSame('default', $name);
        self::assertSame('webFolder', $source['sourceType']);
        self::assertSame('', $source['signingKey']);
        self::assertSame('', $source['apiKey']);
        self::assertIsArray($source['volumeMap']);
    }

    public function testResolvedSourcesAreMemoizedWithinTheRequest(): void
    {
        [, $first] = $this->invoke('source', [null]);
        [, $second] = $this->invoke('source', [null]);
        self::assertSame($first, $second);
    }

    // ----------------------------------------------------------- ratios

    #[DataProvider('ratioProvider')]
    public function testAspectRatiosAreParsed(mixed $input, float $expected): void
    {
        self::assertEqualsWithDelta($expected, $this->invoke('parseRatio', [$input]), 0.0001);
    }

    public static function ratioProvider(): array
    {
        return [
            ['16:9', 16 / 9],
            ['1:1', 1.0],
            [1.5, 1.5],
            ['invalid', 0.0],
            ['16:0', 0.0],
            [null, 0.0],
        ];
    }

    public function testDimensionsCanBeDerivedFromAspectRatio(): void
    {
        self::assertSame([800, 450], $this->invoke('dimensions', ['image.jpg', ['w' => 800, 'ar' => '16:9']]));
        self::assertSame([600, 600], $this->invoke('dimensions', ['image.jpg', ['h' => 600, 'ar' => '1:1']]));
    }

    public function testDimensionsFallBackToTheSourceSizeWithoutParameters(): void
    {
        self::assertSame([1000, 500], $this->invoke('dimensions', [$this->asset(1000, 500), []]));
    }

    // -------------------------------------------------------- upscaling

    public function testWidthIsClampedToTheSourceWidth(): void
    {
        $params = $this->invoke('preventUpscale', [$this->asset(1000, 500), ['w' => 2400]]);
        self::assertSame(1000, $params['w']);
    }

    public function testHeightIsClampedToTheSourceHeight(): void
    {
        $params = $this->invoke('preventUpscale', [$this->asset(1000, 500), ['h' => 900]]);
        self::assertSame(500, $params['h']);
    }

    public function testWidthAndHeightAreClampedTogetherSoTheRatioSurvives(): void
    {
        $params = $this->invoke('preventUpscale', [$this->asset(1000, 500), ['w' => 2000, 'h' => 1000]]);
        self::assertSame(1000, $params['w']);
        self::assertSame(500, $params['h']);
    }

    public function testWidthIsAlsoBoundedByTheRequestedAspectRatio(): void
    {
        // A 1:1 crop from a 1000x500 source can never be wider than 500.
        $params = $this->invoke('preventUpscale', [$this->asset(1000, 500), ['w' => 900, 'ar' => '1:1']]);
        self::assertSame(500, $params['w']);
    }

    public function testUpscalingIsAllowedWhenTheSettingIsOff(): void
    {
        $service = $this->service(['preventUpscale' => false]);
        $params = $this->invoke('preventUpscale', [$this->asset(1000, 500), ['w' => 2400]], $service);
        self::assertSame(2400, $params['w']);
    }

    // ------------------------------------------------------------ srcset

    public function testSrcsetSkipsWidthsAboveTheSourceWidth(): void
    {
        $srcset = $this->service->srcset($this->asset(1000, 500), [], [400, 800, 1600]);
        self::assertStringContainsString(' 400w', $srcset);
        self::assertStringContainsString(' 800w', $srcset);
        self::assertStringNotContainsString(' 1600w', $srcset);
    }

    public function testSrcsetAppendsTheSourceWidthWhenUsingTheDefaultWidths(): void
    {
        $srcset = $this->service->srcset($this->asset(1000, 500));
        self::assertStringContainsString(' 1000w', $srcset);
        self::assertStringNotContainsString(' 1200w', $srcset);
    }

    public function testSrcsetUrlsPointAtTheConfiguredImgixDomain(): void
    {
        $srcset = $this->service->srcset($this->asset(1000, 500), [], [400]);
        self::assertStringStartsWith('https://example.imgix.net/folder/photo.jpg?', $srcset);
        self::assertStringNotContainsString('ixlib=', $srcset);
    }

    public function testSrcsetRefusesWidthHeightAndDpr(): void
    {
        foreach ([['w' => 400], ['h' => 400], ['dpr' => 2]] as $params) {
            try {
                $this->service->srcset($this->asset(1000, 500), $params);
                self::fail('srcset wrongly accepted ' . key($params));
            } catch (ImgixException) {
                self::assertTrue(true);
            }
        }
    }

    public function testDprSrcsetRequiresWidthOrHeight(): void
    {
        $this->expectException(ImgixException::class);
        $this->service->dprSrcset($this->asset(1000, 500), ['fit' => 'max']);
    }

    public function testDprSrcsetSkipsDprsThatWouldUpscale(): void
    {
        // 500 x 3 no longer fits in a source that is 1000 wide.
        $srcset = $this->service->dprSrcset($this->asset(1000, 500), ['w' => 500], [1, 2, 3]);
        self::assertStringContainsString(' 1x', $srcset);
        self::assertStringContainsString(' 2x', $srcset);
        self::assertStringNotContainsString(' 3x', $srcset);
    }

    public function testEmptyInputYieldsNothing(): void
    {
        self::assertNull($this->service->image(null));
        self::assertSame('', $this->service->srcset(null));
        self::assertSame('', $this->service->dprSrcset(null, ['w' => 100]));
    }

    // ------------------------------------------------------- focal point

    public function testFocalPointIsAppliedOnCrop(): void
    {
        $image = $this->service->image($this->asset(1000, 500), ['fit' => 'crop', 'w' => 400]);
        self::assertSame('focalpoint', $image->params['crop']);
        self::assertSame(0.25, $image->params['fp-x']);
        self::assertSame(0.75, $image->params['fp-y']);
    }

    public function testAnExplicitCropWins(): void
    {
        $image = $this->service->image($this->asset(1000, 500), ['fit' => 'crop', 'crop' => 'edges', 'w' => 400]);
        self::assertSame('edges', $image->params['crop']);
        self::assertArrayNotHasKey('fp-x', $image->params);
    }

    public function testFocalPointIsNotAppliedWithoutCrop(): void
    {
        $image = $this->service->image($this->asset(1000, 500), ['fit' => 'max', 'w' => 400]);
        self::assertArrayNotHasKey('crop', $image->params);
    }

    // ------------------------------------------------------- versionering

    public function testAssetUrlsCarryTheLastModifiedTimestamp(): void
    {
        $image = $this->service->image($this->asset(1000, 500), ['w' => 400]);
        self::assertSame(1750000000, $image->params['v']);
        self::assertStringContainsString('v=1750000000', $image->url);
    }

    public function testAnExplicitVersionWins(): void
    {
        $image = $this->service->image($this->asset(1000, 500), ['w' => 400, 'v' => 'handmatig']);
        self::assertSame('handmatig', $image->params['v']);
    }

    public function testVersioningCanBeTurnedOff(): void
    {
        $service = $this->service(['versionUrls' => false]);
        $image = $service->image($this->asset(1000, 500), ['w' => 400]);
        self::assertArrayNotHasKey('v', $image->params);
    }

    public function testStringPathsGetNoVersion(): void
    {
        $image = $this->service->image('folder/photo.jpg', ['w' => 400]);
        self::assertArrayNotHasKey('v', $image->params);
    }

    public function testEverySrcsetVariantIsVersioned(): void
    {
        $srcset = $this->service->srcset($this->asset(1000, 500), [], [400, 800]);
        self::assertSame(2, substr_count($srcset, 'v=1750000000'));
    }

    // ---------------------------------------------------------------- svg

    public function testVectorsBypassImgixEntirely(): void
    {
        $image = $this->service->image($this->asset(200, 100, 'svg'), ['w' => 400]);
        self::assertTrue($image->isPassthrough);
        self::assertSame('https://cdn.example.com/uploads/folder/photo.svg', $image->url);
        self::assertSame(200, $image->width);
        self::assertSame(100, $image->height);
    }

    public function testVectorsGetNoSrcset(): void
    {
        self::assertSame('', $this->service->srcset($this->asset(200, 100, 'svg')));
        self::assertSame('', $this->service->dprSrcset($this->asset(200, 100, 'svg'), ['w' => 192]));
    }

    // ------------------------------------------------------------ domain

    public function testDomainReturnsTheHttpsSourceDomain(): void
    {
        self::assertSame('https://example.imgix.net', $this->service->domain());
    }

    // ----------------------------------------------------------- helpers

    private function service(array $settings = []): UrlService
    {
        return new UrlService([
            'pluginSettings' => new Settings($settings + [
                'defaultSource' => 'default',
                'sources' => [
                    'default' => [
                        'domain' => 'example.imgix.net',
                        'sourceType' => 'webFolder',
                        'volumeMap' => ['imageUploads' => ''],
                    ],
                ],
                'defaultParams' => [],
            ]),
        ]);
    }

    private function webFolderSource(array $volumeMap = ['imageUploads' => '']): array
    {
        return [
            'domain' => 'example.imgix.net',
            'sourceType' => 'webFolder',
            'signingKey' => '',
            'apiKey' => '',
            'volumeMap' => $volumeMap,
        ];
    }

    private function webProxySource(): array
    {
        return [
            'domain' => 'example.imgix.net',
            'sourceType' => 'webProxy',
            'signingKey' => 'secret',
            'apiKey' => '',
            'volumeMap' => [],
        ];
    }

    private function asset(
        int $width = 1000,
        int $height = 500,
        string $extension = 'jpg',
        string $volumeHandle = 'imageUploads',
    ): Asset {
        $volume = $this->createStub(Volume::class);
        $volume->handle = $volumeHandle;

        $asset = $this->createStub(Asset::class);
        $asset->method('getWidth')->willReturn($width);
        $asset->method('getHeight')->willReturn($height);
        $asset->method('getExtension')->willReturn($extension);
        $asset->method('getPath')->willReturn("folder/photo.$extension");
        $asset->method('getVolume')->willReturn($volume);
        $asset->method('getFocalPoint')->willReturn(['x' => 0.25, 'y' => 0.75]);
        $asset->method('getUrl')->willReturn("https://cdn.example.com/uploads/folder/photo.$extension");
        $asset->kind = 'image';
        $asset->dateModified = new \DateTime('@1750000000');

        return $asset;
    }

    private function invoke(string $method, array $arguments, ?UrlService $service = null): mixed
    {
        $service ??= $this->service;
        $reflection = new ReflectionMethod($service, $method);
        return $reflection->invokeArgs($service, $arguments);
    }
}
