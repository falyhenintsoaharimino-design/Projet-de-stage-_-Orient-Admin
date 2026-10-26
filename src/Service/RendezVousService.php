<?php

namespace App\Service;

use App\Entity\Creneau;
use App\Entity\Demande;
use App\Entity\DocumentRequis;
use App\Entity\HistoriqueStatut;
use App\Entity\Procedure;
use App\Entity\RendezVous;
use App\Entity\Service;
use App\Entity\Utilisateur;
use App\Repository\RendezVousRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Règles de réservation, modification, report et annulation d'un rendez-vous.
 * Les erreurs sont signalées par une \DomainException dont le code est le
 * statut HTTP à renvoyer (404, 409, 422...).
 *
 * Statuts de RendezVous : en_attente (réservé par le citoyen, en attente de
 * validation par un agent), confirme, refuse, annule, termine, absent.
 * Les statuts en_attente et confirme occupent une place du créneau : un créneau accepte
 * `capacite` rendez-vous actifs (EF-23, EF-25). `Creneau::disponible` indique seulement
 * si l'agent a ouvert (true) ou bloqué (false) le créneau (EF-29).
 *
 * Le citoyen peut modifier ou annuler son rendez-vous jusqu'à `RDV_DELAI_ANNULATION_HEURES`
 * heures avant son début (EF-26) ; l'agent et l'admin ne sont pas soumis à ce délai.
 */
class RendezVousService
{
    public function __construct(
        private EntityManagerInterface $em,
        private RendezVousRepository $rendezVousRepository,
        private NotificationService $notifications,
        #[Autowire(env: 'int:RDV_DELAI_ANNULATION_HEURES')]
        private int $delaiHeures,
        private Fuseau $fuseau,
    ) {
    }

    public function getDelaiHeures(): int
    {
        return $this->delaiHeures;
    }

    public function reserver(Demande $demande, int $creneauId, ?Procedure $procedure = null): RendezVous
    {
        $service = $demande->getServiceFinal() ?? $demande->getServiceRecommande();
        if (!$service) {
            throw new \DomainException('Cette demande n\'est pas encore orientée vers un service', 422);
        }
        if (in_array($demande->getStatus(), ['cloturee', 'annulee'], true)) {
            throw new \DomainException('Cette demande est déjà terminée ou annulée', 409);
        }
        if ($this->rendezVousRepository->findOneBy(['demande' => $demande, 'statut' => RendezVousRepository::STATUTS_ACTIFS])) {
            throw new \DomainException('Cette demande a déjà un rendez-vous en cours', 409);
        }
        $this->verifierProcedure($procedure, $service);

        // Transaction + verrou sur le créneau : deux citoyens ne peuvent pas prendre
        // la dernière place du même créneau en même temps.
        $rdv = $this->transaction(function () use ($demande, $creneauId, $service, $procedure) {
            $creneau = $this->creneauVerrouille($creneauId, $service);
            $this->verifierReservable($creneau);

            $rdv = new RendezVous();
            $rdv->setDemande($demande);
            $rdv->setCreneau($creneau);
            $rdv->setProcedure($procedure);
            $rdv->setStatut('en_attente');

            $demande->setStatus('rendez_vous_pris');
            $this->ajouterHistorique($demande, 'rendez_vous_pris');

            $this->em->persist($rdv);

            return $rdv;
        });

        $this->notifications->notifierRendezVous($rdv, 'reserve');
        $this->em->flush();

        return $rdv;
    }

    /** Agent : valide un rendez-vous en attente. */
    public function confirmer(RendezVous $rdv): void
    {
        if ($rdv->getStatut() !== 'en_attente') {
            throw new \DomainException('Seul un rendez-vous en attente peut être confirmé', 409);
        }
        $rdv->setStatut('confirme');
        $this->em->flush();

        $this->notifications->notifierRendezVous($rdv, 'confirme');
        $this->em->flush();
    }

    /** Agent : refuse un rendez-vous en attente ; la place est libérée. */
    public function refuser(RendezVous $rdv): void
    {
        if ($rdv->getStatut() !== 'en_attente') {
            throw new \DomainException('Seul un rendez-vous en attente peut être refusé', 409);
        }
        $this->liberer($rdv, 'refuse');
    }

