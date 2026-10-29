<?php

namespace App\Controller;

use App\Entity\Utilisateur;
use App\Service\JournalService;
use App\Service\SauvegardeService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Sauvegardes de la base (EF-47, ENF-15), réservées à l'administrateur : liste, création immédiate, téléchargement.
 * La RESTAURATION n'est volontairement pas dans l'API (elle remplace toutes les données) : elle se fait en ligne de
 * commande sur le serveur, avec `php bin/console app:restauration`. La sauvegarde quotidienne est faite par `app:sauvegarde`.
 */
#[Route('/api/admin/sauvegardes')]
#[IsGranted('ROLE_ADMIN')]
class SauvegardeApiController extends AbstractController
{
    public function __construct(
        private SauvegardeService $sauvegardes,
        private JournalService $journal,
    ) {
    }

    #[Route('', name: 'api_admin_sauvegardes_lister', methods: ['GET'])]
    public function lister(): JsonResponse
    {
        return $this->json($this->sauvegardes->lister());
    }

    #[Route('', name: 'api_admin_sauvegardes_creer', methods: ['POST'])]
    public function creer(#[CurrentUser] Utilisateur $admin): JsonResponse
    {
        try {
            $sauvegarde = $this->sauvegardes->creer();
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], 503);
        }
        $this->journal->enregistrer('sauvegarde_creee', $admin, sprintf('fichier=%s taille=%d', $sauvegarde['nom'], $sauvegarde['taille']));

        return $this->json($sauvegarde, 201);
    }

    /** Télécharge une sauvegarde : elle contient toutes les données personnelles, l'accès est journalisé. */
    #[Route('/{nom}', name: 'api_admin_sauvegardes_telecharger', methods: ['GET'], requirements: ['nom' => 'orient-admin-\d{8}-\d{6}\.dump'])]
    public function telecharger(string $nom, #[CurrentUser] Utilisateur $admin): Response
    {
        $chemin = $this->sauvegardes->chemin($nom);
        if ($chemin === null) {
            return $this->json(['error' => 'Sauvegarde non trouvée'], 404);
        }
        $this->journal->enregistrer('sauvegarde_telechargee', $admin, 'fichier=' . $nom);

        return $this->file($chemin, $nom, ResponseHeaderBag::DISPOSITION_ATTACHMENT);
    }
}
