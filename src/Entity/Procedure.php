<?php

namespace App\Entity;

use App\Repository\ProcedureRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProcedureRepository::class)]
class Procedure
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50)]
    private ?string $nom = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(nullable: true)]
    private ?int $delaiEstime = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $frais = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $version = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Service $service = null;

    /**
     * @var Collection<int, EtapeProcedure>
     */
    #[ORM\OneToMany(targetEntity: EtapeProcedure::class, mappedBy: 'procedure', orphanRemoval: true)]
    #[ORM\OrderBy(['ordre' => 'ASC'])]
    private Collection $etapeProcedures;

    /**
     * @var Collection<int, DocumentRequis>
     */
    #[ORM\OneToMany(targetEntity: DocumentRequis::class, mappedBy: 'procedure', orphanRemoval: true)]
    private Collection $documentRequis;

    public function __construct()
    {
        $this->etapeProcedures = new ArrayCollection();
        $this->documentRequis = new ArrayCollection();
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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getDelaiEstime(): ?int
    {
        return $this->delaiEstime;
    }

    public function setDelaiEstime(?int $delaiEstime): static
    {
        $this->delaiEstime = $delaiEstime;

        return $this;
    }

    public function getFrais(): ?string
    {
        return $this->frais;
    }

    public function setFrais(?string $frais): static
    {
        $this->frais = $frais;

        return $this;
    }

    public function getVersion(): ?string
    {
        return $this->version;
    }

    public function setVersion(?string $version): static
    {
        $this->version = $version;

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

    /**
     * @return Collection<int, EtapeProcedure>
     */
    public function getEtapeProcedures(): Collection
    {
        return $this->etapeProcedures;
    }

    public function addEtapeProcedure(EtapeProcedure $etapeProcedure): static
    {
        if (!$this->etapeProcedures->contains($etapeProcedure)) {
            $this->etapeProcedures->add($etapeProcedure);
            $etapeProcedure->setProcedure($this);
        }

        return $this;
    }

    public function removeEtapeProcedure(EtapeProcedure $etapeProcedure): static
    {
        if ($this->etapeProcedures->removeElement($etapeProcedure)) {
            // set the owning side to null (unless already changed)
            if ($etapeProcedure->getProcedure() === $this) {
                $etapeProcedure->setProcedure(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, DocumentRequis>
     */
    public function getDocumentRequis(): Collection
    {
        return $this->documentRequis;
    }

    public function addDocumentRequi(DocumentRequis $documentRequi): static
    {
        if (!$this->documentRequis->contains($documentRequi)) {
            $this->documentRequis->add($documentRequi);
            $documentRequi->setProcedure($this);
        }

        return $this;
    }

    public function removeDocumentRequi(DocumentRequis $documentRequi): static
    {
        if ($this->documentRequis->removeElement($documentRequi)) {
            // set the owning side to null (unless already changed)
            if ($documentRequi->getProcedure() === $this) {
                $documentRequi->setProcedure(null);
            }
        }

        return $this;
    }
}