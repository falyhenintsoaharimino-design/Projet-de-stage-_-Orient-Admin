<?php

namespace App\Command;

use App\Repository\UtilisateurRepository;
use App\Service\MotDePasseOublieService;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:lien-reinitialisation',
    description: 'Affiche le lien (ou le jeton) de réinitialisation du mot de passe d\'un utilisateur (tests, dépannage)',
)]
class LienReinitialisationCommand
{
    public function __construct(
        private UtilisateurRepository $utilisateurRepository,
        private MotDePasseOublieService $motDePasseOublie,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('E-mail de l\'utilisateur')] string $email,
        #[Option('Affiche seulement le jeton (pour les scripts)')] bool $jeton = false,
        #[Option('Génère un jeton déjà expiré (pour tester l\'expiration)')] bool $expire = false,
    ): int {
        $utilisateur = $this->utilisateurRepository->findOneBy(['email' => $email]);
        if ($utilisateur === null) {
            $io->error("Aucun utilisateur avec l'e-mail $email.");

            return Command::FAILURE;
        }

        $expiration = $expire ? time() - 60 : null;
        $io->writeln($jeton
            ? $this->motDePasseOublie->genererJeton($utilisateur, $expiration)
            : $this->motDePasseOublie->lien($utilisateur, $expiration));

        return Command::SUCCESS;
    }
}
