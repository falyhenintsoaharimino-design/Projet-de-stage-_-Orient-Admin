<?php

namespace App\Service;

use App\Entity\Notification;
use App\Entity\RendezVous;
use App\Entity\Utilisateur;
use App\Repository\UtilisateurRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Notifications : une ligne en base (canal "app", lue / non lue) + un e-mail.
 * L'envoi d'e-mail ne doit jamais faire échouer l'action métier : en cas
 * d'erreur elle est seulement écrite dans le log.
 *
 * Les lignes créées ici sont seulement persistées : l'appelant fait le flush.
 */
class NotificationService
{
    public const CANAL_APP = 'app';
    public const NON_LUE = 'non_lue';
    public const LUE = 'lue';

    public function __construct(
        private EntityManagerInterface $em,
        private MailerInterface $mailer,
        private UtilisateurRepository $utilisateurRepository,
        private LoggerInterface $logger,
        #[Autowire(env: 'MAILER_FROM')]
        private string $expediteur,
    ) {
    }

    public function notifier(Utilisateur $destinataire, string $type, string $sujet, string $message): Notification
    {
        $notification = new Notification();
        $notification->setType($type);
        $notification->setCanal(self::CANAL_APP);
        $notification->setMessage($message);
        $notification->setStatut(self::NON_LUE);
        $notification->setDateEnvoi(new \DateTimeImmutable());
        $notification->setUtilisateur($destinataire);
        $this->em->persist($notification);

        $this->envoyerEmail($destinataire, $sujet, $message);

        return $notification;
    }

    /**
     * Événements d'un rendez-vous : reserve, confirme, refuse, annule.
     * $par = l'utilisateur qui a agi (sert à savoir qui prévenir en cas d'annulation).
     */
    public function notifierRendezVous(RendezVous $rdv, string $evenement, ?Utilisateur $par = null): void
    {
        $citoyen = $rdv->getDemande()?->getUtilisateur();
        $creneau = $rdv->getCreneau();
        $service = $creneau?->getService();
        if (!$citoyen || !$creneau) {
            return;
        }

        $quand = sprintf('du %s à %s', $creneau->getDate()?->format('d/m/Y'), $creneau->getHeure());
        $lieu = $service?->getNom() ?? 'le service';
        $agents = $service ? $this->utilisateurRepository->findAgentsDuService($service) : [];
        $nomCitoyen = trim($citoyen->getPrenom() . ' ' . $citoyen->getNom());

        switch ($evenement) {
            case 'reserve':
                $this->notifier($citoyen, 'rdv_en_attente', 'Demande de rendez-vous enregistrée',
                    "Votre demande de rendez-vous $quand ($lieu) est enregistrée. Un agent doit la confirmer.");
                foreach ($agents as $agent) {
                    $this->notifier($agent, 'rdv_a_valider', 'Nouveau rendez-vous à valider',
                        "$nomCitoyen demande un rendez-vous $quand. À confirmer ou refuser.");
                }
                break;

            case 'confirme':
                $this->notifier($citoyen, 'rdv_confirme', 'Rendez-vous confirmé',
                    "Votre rendez-vous $quand ($lieu) est confirmé.");
                break;

            case 'refuse':
                $this->notifier($citoyen, 'rdv_refuse', 'Rendez-vous refusé',
                    "Votre rendez-vous $quand ($lieu) a été refusé. Vous pouvez choisir un autre créneau.");
                break;

            case 'annule':
                if ($par !== null && $par->getId() === $citoyen->getId()) {
                    // Le citoyen annule : on prévient les agents du service.
                    foreach ($agents as $agent) {
                        $this->notifier($agent, 'rdv_annule', 'Rendez-vous annulé',
                            "$nomCitoyen a annulé son rendez-vous $quand.");
                    }
                } else {
                    // L'agent (ou l'admin) annule : on prévient le citoyen.
                    $this->notifier($citoyen, 'rdv_annule', 'Rendez-vous annulé',
                        "Votre rendez-vous $quand ($lieu) a été annulé par le service. Vous pouvez choisir un autre créneau.");
                }
                break;
        }
    }

    private function envoyerEmail(Utilisateur $destinataire, string $sujet, string $message): void
    {
        if (!$destinataire->getEmail()) {
            return;
        }
        try {
            $this->mailer->send(
                (new Email())
                    ->from($this->expediteur)
                    ->to($destinataire->getEmail())
                    ->subject('[Orient-Admin] ' . $sujet)
                    ->text($message)
            );
        } catch (\Throwable $e) {
            $this->logger->error('Envoi e-mail impossible : ' . $e->getMessage(), ['destinataire' => $destinataire->getEmail()]);
        }
    }
}
