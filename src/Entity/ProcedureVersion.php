<?php

namespace App\Entity;

use App\Repository\ProcedureVersionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Historique des versions d'une procédure (EF-11) : à chaque modification du contenu,
 * un instantané complet (fiche, étapes, pièces) est conservé avec son auteur et son résumé.
 */
#[ORM\Entity(repositoryClass: ProcedureVersionRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_procedure_version_numero', columns: ['procedure_id', 'numero'])]
class ProcedureVersion
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Procedure $procedure = null;

    #[ORM\Column]
    private ?int $numero = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $creeLe = null;

    /** Auteur de la modification ; vidé si son compte est supprimé. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Utilisateur $auteur = null;

    #[ORM\Column(length: 255)]
    private ?string $resume = null;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $contenu = [];

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProcedure(): ?Procedure
    {
        return $this->procedure;
    }

    public function setProcedure(Procedure $procedure): static
    {
        $this->procedure = $procedure;

        return $this;
    }

    public function getNumero(): ?int
    {
        return $this->numero;
    }

    public function setNumero(int $numero): static
    {
        $this->numero = $numero;

        return $this;
    }

    public function getCreeLe(): ?\DateTimeImmutable
    {
        return $this->creeLe;
    }

    public function setCreeLe(\DateTimeImmutable $creeLe): static
    {
        $this->creeLe = $creeLe;

        return $this;
    }

    public function getAuteur(): ?Utilisateur
    {
        return $this->auteur;
    }

    public function setAuteur(?Utilisateur $auteur): static
    {
        $this->auteur = $auteur;

        return $this;
    }

    public function getResume(): ?string
    {
        return $this->resume;
    }

    public function setResume(string $resume): static
    {
        $this->resume = $resume;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getContenu(): array
    {
        return $this->contenu;
    }

    /** @param array<string, mixed> $contenu */
    public function setContenu(array $contenu): static
    {
        $this->contenu = $contenu;

        return $this;
    }
}
