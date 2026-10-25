<?php

namespace App\Entity;

use App\Repository\DocumentRequisRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DocumentRequisRepository::class)]
class DocumentRequis
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 150)]
    private ?string $libelle = null;

    #[ORM\Column]
    private ?bool $obligatoire = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $remarque = null;

    /** Nombre d'exemplaires à fournir. */
    #[ORM\Column]
    private int $exemplaires = 1;

    /** Ancienneté maximale acceptée, en mois (ex. 3 = « de moins de 3 mois »). */
    #[ORM\Column(nullable: true)]
    private ?int $ancienneteMaxMois = null;

    /** Où se procurer la pièce (mairie, fokontany, site officiel...). */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $ouObtenir = null;

    #[ORM\ManyToOne(inversedBy: 'documentRequis')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Procedure $procedure = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLibelle(): ?string
    {
        return $this->libelle;
    }

    public function setLibelle(string $libelle): static
    {
        $this->libelle = $libelle;

        return $this;
    }

    public function isObligatoire(): ?bool
    {
        return $this->obligatoire;
    }

    public function setObligatoire(bool $obligatoire): static
    {
        $this->obligatoire = $obligatoire;

        return $this;
    }

    public function getRemarque(): ?string
    {
        return $this->remarque;
    }

    public function setRemarque(?string $remarque): static
    {
        $this->remarque = $remarque;

        return $this;
    }

    public function getExemplaires(): int
    {
        return $this->exemplaires;
    }

    public function setExemplaires(int $exemplaires): static
    {
        $this->exemplaires = $exemplaires;

        return $this;
    }

    public function getAncienneteMaxMois(): ?int
    {
        return $this->ancienneteMaxMois;
    }

    public function setAncienneteMaxMois(?int $ancienneteMaxMois): static
    {
        $this->ancienneteMaxMois = $ancienneteMaxMois;

        return $this;
    }

    public function getOuObtenir(): ?string
    {
        return $this->ouObtenir;
    }

    public function setOuObtenir(?string $ouObtenir): static
    {
        $this->ouObtenir = $ouObtenir;

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
}
