<?php

declare(strict_types=1);

namespace Contenir\Cache\Laminas\Mvc\View\Helper\Delegator;

use Contenir\Cache\Laminas\Mvc\Listener\CacheStrategy;
use Laminas\EventManager\EventManagerInterface;
use Laminas\Form\Element\Csrf;
use Laminas\Form\ElementInterface;
use Laminas\Form\View\Helper\FormElement;
use Psr\Container\ContainerInterface;

/**
 * Delegator for the FormElement view helper that fires CacheStrategy::EVENT_DISABLE
 * whenever a Csrf element is rendered, so the page-cache listener does not
 * store a response containing a session-bound token.
 *
 * Toggleable via config[pagecache][disable_on_csrf]. When false, the
 * delegator returns the original helper untouched (zero overhead).
 *
 * Soft-depends on laminas/laminas-form. The delegator class is only
 * autoloaded when the FormElement service is requested, which can only
 * happen when laminas-form is installed.
 */
final class FormElementDisableCacheDelegator
{
    /**
     * @param array<string, mixed>|null $options
     */
    public function __invoke(
        ContainerInterface $container,
        string $name,
        callable $callback,
        ?array $options = null,
    ): FormElement {
        $config = $container->has('config') ? $container->get('config') : [];

        if (! ($config['pagecache']['disable_on_csrf'] ?? true)) {
            return $callback();
        }

        $callback();
        $events = $container->get('Application')->getEventManager();

        return new class ($events) extends FormElement {
            public function __construct(private EventManagerInterface $events)
            {
            }

            public function render(ElementInterface $element): string
            {
                if ($element instanceof Csrf) {
                    $this->events->trigger(CacheStrategy::EVENT_DISABLE);
                }

                return parent::render($element);
            }
        };
    }
}
