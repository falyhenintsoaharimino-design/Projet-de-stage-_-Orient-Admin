<?php

namespace App\Entity;

use App\Repository\HistoriqueStatutRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: HistoriqueStatutRepository::class)]
class HistoriqueStatut
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50)]
    private ?string $statut = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $dateChangement = null;

    #[ORM\ManyToOne(inversedBy: 'historiqueStatuts')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Demande $demande = null;

    public function getId(): ?int
    {
        return $this->id;
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

    public function getDateChangement(): ?\DateTimeImmutable
    {
        return $this->dateChangement;
    }

    public function setDateChangement(\DateTimeImmutable $dateChangement): static
    {
        $this->dateChangement = $dateChangement;

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
