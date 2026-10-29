<?php

namespace App\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

/**
 * Sauvegarde et restauration de la base PostgreSQL (EF-47, ENF-15) avec les outils officiels pg_dump / pg_restore.
 *
 * Les sauvegardes sont des fichiers « orient-admin-AAAAMMJJ-HHMMSS.dump » (format personnalisé de pg_dump : compressé,
 * schéma + données + historique des migrations) rangés dans SAUVEGARDE_DIR (par défaut var/sauvegardes, hors dépôt git).
 * Les programmes sont cherchés dans le PATH, ou indiqués par PG_DUMP_BIN et PG_RESTORE_BIN (chemin complet).
 * Le mot de passe de la base passe par la variable d'environnement PGPASSWORD du processus : il n'apparaît jamais
 * dans la ligne de commande, ni dans les messages d'erreur.
 *
 * Erreurs : \RuntimeException (le message dit quoi faire) ; \InvalidArgumentException pour un nom invalide.
 */
class SauvegardeService
{
    public const MOTIF_NOM = '/^orient-admin-\d{8}-\d{6}\.dump$/';

    public function __construct(
        private Connection $connection,
        #[Autowire('%env(resolve:SAUVEGARDE_DIR)%')]
        private string $dossier,
        #[Autowire(env: 'PG_DUMP_BIN')]
        private string $pgDump,
        #[Autowire(env: 'PG_RESTORE_BIN')]
        private string $pgRestore,
    ) {
    }

    /** Vrai si pg_dump et pg_restore sont utilisables sur ce serveur. */
    public function outilsDisponibles(): bool
    {
        foreach ([$this->pgDump, $this->pgRestore] as $outil) {
            try {
                $p = new Process([$outil, '--version']);
                $p->run();
                if (!$p->isSuccessful()) {
                    return false;
                }
            } catch (\Throwable) {
                return false;
            }
        }

        return true;
    }

    /** Crée une sauvegarde de la base courante. @return array{nom: string, taille: int, date: string} */
    public function creer(): array
    {
        $this->preparerDossier();
        $nom = $this->nomLibre();
        $final = $this->dossier . '/' . $nom;
        $temporaire = $final . '.partiel';

        $params = $this->connection->getParams();
        $this->lancer([
            $this->pgDump, '--format=custom', '--no-owner', '--no-privileges',
            '--file=' . $temporaire, ...$this->optionsConnexion($params), '--dbname=' . $params['dbname'],
        ], $params, 'pg_dump', $this->pgDump);

        // Fichier écrit entièrement avant d'apparaître dans la liste : jamais de sauvegarde à moitié finie.
        if (!is_file($temporaire) || filesize($temporaire) === 0 || !@rename($temporaire, $final)) {
            @unlink($temporaire);
            throw new \RuntimeException('La sauvegarde n\'a produit aucun fichier : vérifiez les droits d\'écriture sur ' . $this->dossier);
        }

        return $this->decrire($nom);
    }

    /** @return list<array{nom: string, taille: int, date: string}> de la plus récente à la plus ancienne */
    public function lister(): array
    {
        if (!is_dir($this->dossier)) {
            return [];
        }
        $noms = array_values(array_filter(scandir($this->dossier) ?: [], fn(string $f) => preg_match(self::MOTIF_NOM, $f) === 1));
        rsort($noms); // le nom contient la date : tri alphabétique = tri chronologique

        return array_map(fn(string $nom) => $this->decrire($nom), $noms);
    }

    /** Chemin d'une sauvegarde par son nom (null si le nom est invalide ou le fichier absent : pas de traversée de dossier). */
    public function chemin(string $nom): ?string
    {
        if (preg_match(self::MOTIF_NOM, $nom) !== 1) {
            return null;
        }
        $chemin = $this->dossier . '/' . $nom;

        return is_file($chemin) ? $chemin : null;
    }

    /**
     * Ne garde que les $garder sauvegardes les plus récentes.
     *
     * @return list<string> noms supprimés
     */
    public function purger(int $garder): array
    {
        if ($garder < 1) {
            throw new \InvalidArgumentException('Il faut garder au moins une sauvegarde');
        }
        $supprimees = [];
        foreach (array_slice($this->lister(), $garder) as $ancienne) {
            if (@unlink($this->dossier . '/' . $ancienne['nom'])) {
                $supprimees[] = $ancienne['nom'];
            }
        }

        return $supprimees;
    }

