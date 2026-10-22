<?php

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;

/**
 * Échec de connexion : réponse habituelle de Lexik (401), sauf quand la limitation
 * des tentatives (login_throttling) est atteinte : 429 + en-tête Retry-After.
 */
class LoginFailureHandler implements AuthenticationFailureHandlerInterface
{
    public function __construct(
        #[Autowire(service: 'lexik_jwt_authentication.handler.authentication_failure')]
        private AuthenticationFailureHandlerInterface $lexik,
    ) {
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        if (!$exception instanceof TooManyLoginAttemptsAuthenticationException) {
            return $this->lexik->onAuthenticationFailure($request, $exception);
        }

        $donnees = $exception->getMessageData();
        $minutes = max(1, (int) ($donnees['%minutes%'] ?? 1));

        return new JsonResponse(
            ['error' => sprintf('Trop de tentatives de connexion. Réessayez dans %d minute%s.', $minutes, $minutes > 1 ? 's' : '')],
            Response::HTTP_TOO_MANY_REQUESTS,
            ['Retry-After' => (string) ($minutes * 60)],
        );
    }
}
