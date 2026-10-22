<?php

namespace App\Service;

use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Compte du citoyen connecté : profil et préférences (EF-01, EF-05), export des données
 * personnelles (droit d'accès) et suppression du compte (ENF-05, loi malgache n°2014-038).
 * Les erreurs de validation sont des \DomainException dont le code est le statut HTTP.
 */
class CompteService
{
    public const LANGUES = ['fr', 'mg'];
    public const CANAUX = ['email', 'sms'];
    private const CHAMPS_TEXTE_MAX = 100;
    private const JOURNAL_EXPORT_MAX = 500;

    public function __construct(
        private EntityManagerInterface $em,
        private JournalService $journal,
    ) {
    }

    /** @return array<string, mixed> */
    public function profil(Utilisateur $u): array
    {
        return [
            'id' => $u->getId(),
            'nom' => $u->getNom(),
            'prenom' => $u->getPrenom(),
            'email' => $u->getEmail(),
            'roles' => $u->getRoles(),
            'service' => $u->getService()?->getId(),
            'telephone' => $u->getTelephone(),
            'langue' => $u->getLangue(),
            'canalNotification' => $u->getCanalNotification(),
            'consentementLe' => $u->getConsentementLe()?->format(\DATE_ATOM),
        ];
    }

    /** Retire espaces, points, tirets et parenthèses ; null si vide. */
    public function normaliserTelephone(mixed $valeur): ?string
    {
        if ($valeur === null) {
            return null;
        }
        if (!is_string($valeur)) {
            throw new \DomainException('telephone : texte attendu', 400);
        }
        $numero = (string) preg_replace('/[\s.\-()]/', '', $valeur);
        if ($numero === '') {
            return null;
        }
        if (!preg_match('/^\+?[0-9]{6,15}$/', $numero)) {
            throw new \DomainException('Numéro de téléphone invalide (6 à 15 chiffres, « + » facultatif au début)', 400);
        }

        return $numero;
    }

    /**
     * Modifie uniquement les champs autorisés et présents dans $data ; tout le reste est ignoré
     * (jamais l'e-mail, le mot de passe, le rôle ni le service).
     *
     * @param array<string, mixed> $data
     *
     * @return list<string> noms des champs modifiés
     */
    public function modifierProfil(Utilisateur $u, array $data): array
    {
        $nouveau = [];
        foreach (['nom', 'prenom'] as $champ) {
            if (array_key_exists($champ, $data)) {
                $valeur = is_string($data[$champ]) ? trim($data[$champ]) : '';
                if ($valeur === '' || mb_strlen($valeur) > self::CHAMPS_TEXTE_MAX) {
                    throw new \DomainException(sprintf('%s : texte de 1 à %d caractères requis', $champ, self::CHAMPS_TEXTE_MAX), 400);
                }
                $nouveau[$champ] = $valeur;
            }
        }
        if (array_key_exists('telephone', $data)) {
            $nouveau['telephone'] = $this->normaliserTelephone($data['telephone']);
        }
        if (array_key_exists('langue', $data)) {
            if (!in_array($data['langue'], self::LANGUES, true)) {
                throw new \DomainException('langue : valeurs possibles ' . implode(', ', self::LANGUES), 400);
            }
            $nouveau['langue'] = $data['langue'];
        }
        if (array_key_exists('canalNotification', $data)) {
            if (!in_array($data['canalNotification'], self::CANAUX, true)) {
                throw new \DomainException('canalNotification : valeurs possibles ' . implode(', ', self::CANAUX), 400);
            }
            $nouveau['canalNotification'] = $data['canalNotification'];
        }
        if ($nouveau === []) {
            throw new \DomainException('Aucun champ modifiable fourni (nom, prenom, telephone, langue, canalNotification)', 400);
        }

        $telephone = array_key_exists('telephone', $nouveau) ? $nouveau['telephone'] : $u->getTelephone();
        $canal = $nouveau['canalNotification'] ?? $u->getCanalNotification();
        if ($canal === 'sms' && $telephone === null) {
            throw new \DomainException('Un numéro de téléphone est requis pour recevoir les notifications par SMS', 400);
        }

        foreach ($nouveau as $champ => $valeur) {
            match ($champ) {
                'nom' => $u->setNom($valeur),
                'prenom' => $u->setPrenom($valeur),
                'telephone' => $u->setTelephone($valeur),
                'langue' => $u->setLangue($valeur),
                'canalNotification' => $u->setCanalNotification($valeur),
            };
        }
        $this->em->flush();
        $noms = array_keys($nouveau);
        $this->journal->enregistrer('profil_modifie', $u, implode(', ', $noms));

        return $noms;
    }

    /**
     * Toutes les données personnelles du compte (jamais le mot de passe haché).
     *
     * @return array<string, mixed>
     */
    public function exporterDonnees(Utilisateur $u): array
    {
        $id = $u->getId();
        $date = static fn (?\DateTimeInterface $d): ?string => $d?->format(\DATE_ATOM);

        $demandes = $this->em->createQuery('SELECT d FROM App\Entity\Demande d WHERE d.utilisateur = :id ORDER BY d.id')
            ->setParameter('id', $id)->getResult();
        $rendezVous = $this->em->createQuery('SELECT r FROM App\Entity\RendezVous r JOIN r.demande d WHERE d.utilisateur = :id ORDER BY r.id')
            ->setParameter('id', $id)->getResult();
        $avis = $this->em->createQuery('SELECT a FROM App\Entity\Avis a JOIN a.rendezVous r JOIN r.demande d WHERE d.utilisateur = :id ORDER BY a.id')
            ->setParameter('id', $id)->getResult();
        $notifications = $this->em->createQuery('SELECT n FROM App\Entity\Notification n WHERE n.utilisateur = :id ORDER BY n.id')
            ->setParameter('id', $id)->getResult();
        $journal = $this->em->createQuery('SELECT j FROM App\Entity\Journal j WHERE j.utilisateur = :id ORDER BY j.id DESC')
            ->setParameter('id', $id)->setMaxResults(self::JOURNAL_EXPORT_MAX)->getResult();

        $this->journal->enregistrer('donnees_exportees', $u);

        return [
            'exporteLe' => (new \DateTimeImmutable())->format(\DATE_ATOM),
            'profil' => $this->profil($u) + ['role' => $u->getRole()?->getNom(), 'actif' => $u->isActif()],
            'demandes' => array_map(static fn ($d) => [
                'id' => $d->getId(),
                'texte' => $d->getTextDemande(),
                'statut' => $d->getStatus(),
                'dateCreation' => $date($d->getDateCreation()),
                'confiance' => $d->getConfiance(),
                'retourPertinent' => $d->getRetourPertinent(),
                'serviceRecommande' => $d->getServiceRecommande()?->getNom(),
                'serviceFinal' => $d->getServiceFinal()?->getNom(),
                'historique' => array_map(static fn ($h) => [
                    'statut' => $h->getStatut(),
                    'date' => $date($h->getDateChangement()),
                ], $d->getHistoriqueStatuts()->toArray()),
            ], $demandes),
            'rendezVous' => array_map(static fn ($r) => [
                'id' => $r->getId(),
                'statut' => $r->getStatut(),
                'demandeId' => $r->getDemande()?->getId(),
                'service' => $r->getCreneau()?->getService()?->getNom(),
                'date' => $r->getCreneau()?->getDate()?->format('Y-m-d'),
                'heure' => $r->getCreneau()?->getHeure(),
            ], $rendezVous),
            'avis' => array_map(static fn ($a) => [
                'id' => $a->getId(),
                'rendezVousId' => $a->getRendezVous()?->getId(),
                'note' => $a->getNote(),
                'commentaire' => $a->getCommentaire(),
                'dateCreation' => $date($a->getDateCreation()),
            ], $avis),
            'notifications' => array_map(static fn ($n) => [
                'id' => $n->getId(),
                'type' => $n->getType(),
                'canal' => $n->getCanal(),
                'message' => $n->getMessage(),
                'statut' => $n->getStatut(),
                'dateEnvoi' => $date($n->getDateEnvoi()),
            ], $notifications),
            'journal' => array_map(static fn ($j) => [
                'action' => $j->getAction(),
                'date' => $date($j->getDate()),
                'adresseIp' => $j->getAdresseIp(),
            ], $journal),
        ];
    }

    /**
     * Supprime le compte et ses données : demandes (et historique), rendez-vous, avis, notifications.
     * Les exemples d'entraînement du moteur (déjà anonymisés) sont gardés, détachés de la demande ;
     * les lignes du journal d'audit sont gardées, détachées du compte (traçabilité, ENF-14).
     */
    public function supprimer(Utilisateur $u): void
    {
        $id = $u->getId();
        $demandesDuCompte = '(SELECT d.id FROM App\Entity\Demande d WHERE d.utilisateur = :id)';

        $compteurs = $this->em->wrapInTransaction(function () use ($id, $demandesDuCompte): array {
            $requete = fn (string $dql) => $this->em->createQuery($dql)->setParameter('id', $id)->execute();

            $requete("DELETE App\\Entity\\Avis a WHERE a.rendezVous IN (SELECT r.id FROM App\\Entity\\RendezVous r WHERE r.demande IN $demandesDuCompte)");
            $rdv = $requete("DELETE App\\Entity\\RendezVous r WHERE r.demande IN $demandesDuCompte");
            $requete("UPDATE App\\Entity\\ExempleOrientation e SET e.demande = NULL WHERE e.demande IN $demandesDuCompte");
            $requete("DELETE App\\Entity\\HistoriqueStatut h WHERE h.demande IN $demandesDuCompte");
            $demandes = $requete('DELETE App\Entity\Demande d WHERE d.utilisateur = :id');
            $requete('DELETE App\Entity\Notification n WHERE n.utilisateur = :id');
            $requete('UPDATE App\Entity\Journal j SET j.utilisateur = NULL WHERE j.utilisateur = :id');
            $requete('DELETE App\Entity\Utilisateur u WHERE u.id = :id');

            return ['demandes' => $demandes, 'rendez_vous' => $rdv];
        });

        $this->em->clear();
        $this->journal->enregistrer('compte_supprime', null, sprintf('demandes=%d, rendez_vous=%d', $compteurs['demandes'], $compteurs['rendez_vous']));
    }
}
