<?php

namespace App\Controller;

use App\Entity\RendezVous;
use App\Entity\Utilisateur;
use App\Repository\DemandeRepository;
use App\Repository\RendezVousRepository;
use App\Service\RendezVousService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Rendez-vous liés à une demande. Le citoyen réserve (statut en_attente) et
 * annule les siens ; l'agent voit ceux de son service, les confirme ou les
 * refuse, les annule et note l'issue (termine / absent) ; admin et
 * responsable consultent tout.
 */
#[Route('/api/rendez-vous')]
class RendezVousApiController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private RendezVousRepository $rendezVousRepository,
        private DemandeRepository $demandeRepository,
        private RendezVousService $rendezVousService,
    ) {
    }

    /** Corps : { "demandeId": 12, "creneauId": 34 } */
    #[Route('', name: 'api_rdv_create', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] Utilisateur $utilisateur): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $demandeId = $data['demandeId'] ?? null;
        $creneauId = $data['creneauId'] ?? null;
        if (!$demandeId || !$creneauId) {
            return $this->json(['error' => 'demandeId et creneauId sont requis'], 400);
        }

        $demande = $this->demandeRepository->find($demandeId);
        if (!$demande) {
            return $this->json(['error' => 'Demande non trouvée'], 404);
        }
        // Seul le citoyen auteur de la demande peut réserver (ou un admin).
        if ($demande->getUtilisateur() !== $utilisateur && !$this->isGranted('ROLE_ADMIN')) {
            return $this->json(['error' => 'Accès refusé'], 403);
        }

        try {
            $rdv = $this->rendezVousService->reserver($demande, (int) $creneauId);
            $this->em->flush();
        } catch (\DomainException $e) {
            return $this->json(['error' => $e->getMessage()], $e->getCode() ?: 400);
        }

        return $this->json($this->toArray($rdv), 201);
    }

    #[Route('', name: 'api_rdv_list', methods: ['GET'])]
    public function list(#[CurrentUser] Utilisateur $utilisateur): JsonResponse
    {
        $qb = $this->rendezVousRepository->createQueryBuilder('r')
            ->join('r.creneau', 'c')
            ->join('r.demande', 'd')
            ->orderBy('c.date', 'ASC')
            ->addOrderBy('c.heure', 'ASC');

        if ($this->isGranted('ROLE_ADMIN') || $this->isGranted('ROLE_RESPONSABLE')) {
            // tout
        } elseif ($this->isGranted('ROLE_AGENT')) {
            $service = $utilisateur->getService();
            if (!$service) {
                return $this->json([]);
            }
            $qb->andWhere('c.service = :service')->setParameter('service', $service);
        } else {
            $qb->andWhere('d.utilisateur = :utilisateur')->setParameter('utilisateur', $utilisateur);
        }

        return $this->json(array_map(fn(RendezVous $r) => $this->toArray($r), $qb->getQuery()->getResult()));
    }

    #[Route('/{id}', name: 'api_rdv_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id, #[CurrentUser] Utilisateur $utilisateur): JsonResponse
    {
        $rdv = $this->rendezVousRepository->find($id);
        if (!$rdv) {
            return $this->json(['error' => 'Rendez-vous non trouvé'], 404);
        }
        if (!$this->peutAcceder($rdv, $utilisateur, true)) {
            return $this->json(['error' => 'Accès refusé'], 403);
        }

        return $this->json($this->toArray($rdv));
    }

    #[Route('/{id}/annuler', name: 'api_rdv_annuler', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function annuler(int $id, #[CurrentUser] Utilisateur $utilisateur): JsonResponse
    {
        $rdv = $this->rendezVousRepository->find($id);
        if (!$rdv) {
            return $this->json(['error' => 'Rendez-vous non trouvé'], 404);
        }
        if (!$this->peutAcceder($rdv, $utilisateur, false)) {
            return $this->json(['error' => 'Accès refusé'], 403);
        }

        try {
            $this->rendezVousService->annuler($rdv);
        } catch (\DomainException $e) {
            return $this->json(['error' => $e->getMessage()], $e->getCode() ?: 400);
        }

        return $this->json($this->toArray($rdv));
    }

    /** Décision de l'agent sur un rendez-vous en attente : { "decision": "confirmer" | "refuser" } */
    #[Route('/{id}/decision', name: 'api_rdv_decision', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_AGENT')]
    public function decision(int $id, Request $request, #[CurrentUser] Utilisateur $utilisateur): JsonResponse
    {
        $rdv = $this->rendezVousRepository->find($id);
        if (!$rdv) {
            return $this->json(['error' => 'Rendez-vous non trouvé'], 404);
        }
        if (!$this->peutAcceder($rdv, $utilisateur, false)) {
            return $this->json(['error' => 'Accès refusé'], 403);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $decision = $data['decision'] ?? '';
        if (!in_array($decision, ['confirmer', 'refuser'], true)) {
            return $this->json(['error' => 'decision invalide (confirmer ou refuser)'], 400);
        }

        try {
            $decision === 'confirmer'
                ? $this->rendezVousService->confirmer($rdv)
                : $this->rendezVousService->refuser($rdv);
        } catch (\DomainException $e) {
            return $this->json(['error' => $e->getMessage()], $e->getCode() ?: 400);
        }

        return $this->json($this->toArray($rdv));
    }

    /** Issue du rendez-vous, notée par l'agent : { "statut": "termine" | "absent" } */
    #[Route('/{id}/statut', name: 'api_rdv_statut', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_AGENT')]
    public function changerStatut(int $id, Request $request, #[CurrentUser] Utilisateur $utilisateur): JsonResponse
    {
        $rdv = $this->rendezVousRepository->find($id);
        if (!$rdv) {
            return $this->json(['error' => 'Rendez-vous non trouvé'], 404);
        }
        if (!$this->peutAcceder($rdv, $utilisateur, false)) {
            return $this->json(['error' => 'Accès refusé'], 403);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $statut = trim($data['statut'] ?? '');
        if (!in_array($statut, ['termine', 'absent'], true)) {
            return $this->json(['error' => 'Statut invalide. Valeurs possibles : termine, absent (pour annuler : PATCH /annuler)'], 400);
        }
        if ($rdv->getStatut() !== 'confirme') {
            return $this->json(['error' => 'Seul un rendez-vous confirmé peut être clôturé'], 409);
        }

        $rdv->setStatut($statut);
        $this->em->flush();

        return $this->json($this->toArray($rdv));
    }

    /** $lecture = true : admin/responsable inclus ; false : actions d'écriture. */
    private function peutAcceder(RendezVous $rdv, Utilisateur $utilisateur, bool $lecture): bool
    {
        if ($this->isGranted('ROLE_ADMIN')) {
            return true;
        }
        if ($lecture && $this->isGranted('ROLE_RESPONSABLE')) {
            return true;
        }
        if ($this->isGranted('ROLE_AGENT')) {
            $service = $utilisateur->getService();

            return $service !== null && $rdv->getCreneau()?->getService() === $service;
        }

        return $rdv->getDemande()?->getUtilisateur() === $utilisateur;
    }

    private function toArray(RendezVous $rdv): array
    {
        $creneau = $rdv->getCreneau();
        $demande = $rdv->getDemande();
        $service = $creneau?->getService();

        return [
            'id' => $rdv->getId(),
            'statut' => $rdv->getStatut(),
            'date' => $creneau?->getDate()?->format('Y-m-d'),
            'heure' => $creneau?->getHeure(),
            'creneauId' => $creneau?->getId(),
            'demande' => [
                'id' => $demande?->getId(),
                'texte' => $demande?->getTextDemande(),
                'statut' => $demande?->getStatus(),
            ],
            'service' => [
                'id' => $service?->getId(),
                'nom' => $service?->getNom(),
                'adresse' => $service?->getAdresse(),
            ],
            'citoyenId' => $demande?->getUtilisateur()?->getId(),
            'citoyenNom' => $demande?->getUtilisateur()?->getNom(),
            'citoyenPrenom' => $demande?->getUtilisateur()?->getPrenom(),
        ];
    }
}