    /**
     * Citoyen ou agent : annule un rendez-vous en attente ou confirmé.
     * $respecterDelai = true pour le citoyen : plus d'annulation passé le délai limite (EF-26).
     */
    public function annuler(RendezVous $rdv, ?Utilisateur $par = null, bool $respecterDelai = false): void
    {
        if (!in_array($rdv->getStatut(), RendezVousRepository::STATUTS_ACTIFS, true)) {
            throw new \DomainException('Ce rendez-vous ne peut plus être annulé', 409);
        }
        if ($respecterDelai) {
            $this->verifierDelai($rdv, 'Annulation');
        }
        $this->liberer($rdv, 'annule', $par);
    }

    /**
     * Citoyen (ou admin) : change de créneau et/ou de procédure. Un changement de créneau remet le rendez-vous
     * « en attente » : l'agent doit le confirmer de nouveau.
     */
    public function modifier(RendezVous $rdv, ?int $creneauId, ?Procedure $procedure, bool $changerProcedure, bool $respecterDelai): void
    {
        $this->verifierActif($rdv, 'modifié');
        if ($respecterDelai) {
            $this->verifierDelai($rdv, 'Modification');
        }
        $service = $rdv->getCreneau()->getService();
        if ($changerProcedure) {
            $this->verifierProcedure($procedure, $service);
        }
        $creneauChange = $creneauId !== null && $creneauId !== $rdv->getCreneau()->getId();
        if (!$creneauChange && !$changerProcedure) {
            throw new \DomainException('Rien à modifier : indiquez un autre créneau (creneauId) ou une procédure (procedureId)', 422);
        }

        $this->transaction(function () use ($rdv, $creneauChange, $creneauId, $service, $changerProcedure, $procedure) {
            if ($creneauChange) {
                $creneau = $this->creneauVerrouille($creneauId, $service);
                $this->verifierReservable($creneau);
                $rdv->setCreneau($creneau);
                $rdv->setStatut('en_attente');
            }
            if ($changerProcedure) {
                $rdv->setProcedure($procedure);
            }
        });

        if ($creneauChange) {
            $this->notifications->notifierRendezVous($rdv, 'modifie');
        }
        $this->em->flush();
    }

    /** Agent : reporte un rendez-vous à un autre créneau du même service ; il est alors confirmé (EF-27). */
    public function reporter(RendezVous $rdv, int $creneauId, ?string $motif): void
    {
        $this->verifierActif($rdv, 'reporté');
        if ($creneauId === $rdv->getCreneau()->getId()) {
            throw new \DomainException('Le rendez-vous est déjà sur ce créneau', 422);
        }
        $service = $rdv->getCreneau()->getService();
        $ancien = $this->quand($rdv->getCreneau());

        $this->transaction(function () use ($rdv, $creneauId, $service) {
            $creneau = $this->creneauVerrouille($creneauId, $service);
            $this->verifierReservable($creneau);
            $rdv->setCreneau($creneau);
            $rdv->setStatut('confirme');
        });

        $this->notifications->notifierRendezVous($rdv, 'reporte', null, ['ancien' => $ancien, 'motif' => $motif]);
        $this->em->flush();
    }

    /** Dernier moment où le citoyen peut encore modifier ou annuler (début du rendez-vous moins le délai). */
    public function limiteModification(RendezVous $rdv): ?\DateTimeImmutable
    {
        return $rdv->getCreneau()?->getDebut($this->fuseau->get())?->modify('-' . $this->delaiHeures . ' hours');
    }

    /** Le citoyen peut-il encore modifier ou annuler ce rendez-vous ? */
    public function modifiableParLeCitoyen(RendezVous $rdv): bool
    {
        $limite = $this->limiteModification($rdv);

        return in_array($rdv->getStatut(), RendezVousRepository::STATUTS_ACTIFS, true)
            && $limite !== null && new \DateTimeImmutable() <= $limite;
    }

