<?php

namespace App\Controller;

use App\Entity\Avis;
use App\Entity\Utilisateur;
use App\Repository\AvisRepository;
use App\Repository\RendezVousRepository;
use App\Repository\ServiceRepository;
use App\Service\AvisService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Avis sur les rendez-vous terminés. Le citoyen note le sien (une seule
 * fois) ; l'agent consulte ceux de son service ; responsable et admin
 * consultent tout (filtres : ?serviceId=, ?note=).
 */
#[Route('/api/avis')]
class AvisApiController extends AbstractController
{
    public function __construct(
        private AvisRepository $avisRepository,
        private RendezVousRepository $rendezVousRepository,
        private ServiceRepository $serviceRepository,
        private AvisService $avisService,
    ) {
    }

    /** Corps : { "rendezVousId": 12, "note": 4, "commentaire": "facultatif" } */
    #[Route('', name: 'api_avis_create', methods: ['POST'])]
    #[IsGranted('ROLE_CITOYEN')]
    public function create(Request $request, #[CurrentUser] Utilisateur $utilisateur): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $rdvId = $data['rendezVousId'] ?? null;
        if (!$rdvId || !is_numeric($rdvId)) {
            return $this->json(['error' => 'rendezVousId est requis'], 400);
        }

        $rdv = $this->rendezVousRepository->find((int) $rdvId);
        if (!$rdv) {
            return $this->json(['error' => 'Rendez-vous non trouvé'], 404);
        }

        try {
            $avis = $this->avisService->donner($rdv, $utilisateur, $data['note'] ?? null, $data['commentaire'] ?? null);
        } catch (\DomainException $e) {
            return $this->json(['error' => $e->getMessage()], $e->getCode() ?: 400);
        }

        return $this->json($this->toArray($avis), 201);
    }

    #[Route('', name: 'api_avis_list', methods: ['GET'])]
    public function list(Request $request, #[CurrentUser] Utilisateur $utilisateur): JsonResponse
    {
        $note = $request->query->get('note');
        if ($note !== null && $note !== '' && (!ctype_digit((string) $note) || (int) $note < 1 || (int) $note > 5)) {
            return $this->json(['error' => 'note invalide (1 à 5)'], 400);
        }
        $note = ($note === null || $note === '') ? null : (int) $note;

        $service = null;
        $citoyen = null;
        if ($this->isGranted('ROLE_ADMIN') || $this->isGranted('ROLE_RESPONSABLE')) {
            $serviceId = $request->query->get('serviceId');
            if ($serviceId !== null && $serviceId !== '') {
                $service = $this->serviceRepository->find((int) $serviceId);
                if (!$service) {
                    return $this->json([]);
                }
            }
        } elseif ($this->isGranted('ROLE_AGENT')) {
            $service = $utilisateur->getService();
            if (!$service) {
                return $this->json([]);
            }
        } else {
            $citoyen = $utilisateur;
        }

        $avis = $this->avisRepository->rechercher($service, $citoyen, $note);

        return $this->json(array_map(fn(Avis $a) => $this->toArray($a), $avis));
    }

    /** Avis d'un rendez-vous (404 s'il n'y en a pas encore : utile pour savoir si on peut noter). */
    #[Route('/rendez-vous/{id}', name: 'api_avis_par_rdv', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function parRendezVous(int $id, #[CurrentUser] Utilisateur $utilisateur): JsonResponse
    {
        $rdv = $this->rendezVousRepository->find($id);
        if (!$rdv) {
            return $this->json(['error' => 'Rendez-vous non trouvé'], 404);
        }

        if ($this->isGranted('ROLE_ADMIN') || $this->isGranted('ROLE_RESPONSABLE')) {
            $autorise = true;
        } elseif ($this->isGranted('ROLE_AGENT')) {
            $service = $utilisateur->getService();
            $autorise = $service !== null && $rdv->getCreneau()?->getService() === $service;
        } else {
            $autorise = $rdv->getDemande()?->getUtilisateur() === $utilisateur;
        }
        if (!$autorise) {
            return $this->json(['error' => 'Accès refusé'], 403);
        }

        $avis = $this->avisRepository->findOneBy(['rendezVous' => $rdv]);
        if (!$avis) {
            return $this->json(['error' => 'Aucun avis pour ce rendez-vous'], 404);
        }

        return $this->json($this->toArray($avis));
    }

    private function toArray(Avis $a): array
    {
        $rdv = $a->getRendezVous();
        $creneau = $rdv?->getCreneau();
        $service = $creneau?->getService();
        $citoyen = $rdv?->getDemande()?->getUtilisateur();

        return [
            'id' => $a->getId(),
            'note' => $a->getNote(),
            'commentaire' => $a->getCommentaire(),
            'date' => $a->getDateCreation()?->format(DATE_ATOM),
            'rendezVousId' => $rdv?->getId(),
            'rendezVousDate' => $creneau?->getDate()?->format('Y-m-d'),
            'service' => [
                'id' => $service?->getId(),
                'nom' => $service?->getNom(),
            ],
            'citoyenNom' => $citoyen?->getNom(),
            'citoyenPrenom' => $citoyen?->getPrenom(),
        ];
    }
}
