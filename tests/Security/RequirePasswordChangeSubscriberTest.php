<?php

namespace App\Tests\Security;

use App\Entity\User;
use App\EventSubscriber\RequirePasswordChangeSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class RequirePasswordChangeSubscriberTest extends TestCase
{
    public function testRedirectsFlaggedUserAwayFromOtherRoutes(): void
    {
        $security = $this->createMock(Security::class);
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $kernel = $this->createMock(HttpKernelInterface::class);

        $security
            ->expects($this->once())
            ->method('getUser')
            ->willReturn(
                (new User())
                    ->setEmail('user@example.com')
                    ->setPassword('hashed-password')
                    ->setRequiresPasswordChange(true),
            );

        $urlGenerator
            ->expects($this->once())
            ->method('generate')
            ->with('app_user_change_password')
            ->willReturn('/user/change-password');

        $subscriber = new RequirePasswordChangeSubscriber($security, $urlGenerator);
        $request = new Request();
        $request->attributes->set('_route', 'app_user_profile_page');
        $event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);

        $subscriber->onKernelRequest($event);

        self::assertSame('/user/change-password', $event->getResponse()?->headers->get('Location'));
    }

    public function testDoesNotRedirectOnExcludedRoute(): void
    {
        $security = $this->createMock(Security::class);
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $kernel = $this->createMock(HttpKernelInterface::class);

        $security
            ->expects($this->once())
            ->method('getUser')
            ->willReturn(
                (new User())
                    ->setEmail('user@example.com')
                    ->setPassword('hashed-password')
                    ->setRequiresPasswordChange(true),
            );

        $urlGenerator->expects($this->never())->method('generate');

        $subscriber = new RequirePasswordChangeSubscriber($security, $urlGenerator);
        $request = new Request();
        $request->attributes->set('_route', 'app_user_change_password');
        $event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);

        $subscriber->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }
}
