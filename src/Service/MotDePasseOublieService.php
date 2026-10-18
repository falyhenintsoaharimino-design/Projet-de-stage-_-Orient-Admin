<?php

namespace App\Service;

use App\Entity\Utilisateur;
use App\Repository\UtilisateurRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Mot de passe oublié par lien signé, sans table supplémentaire.
 *
 * Jeton = "idUtilisateur.expiration.empreinte.signature" où
 *  - expiration : horodatage Unix (validité : 1 heure) ;
 *  - empreinte  : début du hash du mot de passe actuel, donc le lien ne marche
 *                 qu'une fois (dès que le mot de passe change, il est invalide) ;
 *  - signature  : HMAC-SHA256 des trois premiers éléments avec APP_SECRET.
 *
 * Erreurs métier : \DomainException dont le code est le statut HTTP.
 */
class MotDePasseOublieService
{
    public const VALIDITE = 3600;
    public const PAGE_REINITIALISATION = '/pages/auth/reinitialiser-mot-de-passe.html';

    public function __construct(
        private EntityManagerInterface $em,
        private UtilisateurRepository $utilisateurRepository,
        private UserPasswordHasherInterface $passwordHasher,
        private NotificationService $notifications,
        private JournalService $journal,
        #[Autowire('%kernel.secret%')]
        private string $secret,
        #[Autowire(env: 'APP_URL')]
        private string $appUrl,
    ) {
    }

    /** $expiration (horodatage Unix) sert uniquement aux tests : jeton déjà expiré. */
    public function genererJeton(Utilisateur $utilisateur, ?int $expiration = null): string
    {
        if ($this->secret === '') {
            throw new \LogicException('APP_SECRET est vide : impossible de signer un lien (voir .env.local).');
        }
        $charge = $utilisateur->getId() . '.' . ($expiration ?? time() + self::VALIDITE) . '.' . $this->empreinte($utilisateur);

        return $charge . '.' . hash_hmac('sha256', $charge, $this->secret);
    }

    public function lien(Utilisateur $utilisateur, ?int $expiration = null): string
    {
        return rtrim($this->appUrl, '/') . self::PAGE_REINITIALISATION . '?token=' . $this->genererJeton($utilisateur, $expiration);
    }

    /**
     * Envoie le lien par e-mail. Ne dit rien si le compte n'existe pas ou est
     * désactivé (le contrôleur répond pareil dans tous les cas).
     */
    public function demander(string $email): void
    {
        $utilisateur = $this->utilisateurRepository->findOneBy(['email' => $email]);
        if ($utilisateur === null || !$utilisateur->isActif()) {
            return;
        }

        $this->notifications->envoyerEmail(
            $utilisateur,
            'Réinitialisation de votre mot de passe',
            sprintf(
                "Bonjour %s,\n\nPour choisir un nouveau mot de passe, ouvrez ce lien (valable 1 heure, utilisable une seule fois) :\n%s\n\nSi vous n'êtes pas à l'origine de cette demande, ignorez ce message : votre mot de passe reste inchangé.",
                $utilisateur->getPrenom(),
                $this->lien($utilisateur)
            )
        );
        $this->journal->enregistrer('mdp_oublie_demande', $utilisateur);
    }

    public function reinitialiser(string $jeton, string $motDePasse): Utilisateur
    {
        $utilisateur = $this->utilisateurDuJeton($jeton);
        if ($utilisateur === null) {
            throw new \DomainException('Lien invalide ou expiré', 400);
        }
        if (strlen($motDePasse) < 8) {
            throw new \DomainException('Le mot de passe doit contenir au moins 8 caractères', 400);
        }

        $utilisateur->setPassword($this->passwordHasher->hashPassword($utilisateur, $motDePasse));
        $this->em->flush();
        $this->journal->enregistrer('mdp_reinitialise', $utilisateur);
        $this->notifications->envoyerEmail(
            $utilisateur,
            'Votre mot de passe a été modifié',
            "Bonjour {$utilisateur->getPrenom()},\n\nVotre mot de passe vient d'être modifié. Si ce n'est pas vous, contactez l'administration."
        );

        return $utilisateur;
    }

    private function utilisateurDuJeton(string $jeton): ?Utilisateur
    {
        $morceaux = explode('.', $jeton);
        if (count($morceaux) !== 4 || $this->secret === '') {
            return null;
        }
        [$id, $expiration, $empreinte, $signature] = $morceaux;
        if (!ctype_digit($id) || !ctype_digit($expiration)) {
            return null;
        }
        if (!hash_equals(hash_hmac('sha256', "$id.$expiration.$empreinte", $this->secret), $signature)) {
            return null;
        }
        if ((int) $expiration < time()) {
            return null;
        }
        $utilisateur = $this->utilisateurRepository->find((int) $id);
        if ($utilisateur === null || !$utilisateur->isActif()) {
            return null;
        }

        return hash_equals($this->empreinte($utilisateur), $empreinte) ? $utilisateur : null;
    }

    private function empreinte(Utilisateur $utilisateur): string
    {
        return substr(hash('sha256', (string) $utilisateur->getPassword()), 0, 16);
    }
}
