<?php

namespace App\Service;

use App\Entity\DocumentRequis;
use App\Entity\EtapeProcedure;
use App\Entity\Procedure;
use App\Entity\ProcedureVersion;
use App\Entity\Service;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Écriture du catalogue des procédures (EF-09, EF-11, ENF-13) : validation, droits (l'agent ne modifie que SON
 * service, l'admin tous), historique des versions, date de mise à jour, journal d'audit.
 * Les erreurs sont des \DomainException dont le code est le statut HTTP.
 */
class CatalogueService
{
    public const AVERTISSEMENT = 'Information indicative : vérifiez auprès du service concerné avant de vous déplacer.';

    private const ETIQUETTES = [
        'nom' => 'nom', 'description' => 'description', 'delaiEstime' => 'délai', 'frais' => 'frais',
        'version' => 'libellé de version', 'source' => 'source', 'serviceId' => 'service', 'actif' => 'statut',
        'etapes' => 'étapes', 'documentsRequis' => 'pièces requises',
    ];

    public function __construct(
        private EntityManagerInterface $em,
        private JournalService $journal,
    ) {
    }

    /** Un agent gère les procédures de son service ; l'admin gère tout. */
    public function peutGerer(?Utilisateur $utilisateur, Service $service): bool
    {
        if ($utilisateur === null) {
            return false;
        }
        $roles = $utilisateur->getRoles();
        if (in_array('ROLE_ADMIN', $roles, true)) {
            return true;
        }

        return in_array('ROLE_AGENT', $roles, true)
            && $utilisateur->getService() !== null
            && $utilisateur->getService()->getId() === $service->getId();
    }

    public function exigerDroit(?Utilisateur $utilisateur, Service $service): void
    {
        if (!$this->peutGerer($utilisateur, $service)) {
            throw new \DomainException('Vous ne pouvez gérer que les procédures de votre service', 403);
        }
    }

    /** Public : procédure et service actifs. Gestionnaire (agent du service, admin) : tout. */
    public function estVisible(Procedure $procedure, ?Utilisateur $utilisateur): bool
    {
        return ($procedure->isActif() && $procedure->getService()->isActif())
            || $this->peutGerer($utilisateur, $procedure->getService());
    }

    /**
     * Crée ($procedure null) ou modifie une procédure. Seules les clés présentes dans $data sont modifiées ;
     * `etapes` et `documentsRequis`, si présentes, remplacent toute la liste. Un nouvel instantané est enregistré
     * seulement si le contenu change réellement.
     *
     * @param array<string, mixed> $data
     */
    public function enregistrer(?Procedure $procedure, Service $service, array $data, Utilisateur $auteur): Procedure
    {
        $creation = $procedure === null;
        $this->valider($data, $creation);

        if ($creation) {
            $procedure = new Procedure();
            $procedure->setService($service);
            $this->em->persist($procedure);
            $avant = [];
        } else {
            $avant = $this->instantane($procedure);
            if ($avant !== [] && !$this->aDesVersions($procedure)) {
                // Procédure antérieure à l'historique : on garde d'abord son état initial.
                $this->ajouterVersion($procedure, $auteur, 'État initial', $avant, $procedure->getNumeroVersion());
            }
        }

        $this->appliquer($procedure, $service, $data);
        $apres = $this->instantane($procedure);

        if (!$creation && $apres === $avant) {
            $this->em->flush();

            return $procedure; // rien n'a changé : pas de nouvelle version, date inchangée
        }

        $numero = $creation ? 1 : $procedure->getNumeroVersion() + 1;
        $procedure->setNumeroVersion($numero);
        $procedure->setMisAJourLe(new \DateTimeImmutable());
        $resume = $creation ? 'Création' : $this->resumer($avant, $apres);
        $this->ajouterVersion($procedure, $auteur, $resume, $apres, $numero);
        $this->em->flush();

        $action = match (true) {
            $creation => 'procedure_creee',
            $avant['actif'] && !$apres['actif'] => 'procedure_desactivee',
            !$avant['actif'] && $apres['actif'] => 'procedure_reactivee',
            default => 'procedure_modifiee',
        };
        $this->journal->enregistrer($action, $auteur, 'procedure=' . $procedure->getId() . ' version=' . $numero);

        return $procedure;
    }

    /** Suppression logique (réactivable avec `actif: true`). */
    public function desactiver(Procedure $procedure, Utilisateur $auteur): void
    {
        $this->enregistrer($procedure, $procedure->getService(), ['actif' => false], $auteur);
    }

    /** L'agent confirme que l'information est toujours exacte : met à jour la date de vérification seulement. */
    public function verifier(Procedure $procedure, Utilisateur $auteur): void
    {
        $procedure->setVerifieLe(new \DateTimeImmutable());
        $this->em->flush();
        $this->journal->enregistrer('procedure_verifiee', $auteur, 'procedure=' . $procedure->getId());
    }

    /** @return list<array<string, mixed>> versions, de la plus récente à la plus ancienne */
    public function versions(Procedure $procedure): array
    {
        $versions = $this->em->getRepository(ProcedureVersion::class)
            ->findBy(['procedure' => $procedure], ['numero' => 'DESC']);

        return array_map(fn(ProcedureVersion $v) => [
            'numero' => $v->getNumero(),
            'date' => $v->getCreeLe()?->format(\DATE_ATOM),
            'auteur' => $v->getAuteur() ? [
                'id' => $v->getAuteur()->getId(),
                'nom' => trim($v->getAuteur()->getPrenom() . ' ' . $v->getAuteur()->getNom()),
            ] : null,
            'resume' => $v->getResume(),
            'contenu' => $v->getContenu(),
        ], $versions);
    }

    /** @return array<string, mixed> */
    public function serialiser(Procedure $p): array
    {
        $service = $p->getService();

        return [
            'id' => $p->getId(),
            'nom' => $p->getNom(),
            'description' => $p->getDescription(),
            'delaiEstime' => $p->getDelaiEstime(),
            'frais' => $p->getFrais(),
            'version' => $p->getVersion(),
            'numeroVersion' => $p->getNumeroVersion(),
            'source' => $p->getSource(),
            'misAJourLe' => $p->getMisAJourLe()?->format(\DATE_ATOM),
            'verifieLe' => $p->getVerifieLe()?->format(\DATE_ATOM),
            'actif' => $p->isActif(),
            'avertissement' => self::AVERTISSEMENT,
            'service' => ['id' => $service->getId(), 'nom' => $service->getNom(), 'categorie' => $service->getCategorie()],
            'etapes' => array_map(fn(EtapeProcedure $e) => [
                'id' => $e->getId(), 'ordre' => $e->getOrdre(), 'description' => $e->getDescription(),
            ], $p->getEtapeProcedures()->toArray()),
            'documentsRequis' => array_map(fn(DocumentRequis $d) => [
                'id' => $d->getId(),
                'libelle' => $d->getLibelle(),
                'obligatoire' => $d->isObligatoire(),
                'remarque' => $d->getRemarque(),
                'exemplaires' => $d->getExemplaires(),
                'ancienneteMaxMois' => $d->getAncienneteMaxMois(),
                'ouObtenir' => $d->getOuObtenir(),
            ], $p->getDocumentRequis()->toArray()),
        ];
    }

    /** Contenu comparé d'une version à l'autre (sans les dates ni les identifiants). @return array<string, mixed> */
    public function instantane(Procedure $p): array
    {
        return [
            'nom' => $p->getNom(),
            'description' => $p->getDescription(),
            'delaiEstime' => $p->getDelaiEstime(),
            'frais' => $p->getFrais(),
            'version' => $p->getVersion(),
            'source' => $p->getSource(),
            'serviceId' => $p->getService()?->getId(),
            'actif' => $p->isActif(),
            'etapes' => array_map(fn(EtapeProcedure $e) => $e->getDescription(), $p->getEtapeProcedures()->toArray()),
            'documentsRequis' => array_map(fn(DocumentRequis $d) => [
                'libelle' => $d->getLibelle(),
                'obligatoire' => $d->isObligatoire(),
                'remarque' => $d->getRemarque(),
                'exemplaires' => $d->getExemplaires(),
                'ancienneteMaxMois' => $d->getAncienneteMaxMois(),
                'ouObtenir' => $d->getOuObtenir(),
            ], $p->getDocumentRequis()->toArray()),
        ];
    }

    /** @param array<string, mixed> $data */
    private function appliquer(Procedure $p, Service $service, array $data): void
    {
        $p->setService($service);
        if (array_key_exists('nom', $data)) {
            $p->setNom(trim($data['nom']));
        }
        foreach (['description' => 'setDescription', 'frais' => 'setFrais', 'version' => 'setVersion', 'source' => 'setSource'] as $cle => $setter) {
            if (array_key_exists($cle, $data)) {
                $p->$setter($this->texteOuNull($data[$cle]));
            }
        }
        if (array_key_exists('delaiEstime', $data)) {
            $p->setDelaiEstime($data['delaiEstime']);
        }
        if (array_key_exists('actif', $data)) {
            $p->setActif($data['actif']);
        }

        if (array_key_exists('etapes', $data)) {
            foreach ($p->getEtapeProcedures()->toArray() as $ancienne) {
                $p->removeEtapeProcedure($ancienne); // orphanRemoval : supprimée en base
            }
            foreach (array_values($data['etapes']) as $i => $etape) {
                $e = new EtapeProcedure();
                $e->setOrdre($i + 1);
                $e->setDescription(trim(is_array($etape) ? $etape['description'] : $etape));
                $p->addEtapeProcedure($e);
                $this->em->persist($e);
            }
        }

        if (array_key_exists('documentsRequis', $data)) {
            foreach ($p->getDocumentRequis()->toArray() as $ancien) {
                $p->removeDocumentRequi($ancien);
            }
            foreach ($data['documentsRequis'] as $doc) {
                $d = new DocumentRequis();
                $d->setLibelle(trim($doc['libelle']));
                $d->setObligatoire($doc['obligatoire'] ?? true);
                $d->setRemarque($this->texteOuNull($doc['remarque'] ?? null));
                $d->setExemplaires($doc['exemplaires'] ?? 1);
                $d->setAncienneteMaxMois($doc['ancienneteMaxMois'] ?? null);
                $d->setOuObtenir($this->texteOuNull($doc['ouObtenir'] ?? null));
                $p->addDocumentRequi($d);
                $this->em->persist($d);
            }
        }
    }

    /** @param array<string, mixed> $data */
    private function valider(array $data, bool $creation): void
    {
        if ($creation && (!isset($data['nom']) || !is_string($data['nom']) || trim($data['nom']) === '')) {
            throw new \DomainException('nom est requis', 400);
        }
        $this->texte($data, 'nom', 150, false);
        $this->texte($data, 'description', 5000);
        $this->texte($data, 'frais', 50);
        $this->texte($data, 'version', 50);
        $this->texte($data, 'source', 255);
        $this->entier($data, 'delaiEstime', 0, 3650);
        if (array_key_exists('actif', $data) && !is_bool($data['actif'])) {
            throw new \DomainException('actif doit être true ou false', 400);
        }

        if (array_key_exists('etapes', $data)) {
            $this->liste($data['etapes'], 'etapes');
            foreach ($data['etapes'] as $etape) {
                $texte = is_array($etape) ? ($etape['description'] ?? null) : $etape;
                if (!is_string($texte) || trim($texte) === '' || strlen($texte) > 2000) {
                    throw new \DomainException('Chaque étape doit avoir une description (1 à 2000 caractères)', 400);
                }
            }
        }
        if (array_key_exists('documentsRequis', $data)) {
            $this->liste($data['documentsRequis'], 'documentsRequis');
            foreach ($data['documentsRequis'] as $doc) {
                if (!is_array($doc)) {
                    throw new \DomainException('Chaque pièce doit être un objet {"libelle": ...}', 400);
                }
                if (!isset($doc['libelle']) || !is_string($doc['libelle']) || trim($doc['libelle']) === '' || strlen($doc['libelle']) > 150) {
                    throw new \DomainException('Chaque pièce doit avoir un libelle (1 à 150 caractères)', 400);
                }
                if (array_key_exists('obligatoire', $doc) && !is_bool($doc['obligatoire'])) {
                    throw new \DomainException('obligatoire doit être true ou false', 400);
                }
                $this->texte($doc, 'remarque', 2000);
                $this->texte($doc, 'ouObtenir', 255);
                $this->entier($doc, 'exemplaires', 1, 20, false);
                $this->entier($doc, 'ancienneteMaxMois', 1, 600);
            }
        }
    }

    /** @param array<string, mixed> $data */
    private function texte(array $data, string $cle, int $max, bool $nullable = true): void
    {
        if (!array_key_exists($cle, $data)) {
            return;
        }
        $valeur = $data[$cle];
        if ($valeur === null && $nullable) {
            return;
        }
        if (!is_string($valeur) || strlen($valeur) > $max || (!$nullable && trim($valeur) === '')) {
            throw new \DomainException(sprintf('%s doit être un texte de %d caractères au plus', $cle, $max), 400);
        }
    }

    /** @param array<string, mixed> $data */
    private function entier(array $data, string $cle, int $min, int $max, bool $nullable = true): void
    {
        if (!array_key_exists($cle, $data) || ($data[$cle] === null && $nullable)) {
            return;
        }
        if (!is_int($data[$cle]) || $data[$cle] < $min || $data[$cle] > $max) {
            throw new \DomainException(sprintf('%s doit être un entier entre %d et %d', $cle, $min, $max), 400);
        }
    }

    private function liste(mixed $valeur, string $cle): void
    {
        if (!is_array($valeur) || !array_is_list($valeur) || count($valeur) > 50) {
            throw new \DomainException($cle . ' doit être une liste de 50 éléments au plus', 400);
        }
    }

    private function texteOuNull(mixed $valeur): ?string
    {
        $texte = $valeur === null ? null : trim((string) $valeur);

        return $texte === '' ? null : $texte;
    }

    /** @param array<string, mixed> $avant @param array<string, mixed> $apres */
    private function resumer(array $avant, array $apres): string
    {
        if ($avant['actif'] && !$apres['actif']) {
            return 'Désactivation';
        }
        if (!$avant['actif'] && $apres['actif']) {
            return 'Réactivation';
        }
        $champs = [];
        foreach (self::ETIQUETTES as $cle => $etiquette) {
            if (($avant[$cle] ?? null) !== ($apres[$cle] ?? null)) {
                $champs[] = $etiquette;
            }
        }

        return 'Modification : ' . implode(', ', $champs);
    }

    private function aDesVersions(Procedure $procedure): bool
    {
        return $this->em->getRepository(ProcedureVersion::class)->count(['procedure' => $procedure]) > 0;
    }

    /** @param array<string, mixed> $contenu */
    private function ajouterVersion(Procedure $p, ?Utilisateur $auteur, string $resume, array $contenu, int $numero): void
    {
        $v = new ProcedureVersion();
        $v->setProcedure($p)->setNumero($numero)->setCreeLe(new \DateTimeImmutable())
            ->setAuteur($auteur)->setResume($resume)->setContenu($contenu);
        $this->em->persist($v);
    }
}