    /**
     * Récapitulatif de confirmation (EF-28) : quand, où, et pièces à apporter.
     * Les pièces viennent de la procédure choisie pour le rendez-vous ; sans procédure, la liste est vide.
     *
     * @return array<string, mixed>
     */
    public function recapitulatif(RendezVous $rdv): array
    {
        $creneau = $rdv->getCreneau();
        $service = $creneau->getService();
        $procedure = $rdv->getProcedure();
        $limite = $this->limiteModification($rdv);

        return [
            'rendezVousId' => $rdv->getId(),
            'statut' => $rdv->getStatut(),
            'quand' => [
                'date' => $creneau->getDate()?->format('Y-m-d'),
                'heureDebut' => $creneau->getHeure(),
                'heureFin' => $creneau->getFin()?->format('H:i'),
                'dureeMinutes' => $creneau->getDureeMinutes(),
            ],
            'lieu' => [
                'service' => $service->getNom(),
                'adresse' => $service->getAdresse(),
                'horaires' => $service->getHoraires(),
                'contact' => $service->getContact(),
            ],
            'procedure' => $procedure ? ['id' => $procedure->getId(), 'nom' => $procedure->getNom()] : null,
            'piecesAApporter' => $procedure ? array_map(fn(DocumentRequis $d) => [
                'libelle' => $d->getLibelle(),
                'obligatoire' => $d->isObligatoire(),
                'exemplaires' => $d->getExemplaires(),
                'ancienneteMaxMois' => $d->getAncienneteMaxMois(),
                'ouObtenir' => $d->getOuObtenir(),
                'remarque' => $d->getRemarque(),
            ], $procedure->getDocumentRequis()->toArray()) : [],
            'modifiableJusquau' => $limite?->format(\DATE_ATOM),
            'modifiableParLeCitoyen' => $this->modifiableParLeCitoyen($rdv),
            'avertissement' => CatalogueService::AVERTISSEMENT,
        ];
    }

    private function liberer(RendezVous $rdv, string $statutFinal, ?Utilisateur $par = null): void
    {
        $rdv->setStatut($statutFinal);

        $demande = $rdv->getDemande();
        if ($demande && $demande->getStatus() === 'rendez_vous_pris') {
            $demande->setStatus('orientee');
            $this->ajouterHistorique($demande, 'orientee');
        }

        $this->em->flush();

        $this->notifications->notifierRendezVous($rdv, $statutFinal, $par);
        $this->em->flush();
    }

    /**
     * Transaction (nécessaire au verrou du créneau). Contrairement à EntityManager::wrapInTransaction, une exception
     * (créneau complet, passé...) annule la transaction sans fermer l'EntityManager.
     */
    private function transaction(callable $operation): mixed
    {
        $connexion = $this->em->getConnection();
        $connexion->beginTransaction();
        try {
            $resultat = $operation();
            $this->em->flush();
            $connexion->commit();

            return $resultat;
        } catch (\Throwable $e) {
            $connexion->rollBack();
            throw $e;
        }
    }

    private function creneauVerrouille(int $creneauId, Service $service): Creneau
    {
        $creneau = $this->em->find(Creneau::class, $creneauId, LockMode::PESSIMISTIC_WRITE);
        if (!$creneau) {
            throw new \DomainException('Créneau introuvable', 404);
        }
        if ($creneau->getService() !== $service) {
            throw new \DomainException('Ce créneau n\'appartient pas au service de la demande', 422);
        }

        return $creneau;
    }

    /** Le créneau doit être à venir, ouvert par l'agent et avoir au moins une place libre. */
    private function verifierReservable(Creneau $creneau): void
    {
        $debut = $creneau->getDebut($this->fuseau->get());
        if ($debut === null || $debut < new \DateTimeImmutable()) {
            throw new \DomainException('Ce créneau est déjà passé', 409);
        }
        if (!$creneau->isDisponible()) {
            throw new \DomainException('Ce créneau n\'est plus disponible', 409);
        }
        if ($this->rendezVousRepository->compterActifs($creneau->getId()) >= $creneau->getCapacite()) {
            throw new \DomainException('Ce créneau est complet', 409);
        }
    }

    private function verifierActif(RendezVous $rdv, string $verbe): void
    {
        if (!in_array($rdv->getStatut(), RendezVousRepository::STATUTS_ACTIFS, true)) {
            throw new \DomainException('Ce rendez-vous ne peut plus être ' . $verbe, 409);
        }
    }

    private function verifierDelai(RendezVous $rdv, string $action): void
    {
        $limite = $this->limiteModification($rdv);
        if ($limite === null || new \DateTimeImmutable() > $limite) {
            throw new \DomainException(sprintf('%s impossible : le délai de %d h avant le rendez-vous est dépassé. Contactez le service.', $action, $this->delaiHeures), 409);
        }
    }

    private function verifierProcedure(?Procedure $procedure, Service $service): void
    {
        if ($procedure === null) {
            return;
        }
        if ($procedure->getService() !== $service || !$procedure->isActif()) {
            throw new \DomainException('Cette procédure n\'appartient pas au service du rendez-vous ou n\'est plus disponible', 422);
        }
    }

    private function quand(Creneau $creneau): string
    {
        return sprintf('du %s à %s', $creneau->getDate()?->format('d/m/Y'), $creneau->getHeure());
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
