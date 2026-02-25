<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class ApiCacheHeadersSubscriber implements EventSubscriberInterface
{
    private const int PUBLIC_SHARED_MAX_AGE = 86400;
    private const int PUBLIC_STALE_WHILE_REVALIDATE = 3600;

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => 'onKernelResponse',
        ];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $response = $event->getResponse();

        $path = $request->getPathInfo();
        if (!str_starts_with($path, '/api/')) {
            return;
        }

        if (!str_starts_with((string) $request->attributes->get('_route', ''), '_api_')) {
            return;
        }

        if (!$response->isSuccessful()) {
            return;
        }

        if (str_starts_with($path, '/api/user/profile/')) {
            $response->setPrivate();
            $response->setMaxAge(0);
            $response->setSharedMaxAge(0);
            $response->headers->addCacheControlDirective('no-store', true);

            return;
        }

        if ($request->isMethodCacheable()) {
            $response->setPublic();
            $response->setMaxAge(0);
            $response->setSharedMaxAge(self::PUBLIC_SHARED_MAX_AGE);
            $response->headers->addCacheControlDirective('stale-while-revalidate', self::PUBLIC_STALE_WHILE_REVALIDATE);
        }
    }
}
