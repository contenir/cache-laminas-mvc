<?php

declare(strict_types=1);

namespace Contenir\PageCache\Laminas\Mvc\View\Helper;

use Contenir\PageCache\Laminas\Mvc\Listener\CacheStrategy;
use Laminas\EventManager\EventManagerInterface;
use Laminas\Form\ElementInterface;
use Laminas\Form\View\Helper\FormHidden;

/**
 * Renders a Csrf element the way laminas-form does, through the `formhidden`
 * helper, after firing CacheStrategy::EVENT_DISABLE so the page-cache
 * listener does not store a response carrying a session-bound token.
 *
 * FormElementDisableCacheDelegator maps the Csrf element class to this helper
 * on the real FormElement helper, so `formElement()`, `formRow()`,
 * `formCollection()` and `form()` reach it unchanged.
 *
 * @api
 */
final readonly class FormCsrfDisableCache
{
    public function __construct(
        private EventManagerInterface $events,
        private FormHidden $hidden,
    ) {}

    public function render(ElementInterface $element): string
    {
        $this->events->trigger(CacheStrategy::EVENT_DISABLE);

        return $this->hidden->render($element);
    }

    /**
     * Proxies to render(); without an element, returns the helper itself.
     */
    public function __invoke(?ElementInterface $element = null): self|string
    {
        return null === $element ? $this : $this->render($element);
    }
}
