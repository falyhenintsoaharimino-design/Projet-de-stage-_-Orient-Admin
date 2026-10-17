<?php

namespace App\EventListener;

use App\Entity\Utilisateur;
use App\Service\JournalService;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Journalise les connexions (pare-feu « login » uniquement : le pare-feu
 * « api » déclenche aussi LoginSuccessEvent à chaque requête avec jeton).
 */
class JournalConnexionListener
{
    private const PARE_FEU_LOGIN = 'login';

    public function __construct(private JournalService $journal)
    {
    }

    #[AsEventListener]
    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        if ($event->getFirewallName() !== self::PARE_FEU_LOGIN) {
            return;
        }
        $utilisateur = $event->getUser();
        if ($utilisateur instanceof Utilisateur) {
            $this->journal->enregistrer('connexion', $utilisateur);
        }
    }

    #[AsEventListener]
    public function onLoginFailure(LoginFailureEvent $event): void
    {
        if ($event->getFirewallName() !== self::PARE_FEU_LOGIN) {
            return;
        }
        // Jamais le mot de passe : seulement l'e-mail saisi.
        $donnees = json_decode($event->getRequest()->getContent(), true);
        $email = is_array($donnees) && is_string($donnees['email'] ?? null) ? $donnees['email'] : null;

        $this->journal->enregistrer('connexion_echec', null, $email === null ? null : 'email=' . mb_substr($email, 0, 100));
    }
}
