<?php

namespace App\Controller;

use App\Entity\Procedure;
use App\Entity\RendezVous;
use App\Entity\Utilisateur;
use App\Repository\DemandeRepository;
use App\Repository\RendezVousRepository;
use App\Service\JournalService;
use App\Service\NotificationService;
use App\Service\RendezVousService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Rendez-vous liés à une demande. Le citoyen réserve (statut en_attente), modifie et
 * annule les siens jusqu'au délai limite avant le rendez-vous ; l'agent voit ceux de son
 * service, les confirme ou les refuse, les reporte, les annule et note l'issue
 * (termine / absent) ; admin et responsable consultent tout.
 */
#[Route('/api/rendez-vous')]
class RendezVousApiController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private RendezVousRepository $rendezVousRepository,
        private DemandeRepository $demandeRepository,
        private RendezVousService $rendezVousService,
        private NotificationService $notifications,
        private JournalService $journal,
    ) {
    }

    /** Corps : { "demandeId": 12, "creneauId": 34, "procedureId": 5 } (procedureId facultatif : sert au récapitulatif « pièces à apporter »). */
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

        $procedure = null;
        if (!empty($data['procedureId'])) {
            $procedure = $this->em->find(Procedure::class, (int) $data['procedureId']);
            if (!$procedure) {
                return $this->json(['error' => 'Procédure introuvable'], 404);
            }
        }

        try {
            $rdv = $this->rendezVousService->reserver($demande, (int) $creneauId, $procedure);
            $this->em->flush();
        } catch (\DomainException $e) {
            return $this->json(['error' => $e->getMessage()], $e->getCode() ?: 400);
        }

        $this->journaliser('rdv_reserve', $utilisateur, $rdv);

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

    /**
     * Récapitulatif de confirmation (EF-28) : date, heure, lieu (adresse, horaires, contact), pièces à apporter,
     * et date limite de modification par le citoyen.
     */
    #[Route('/{id}/recapitulatif', name: 'api_rdv_recapitulatif', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function recapitulatif(int $id, #[CurrentUser] Utilisateur $utilisateur): JsonResponse
    {
        $rdv = $this->rendezVousRepository->find($id);
        if (!$rdv) {
            return $this->json(['error' => 'Rendez-vous non trouvé'], 404);
        }
        if (!$this->peutAcceder($rdv, $utilisateur, true)) {
            return $this->json(['error' => 'Accès refusé'], 403);
        }

        return $this->json($this->rendezVousService->recapitulatif($rdv));
    }

    /**
     * Le citoyen change de créneau et/ou de procédure : { "creneauId": 40, "procedureId": 5 } (au moins un des deux ;
     * procedureId null retire la procédure). Un changement de créneau remet le rendez-vous « en attente ».
     * Impossible passé le délai limite (409) ; l'agent utilise plutôt /reporter.
     */
    #[Route('/{id}', name: 'api_rdv_modifier', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function modifier(int $id, Request $request, #[CurrentUser] Utilisateur $utilisateur): JsonResponse
    {
        $rdv = $this->rendezVousRepository->find($id);
        if (!$rdv) {
            return $this->json(['error' => 'Rendez-vous non trouvé'], 404);
        }
        if (!$this->isGranted('ROLE_ADMIN') && $rdv->getDemande()?->getUtilisateur() !== $utilisateur) {
            return $this->json(['error' => 'Accès refusé'], 403);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['error' => 'Le corps de la requête doit être un objet JSON'], 400);
        }
        $creneauId = $data['creneauId'] ?? null;
        if ($creneauId !== null && !is_int($creneauId)) {
            return $this->json(['error' => 'creneauId doit être un entier'], 400);
        }
        $changerProcedure = array_key_exists('procedureId', $data);
        $procedure = null;
        if ($changerProcedure && $data['procedureId'] !== null) {
            $procedure = is_int($data['procedureId']) ? $this->em->find(Procedure::class, $data['procedureId']) : null;
            if (!$procedure) {
                return $this->json(['error' => 'Procédure introuvable'], 404);
            }
        }

        try {
            $this->rendezVousService->modifier($rdv, $creneauId, $procedure, $changerProcedure, !$this->isGranted('ROLE_ADMIN'));
        } catch (\DomainException $e) {
            return $this->json(['error' => $e->getMessage()], $e->getCode() ?: 400);
        }

        $this->journaliser('rdv_modifie', $utilisateur, $rdv);

        return $this->json($this->toArray($rdv));
    }

    /** Report par l'agent (EF-27) : { "creneauId": 41, "motif": "Absence de l'agent" } ; le rendez-vous est confirmé sur le nouveau créneau. */
    #[Route('/{id}/reporter', name: 'api_rdv_reporter', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_AGENT')]
    public function reporter(int $id, Request $request, #[CurrentUser] Utilisateur $utilisateur): JsonResponse
    {
        $rdv = $this->rendezVousRepository->find($id);
        if (!$rdv) {
            return $this->json(['error' => 'Rendez-vous non trouvé'], 404);
        }
        if (!$this->peutAcceder($rdv, $utilisateur, false)) {
            return $this->json(['error' => 'Accès refusé'], 403);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || !is_int($data['creneauId'] ?? null)) {
            return $this->json(['error' => 'creneauId (entier) est requis'], 400);
        }
        $motif = $data['motif'] ?? null;
        if ($motif !== null && (!is_string($motif) || strlen($motif) > 500)) {
            return $this->json(['error' => 'motif doit être un texte de 500 caractères au plus'], 400);
        }

        try {
            $this->rendezVousService->reporter($rdv, $data['creneauId'], $motif !== null && trim($motif) !== '' ? trim($motif) : null);
        } catch (\DomainException $e) {
            return $this->json(['error' => $e->getMessage()], $e->getCode() ?: 400);
        }

        $this->journaliser('rdv_reporte', $utilisateur, $rdv);

        return $this->json($this->toArray($rdv));
    }

    /**
     * Alerte « pièces manquantes » (EF-32) : { "pieces": ["Photo d'identité", "Livret de famille"], "message": "..." }.
     * Le citoyen reçoit une notification (et un e-mail ou SMS selon son canal) avec la liste de ce qu'il doit encore apporter.
     */
    #[Route('/{id}/pieces-manquantes', name: 'api_rdv_pieces_manquantes', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_AGENT')]
    public function piecesManquantes(int $id, Request $request, #[CurrentUser] Utilisateur $utilisateur): JsonResponse
    {
        $rdv = $this->rendezVousRepository->find($id);
        if (!$rdv) {
            return $this->json(['error' => 'Rendez-vous non trouvé'], 404);
        }
        if (!$this->peutAcceder($rdv, $utilisateur, false)) {
            return $this->json(['error' => 'Accès refusé'], 403);
        }

        $data = json_decode($request->getContent(), true);
        $pieces = is_array($data) ? ($data['pieces'] ?? null) : null;
        if (!is_array($pieces) || !array_is_list($pieces) || $pieces === [] || count($pieces) > 20
            || array_filter($pieces, fn($p) => !is_string($p) || trim($p) === '' || strlen($p) > 150) !== []) {
            return $this->json(['error' => 'pieces doit être une liste de 1 à 20 textes (150 caractères au plus)'], 400);
        }
        $message = $data['message'] ?? null;
        if ($message !== null && (!is_string($message) || strlen($message) > 500)) {
            return $this->json(['error' => 'message doit être un texte de 500 caractères au plus'], 400);
        }
        if (!in_array($rdv->getStatut(), ['en_attente', 'confirme'], true)) {
            return $this->json(['error' => 'Ce rendez-vous n\'est plus actif'], 409);
        }

        $pieces = array_map('trim', $pieces);
        $this->notifications->piecesManquantes($rdv, $pieces, $message !== null && trim($message) !== '' ? trim($message) : null);
        $this->em->flush();
        $this->journal->enregistrer('rdv_pieces_manquantes', $utilisateur, sprintf('rdv=%d pieces=%d', $rdv->getId(), count($pieces)));

        return $this->json(['prevenu' => true, 'pieces' => $pieces]);
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
            // Le citoyen est soumis au délai limite ; l'agent et l'admin non.
            $this->rendezVousService->annuler($rdv, $utilisateur, !$this->isGranted('ROLE_AGENT'));
        } catch (\DomainException $e) {
            return $this->json(['error' => $e->getMessage()], $e->getCode() ?: 400);
        }

        $this->journaliser('rdv_annule', $utilisateur, $rdv);

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

        $this->journaliser($decision === 'confirmer' ? 'rdv_confirme' : 'rdv_refuse', $utilisateur, $rdv);

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
        if ($statut === 'termine') {
            // Invite le citoyen à donner son avis (POST /api/avis).
            $this->notifications->notifierRendezVous($rdv, 'termine');
        }
        $this->em->flush();
        $this->journaliser('rdv_' . $statut, $utilisateur, $rdv);

        return $this->json($this->toArray($rdv));
    }

    private function journaliser(string $action, Utilisateur $utilisateur, RendezVous $rdv): void
    {
        $this->journal->enregistrer($action, $utilisateur, sprintf('rdv=%d demande=%d', $rdv->getId(), $rdv->getDemande()?->getId() ?? 0));
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
            'heureFin' => $creneau?->getFin()?->format('H:i'),
            'creneauId' => $creneau?->getId(),
            'procedureId' => $rdv->getProcedure()?->getId(),
            'modifiableJusquau' => $this->rendezVousService->limiteModification($rdv)?->format(\DATE_ATOM),
            'modifiableParLeCitoyen' => $this->rendezVousService->modifiableParLeCitoyen($rdv),
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
