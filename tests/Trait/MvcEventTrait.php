<?php

declare(strict_types=1);

namespace Contenir\PageCache\Laminas\Mvc\Tests\Trait;

use Laminas\EventManager\EventManagerInterface;
use Laminas\Http\PhpEnvironment\Response;
use Laminas\Http\Request as HttpRequest;
use Laminas\Mvc\ApplicationInterface;
use Laminas\Mvc\MvcEvent;
use Laminas\Stdlib\RequestInterface;
use Laminas\Stdlib\ResponseInterface;

/**
 * Builds HTTP requests and MvcEvents around a stubbed application.
 */
trait MvcEventTrait
{
    private function event(
        RequestInterface $request,
        ?ResponseInterface $applicationResponse = null,
        ?EventManagerInterface $events = null,
    ): MvcEvent {
        $application = $this->createStub(ApplicationInterface::class);
        $application->method('getResponse')->willReturn($applicationResponse ?? new Response());
        if (null !== $events) {
            $application->method('getEventManager')->willReturn($events);
        }

        $event = new MvcEvent();
        $event->setRequest($request);
        $event->setApplication($application);

        return $event;
    }

    private function finishEvent(ResponseInterface $response): MvcEvent
    {
        $event = new MvcEvent();
        $event->setResponse($response);

        return $event;
    }

    private function okResponse(string $body = '<p>page</p>'): Response
    {
        $response = new Response();
        $response->setStatusCode(200);
        $response->setContent($body);

        return $response;
    }

    /**
     * @param array<string, string> $headers
     */
    private function request(
        string $uri = 'http://example.com/page',
        string $method = HttpRequest::METHOD_GET,
        array $headers = [],
    ): HttpRequest {
        $request = new HttpRequest();
        $request->setUri($uri);
        $request->setMethod($method);
        $request->getHeaders()->addHeaders($headers);

        return $request;
    }
}
