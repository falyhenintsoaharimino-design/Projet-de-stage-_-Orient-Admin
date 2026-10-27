<?php

namespace App\Entity;

use App\Repository\RendezVousRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RendezVousRepository::class)]
class RendezVous
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50)]
    private ?string $statut = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Demande $demande = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Creneau $creneau = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    /** Procédure concernée par le rendez-vous : sert au récapitulatif « pièces à apporter » (EF-28). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Procedure $procedure = null;

    /** Date d'envoi du rappel de la veille (EF-31) ; vidée si le rendez-vous est déplacé, pour qu'un nouveau rappel parte. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $rappelEnvoyeLe = null;

    public function getRappelEnvoyeLe(): ?\DateTimeImmutable
    {
        return $this->rappelEnvoyeLe;
    }

    public function setRappelEnvoyeLe(?\DateTimeImmutable $rappelEnvoyeLe): static
    {
        $this->rappelEnvoyeLe = $rappelEnvoyeLe;

        return $this;
    }

    public function getProcedure(): ?Procedure
    {
        return $this->procedure;
    }

    public function setProcedure(?Procedure $procedure): static
    {
        $this->procedure = $procedure;

        return $this;
    }

    public function getStatut(): ?string
    {
        return $this->statut;
    }

    public function setStatut(string $statut): static
    {
        $this->statut = $statut;

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

    public function getCreneau(): ?Creneau
    {
        return $this->creneau;
    }

    public function setCreneau(?Creneau $creneau): static
    {
        $this->creneau = $creneau;

        return $this;
    }
}
