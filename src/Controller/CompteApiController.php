<?php

namespace App\Controller;

use App\Entity\Utilisateur;
use App\Service\CompteService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/** Mon compte : profil et préférences, export de mes données, suppression (étape 6). */
#[Route('/api/me')]
class CompteApiController extends AbstractController
{
    public function __construct(private CompteService $compte)
    {
    }

    /** Corps : un ou plusieurs champs parmi nom, prenom, telephone, langue, canalNotification. */
    #[Route('', name: 'api_me_update', methods: ['PATCH'])]
    public function modifier(#[CurrentUser] Utilisateur $utilisateur, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        try {
            $this->compte->modifierProfil($utilisateur, is_array($data) ? $data : []);
        } catch (\DomainException $e) {
            return $this->json(['error' => $e->getMessage()], $e->getCode());
        }

        return $this->json($this->compte->profil($utilisateur));
    }

    #[Route('/donnees', name: 'api_me_donnees', methods: ['GET'])]
    public function donnees(#[CurrentUser] Utilisateur $utilisateur): JsonResponse
    {
        $reponse = $this->json($this->compte->exporterDonnees($utilisateur));
        $reponse->headers->set('Content-Disposition', 'attachment; filename="mes-donnees-orient-admin.json"');

        return $reponse;
    }

    /** Corps : {"motDePasse": "..."} (confirmation). Réservé aux comptes citoyens ; 204 sans corps. */
    #[Route('', name: 'api_me_delete', methods: ['DELETE'])]
    public function supprimer(
        #[CurrentUser] Utilisateur $utilisateur,
        Request $request,
        UserPasswordHasherInterface $hasher,
    ): Response {
        if ($utilisateur->getRole()?->getNom() !== 'citoyen') {
            return $this->json(['error' => 'Seuls les comptes citoyens se suppriment eux-mêmes ; les comptes du personnel sont gérés par un administrateur'], 403);
        }

        $data = json_decode($request->getContent(), true);
        $motDePasse = is_array($data) && is_string($data['motDePasse'] ?? null) ? $data['motDePasse'] : '';
        if ($motDePasse === '') {
            return $this->json(['error' => 'motDePasse est requis pour confirmer la suppression'], 400);
        }
        if (!$hasher->isPasswordValid($utilisateur, $motDePasse)) {
            return $this->json(['error' => 'Mot de passe incorrect'], 403);
        }

        $this->compte->supprimer($utilisateur);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
