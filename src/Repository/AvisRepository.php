<?php

namespace App\Repository;

use App\Entity\Avis;
use App\Entity\Service;
use App\Entity\Utilisateur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Avis>
 */
class AvisRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Avis::class);
    }

    /**
     * Avis les plus récents d'abord. Tous les filtres sont facultatifs :
     * $service (service du rendez-vous), $citoyen (auteur de la demande), $note (1 à 5).
     *
     * @return Avis[]
     */
    public function rechercher(?Service $service = null, ?Utilisateur $citoyen = null, ?int $note = null): array
    {
        $qb = $this->createQueryBuilder('a')
            ->join('a.rendezVous', 'r')
            ->join('r.creneau', 'c')
            ->join('r.demande', 'd')
            ->orderBy('a.dateCreation', 'DESC')
            ->addOrderBy('a.id', 'DESC');

        if ($service) {
            $qb->andWhere('c.service = :service')->setParameter('service', $service);
        }
        if ($citoyen) {
            $qb->andWhere('d.utilisateur = :citoyen')->setParameter('citoyen', $citoyen);
        }
        if ($note !== null) {
            $qb->andWhere('a.note = :note')->setParameter('note', $note);
        }

        return $qb->getQuery()->getResult();
    }

//    /**
//     * @return Avis[] Returns an array of Avis objects
//     */
//    public function findByExampleField($value): array
//    {
//        return $this->createQueryBuilder('a')
//            ->andWhere('a.exampleField = :val')
//            ->setParameter('val', $value)
//            ->orderBy('a.id', 'ASC')
//            ->setMaxResults(10)
//            ->getQuery()
//            ->getResult()
//        ;
//    }

//    public function findOneBySomeField($value): ?Avis
//    {
//        return $this->createQueryBuilder('a')
//            ->andWhere('a.exampleField = :val')
//            ->setParameter('val', $value)
//            ->getQuery()
//            ->getOneOrNullResult()
//        ;
//    }
}
