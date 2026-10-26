<?php

namespace App\Entity;

use App\Repository\CreneauRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CreneauRepository::class)]
class Creneau
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    private ?\DateTime $date = null;

    #[ORM\Column(length: 50)]
    private ?string $heure = null;

    #[ORM\Column]
    private ?bool $disponible = null;

    /** Nombre de rendez-vous simultanés acceptés sur ce créneau (EF-23). */
    #[ORM\Column]
    private int $capacite = 1;

    /** Durée du créneau, en minutes (sert à calculer l'heure de fin). */
    #[ORM\Column]
    private int $dureeMinutes = 30;

    #[ORM\ManyToOne(inversedBy: 'creneaux')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Service $service = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDate(): ?\DateTime
    {
        return $this->date;
    }

    public function setDate(\DateTime $date): static
    {
        $this->date = $date;

        return $this;
    }

    public function getHeure(): ?string
    {
        return $this->heure;
    }

    public function setHeure(string $heure): static
    {
        $this->heure = $heure;

        return $this;
    }

    public function isDisponible(): ?bool
    {
        return $this->disponible;
    }

    public function setDisponible(bool $disponible): static
    {
        $this->disponible = $disponible;

        return $this;
    }

    public function getCapacite(): int
    {
        return $this->capacite;
    }

    public function setCapacite(int $capacite): static
    {
        $this->capacite = $capacite;

        return $this;
    }

    public function getDureeMinutes(): int
    {
        return $this->dureeMinutes;
    }

    public function setDureeMinutes(int $dureeMinutes): static
    {
        $this->dureeMinutes = $dureeMinutes;

        return $this;
    }

    /**
     * Début du créneau (date + heure lues dans le fuseau $fuseau, celui de l'application), ou null si l'heure est illisible.
     * Sans fuseau, celui de PHP est utilisé.
     */
    public function getDebut(?\DateTimeZone $fuseau = null): ?\DateTimeImmutable
    {
        if ($this->date === null || !preg_match('/^(\d{2}):(\d{2})$/', (string) $this->heure, $m)) {
            return null;
        }

        return new \DateTimeImmutable($this->date->format('Y-m-d') . sprintf(' %02d:%02d:00', (int) $m[1], (int) $m[2]), $fuseau);
    }

    public function getFin(?\DateTimeZone $fuseau = null): ?\DateTimeImmutable
    {
        return $this->getDebut($fuseau)?->modify('+' . $this->dureeMinutes . ' minutes');
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
}
