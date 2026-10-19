<?php

namespace App\Entity;

use App\Repository\ExempleOrientationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Exemple d'apprentissage pour le moteur d'orientation (EF-19, EF-21, EF-22) :
 * le texte d'une demande, ANONYMISÉ, associé au service jugé correct.
 * Un seul exemple par demande.
 */
#[ORM\Entity(repositoryClass: ExempleOrientationRepository::class)]
class ExempleOrientation
{
    /** Un agent a réorienté la demande : le service qu'il a choisi fait référence. */
    public const ORIGINE_REORIENTATION = 'reorientation';
    /** Le citoyen a confirmé que le service recommandé par le moteur était le bon. */
    public const ORIGINE_RETOUR_CITOYEN = 'retour_citoyen';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $texte = null;

    #[ORM\Column(length: 30)]
    private ?string $origine = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Service $service = null;

    #[ORM\ManyToOne]
    private ?Demande $demande = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTexte(): ?string
    {
        return $this->texte;
    }

    public function setTexte(string $texte): static
    {
        $this->texte = $texte;

        return $this;
    }

    public function getOrigine(): ?string
    {
        return $this->origine;
    }

    public function setOrigine(string $origine): static
    {
        $this->origine = $origine;

        return $this;
    }

    public function getDateCreation(): ?\DateTimeImmutable
    {
        return $this->dateCreation;
    }

    public function setDateCreation(\DateTimeImmutable $dateCreation): static
    {
        $this->dateCreation = $dateCreation;

        return $this;
    }

    public function getService(): ?Service
    {
        return $this->service;
    }

    public function setService(?Service $service): static
    {
        $this->service = $service;

        return $this;
    }

    public function getDemande(): ?Demande
    {
        return $this->demande;
    }

    public function setDemande(?Demande $demande): static
    {
        $this->demande = $demande;

        return $this;
    }
}
