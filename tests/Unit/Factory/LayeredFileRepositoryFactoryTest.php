<?php

declare(strict_types=1);

namespace Contenir\PageCache\Laminas\Mvc\Tests\Unit\Factory;

use Contenir\PageCache\CacheControl;
use Contenir\PageCache\Laminas\Mvc\Factory\LayeredFileRepositoryFactory;
use Contenir\PageCache\Laminas\Mvc\Tests\TestAsset\Container\InMemoryContainer;
use Contenir\PageCache\Repository\LayeredFileRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function getcwd;

#[Group('unit')]
#[Group('cache')]
final class LayeredFileRepositoryFactoryTest extends TestCase
{
    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function unsetFileProvider(): array
    {
        return [
            'no config service'      => [[]],
            'config is not an array' => [['config' => 'nope']],
            'pagecache not an array' => [['config' => ['pagecache' => true]]],
            'file key missing'       => [['config' => ['pagecache' => []]]],
            'file not a string'      => [['config' => ['pagecache' => ['file' => 42]]]],
            'file an empty string'   => [['config' => ['pagecache' => ['file' => '']]]],
        ];
    }

    #[Test]
    public function laysTheConfiguredFileOverTheSiteOptionsAndRoutes(): void
    {
        $repository = (new LayeredFileRepositoryFactory())(new InMemoryContainer([
            'config' => [
                'pagecache' => [
                    'file'    => '/srv/site/pagecache.local.php',
                    'options' => ['cache' => true, 'ttl' => 600],
                    'routes'  => ['^/api' => ['cache' => false]],
                ],
            ],
        ]));

        static::assertEquals(
            new LayeredFileRepository(
                '/srv/site/pagecache.local.php',
                new CacheControl(true, ['ttl' => 600], ['^/api' => ['cache' => false]]),
            ),
            $repository,
        );
    }

    /**
     * @param array<string, mixed> $services
     */
    #[Test]
    #[DataProvider('unsetFileProvider')]
    public function usesTheDefaultFileUnderTheWorkingDirectoryWhenNoneIsConfigured(array $services): void
    {
        static::assertEquals(
            new LayeredFileRepository(getcwd() . '/config/autoload/pagecache.local.php'),
            (new LayeredFileRepositoryFactory())(new InMemoryContainer($services)),
        );
    }
}
