<?php

namespace App\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractLoginFormAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\SecurityRequestAttributes;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

class LoginFormAuthenticator extends AbstractLoginFormAuthenticator
{
    use TargetPathTrait;

    public const LOGIN_ROUTE = 'app_login';

    public function __construct(private readonly UrlGeneratorInterface $urlGenerator)
    {
    }

    public function authenticate(Request $request): Passport
    {
        $payload = $this->extractPayload($request);
        $email = trim((string) ($payload['email'] ?? $payload['_username'] ?? ''));
        $password = (string) ($payload['password'] ?? $payload['_password'] ?? '');

        if (!$this->isStatelessRequest($request) && $request->hasSession()) {
            $request->getSession()->set(SecurityRequestAttributes::LAST_USERNAME, $email);
        }

        $badges = [new RememberMeBadge()];

        if (!$this->isJsonRequest($request)) {
            $badges[] = new CsrfTokenBadge('authenticate', (string) ($payload['_csrf_token'] ?? ''));
        }

        return new Passport(
            new UserBadge($email),
            new PasswordCredentials($password),
            $badges,
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        if (!$this->isStatelessRequest($request) && $request->hasSession()) {
            if ($targetPath = $this->getTargetPath($request->getSession(), $firewallName)) {
                return new RedirectResponse($targetPath);
            }
        }

        if ($this->wantsJsonResponse($request)) {
            return new JsonResponse(['status' => 'ok']);
        }

        return new RedirectResponse($this->urlGenerator->generate('app_ranking_page'));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        if ($this->wantsJsonResponse($request)) {
            return new JsonResponse(['message' => 'Authentication failed.'], Response::HTTP_UNAUTHORIZED);
        }

        return parent::onAuthenticationFailure($request, $exception);
    }

    protected function getLoginUrl(Request $request): string
    {
        return $this->urlGenerator->generate(self::LOGIN_ROUTE);
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        if ($this->wantsJsonResponse($request)) {
            return new JsonResponse(['message' => 'Authentication required.'], Response::HTTP_UNAUTHORIZED);
        }

        return parent::start($request, $authException);
    }

    private function wantsJsonResponse(Request $request): bool
    {
        if ($request->isXmlHttpRequest()) {
            return true;
        }

        $accept = (string) $request->headers->get('Accept');
        if (str_contains($accept, 'application/json')) {
            return true;
        }

        return str_starts_with($request->getPathInfo(), '/api/');
    }

    private function isJsonRequest(Request $request): bool
    {
        $contentType = (string) $request->headers->get('Content-Type');

        return str_contains($contentType, 'application/json');
    }

    private function isStatelessRequest(Request $request): bool
    {
        return (bool) $request->attributes->get('_stateless', false);
    }

    /**
     * @return array<string, mixed>
     */
    private function extractPayload(Request $request): array
    {
        if (!$this->isJsonRequest($request)) {
            return $request->request->all();
        }

        $content = (string) $request->getContent();
        if ($content === '') {
            return [];
        }

        $decoded = json_decode($content, true);

        return is_array($decoded) ? $decoded : [];
    }
}
