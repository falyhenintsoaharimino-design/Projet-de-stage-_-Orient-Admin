<?php

namespace App\EventListener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Le front-end (orientadmin-frontend) est servi en pages HTML statiques,
 * depuis une origine différente de celle de l'API Symfony (ports différents
 * en local, domaines différents en production). Sans en-têtes CORS, le
 * navigateur bloque silencieusement tous les appels fetch() vers /api/*.
 *
 * C'est une solution minimale écrite à la main : dans un environnement avec
 * accès à Packagist, préférez `composer require nelmio/cors-bundle`, plus
 * complet (gestion fine par route, credentials, etc.). Ici, l'accès réseau
 * du bac à sable ne permettait pas d'installer ce paquet.
 */
class CorsSubscriber implements EventSubscriberInterface
{
    public function __construct(
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire(env: 'CORS_ALLOW_ORIGIN')]
        private readonly string $allowedOrigin = '*',
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 250],
            KernelEvents::RESPONSE => ['onKernelResponse', 0],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if (!str_starts_with($request->getPathInfo(), '/api')) {
            return;
        }

        // Répond directement aux requêtes de pré-vol (preflight) OPTIONS
        // envoyées automatiquement par le navigateur avant chaque
        // requête "non simple" (POST/PUT/DELETE en JSON, en-tête Authorization...).
        if ($request->getMethod() === 'OPTIONS') {
            $response = new JsonResponse(null, 204);
            $this->addCorsHeaders($response, $request->headers->get('Access-Control-Request-Headers'));
            $event->setResponse($response);
        }
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();

        if (!str_starts_with($request->getPathInfo(), '/api')) {
            return;
        }

        $this->addCorsHeaders($event->getResponse(), $request->headers->get('Access-Control-Request-Headers'));
    }

    private function addCorsHeaders(Response $response, ?string $requestedHeaders): void
    {
        $response->headers->set('Access-Control-Allow-Origin', $this->allowedOrigin);
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
        $response->headers->set(
            'Access-Control-Allow-Headers',
            $requestedHeaders ?: 'Content-Type, Authorization'
        );
        $response->headers->set('Access-Control-Max-Age', '3600');
    }
}
