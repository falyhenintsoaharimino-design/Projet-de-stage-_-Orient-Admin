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
     * Événements d'un rendez-vous : reserve, confirme, refuse, annule, termine, modifie, reporte.
     * $par = l'utilisateur qui a agi (sert à savoir qui prévenir en cas d'annulation).
     * $contexte : pour « reporte », 'ancien' (ancienne date) et 'motif' facultatif.
     *
     * @param array<string, mixed> $contexte
     */
    public function notifierRendezVous(RendezVous $rdv, string $evenement, ?Utilisateur $par = null, array $contexte = []): void
    {
        $citoyen = $rdv->getDemande()?->getUtilisateur();
        $creneau = $rdv->getCreneau();
        $service = $creneau?->getService();
        if (!$citoyen || !$creneau) {
            return;
        }

        $quand = sprintf('du %s à %s', $creneau->getDate()?->format('d/m/Y'), $creneau->getHeure());
        $lieu = $service?->getNom() ?? 'le service';
        $adresse = $service?->getAdresse();
        $ou = $adresse ? "$lieu, $adresse" : $lieu;
        $agents = $service ? $this->utilisateurRepository->findAgentsDuService($service) : [];
        $nomCitoyen = trim($citoyen->getPrenom() . ' ' . $citoyen->getNom());

        switch ($evenement) {
            case 'reserve':
                $this->notifier($citoyen, 'rdv_en_attente', 'Demande de rendez-vous enregistrée',
                    "Votre demande de rendez-vous $quand ($ou) est enregistrée. Un agent doit la confirmer." . $this->piecesAApporter($rdv));
                foreach ($agents as $agent) {
                    $this->notifier($agent, 'rdv_a_valider', 'Nouveau rendez-vous à valider',
                        "$nomCitoyen demande un rendez-vous $quand. À confirmer ou refuser.");
                }
                break;

            case 'confirme':
                $this->notifier($citoyen, 'rdv_confirme', 'Rendez-vous confirmé',
                    "Votre rendez-vous $quand est confirmé. Lieu : $ou." . $this->piecesAApporter($rdv));
                break;

            case 'modifie':
                $this->notifier($citoyen, 'rdv_modifie', 'Rendez-vous modifié',
                    "Votre rendez-vous est maintenant fixé $quand ($ou). Un agent doit le confirmer de nouveau." . $this->piecesAApporter($rdv));
                foreach ($agents as $agent) {
                    $this->notifier($agent, 'rdv_a_valider', 'Rendez-vous modifié à valider',
                        "$nomCitoyen a déplacé son rendez-vous $quand. À confirmer ou refuser.");
                }
                break;

            case 'reporte':
                $motif = !empty($contexte['motif']) ? ' Motif : ' . $contexte['motif'] . '.' : '';
                $this->notifier($citoyen, 'rdv_reporte', 'Rendez-vous reporté',
                    "Votre rendez-vous " . ($contexte['ancien'] ?? '') . " est reporté : il a lieu $quand ($ou).$motif" . $this->piecesAApporter($rdv));
                break;

            case 'refuse':
                $this->notifier($citoyen, 'rdv_refuse', 'Rendez-vous refusé',
                    "Votre rendez-vous $quand ($lieu) a été refusé. Vous pouvez choisir un autre créneau.");
                break;

            case 'termine':
                $this->notifier($citoyen, 'rdv_termine', 'Votre rendez-vous est terminé',
                    "Votre rendez-vous $quand ($lieu) est terminé. Donnez votre avis pour nous aider à améliorer le service.");
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

    /** « Pièces à apporter : ... » d'après la procédure choisie pour le rendez-vous (vide sinon). */
    private function piecesAApporter(RendezVous $rdv): string
    {
        $procedure = $rdv->getProcedure();
        if (!$procedure || $procedure->getDocumentRequis()->isEmpty()) {
            return '';
        }
        $lignes = [];
        foreach ($procedure->getDocumentRequis() as $d) {
            $details = [];
            if ($d->getExemplaires() > 1) {
                $details[] = $d->getExemplaires() . ' exemplaires';
            }
            if ($d->getAncienneteMaxMois() !== null) {
                $details[] = 'de moins de ' . $d->getAncienneteMaxMois() . ' mois';
            }
            if (!$d->isObligatoire()) {
                $details[] = 'facultatif';
            }
            $lignes[] = $d->getLibelle() . ($details ? ' (' . implode(', ', $details) . ')' : '');
        }

        return ' Pièces à apporter : ' . implode(' ; ', $lignes) . '.';
    }

    /** E-mail seul, sans ligne de notification en base (ne lève jamais d'exception). */
    public function envoyerEmail(Utilisateur $destinataire, string $sujet, string $message): void
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
