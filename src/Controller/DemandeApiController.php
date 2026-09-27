<?php

namespace App\Controller;

use App\Entity\Demande;
use App\Entity\HistoriqueStatut;
use App\Entity\Utilisateur;
use App\Repository\DemandeRepository;
use App\Repository\ServiceRepository;
use App\Service\OrientationEngine;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Reçoit la demande d'un citoyen, la fait orienter par le moteur
 * (App\Service\OrientationEngine), l'enregistre, et permet ensuite le suivi
 * (citoyen), la réorientation et le traitement (agent), et la consultation
 * globale (admin/responsable). Couvre EF-13 à EF-20 et EF-36 du cahier des
 * charges.
 *
 * Statuts possibles (Demande::status) : soumise, orientee, rendez_vous_pris,
 * en_traitement, cloturee, annulee.
 */
#[Route('/api/demandes')]
class DemandeApiController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private DemandeRepository $demandeRepository,
        private ServiceRepository $serviceRepository,
        private OrientationEngine $orientationEngine,
    ) {
    }

    #[Route('', name: 'api_demandes_create', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] Utilisateur $utilisateur): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $texte = trim($data['texte'] ?? '');

        if ($texte === '') {
            return $this->json(['error' => 'Le texte de la demande est requis'], 400);
        }

        $resultat = $this->orientationEngine->orienter($texte);

        $demande = new Demande();
        $demande->setTextDemande($texte);
        $demande->setDateCreation(new \DateTimeImmutable());
        $demande->setUtilisateur($utilisateur);
        $demande->setServiceRecommande($resultat['service']);
        $demande->setConfiance($resultat['confiance']);
        // Sans service reconnu avec assez de confiance, la demande reste
        // "soumise" : un agent ou l'administrateur devra l'orienter à la main
        // (voir EF-16 : ne jamais orienter au hasard).
        $demande->setStatus($resultat['service'] ? 'orientee' : 'soumise');

        $historique = new HistoriqueStatut();
        $historique->setStatut($demande->getStatus());
        $historique->setDateChangement($demande->getDateCreation());
        $demande->addHistoriqueStatut($historique);

        $this->em->persist($demande);
        $this->em->persist($historique);
        $this->em->flush();

        return $this->json($this->toArray($demande, $resultat['alternatives']), 201);
    }

    #[Route('', name: 'api_demandes_list', methods: ['GET'])]
    public function list(#[CurrentUser] Utilisateur $utilisateur): JsonResponse
    {
        $roles = $utilisateur->getRoles();

        if (in_array('ROLE_ADMIN', $roles, true) || in_array('ROLE_RESPONSABLE', $roles, true)) {
            $demandes = $this->demandeRepository->findBy([], ['dateCreation' => 'DESC']);
        } elseif (in_array('ROLE_AGENT', $roles, true)) {
            $service = $utilisateur->getService();
            if (!$service) {
                return $this->json([]);
            }
            // Un agent voit les demandes orientées vers son service, qu'elles
            // aient été confirmées telles quelles ou réorientées vers lui.
            $demandes = $this->demandeRepository->createQueryBuilder('d')
                ->where('d.serviceRecommande = :service')
                ->orWhere('d.serviceFinal = :service')
                ->setParameter('service', $service)
                ->orderBy('d.dateCreation', 'DESC')
                ->getQuery()
                ->getResult();
        } else {
            // Citoyen : uniquement ses propres demandes.
            $demandes = $this->demandeRepository->findBy(
                ['utilisateur' => $utilisateur],
                ['dateCreation' => 'DESC']
            );
        }

        return $this->json(array_map(fn(Demande $d) => $this->toArray($d), $demandes));
    }

    #[Route('/{id}', name: 'api_demandes_show', methods: ['GET'])]
    public function show(int $id, #[CurrentUser] Utilisateur $utilisateur): JsonResponse
    {
        $demande = $this->demandeRepository->find($id);
        if (!$demande) {
            return $this->json(['error' => 'Demande non trouvée'], 404);
        }
        if (!$this->peutAcceder($demande, $utilisateur)) {
            return $this->json(['error' => 'Accès refusé'], 403);
        }

        return $this->json($this->toArray($demande, inclureHistorique: true));
    }

    /**
     * Réoriente une demande vers un autre service : c'est ce qui permet à un
     * agent de corriger une erreur du moteur (EF-19). Le service choisi par
     * l'agent devient la vérité de référence pour un futur réentraînement.
     */
    #[Route('/{id}/reorienter', name: 'api_demandes_reorienter', methods: ['PATCH'])]
    #[IsGranted('ROLE_AGENT')]
    public function reorienter(int $id, Request $request): JsonResponse
    {
        $demande = $this->demandeRepository->find($id);
        if (!$demande) {
            return $this->json(['error' => 'Demande non trouvée'], 404);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $serviceId = $data['serviceId'] ?? null;
        if (!$serviceId) {
            return $this->json(['error' => 'serviceId est requis'], 400);
        }

        $service = $this->serviceRepository->find($serviceId);
        if (!$service) {
            return $this->json(['error' => 'Service introuvable'], 404);
        }

        $demande->setServiceFinal($service);
        $demande->setStatus('orientee');
        $this->ajouterHistorique($demande, 'orientee');

        $this->em->flush();

        return $this->json($this->toArray($demande, inclureHistorique: true));
    }

    /**
     * Changement de statut générique (ex. "en_traitement", "cloturee",
     * "annulee"), utilisé par l'agent au fil du traitement du dossier.
     */
    #[Route('/{id}/statut', name: 'api_demandes_statut', methods: ['PATCH'])]
    #[IsGranted('ROLE_AGENT')]
    public function changerStatut(int $id, Request $request): JsonResponse
    {
        $demande = $this->demandeRepository->find($id);
        if (!$demande) {
            return $this->json(['error' => 'Demande non trouvée'], 404);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $statut = trim($data['statut'] ?? '');

        $statutsValides = ['soumise', 'orientee', 'rendez_vous_pris', 'en_traitement', 'cloturee', 'annulee'];
        if (!in_array($statut, $statutsValides, true)) {
            return $this->json(['error' => 'Statut invalide. Valeurs possibles : ' . implode(', ', $statutsValides)], 400);
        }

        $demande->setStatus($statut);
        $this->ajouterHistorique($demande, $statut);

        $this->em->flush();

        return $this->json($this->toArray($demande, inclureHistorique: true));
    }

    private function ajouterHistorique(Demande $demande, string $statut): void
    {
        $historique = new HistoriqueStatut();
        $historique->setStatut($statut);
        $historique->setDateChangement(new \DateTimeImmutable());
        $demande->addHistoriqueStatut($historique);
        $this->em->persist($historique);
    }

    private function peutAcceder(Demande $demande, Utilisateur $utilisateur): bool
    {
        $roles = $utilisateur->getRoles();

        if (in_array('ROLE_ADMIN', $roles, true) || in_array('ROLE_RESPONSABLE', $roles, true)) {
            return true;
        }
        if (in_array('ROLE_AGENT', $roles, true)) {
            $service = $utilisateur->getService();
            return $service && (
                $demande->getServiceRecommande() === $service
                || $demande->getServiceFinal() === $service
            );
        }

        return $demande->getUtilisateur() === $utilisateur;
    }

    private function toArray(Demande $demande, array $alternatives = [], bool $inclureHistorique = false): array
    {
        $data = [
            'id' => $demande->getId(),
            'texte' => $demande->getTextDemande(),
            'statut' => $demande->getStatus(),
            'confiance' => $demande->getConfiance(),
            'dateCreation' => $demande->getDateCreation()?->format(DATE_ATOM),
            'serviceRecommande' => $this->serviceResume($demande->getServiceRecommande()),
            'serviceFinal' => $this->serviceResume($demande->getServiceFinal()),
            'citoyenId' => $demande->getUtilisateur()?->getId(),
            'citoyenNom' => $demande->getUtilisateur()?->getNom(),
            'citoyenPrenom' => $demande->getUtilisateur()?->getPrenom(),
        ];

        if ($alternatives !== []) {
            $data['alternatives'] = array_map(
                fn(array $a) => [
                    'service' => $this->serviceResume($a['service']),
                    'confiance' => $a['confiance'],
                ],
                $alternatives
            );
        }

        if ($inclureHistorique) {
            $data['historique'] = array_map(
                fn(HistoriqueStatut $h) => [
                    'statut' => $h->getStatut(),
                    'date' => $h->getDateChangement()?->format(DATE_ATOM),
                ],
                $demande->getHistoriqueStatuts()->toArray()
            );
        }

        return $data;
    }

    private function serviceResume(?\App\Entity\Service $service): ?array
    {
        if (!$service) {
            return null;
        }

        return [
            'id' => $service->getId(),
            'nom' => $service->getNom(),
            'adresse' => $service->getAdresse(),
            'horaires' => $service->getHoraires(),
        ];
    }
}
