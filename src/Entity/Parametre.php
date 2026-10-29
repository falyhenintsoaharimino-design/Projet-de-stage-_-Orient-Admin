<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Paramètre de l'application (EF-46) : une ligne par réglage, la valeur est du JSON.
 * Clés utilisées (voir App\Service\ParametreService) : duree_creneau_defaut, jours_feries, modeles_messages.
 */
#[ORM\Entity]
class Parametre
{
    #[ORM\Id]
    #[ORM\Column(length: 60)]
    private string $cle;

    /** @var mixed JSON décodé (entier, liste, objet...) */
    #[ORM\Column(type: Types::JSON)]
    private mixed $valeur = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $modifieLe = null;

    /** Administrateur qui a fait la dernière modification ; vidé si son compte est supprimé. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Utilisateur $modifiePar = null;

    public function __construct(string $cle)
    {
        $this->cle = $cle;
    }

    public function getCle(): string
    {
        return $this->cle;
    }

    public function getValeur(): mixed
    {
        return $this->valeur;
    }

    public function setValeur(mixed $valeur): static
    {
        $this->valeur = $valeur;

        return $this;
    }

    public function getModifieLe(): ?\DateTimeImmutable
    {
        return $this->modifieLe;
    }

    public function setModifieLe(\DateTimeImmutable $modifieLe): static
    {
        $this->modifieLe = $modifieLe;

        return $this;
    }

    public function getModifiePar(): ?Utilisateur
    {
        return $this->modifiePar;
    }

    public function setModifiePar(?Utilisateur $modifiePar): static
    {
        $this->modifiePar = $modifiePar;

        return $this;
    }
}
