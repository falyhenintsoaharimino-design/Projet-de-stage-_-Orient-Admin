<?php

namespace App\Repository;

use App\Entity\Notification;
use App\Entity\Utilisateur;
use App\Service\NotificationService;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Notification>
 */
class NotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Notification::class);
    }

    /** @return Notification[] les plus récentes d'abord */
    public function pourUtilisateur(Utilisateur $utilisateur, bool $nonLuesSeulement = false, int $limite = 50): array
    {
        $qb = $this->createQueryBuilder('n')
            ->andWhere('n.utilisateur = :u')->setParameter('u', $utilisateur)
            ->andWhere('n.canal = :canal')->setParameter('canal', NotificationService::CANAL_APP)
            ->orderBy('n.dateEnvoi', 'DESC')
            ->addOrderBy('n.id', 'DESC')
            ->setMaxResults($limite);
        if ($nonLuesSeulement) {
            $qb->andWhere('n.statut = :statut')->setParameter('statut', NotificationService::NON_LUE);
        }

        return $qb->getQuery()->getResult();
    }

    public function compterNonLues(Utilisateur $utilisateur): int
    {
        return (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->andWhere('n.utilisateur = :u')->setParameter('u', $utilisateur)
            ->andWhere('n.canal = :canal')->setParameter('canal', NotificationService::CANAL_APP)
            ->andWhere('n.statut = :statut')->setParameter('statut', NotificationService::NON_LUE)
            ->getQuery()->getSingleScalarResult();
    }

    /** @return int nombre de notifications passées à "lue" */
    public function toutMarquerLu(Utilisateur $utilisateur): int
    {
        return (int) $this->createQueryBuilder('n')
            ->update()
            ->set('n.statut', ':lue')->setParameter('lue', NotificationService::LUE)
            ->where('n.utilisateur = :u')->setParameter('u', $utilisateur)
            ->andWhere('n.canal = :canal')->setParameter('canal', NotificationService::CANAL_APP)
            ->andWhere('n.statut = :nonLue')->setParameter('nonLue', NotificationService::NON_LUE)
            ->getQuery()->execute();
    }

//    /**
//     * @return Notification[] Returns an array of Notification objects
//     */
//    public function findByExampleField($value): array
//    {
//        return $this->createQueryBuilder('n')
//            ->andWhere('n.exampleField = :val')
//            ->setParameter('val', $value)
//            ->orderBy('n.id', 'ASC')
//            ->setMaxResults(10)
//            ->getQuery()
//            ->getResult()
//        ;
//    }

//    public function findOneBySomeField($value): ?Notification
//    {
//        return $this->createQueryBuilder('n')
//            ->andWhere('n.exampleField = :val')
//            ->setParameter('val', $value)
//            ->getQuery()
//            ->getOneOrNullResult()
//        ;
//    }
}
