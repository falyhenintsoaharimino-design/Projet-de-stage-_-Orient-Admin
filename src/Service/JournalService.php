<?php

namespace App\Service;

use App\Entity\Journal;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Journal d'audit des actions sensibles. Un échec d'écriture du journal ne
 * doit jamais faire échouer l'action métier : il est seulement consigné
 * dans les logs Symfony (var/log).
 *
 * Noms d'actions utilisés : connexion, connexion_echec, inscription,
 * demande_creee, demande_reorientee, demande_statut, rdv_reserve,
 * rdv_confirme, rdv_refuse, rdv_annule, rdv_termine, rdv_absent, avis_donne,
 * creneau_cree, creneau_modifie, creneau_supprime, utilisateur_cree,
 * utilisateur_modifie, utilisateur_active, utilisateur_desactive,
 * mdp_oublie_demande, mdp_reinitialise, export_rapport, retour_orientation, exemple_supprime, moteur_reentraine,
 * profil_modifie, donnees_exportees, compte_supprime, service_desactive, service_modifie,
 * procedure_creee, procedure_modifiee, procedure_desactivee, procedure_reactivee, procedure_verifiee,
 * creneaux_generes, rdv_modifie, rdv_reporte, rdv_pieces_manquantes, message_agent_envoye, rappels_envoyes.
 */
class JournalService
{
    public const ACTION_MAX = 50;
    public const DETAILS_MAX = 500;

    public function __construct(
        private EntityManagerInterface $em,
        private RequestStack $requestStack,
        private LoggerInterface $logger,
    ) {
    }

    public function enregistrer(string $action, ?Utilisateur $utilisateur = null, ?string $details = null): void
    {
        try {
            $journal = new Journal();
            $journal->setAction(mb_substr($action, 0, self::ACTION_MAX));
            $journal->setDate(new \DateTimeImmutable());
            // En ligne de commande (pas de requête) : adresse « cli ».
            $journal->setAdresseIp(mb_substr($this->requestStack->getCurrentRequest()?->getClientIp() ?? 'cli', 0, 50));
            $journal->setUtilisateur($utilisateur);
            $journal->setDetails($details === null ? null : mb_substr($details, 0, self::DETAILS_MAX));

            $this->em->persist($journal);
            $this->em->flush();
        } catch (\Throwable $e) {
            $this->logger->error('Journal d\'audit : écriture impossible (' . $action . ') : ' . $e->getMessage());
        }
    }
}
