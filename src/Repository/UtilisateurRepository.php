<?php

namespace App\Repository;

use App\Entity\Service;
use App\Entity\Utilisateur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Utilisateur>
 */
class UtilisateurRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Utilisateur::class);
    }

    /** @return Utilisateur[] les agents (rôle "agent") actifs rattachés à ce service */
    public function findAgentsDuService(Service $service): array
    {
        return $this->createQueryBuilder('u')
            ->join('u.role', 'r')
            ->andWhere('u.service = :service')->setParameter('service', $service)
            ->andWhere('r.nom = :role')->setParameter('role', 'agent')
            ->andWhere('u.actif = true')
            ->getQuery()->getResult();
    }

    /**
     * Liste paginée pour l'administration, triée par nom.
     * Filtres facultatifs : role (nom exact), serviceId, actif (bool), q (nom, prénom ou e-mail).
     *
     * @param array{role?: ?string, serviceId?: ?int, actif?: ?bool, q?: ?string} $filtres
     *
     * @return array{items: Utilisateur[], total: int}
     */
    public function rechercher(array $filtres, int $page, int $limite): array
    {
        $qb = $this->filtrer(
            $this->createQueryBuilder('u')->join('u.role', 'r')->addSelect('r')->leftJoin('u.service', 's')->addSelect('s'),
            $filtres
        )
            ->orderBy('u.nom', 'ASC')
            ->addOrderBy('u.prenom', 'ASC')
            ->addOrderBy('u.id', 'ASC')
            ->setFirstResult(($page - 1) * $limite)
            ->setMaxResults($limite);

        $total = (int) $this->filtrer($this->createQueryBuilder('u')->select('COUNT(u.id)')->join('u.role', 'r'), $filtres)
            ->getQuery()
            ->getSingleScalarResult();

        return ['items' => $qb->getQuery()->getResult(), 'total' => $total];
    }

    private function filtrer(QueryBuilder $qb, array $filtres): QueryBuilder
    {
        if (!empty($filtres['role'])) {
            $qb->andWhere('r.nom = :role')->setParameter('role', $filtres['role']);
        }
        if (!empty($filtres['serviceId'])) {
            $qb->andWhere('IDENTITY(u.service) = :sid')->setParameter('sid', $filtres['serviceId']);
        }
        if (isset($filtres['actif'])) {
            $qb->andWhere('u.actif = :actif')->setParameter('actif', $filtres['actif']);
        }
        if (!empty($filtres['q'])) {
            $motif = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($filtres['q'])) . '%';
            $qb->andWhere("LOWER(u.nom) LIKE :q ESCAPE '!' OR LOWER(u.prenom) LIKE :q ESCAPE '!' OR LOWER(u.email) LIKE :q ESCAPE '!'")
                ->setParameter('q', $motif);
        }

        return $qb;
    }

//    /**
//     * @return Utilisateur[] Returns an array of Utilisateur objects
//     */
//    public function findByExampleField($value): array
//    {
//        return $this->createQueryBuilder('u')
//            ->andWhere('u.exampleField = :val')
//            ->setParameter('val', $value)
//            ->orderBy('u.id', 'ASC')
//            ->setMaxResults(10)
//            ->getQuery()
//            ->getResult()
//        ;
//    }

//    public function findOneBySomeField($value): ?Utilisateur
//    {
//        return $this->createQueryBuilder('u')
//            ->andWhere('u.exampleField = :val')
//            ->setParameter('val', $value)
//            ->getQuery()
//            ->getOneOrNullResult()
//        ;
//    }
}
