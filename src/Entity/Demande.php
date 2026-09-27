<?php

namespace App\Entity;

use App\Repository\DemandeRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DemandeRepository::class)]
class Demande
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $textDemande = null;

    #[ORM\Column(length: 50)]
    private ?string $status = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\Column(nullable: true)]
    private ?float $confiance = null;

    #[ORM\ManyToOne(inversedBy: 'demandes')]
    #[ORM\JoinColumn(nullable: false)]
    private ?utilisateur $utilisateur = null;

    #[ORM\ManyToOne]
    private ?service $serviceRecommande = null;

    #[ORM\ManyToOne]
    private ?service $serviceFinal = null;

    /**
     * @var Collection<int, HistoriqueStatut>
     */
    #[ORM\OneToMany(targetEntity: HistoriqueStatut::class, mappedBy: 'demande', orphanRemoval: true)]
    private Collection $historiqueStatuts;

    public function __construct()
    {
        $this->historiqueStatuts = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTextDemande(): ?string
    {
        return $this->textDemande;
    }

    public function setTextDemande(string $textDemande): static
    {
        $this->textDemande = $textDemande;

        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

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

    public function getConfiance(): ?float
    {
        return $this->confiance;
    }

    public function setConfiance(?float $confiance): static
    {
        $this->confiance = $confiance;

        return $this;
    }

    public function getUtilisateur(): ?utilisateur
    {
        return $this->utilisateur;
    }

    public function setUtilisateur(?utilisateur $utilisateur): static
    {
        $this->utilisateur = $utilisateur;

        return $this;
    }

    public function getServiceRecommande(): ?service
    {
        return $this->serviceRecommande;
    }

    public function setServiceRecommande(?service $serviceRecommande): static
    {
        $this->serviceRecommande = $serviceRecommande;

        return $this;
    }

    public function getServiceFinal(): ?service
    {
        return $this->serviceFinal;
    }

    public function setServiceFinal(?service $serviceFinal): static
    {
        $this->serviceFinal = $serviceFinal;

        return $this;
    }

    /**
     * @return Collection<int, HistoriqueStatut>
     */
    public function getHistoriqueStatuts(): Collection
    {
        return $this->historiqueStatuts;
    }

    public function addHistoriqueStatut(HistoriqueStatut $historiqueStatut): static
    {
        if (!$this->historiqueStatuts->contains($historiqueStatut)) {
            $this->historiqueStatuts->add($historiqueStatut);
            $historiqueStatut->setDemande($this);
        }

        return $this;
    }

    public function removeHistoriqueStatut(HistoriqueStatut $historiqueStatut): static
    {
        if ($this->historiqueStatuts->removeElement($historiqueStatut)) {
            // set the owning side to null (unless already changed)
            if ($historiqueStatut->getDemande() === $this) {
                $historiqueStatut->setDemande(null);
            }
        }

        return $this;
    }
}
