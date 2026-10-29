<?php

namespace App\Command;

use App\Service\JournalService;
use App\Service\SauvegardeService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Sauvegarde de la base (EF-47, ENF-15) : crée un fichier dans SAUVEGARDE_DIR et ne garde que les plus récents.
 *
 * À planifier une fois par jour, par exemple avec cron :
 *   0 2 * * *  cd /chemin/du/projet && php bin/console app:sauvegarde
 * ou avec le planificateur de tâches de Windows (programme : php, arguments : bin/console app:sauvegarde).
 */
#[AsCommand(name: 'app:sauvegarde', description: 'Sauvegarde la base de données (pg_dump) et supprime les plus anciennes sauvegardes')]
class SauvegardeCommand extends Command
{
    public function __construct(
        private SauvegardeService $sauvegardes,
        private JournalService $journal,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('garder', null, InputOption::VALUE_REQUIRED, 'Nombre de sauvegardes à conserver (les plus récentes)', '14');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $garder = filter_var($input->getOption('garder'), FILTER_VALIDATE_INT);
        if ($garder === false || $garder < 1) {
            $io->error('--garder doit être un entier supérieur ou égal à 1.');

            return Command::INVALID;
        }

        try {
            $sauvegarde = $this->sauvegardes->creer();
            $supprimees = $this->sauvegardes->purger($garder);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $this->journal->enregistrer('sauvegarde_creee', null, sprintf('fichier=%s taille=%d', $sauvegarde['nom'], $sauvegarde['taille']));
        $io->success(sprintf('Sauvegarde %s (%d octets).%s', $sauvegarde['nom'], $sauvegarde['taille'],
            $supprimees ? ' Anciennes sauvegardes supprimées : ' . count($supprimees) . '.' : ''));

        return Command::SUCCESS;
    }
}
