<?php

namespace App\Command;

use App\Entity\Role;
use App\Entity\Utilisateur;
use App\Repository\RoleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:create-test-user',
    description: 'Crée un utilisateur de test pour vérifier l\'authentification JWT',
)]
class CreateTestUserCommand
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $passwordHasher,
        private RoleRepository $roleRepository,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        // Crée le rôle "citoyen" s'il n'existe pas déjà
        $role = $this->roleRepository->findOneBy(['nom' => 'citoyen']);
        if (!$role) {
            $role = new Role();
            $role->setNom('citoyen');
            $this->em->persist($role);
        }

        $email = 'test@test.com';

        // Évite de créer deux fois le même utilisateur si tu relances la commande
        $existing = $this->em->getRepository(Utilisateur::class)->findOneBy(['email' => $email]);
        if ($existing) {
            $io->warning("L'utilisateur $email existe déjà.");
            return 0;
        }

        $utilisateur = new Utilisateur();
        $utilisateur->setNom('Test');
        $utilisateur->setPrenom('Utilisateur');
        $utilisateur->setEmail($email);
        $utilisateur->setRole($role);

        $hashedPassword = $this->passwordHasher->hashPassword($utilisateur, 'motdepasse123');
        $utilisateur->setPassword($hashedPassword);

        $this->em->persist($utilisateur);
        $this->em->flush();

        $io->success("Utilisateur créé : $email / motdepasse123");

        return 0;
    }
}