    /**
     * Restaure une sauvegarde, en une seule transaction (échec = rien n'est modifié).
     *  - sans $baseCible : REMPLACE toutes les données de la base courante ;
     *  - avec $baseCible : restaure dans cette autre base (créée si besoin), sans toucher à la base courante :
     *    c'est le moyen de vérifier une sauvegarde sans risque.
     *
     * @return string nom de la base restaurée
     */
    public function restaurer(string $fichier, ?string $baseCible = null): string
    {
        if (!is_file($fichier)) {
            throw new \RuntimeException("Fichier de sauvegarde introuvable : $fichier");
        }
        $params = $this->connection->getParams();
        $cible = $baseCible ?? $params['dbname'];
        if ($baseCible !== null) {
            if (preg_match('/^[A-Za-z0-9_]{1,63}$/', $baseCible) !== 1) {
                throw new \InvalidArgumentException('Nom de base invalide (lettres, chiffres et _ seulement, 63 caractères au plus)');
            }
            if ($baseCible === $params['dbname']) {
                throw new \InvalidArgumentException('Pour remplacer la base courante, ne donnez pas --base');
            }
            $this->creerBaseSiAbsente($baseCible, $params);
        }

        $this->lancer([
            $this->pgRestore, '--clean', '--if-exists', '--no-owner', '--no-privileges', '--single-transaction',
            ...$this->optionsConnexion($params), '--dbname=' . $cible, $fichier,
        ], $params, 'pg_restore', $this->pgRestore);

        return $cible;
    }

    /**
     * Contrôle chiffré d'une base (courante si $base est null) : nombre de tables et de lignes des tables clés.
     * Sert à comparer une base restaurée avec la base d'origine.
     *
     * @return array<string, int>
     */
    public function controle(?string $base = null): array
    {
        $params = $this->connection->getParams();
        $connexion = $base === null ? $this->connection : DriverManager::getConnection(array_merge($params, ['dbname' => $base]));
        try {
            $resume = ['tables' => (int) $connexion->fetchOne("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'public' AND table_type = 'BASE TABLE'")];
            foreach (['service', 'utilisateur', 'demande', 'rendez_vous', 'procedure', 'journal', 'doctrine_migration_versions'] as $table) {
                $resume[$table] = (int) $connexion->fetchOne('SELECT COUNT(*) FROM "' . $table . '"');
            }
        } finally {
            if ($base !== null) {
                $connexion->close();
            }
        }

        return $resume;
    }

    /** @param array<string, mixed> $params */
    private function creerBaseSiAbsente(string $base, array $params): void
    {
        $admin = DriverManager::getConnection(array_merge($params, ['dbname' => 'postgres']));
        try {
            if (!$admin->fetchOne('SELECT 1 FROM pg_database WHERE datname = ?', [$base])) {
                $admin->executeStatement('CREATE DATABASE "' . $base . '"');
            }
        } finally {
            $admin->close();
        }
    }

    /** @param array<string, mixed> $params @return list<string> */
    private function optionsConnexion(array $params): array
    {
        $options = [];
        if (!empty($params['host'])) {
            $options[] = '--host=' . $params['host'];
        }
        if (!empty($params['port'])) {
            $options[] = '--port=' . $params['port'];
        }
        if (!empty($params['user'])) {
            $options[] = '--username=' . $params['user'];
        }

        return $options;
    }

    /** @param list<string> $commande @param array<string, mixed> $params */
    private function lancer(array $commande, array $params, string $programme, string $binaire): void
    {
        $process = new Process($commande, null, ['PGPASSWORD' => (string) ($params['password'] ?? '')], null, 900);
        try {
            $process->run();
        } catch (\Throwable $e) {
            throw new \RuntimeException("$programme est introuvable ou n'a pas pu démarrer : installez les outils client PostgreSQL ou renseignez "
                . ($programme === 'pg_dump' ? 'PG_DUMP_BIN' : 'PG_RESTORE_BIN') . " (valeur actuelle : $binaire). Détail : " . $e->getMessage(), 0, $e);
        }
        if (!$process->isSuccessful()) {
            $erreur = trim($process->getErrorOutput());
            if (($params['password'] ?? '') !== '') {
                $erreur = str_replace((string) $params['password'], '***', $erreur);
            }
            throw new \RuntimeException("$programme a échoué (code " . $process->getExitCode() . ') : ' . mb_substr($erreur, 0, 500));
        }
    }

    private function preparerDossier(): void
    {
        if (!is_dir($this->dossier) && !@mkdir($this->dossier, 0775, true) && !is_dir($this->dossier)) {
            throw new \RuntimeException('Impossible de créer le dossier des sauvegardes : ' . $this->dossier);
        }
    }

    /** Nom horodaté ; si deux sauvegardes tombent dans la même seconde, on avance d'une seconde. */
    private function nomLibre(): string
    {
        $moment = new \DateTimeImmutable();
        do {
            $nom = 'orient-admin-' . $moment->format('Ymd-His') . '.dump';
            $moment = $moment->modify('+1 second');
        } while (file_exists($this->dossier . '/' . $nom));

        return $nom;
    }

    /** @return array{nom: string, taille: int, date: string} */
    private function decrire(string $nom): array
    {
        $chemin = $this->dossier . '/' . $nom;

        return [
            'nom' => $nom,
            'taille' => (int) filesize($chemin),
            'date' => (new \DateTimeImmutable('@' . filemtime($chemin)))->format(DATE_ATOM),
        ];
    }
}
