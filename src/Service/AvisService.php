<?php

namespace App\Service;

use App\Entity\Avis;
use App\Entity\RendezVous;
use App\Entity\Utilisateur;
use App\Repository\AvisRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Règles de dépôt d'un avis. Les erreurs sont signalées par une
 * \DomainException dont le code est le statut HTTP à renvoyer.
 */
class AvisService
{
    public const NOTE_MIN = 1;
    public const NOTE_MAX = 5;
    public const COMMENTAIRE_MAX = 1000;

    public function __construct(
        private EntityManagerInterface $em,
        private AvisRepository $avisRepository,
    ) {
    }

    public function donner(RendezVous $rdv, Utilisateur $citoyen, mixed $note, mixed $commentaire): Avis
    {
        if ($rdv->getDemande()?->getUtilisateur() !== $citoyen) {
            throw new \DomainException('Accès refusé', 403);
        }
        if ($rdv->getStatut() !== 'termine') {
            throw new \DomainException('Vous ne pouvez noter qu\'un rendez-vous terminé', 409);
        }

        $noteInt = filter_var($note, FILTER_VALIDATE_INT);
        if (is_bool($note) || $noteInt === false || $noteInt < self::NOTE_MIN || $noteInt > self::NOTE_MAX) {
            throw new \DomainException(sprintf('La note doit être un entier entre %d et %d', self::NOTE_MIN, self::NOTE_MAX), 422);
        }

        if ($commentaire !== null && !is_string($commentaire)) {
            throw new \DomainException('Le commentaire doit être du texte', 422);
        }
        $commentaire = $commentaire === null ? null : trim($commentaire);
        if ($commentaire === '') {
            $commentaire = null;
        }
        if ($commentaire !== null && mb_strlen($commentaire) > self::COMMENTAIRE_MAX) {
            throw new \DomainException(sprintf('Le commentaire ne doit pas dépasser %d caractères', self::COMMENTAIRE_MAX), 422);
        }

        if ($this->avisRepository->findOneBy(['rendezVous' => $rdv])) {
            throw new \DomainException('Vous avez déjà donné votre avis sur ce rendez-vous', 409);
        }

        $avis = new Avis();
        $avis->setRendezVous($rdv);
        $avis->setNote($noteInt);
        $avis->setCommentaire($commentaire);
        $avis->setDateCreation(new \DateTimeImmutable());
        $this->em->persist($avis);

        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            // Deux envois simultanés : l'index unique de la base a le dernier mot.
            throw new \DomainException('Vous avez déjà donné votre avis sur ce rendez-vous', 409);
        }

        return $avis;
    }
}
