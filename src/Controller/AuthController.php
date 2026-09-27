<?php

namespace App\Controller;

use App\Entity\Role;
use App\Entity\Utilisateur;
use App\Repository\RoleRepository;
use App\Repository\UtilisateurRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * security.yaml autorisait déjà /api/register en accès public, mais aucun
 * contrôleur ne l'implémentait : la route n'existait pas (404 systématique).
 * C'est ce qui empêchait la page pages/auth/inscription.html de fonctionner.
 */
#[Route('/api')]
class AuthController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private UtilisateurRepository $utilisateurRepository,
        private RoleRepository $roleRepository,
        private UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    #[Route('/register', name: 'api_register', methods: ['POST'])]
    public function register(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];

        $nom = trim($data['nom'] ?? '');
        $prenom = trim($data['prenom'] ?? '');
        $email = trim($data['email'] ?? '');
        $password = (string) ($data['password'] ?? '');

        if ($nom === '' || $email === '' || $password === '') {
            return $this->json(['error' => 'nom, email et password sont requis'], 400);
        }

        if (strlen($password) < 8) {
            return $this->json(['error' => 'Le mot de passe doit contenir au moins 8 caractères'], 400);
        }

        if ($this->utilisateurRepository->findOneBy(['email' => $email])) {
            return $this->json(['error' => 'Un compte existe déjà avec cet e-mail'], 409);
        }

        // Rôle "citoyen" par défaut pour toute inscription publique.
        $role = $this->roleRepository->findOneBy(['nom' => 'citoyen']);
        if (!$role) {
            $role = new Role();
            $role->setNom('citoyen');
            $this->em->persist($role);
        }

        $utilisateur = new Utilisateur();
        $utilisateur->setNom($nom);
        $utilisateur->setPrenom($prenom !== '' ? $prenom : $nom);
        $utilisateur->setEmail($email);
        $utilisateur->setRole($role);
        $utilisateur->setPassword($this->passwordHasher->hashPassword($utilisateur, $password));

        $this->em->persist($utilisateur);
        $this->em->flush();

        return $this->json([
            'id' => $utilisateur->getId(),
            'nom' => $utilisateur->getNom(),
            'prenom' => $utilisateur->getPrenom(),
            'email' => $utilisateur->getEmail(),
            'role' => $role->getNom(),
        ], 201);
    }

    #[Route('/me', name: 'api_me', methods: ['GET'])]
    public function me(#[CurrentUser] ?Utilisateur $utilisateur): JsonResponse
    {
        if (!$utilisateur) {
            return $this->json(['error' => 'Non authentifié'], 401);
        }

        return $this->json([
            'id' => $utilisateur->getId(),
            'nom' => $utilisateur->getNom(),
            'prenom' => $utilisateur->getPrenom(),
            'email' => $utilisateur->getEmail(),
            'roles' => $utilisateur->getRoles(),
            'service' => $utilisateur->getService()?->getId(),
        ]);
    }
}
