<?php

namespace App\Entity;

use App\Repository\ServiceRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ServiceRepository::class)]
class Service
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 150)]
    private ?string $nom = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $mission = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $categorie = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $adresse = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $horaires = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $contact = null;

    #[ORM\Column]
    private ?bool $actif = null;

    /**
     * Mots-clés séparés par des virgules, utilisés par le moteur d'orientation
     * pour reconnaître ce service à partir du texte libre d'un citoyen
     * (ex. "acte de naissance, extrait de naissance, naissance").
     * Modifiable par l'agent/l'administrateur via l'API, sans toucher au code :
     * c'est la "base de connaissances" attendue par le cahier des charges.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $motsCles = null;

    /**
     * @var Collection<int, Creneau>
     */
    #[ORM\OneToMany(targetEntity: Creneau::class, mappedBy: 'service', orphanRemoval: true)]
    private Collection $creneaux;

    public function __construct()
    {
        $this->creneaux = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(string $nom): static
    {
        $this->nom = $nom;

        return $this;
    }

    public function getMission(): ?string
    {
        return $this->mission;
    }

    public function setMission(?string $mission): static
    {
        $this->mission = $mission;

        return $this;
    }

    public function getCategorie(): ?string
    {
        return $this->categorie;
    }

    public function setCategorie(?string $categorie): static
    {
        $this->categorie = $categorie;

        return $this;
    }

    public function getAdresse(): ?string
    {
        return $this->adresse;
    }

    public function setAdresse(?string $adresse): static
    {
        $this->adresse = $adresse;

        return $this;
    }

    public function getHoraires(): ?string
    {
        return $this->horaires;
    }

    public function setHoraires(?string $horaires): static
    {
        $this->horaires = $horaires;

        return $this;
    }

    public function getContact(): ?string
    {
        return $this->contact;
    }

    public function setContact(?string $contact): static
    {
        $this->contact = $contact;

        return $this;
    }

    public function isActif(): ?bool
    {
        return $this->actif;
    }

    public function setActif(bool $actif): static
    {
        $this->actif = $actif;

        return $this;
    }

    public function getMotsCles(): ?string
    {
        return $this->motsCles;
    }

    public function setMotsCles(?string $motsCles): static
    {
        $this->motsCles = $motsCles;

        return $this;
    }

    /**
     * @return string[] mots-clés nettoyés (minuscules, sans espaces superflus, sans doublons)
     */
    public function getMotsClesListe(): array
    {
        if ($this->motsCles === null || trim($this->motsCles) === '') {
            return [];
        }

        $mots = array_map(
            fn(string $m) => strtolower(trim($m)),
            explode(',', $this->motsCles)
        );

        return array_values(array_unique(array_filter($mots, fn(string $m) => $m !== '')));
    }

    /**
     * @return Collection<int, Creneau>
     */
    public function getCreneaux(): Collection
    {
        return $this->creneaux;
    }

    public function addCreneau(Creneau $creneau): static
    {
        if (!$this->creneaux->contains($creneau)) {
            $this->creneaux->add($creneau);
            $creneau->setService($this);
        }

        return $this;
    }

    public function removeCreneau(Creneau $creneau): static
    {
        if ($this->creneaux->removeElement($creneau)) {
            // set the owning side to null (unless already changed)
            if ($creneau->getService() === $this) {
                $creneau->setService(null);
            }
        }

        return $this;
    }
}
