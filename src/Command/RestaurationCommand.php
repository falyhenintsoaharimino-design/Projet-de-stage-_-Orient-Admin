<?php

namespace App\Command;

use App\Service\JournalService;
use App\Service\SauvegardeService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Restauration d'une sauvegarde (EF-47, ENF-15).
 *
 *   php bin/console app:restauration orient-admin-20261028-020000.dump --base=orient_admin_verif
 *       -> restaure dans une AUTRE base (créée si besoin) : vérification sans risque, la base courante n'est pas touchée ;
 *   php bin/console app:restauration orient-admin-20261028-020000.dump --oui
 *       -> REMPLACE toutes les données de la base courante (une confirmation est demandée sans --oui).
 * Le fichier est donné par son nom (dans SAUVEGARDE_DIR) ou par son chemin complet. La restauration est faite en une seule
 * transaction : en cas d'échec, rien n'est modifié. Arrêtez le serveur web avant de remplacer la base courante.
 */
#[AsCommand(name: 'app:restauration', description: 'Restaure une sauvegarde de la base (dans une autre base avec --base, ou la base courante)')]
class RestaurationCommand extends Command
{
    public function __construct(
        private SauvegardeService $sauvegardes,
        private JournalService $journal,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('fichier', InputArgument::REQUIRED, 'Nom de la sauvegarde (dans SAUVEGARDE_DIR) ou chemin du fichier')
            ->addOption('base', null, InputOption::VALUE_REQUIRED, 'Restaurer dans cette autre base (créée si besoin) au lieu de la base courante')
            ->addOption('oui', null, InputOption::VALUE_NONE, 'Confirmer le remplacement de la base courante sans question');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $fichier = (string) $input->getArgument('fichier');
        $chemin = $this->sauvegardes->chemin($fichier) ?? (is_file($fichier) ? $fichier : null);
        if ($chemin === null) {
            $io->error("Sauvegarde introuvable : $fichier");

            return Command::FAILURE;
        }
        $base = $input->getOption('base');
        if ($base !== null && $base !== '' && !is_string($base)) {
            $io->error('--base doit être un nom de base.');

            return Command::INVALID;
        }
        $base = $base === '' ? null : $base;

        if ($base === null && !$input->getOption('oui')
            && !$io->confirm('Toutes les données de la base courante seront REMPLACÉES par cette sauvegarde. Continuer ?', false)) {
            $io->warning('Restauration annulée : rien n\'a été modifié.');

            return Command::FAILURE;
        }

        try {
            $cible = $this->sauvegardes->restaurer($chemin, $base);
            // Restauration dans une autre base : deux lignes de contrôle à comparer (ENF-15, restauration testée).
            $controles = $base !== null
                ? ['courante' => $this->sauvegardes->controle(), 'restauree' => $this->sauvegardes->controle($base)]
                : [];
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $this->journal->enregistrer('sauvegarde_restauree', null, sprintf('fichier=%s base=%s', basename($chemin), $cible));
        foreach ($controles as $nom => $resume) {
            $io->writeln(sprintf('Contrôle %s : %s', $nom, implode(' ', array_map(fn($k, $v) => "$k=$v", array_keys($resume), $resume))));
        }
        $io->success(sprintf('Sauvegarde %s restaurée dans la base « %s ».', basename($chemin), $cible));

        return Command::SUCCESS;
    }
}
