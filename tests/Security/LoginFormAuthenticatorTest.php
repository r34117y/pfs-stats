<?php

namespace App\Tests\Security;

use App\Entity\User;
use App\Security\LoginFormAuthenticator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

class LoginFormAuthenticatorTest extends TestCase
{
    public function testRedirectsFlaggedUserToPasswordChangePageAfterLogin(): void
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator
            ->expects($this->once())
            ->method('generate')
            ->with('app_user_change_password')
            ->willReturn('/user/change-password');

        $token = $this->createMock(TokenInterface::class);
        $user = (new User())
            ->setEmail('user@example.com')
            ->setPassword('hashed-password')
            ->setRequiresPasswordChange(true);

        $token
            ->expects($this->once())
            ->method('getUser')
            ->willReturn($user);

        $authenticator = new LoginFormAuthenticator($urlGenerator);
        $response = $authenticator->onAuthenticationSuccess(new Request(), $token, 'main');

        self::assertSame(302, $response?->getStatusCode());
        self::assertSame('/user/change-password', $response?->headers->get('Location'));
    }
}
