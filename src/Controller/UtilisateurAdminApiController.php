<?php

namespace App\Controller;

use App\Entity\Role;
use App\Entity\Service;
use App\Entity\Utilisateur;
use App\Repository\RoleRepository;
use App\Repository\ServiceRepository;
use App\Repository\UtilisateurRepository;
use App\Service\JournalService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Gestion des utilisateurs et des rôles, réservée à l'administrateur.
 *
 * GET   /api/admin/utilisateurs        ?role= ?serviceId= ?actif=true|false ?q= ?page= ?limite=
 * GET   /api/admin/utilisateurs/{id}
 * POST  /api/admin/utilisateurs        {nom, prenom, email, password, role, serviceId}
 * PATCH /api/admin/utilisateurs/{id}   {nom, prenom, email, role, serviceId}  (champs facultatifs)
 * PATCH /api/admin/utilisateurs/{id}/actif  {actif: true|false}
 * GET   /api/admin/roles
 *
 * Un agent doit être rattaché à un service. L'admin ne peut ni changer son
 * propre rôle ni se désactiver (pour ne jamais perdre le dernier admin).
 */
#[Route('/api/admin')]
#[IsGranted('ROLE_ADMIN')]
class UtilisateurAdminApiController extends AbstractController
{
    private const LIMITE_DEFAUT = 20;
    private const LIMITE_MAX = 100;

    public function __construct(
        private EntityManagerInterface $em,
        private UtilisateurRepository $utilisateurRepository,
        private RoleRepository $roleRepository,
        private ServiceRepository $serviceRepository,
        private UserPasswordHasherInterface $passwordHasher,
        private JournalService $journal,
    ) {
    }

    #[Route('/roles', name: 'api_admin_roles', methods: ['GET'])]
    public function roles(): JsonResponse
    {
        return $this->json(array_map(
            fn(Role $r) => ['id' => $r->getId(), 'nom' => $r->getNom()],
            $this->roleRepository->findBy([], ['id' => 'ASC'])
        ));
    }

    #[Route('/utilisateurs', name: 'api_admin_utilisateurs_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $page = $this->entierPositif($request->query->get('page'), 1);
        $limite = $this->entierPositif($request->query->get('limite'), self::LIMITE_DEFAUT);
        if ($page === null || $limite === null || $limite > self::LIMITE_MAX) {
            return $this->json(['error' => sprintf('page et limite doivent être des entiers positifs (limite maximum %d)', self::LIMITE_MAX)], 400);
        }

        $serviceId = $this->entierPositif($request->query->get('serviceId'), 0);
        if ($serviceId === null) {
            return $this->json(['error' => 'serviceId invalide'], 400);
        }

        $actifBrut = $request->query->get('actif');
        $actif = null;
        if ($actifBrut !== null && $actifBrut !== '') {
            $actif = match ($actifBrut) {
                'true', '1' => true,
                'false', '0' => false,
                default => 'invalide',
            };
            if ($actif === 'invalide') {
                return $this->json(['error' => 'actif : true ou false attendu'], 400);
            }
        }

        $role = $request->query->get('role');
        $q = $request->query->get('q');
        $resultat = $this->utilisateurRepository->rechercher([
            'role' => is_string($role) && $role !== '' ? $role : null,
            'serviceId' => $serviceId ?: null,
            'actif' => $actif,
            'q' => is_string($q) && trim($q) !== '' ? trim($q) : null,
        ], $page, $limite);

