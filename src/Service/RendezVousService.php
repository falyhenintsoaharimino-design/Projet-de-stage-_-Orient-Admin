<?php

namespace App\Service;

use App\Entity\Creneau;
use App\Entity\Demande;
use App\Entity\HistoriqueStatut;
use App\Entity\RendezVous;
use App\Repository\RendezVousRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Règles de réservation / annulation d'un rendez-vous.
 * Les erreurs sont signalées par une \DomainException dont le code est le
 * statut HTTP à renvoyer (404, 409, 422...).
 *
 * Statuts de RendezVous : confirme, annule, termine, absent.
 */
class RendezVousService
{
    public function __construct(
        private EntityManagerInterface $em,
        private RendezVousRepository $rendezVousRepository,
    ) {
    }

    public function reserver(Demande $demande, int $creneauId): RendezVous
    {
        $service = $demande->getServiceFinal() ?? $demande->getServiceRecommande();
        if (!$service) {
            throw new \DomainException('Cette demande n\'est pas encore orientée vers un service', 422);
        }
        if (in_array($demande->getStatus(), ['cloturee', 'annulee'], true)) {
            throw new \DomainException('Cette demande est déjà terminée ou annulée', 409);
        }
        if ($this->rendezVousRepository->findOneBy(['demande' => $demande, 'statut' => 'confirme'])) {
            throw new \DomainException('Cette demande a déjà un rendez-vous confirmé', 409);
        }

        // Transaction + verrou sur le créneau : deux citoyens ne peuvent pas
        // réserver le même créneau en même temps.
        return $this->em->wrapInTransaction(function () use ($demande, $creneauId, $service) {
            $creneau = $this->em->find(Creneau::class, $creneauId, LockMode::PESSIMISTIC_WRITE);
            if (!$creneau) {
                throw new \DomainException('Créneau introuvable', 404);
            }
            if ($creneau->getService() !== $service) {
                throw new \DomainException('Ce créneau n\'appartient pas au service de la demande', 422);
            }
            if ($creneau->getDate() < new \DateTime('today')) {
                throw new \DomainException('Ce créneau est déjà passé', 409);
            }
            if (!$creneau->isDisponible()) {
                throw new \DomainException('Ce créneau n\'est plus disponible', 409);
            }

            $rdv = new RendezVous();
            $rdv->setDemande($demande);
            $rdv->setCreneau($creneau);
            $rdv->setStatut('confirme');
            $creneau->setDisponible(false);

            $demande->setStatus('rendez_vous_pris');
            $this->ajouterHistorique($demande, 'rendez_vous_pris');

            $this->em->persist($rdv);

            return $rdv;
        });
    }

    public function annuler(RendezVous $rdv): void
    {
        if ($rdv->getStatut() !== 'confirme') {
            throw new \DomainException('Seul un rendez-vous confirmé peut être annulé', 409);
        }

        $rdv->setStatut('annule');
        $rdv->getCreneau()?->setDisponible(true);

        $demande = $rdv->getDemande();
        if ($demande && $demande->getStatus() === 'rendez_vous_pris') {
            $demande->setStatus('orientee');
            $this->ajouterHistorique($demande, 'orientee');
        }

        $this->em->flush();
    }

    private function ajouterHistorique(Demande $demande, string $statut): void
    {
        $historique = new HistoriqueStatut();
        $historique->setStatut($statut);
        $historique->setDateChangement(new \DateTimeImmutable());
        $demande->addHistoriqueStatut($historique);
        $this->em->persist($historique);
    }
}
