<?php

namespace App\Controller;

use App\Service\MotDePasseOublieService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** Mot de passe oublié (routes publiques). */
#[Route('/api/mot-de-passe')]
class MotDePasseApiController extends AbstractController
{
    public function __construct(private MotDePasseOublieService $service)
    {
    }

    /** Corps : {"email": "..."}. Réponse identique que le compte existe ou non. */
    #[Route('/oublie', name: 'api_mdp_oublie', methods: ['POST'])]
    public function oublie(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $email = is_array($data) && is_string($data['email'] ?? null) ? trim($data['email']) : '';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json(['error' => 'Un e-mail valide est requis'], 400);
        }

        $this->service->demander($email);

        return $this->json(['message' => 'Si un compte existe avec cet e-mail, un lien de réinitialisation vient d\'être envoyé.'], 202);
    }

    /** Corps : {"token": "...", "password": "..."}. */
    #[Route('/reinitialiser', name: 'api_mdp_reinitialiser', methods: ['POST'])]
    public function reinitialiser(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $token = is_array($data) && is_string($data['token'] ?? null) ? trim($data['token']) : '';
        $password = is_array($data) && is_string($data['password'] ?? null) ? $data['password'] : '';
        if ($token === '' || $password === '') {
            return $this->json(['error' => 'token et password sont requis'], 400);
        }

        try {
            $this->service->reinitialiser($token, $password);
        } catch (\DomainException $e) {
            return $this->json(['error' => $e->getMessage()], $e->getCode() ?: 400);
        }

        return $this->json(['message' => 'Mot de passe modifié. Vous pouvez vous connecter.']);
    }
}
