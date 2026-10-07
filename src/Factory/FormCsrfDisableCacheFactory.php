<?php

declare(strict_types=1);

namespace Contenir\PageCache\Laminas\Mvc\Factory;

use Contenir\PageCache\Laminas\Mvc\View\Helper\FormCsrfDisableCache;
use Laminas\Form\View\Helper\FormHidden;
use Laminas\Mvc\ApplicationInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use RuntimeException;

use function get_debug_type;
use function sprintf;

/**
 * Builds the Csrf render helper from the MVC Application's event manager, the
 * one the page-cache listener listens to for CacheStrategy::EVENT_DISABLE,
 * and the `formhidden` helper from the `ViewHelperManager`, the helper
 * laminas-form's FormElement renders a Csrf element with.
 *
 * @api
 */
final class FormCsrfDisableCacheFactory
{
    /**
     * @template T of object
     *
     * @param class-string<T> $type
     *
     * @return T
     *
     * @throws RuntimeException When the service is not an instance of $type.
     */
    private static function assertType(string $id, string $type, mixed $service): object
    {
        if ($service instanceof $type) {
            return $service;
        }

        throw new RuntimeException(sprintf(
            'contenir/contenir-page-cache-laminas-mvc: service "%s" must resolve to a %s; got %s.',
            $id,
            $type,
            get_debug_type($service),
        ));
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $type
     *
     * @return T
     *
     * @throws RuntimeException When the service is not an instance of $type.
     * @throws ContainerExceptionInterface When the service cannot be built.
     */
    private static function service(ContainerInterface $container, string $id, string $type): object
    {
        return self::assertType($id, $type, $container->get($id));
    }

    /**
     * @throws RuntimeException When a service does not resolve to the expected type.
     * @throws ContainerExceptionInterface When a service cannot be built.
     */
    public function __invoke(ContainerInterface $container): FormCsrfDisableCache
    {
        return new FormCsrfDisableCache(
            self::service($container, 'Application', ApplicationInterface::class)->getEventManager(),
            self::service(
                self::service($container, 'ViewHelperManager', ContainerInterface::class),
                'formhidden',
                FormHidden::class,
            ),
        );
    }
}
