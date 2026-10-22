<?php

namespace App\Tests\Controller;

use App\Entity\Role;
use App\Entity\Utilisateur;
use App\Service\CompteService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\BrowserKit\AbstractBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Outils communs aux tests d'API qui créent leurs propres comptes (adresse ctest-...@orient-admin.test),
 * supprimés à la fin de chaque test pour ne rien laisser dans la base de TEST.
 */
trait OutilsDeTestTrait
{
    /** @var list<int> */
    private array $comptesCrees = [];

    /** À appeler depuis tearDown() : supprime les comptes créés par le test (et leurs données). */
    private function nettoyerComptes(): void
    {
        foreach ($this->comptesCrees as $id) {
            $em = $this->em();
            $em->clear();
            $utilisateur = $em->find(Utilisateur::class, $id);
            if ($utilisateur) {
                static::getContainer()->get(CompteService::class)->supprimer($utilisateur);
            }
        }
        $this->comptesCrees = [];
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function creerUtilisateur(string $roleNom, string $motDePasse = 'motdepasse-test-1'): Utilisateur
    {
        $conteneur = static::getContainer();
        $em = $this->em();
        $role = $em->getRepository(Role::class)->findOneBy(['nom' => $roleNom]);
        if (!$role) {
            $role = new Role();
            $role->setNom($roleNom);
            $em->persist($role);
        }

        $utilisateur = new Utilisateur();
        $utilisateur->setNom('Test');
        $utilisateur->setPrenom(ucfirst($roleNom));
        $utilisateur->setEmail(sprintf('ctest-%s@orient-admin.test', bin2hex(random_bytes(6))));
        $utilisateur->setRole($role);
        $utilisateur->setPassword($conteneur->get(UserPasswordHasherInterface::class)->hashPassword($utilisateur, $motDePasse));
        $em->persist($utilisateur);
        $em->flush();
        $this->comptesCrees[] = $utilisateur->getId();

        return $utilisateur;
    }

    private function jetonDe(Utilisateur $utilisateur): string
    {
        return static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($utilisateur);
    }

    private function appeler(AbstractBrowser $client, string $methode, string $url, ?string $jeton = null, string $corps = ''): void
    {
        $serveur = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        if ($jeton !== null) {
            $serveur['HTTP_AUTHORIZATION'] = 'Bearer ' . $jeton;
        }
        $client->request($methode, $url, [], [], $serveur, $corps);
    }

    /** @return mixed corps JSON de la dernière réponse */
    private function json(AbstractBrowser $client): mixed
    {
        return json_decode((string) $client->getResponse()->getContent(), true);
    }
}
