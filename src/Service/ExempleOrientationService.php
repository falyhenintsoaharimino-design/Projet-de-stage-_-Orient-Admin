<?php

namespace App\Service;

use App\Entity\Demande;
use App\Entity\ExempleOrientation;
use App\Entity\Service;
use App\Repository\ExempleOrientationRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Enregistre les exemples d'apprentissage du moteur (un par demande, anonymisé).
 * Règle de priorité : le choix d'un agent (réorientation) l'emporte sur le retour du citoyen.
 * Ne fait pas de flush : l'appelant le fait avec le reste de sa modification.
 */
class ExempleOrientationService
{
    public function __construct(
        private EntityManagerInterface $em,
        private ExempleOrientationRepository $repository,
        private AnonymisationService $anonymisation,
    ) {
    }

    public function enregistrer(Demande $demande, Service $service, string $origine): ?ExempleOrientation
    {
        $existant = $this->repository->findOneBy(['demande' => $demande]);
        if ($existant && $existant->getOrigine() === ExempleOrientation::ORIGINE_REORIENTATION
            && $origine === ExempleOrientation::ORIGINE_RETOUR_CITOYEN) {
            return $existant;
        }

        $texte = $this->anonymisation->anonymiser((string) $demande->getTextDemande(), $demande->getUtilisateur());
        if ($texte === null) {
            return $existant;
        }

        $exemple = $existant ?? (new ExempleOrientation())->setDemande($demande);
        $exemple->setTexte($texte)
            ->setService($service)
            ->setOrigine($origine)
            ->setDateCreation(new \DateTimeImmutable());
        $this->em->persist($exemple);

        return $exemple;
    }

    /** Le citoyen revient sur son « oui » : on retire l'exemple qu'il avait fait créer. */
    public function retirerRetourCitoyen(Demande $demande): void
    {
        $existant = $this->repository->findOneBy(['demande' => $demande]);
        if ($existant && $existant->getOrigine() === ExempleOrientation::ORIGINE_RETOUR_CITOYEN) {
            $this->em->remove($existant);
        }
    }
}
