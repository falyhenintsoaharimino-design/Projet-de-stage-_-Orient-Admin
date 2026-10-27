<?php

namespace App\Command;

use App\Repository\RendezVousRepository;
use App\Service\Fuseau;
use App\Service\JournalService;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rappel automatique avant le rendez-vous (EF-31) : prévient les citoyens dont le rendez-vous CONFIRMÉ a lieu demain
 * (notification dans l'application + e-mail ou SMS simulé selon leur canal). Chaque rendez-vous n'est rappelé qu'une fois :
 * on peut donc lancer la commande plusieurs fois par jour sans doublon. Un rendez-vous déplacé est rappelé de nouveau.
 *
 * À planifier une fois par jour (ou toutes les heures, sans risque), par exemple avec cron :
 *   0 8 * * *  cd /chemin/du/projet && php bin/console app:rappels-rdv
 * ou avec le planificateur de tâches de Windows (programme : php, arguments : bin/console app:rappels-rdv).
 */
#[AsCommand(name: 'app:rappels-rdv', description: 'Envoie le rappel de la veille pour les rendez-vous confirmés de demain')]
class RappelsRendezVousCommand extends Command
{
    public function __construct(
        private RendezVousRepository $rendezVousRepository,
        private NotificationService $notifications,
        private EntityManagerInterface $em,
        private Fuseau $fuseau,
        private JournalService $journal,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('jours', null, InputOption::VALUE_REQUIRED, 'Rappeler les rendez-vous qui ont lieu dans N jours (1 = demain)', '1')
            ->addOption('date', null, InputOption::VALUE_REQUIRED, 'Rappeler les rendez-vous de ce jour précis (AAAA-MM-JJ), à la place de --jours')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Afficher les rendez-vous concernés sans rien envoyer');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $date = $input->getOption('date');
        if ($date !== null) {
            $valide = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $date);
            if (!$valide || $valide->format('Y-m-d') !== $date) {
                $io->error('--date doit être au format AAAA-MM-JJ');

                return Command::INVALID;
            }
            $jour = $date;
        } else {
            $jours = $input->getOption('jours');
            if (!ctype_digit((string) $jours) || (int) $jours > 30) {
                $io->error('--jours doit être un entier de 0 à 30');

                return Command::INVALID;
            }
            $jour = $this->fuseau->aujourdhui()->modify('+' . (int) $jours . ' days')->format('Y-m-d');
        }

        $rendezVous = $this->rendezVousRepository->aRappeler($jour);
        $envoyes = 0;
        foreach ($rendezVous as $rdv) {
            $citoyen = $rdv->getDemande()?->getUtilisateur();
            $io->writeln(sprintf('%s rendez-vous #%d à %s (%s)', $input->getOption('dry-run') ? '[simulation]' : 'Rappel :',
                $rdv->getId(), $rdv->getCreneau()->getHeure(), $citoyen?->getEmail() ?? 'sans citoyen'), OutputInterface::VERBOSITY_VERBOSE);
            if ($input->getOption('dry-run')) {
                continue;
            }
            $this->notifications->rappelRendezVous($rdv);
            $rdv->setRappelEnvoyeLe(new \DateTimeImmutable());
            $this->em->flush(); // un par un : en cas d'erreur, ceux déjà rappelés ne le seront pas deux fois
            ++$envoyes;
        }

        if ($input->getOption('dry-run')) {
            $io->success(sprintf('%d rappel(s) seraient envoyés pour le %s (simulation : rien n\'a été envoyé).', count($rendezVous), $jour));

            return Command::SUCCESS;
        }
        if ($envoyes > 0) {
            $this->journal->enregistrer('rappels_envoyes', null, sprintf('jour=%s nombre=%d', $jour, $envoyes));
        }
        $io->success(sprintf('%d rappel(s) envoyé(s) pour le %s.', $envoyes, $jour));

        return Command::SUCCESS;
    }
}
