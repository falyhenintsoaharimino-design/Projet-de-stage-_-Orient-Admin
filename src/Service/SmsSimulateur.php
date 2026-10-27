<?php

namespace App\Service;

use App\Entity\Notification;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Passerelle SMS simulée (EF-33) : aucun SMS réel n'est envoyé. Chaque « envoi » est enregistré comme une
 * notification de canal « sms » (statut « envoye ») et écrit dans le journal technique ; l'administrateur peut
 * les consulter avec GET /api/sms-simules. Une vraie passerelle remplacerait seulement cette classe.
 *
 * Comme pour les e-mails, un SMS ne doit jamais faire échouer l'action métier. Ligne persistée seulement :
 * l'appelant fait le flush.
 */
class SmsSimulateur
{
    public const CANAL_SMS = 'sms';
    public const ENVOYE = 'envoye';
    public const LONGUEUR_MAX = 320;

    public function __construct(
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    /** Un SMS part si le destinataire a choisi le canal « sms » et a un numéro de téléphone. */
    public function veutUnSms(Utilisateur $destinataire): bool
    {
        return $destinataire->getCanalNotification() === self::CANAL_SMS && $destinataire->getTelephone() !== null;
    }

    public function envoyer(Utilisateur $destinataire, string $message): void
    {
        $texte = mb_strlen($message) > self::LONGUEUR_MAX ? mb_substr($message, 0, self::LONGUEUR_MAX - 1) . '…' : $message;

        $sms = new Notification();
        $sms->setType('sms');
        $sms->setCanal(self::CANAL_SMS);
        $sms->setMessage($texte);
        $sms->setStatut(self::ENVOYE);
        $sms->setDateEnvoi(new \DateTimeImmutable());
        $sms->setUtilisateur($destinataire);
        $this->em->persist($sms);

        $this->logger->info('SMS simulé', ['telephone' => $destinataire->getTelephone(), 'message' => $texte]);
    }
}
