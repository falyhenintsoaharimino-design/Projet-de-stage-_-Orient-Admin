<?php

namespace App\Entity;

use App\Repository\EtapeProcedureRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EtapeProcedureRepository::class)]
class EtapeProcedure
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private ?int $ordre = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $description = null;

    #[ORM\ManyToOne(inversedBy: 'etapeProcedures')]
    #[ORM\JoinColumn(nullable: false)]
    private ?procedure $procedure = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrdre(): ?int
    {
        return $this->ordre;
    }

    public function setOrdre(int $ordre): static
    {
        $this->ordre = $ordre;

        return $this;
    }

    public function getDescripton(): ?string
    {
        return $this->description;
    }

    public function setDescripton(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getProcedure(): ?procedure
    {
        return $this->procedure;
    }

    public function setProcedure(?procedure $procedure): static
    {
        $this->procedure = $procedure;

        return $this;
    }
}
