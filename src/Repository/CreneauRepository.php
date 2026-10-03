<?php

namespace App\Repository;

use App\Entity\Creneau;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Creneau>
 */
class CreneauRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Creneau::class);
    }

    /**
     * Créneaux d'un service (ou de tous), à partir d'aujourd'hui, triés par
     * date puis heure. $date (Y-m-d) restreint à un jour précis.
     *
     * @return Creneau[]
     */
    public function rechercher(?int $serviceId, ?string $date, bool $seulementDisponibles): array
    {
        $qb = $this->createQueryBuilder('c')
            ->andWhere('c.date >= :aujourdhui')
            ->setParameter('aujourdhui', (new \DateTime('today'))->format('Y-m-d'))
            ->orderBy('c.date', 'ASC')
            ->addOrderBy('c.heure', 'ASC');

        if ($serviceId !== null) {
            $qb->andWhere('c.service = :service')->setParameter('service', $serviceId);
        }
        if ($date !== null) {
            $qb->andWhere('c.date = :date')->setParameter('date', $date);
        }
        if ($seulementDisponibles) {
            $qb->andWhere('c.disponible = true');
        }

        return $qb->getQuery()->getResult();
    }

//    /**
//     * @return Creneau[] Returns an array of Creneau objects
//     */
//    public function findByExampleField($value): array
//    {
//        return $this->createQueryBuilder('c')
//            ->andWhere('c.exampleField = :val')
//            ->setParameter('val', $value)
//            ->orderBy('c.id', 'ASC')
//            ->setMaxResults(10)
//            ->getQuery()
//            ->getResult()
//        ;
//    }

//    public function findOneBySomeField($value): ?Creneau
//    {
//        return $this->createQueryBuilder('c')
//            ->andWhere('c.exampleField = :val')
//            ->setParameter('val', $value)
//            ->getQuery()
//            ->getOneOrNullResult()
//        ;
//    }
}
