<?php

declare(strict_types=1);

namespace Contenir\PageCache\Laminas\Mvc\Tests\Integration\Factory;

use Contenir\PageCache\CacheControl;
use Contenir\PageCache\Laminas\Mvc\Factory\LayeredFileRepositoryFactory;
use Contenir\PageCache\Laminas\Mvc\Tests\TestAsset\Container\InMemoryContainer;
use Contenir\PageCache\Repository\LayeredFileRepository;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function file_put_contents;
use function is_file;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;
use function var_export;

/**
 * The admin's pagecache.local.php, read through the repository the factory
 * builds when the container registers none.
 */
#[Group('integration')]
#[Group('cache')]
final class LayeredFileRepositoryFactoryTest extends TestCase
{
    private string $file;

    /**
     * Admin-file contents that carry no usable override. Null leaves the file
     * missing.
     *
     * @return array<string, array{?string}>
     */
    public static function unusableAdminFileProvider(): array
    {
        return [
            'no file'                           => [null],
            'file returns no array'             => ['<?php return 42;'],
            'no pagecache section'              => ["<?php return ['maintenance' => ['enabled' => true]];"],
            'pagecache is not an array'         => ["<?php return ['pagecache' => 'off'];"],
            'options and routes are not arrays' => [
                "<?php return ['pagecache' => ['options' => 'off', 'routes' => 'none']];",
            ],
        ];
    }

    #[Test]
    public function inheritsSiteSettingsTheAdminFileDoesNotSet(): void
    {
        $this->writeAdminFile(['options' => ['cache_with_cookie' => true]]);

        static::assertEquals(
            new CacheControl(true, ['ttl' => 600, 'cache_with_cookie' => true], ['^/api' => ['cache' => false]]),
            $this->repository(['cache' => true, 'ttl' => 600], ['^/api' => ['cache' => false]])->get(),
        );
    }

    #[Test]
    #[DataProvider('unusableAdminFileProvider')]
    public function keepsTheSiteSettingsWhenTheAdminFileHasNoUsableOverrides(?string $contents): void
    {
        if (null !== $contents) {
            file_put_contents($this->file, $contents);
        }

        static::assertEquals(
            new CacheControl(true, ['ttl' => 600], ['^/api' => ['cache' => false]]),
            $this->repository(['cache' => true, 'ttl' => 600], ['^/api' => ['cache' => false]])->get(),
        );
    }

    /**
     * The admin manages routes as one list, so its routes replace the site's
     * rather than merging with them.
     */
    #[Test]
    public function replacesTheSiteRoutesWithTheAdminRoutes(): void
    {
        $this->writeAdminFile(['routes' => ['^/b' => ['cache' => false]]]);

        static::assertSame(
            ['^/b' => ['cache' => false]],
            $this->repository(['cache' => true], ['^/a' => ['cache' => false]])->get()->routes,
        );
    }

    #[Test]
    public function seesAnAdminChangeOnTheNextRead(): void
    {
        $repository = $this->repository(['cache' => true]);
        $this->writeAdminFile(['options' => ['cache' => false]]);
        $before = $repository->get()->enabled;

        $this->writeAdminFile(['options' => ['cache' => true]]);

        static::assertSame([false, true], [$before, $repository->get()->enabled]);
    }

    #[Override]
    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/contenir-page-cache-laminas-mvc-' . uniqid(more_entropy: true) . '.php';
    }

    #[Override]
    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, array<string, mixed>> $routes
     */
    private function repository(array $options, array $routes = []): LayeredFileRepository
    {
        return (new LayeredFileRepositoryFactory())(new InMemoryContainer([
            'config' => ['pagecache' => ['file' => $this->file, 'options' => $options, 'routes' => $routes]],
        ]));
    }

    /**
     * @param array<string, mixed> $pagecache
     */
    private function writeAdminFile(array $pagecache): void
    {
        file_put_contents($this->file, '<?php return ' . var_export(['pagecache' => $pagecache], return: true) . ';');
    }
}
