<?php

namespace App\Repository;

use App\Entity\Journal;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Journal>
 */
class JournalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Journal::class);
    }

    /**
     * Consultation paginée, du plus récent au plus ancien.
     * Filtres facultatifs : action (exacte), utilisateurId, du / au (jours inclus).
     *
     * @param array{action?: ?string, utilisateurId?: ?int, du?: ?\DateTimeImmutable, au?: ?\DateTimeImmutable} $filtres
     *
     * @return array{items: Journal[], total: int}
     */
    public function rechercher(array $filtres, int $page, int $limite): array
    {
        $qb = $this->filtrer($this->createQueryBuilder('j')->leftJoin('j.utilisateur', 'u')->addSelect('u'), $filtres)
            ->orderBy('j.date', 'DESC')
            ->addOrderBy('j.id', 'DESC')
            ->setFirstResult(($page - 1) * $limite)
            ->setMaxResults($limite);

        $total = (int) $this->filtrer($this->createQueryBuilder('j')->select('COUNT(j.id)'), $filtres)
            ->getQuery()
            ->getSingleScalarResult();

        return ['items' => $qb->getQuery()->getResult(), 'total' => $total];
    }

    /** @return string[] actions distinctes déjà enregistrées (pour un menu de filtre) */
    public function actionsConnues(): array
    {
        $lignes = $this->createQueryBuilder('j')
            ->select('DISTINCT j.action AS action')
            ->orderBy('j.action', 'ASC')
            ->getQuery()
            ->getScalarResult();

        return array_column($lignes, 'action');
    }

    private function filtrer(QueryBuilder $qb, array $filtres): QueryBuilder
    {
        if (!empty($filtres['action'])) {
            $qb->andWhere('j.action = :action')->setParameter('action', $filtres['action']);
        }
        if (!empty($filtres['utilisateurId'])) {
            $qb->andWhere('IDENTITY(j.utilisateur) = :uid')->setParameter('uid', $filtres['utilisateurId']);
        }
        if (!empty($filtres['du'])) {
            $qb->andWhere('j.date >= :du')->setParameter('du', $filtres['du']);
        }
        if (!empty($filtres['au'])) {
            // « au » est inclus : on s'arrête à minuit du lendemain (exclu).
            $qb->andWhere('j.date < :au')->setParameter('au', $filtres['au']->modify('+1 day'));
        }

        return $qb;
    }

//    /**
//     * @return Journal[] Returns an array of Journal objects
//     */
//    public function findByExampleField($value): array
//    {
//        return $this->createQueryBuilder('j')
//            ->andWhere('j.exampleField = :val')
//            ->setParameter('val', $value)
//            ->orderBy('j.id', 'ASC')
//            ->setMaxResults(10)
//            ->getQuery()
//            ->getResult()
//        ;
//    }

//    public function findOneBySomeField($value): ?Journal
//    {
//        return $this->createQueryBuilder('j')
//            ->andWhere('j.exampleField = :val')
//            ->setParameter('val', $value)
//            ->getQuery()
//            ->getOneOrNullResult()
//        ;
//    }
}