        return $this->json([
            'items' => array_map(fn(Utilisateur $u) => $this->toArray($u), $resultat['items']),
            'page' => $page,
            'limite' => $limite,
            'total' => $resultat['total'],
            'pages' => max(1, (int) ceil($resultat['total'] / $limite)),
        ]);
    }

    #[Route('/utilisateurs/{id}', name: 'api_admin_utilisateurs_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): JsonResponse
    {
        $utilisateur = $this->utilisateurRepository->find($id);
        if (!$utilisateur) {
            return $this->json(['error' => 'Utilisateur introuvable'], 404);
        }

        return $this->json($this->toArray($utilisateur));
    }

    #[Route('/utilisateurs', name: 'api_admin_utilisateurs_create', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] Utilisateur $admin): JsonResponse
    {
        $data = $this->donnees($request);

        $nom = $this->texte($data, 'nom');
        $prenom = $this->texte($data, 'prenom');
        $email = $this->texte($data, 'email');
        $password = is_string($data['password'] ?? null) ? $data['password'] : '';
        $roleNom = $this->texte($data, 'role');

        if ($nom === '' || $email === '' || $password === '' || $roleNom === '') {
            return $this->json(['error' => 'nom, email, password et role sont requis'], 400);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json(['error' => 'E-mail invalide'], 400);
        }
        if (strlen($password) < 8) {
            return $this->json(['error' => 'Le mot de passe doit contenir au moins 8 caractères'], 400);
        }
        $role = $this->roleRepository->findOneBy(['nom' => $roleNom]);
        if (!$role) {
            return $this->json(['error' => 'Rôle inconnu', 'roles' => $this->nomsDesRoles()], 400);
        }
        if ($this->utilisateurRepository->findOneBy(['email' => $email])) {
            return $this->json(['error' => 'Un compte existe déjà avec cet e-mail'], 409);
        }

        $service = null;
        if (($data['serviceId'] ?? null) !== null) {
            $service = $this->serviceParId($data['serviceId']);
            if (!$service) {
                return $this->json(['error' => 'serviceId invalide ou service introuvable'], 400);
            }
        }
        if ($roleNom === 'agent' && !$service) {
            return $this->json(['error' => 'Un agent doit être rattaché à un service (serviceId)'], 400);
        }

        $utilisateur = new Utilisateur();
        $utilisateur->setNom($nom);
        $utilisateur->setPrenom($prenom !== '' ? $prenom : $nom);
        $utilisateur->setEmail($email);
        $utilisateur->setRole($role);
        $utilisateur->setService($service);
        $utilisateur->setPassword($this->passwordHasher->hashPassword($utilisateur, $password));

        $this->em->persist($utilisateur);
        $this->em->flush();
        $this->journal->enregistrer('utilisateur_cree', $admin, sprintf('cible=%d role=%s', $utilisateur->getId(), $roleNom));

        return $this->json($this->toArray($utilisateur), 201);
    }

    #[Route('/utilisateurs/{id}', name: 'api_admin_utilisateurs_update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function update(int $id, Request $request, #[CurrentUser] Utilisateur $admin): JsonResponse
    {
        $cible = $this->utilisateurRepository->find($id);
        if (!$cible) {
            return $this->json(['error' => 'Utilisateur introuvable'], 404);
        }
        $data = $this->donnees($request);
        $modifies = [];

        foreach (['nom', 'prenom'] as $champ) {
            if (array_key_exists($champ, $data)) {
                $valeur = $this->texte($data, $champ);
                if ($valeur === '') {
                    return $this->json(['error' => "$champ ne peut pas être vide"], 400);
                }
                $champ === 'nom' ? $cible->setNom($valeur) : $cible->setPrenom($valeur);
                $modifies[] = $champ;
            }
        }

        if (array_key_exists('email', $data)) {
            $email = $this->texte($data, 'email');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $this->json(['error' => 'E-mail invalide'], 400);
            }
            $existant = $this->utilisateurRepository->findOneBy(['email' => $email]);
            if ($existant && $existant->getId() !== $cible->getId()) {
                return $this->json(['error' => 'Un compte existe déjà avec cet e-mail'], 409);
            }
            $cible->setEmail($email);
            $modifies[] = 'email';
        }

        if (array_key_exists('role', $data)) {
            $role = $this->roleRepository->findOneBy(['nom' => $this->texte($data, 'role')]);
            if (!$role) {
                return $this->json(['error' => 'Rôle inconnu', 'roles' => $this->nomsDesRoles()], 400);
            }
            if ($cible->getId() === $admin->getId() && $role->getId() !== $cible->getRole()?->getId()) {
                return $this->json(['error' => 'Vous ne pouvez pas modifier votre propre rôle'], 409);
            }
            $cible->setRole($role);
            $modifies[] = 'role';
        }

        if (array_key_exists('serviceId', $data)) {
            $service = null;
            if ($data['serviceId'] !== null) {
                $service = $this->serviceParId($data['serviceId']);
                if (!$service) {
                    return $this->json(['error' => 'serviceId invalide ou service introuvable'], 400);
                }
            }
            $cible->setService($service);
            $modifies[] = 'serviceId';
        }

        if ($modifies === []) {
            return $this->json(['error' => 'Aucun champ à modifier (nom, prenom, email, role, serviceId)'], 400);
        }
        if ($cible->getRole()?->getNom() === 'agent' && !$cible->getService()) {
            return $this->json(['error' => 'Un agent doit être rattaché à un service (serviceId)'], 400);
        }

        $this->em->flush();
        $this->journal->enregistrer('utilisateur_modifie', $admin, sprintf('cible=%d champs=%s', $cible->getId(), implode(',', $modifies)));

        return $this->json($this->toArray($cible));
    }

    #[Route('/utilisateurs/{id}/actif', name: 'api_admin_utilisateurs_actif', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function actif(int $id, Request $request, #[CurrentUser] Utilisateur $admin): JsonResponse
    {
        $cible = $this->utilisateurRepository->find($id);
        if (!$cible) {
            return $this->json(['error' => 'Utilisateur introuvable'], 404);
        }
        $data = $this->donnees($request);
        if (!is_bool($data['actif'] ?? null)) {
            return $this->json(['error' => 'actif (true ou false) est requis'], 400);
        }
        if ($cible->getId() === $admin->getId() && $data['actif'] === false) {
            return $this->json(['error' => 'Vous ne pouvez pas désactiver votre propre compte'], 409);
        }

        $cible->setActif($data['actif']);
        $this->em->flush();
        $this->journal->enregistrer($data['actif'] ? 'utilisateur_active' : 'utilisateur_desactive', $admin, sprintf('cible=%d', $cible->getId()));

        return $this->json($this->toArray($cible));
    }

    private function donnees(Request $request): array
    {
        $data = json_decode($request->getContent(), true);

        return is_array($data) ? $data : [];
    }

    private function texte(array $data, string $cle): string
    {
        return is_string($data[$cle] ?? null) ? trim($data[$cle]) : '';
    }

    private function serviceParId(mixed $valeur): ?Service
    {
        if (is_string($valeur) && ctype_digit($valeur)) {
            $valeur = (int) $valeur;
        }

        return is_int($valeur) ? $this->serviceRepository->find($valeur) : null;
    }

    /** @return string[] */
    private function nomsDesRoles(): array
    {
        return array_map(fn(Role $r) => $r->getNom(), $this->roleRepository->findBy([], ['id' => 'ASC']));
    }

    /** Valeur absente -> $defaut ; entier > 0 -> l'entier ; sinon null (invalide). */
    private function entierPositif(mixed $valeur, int $defaut): ?int
    {
        if ($valeur === null || $valeur === '') {
            return $defaut;
        }
        if (!ctype_digit((string) $valeur) || (int) $valeur < 1) {
            return null;
        }

        return (int) $valeur;
    }

    private function toArray(Utilisateur $u): array
    {
        $service = $u->getService();

        return [
            'id' => $u->getId(),
            'nom' => $u->getNom(),
            'prenom' => $u->getPrenom(),
            'email' => $u->getEmail(),
            'role' => $u->getRole()?->getNom(),
            'service' => $service === null ? null : ['id' => $service->getId(), 'nom' => $service->getNom()],
            'actif' => $u->isActif(),
        ];
    }
}
