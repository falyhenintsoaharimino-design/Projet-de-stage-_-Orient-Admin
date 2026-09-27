<?php

namespace App\Command;

use App\Entity\Role;
use App\Entity\Service;
use App\Entity\Utilisateur;
use App\Repository\RoleRepository;
use App\Repository\ServiceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Peuple les 4 rôles et un premier catalogue de services (avec leurs
 * mots-clés) pour pouvoir tester le moteur d'orientation et le reste de
 * l'API sans tout ressaisir à la main via Postman/curl.
 *
 * Idempotente : relancer la commande ne duplique rien (vérifie l'existence
 * par nom avant de créer).
 *
 * Les procédures, étapes et pièces requises de chaque service restent à
 * saisir ensuite (via l'API, en ROLE_ADMIN) : c'est un vrai travail de
 * contenu, cf. cahier des charges §4.4, volontairement hors de cette
 * commande pour ne pas inventer des données métier à ta place.
 */
#[AsCommand(
    name: 'app:seed-demo',
    description: 'Crée les 4 rôles et un catalogue de services de démonstration (avec mots-clés)',
)]
class SeedDemoDataCommand
{
    public function __construct(
        private EntityManagerInterface $em,
        private RoleRepository $roleRepository,
        private ServiceRepository $serviceRepository,
        private UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        foreach (['citoyen', 'agent', 'admin', 'responsable'] as $nomRole) {
            if (!$this->roleRepository->findOneBy(['nom' => $nomRole])) {
                $role = new Role();
                $role->setNom($nomRole);
                $this->em->persist($role);
                $io->writeln("Rôle créé : $nomRole");
            }
        }

        // service => [catégorie, mots-clés]
        // Les mots-clés reprennent volontairement les mêmes intentions que
        // la simulation JS du frontend (assets/js/pages/assistant.js), pour
        // que le comportement reste cohérent une fois l'API branchée dessus.
        $services = [
            'Service de l\'état civil' => [
                'categorie' => 'État civil',
                'motsCles' => 'acte de naissance,extrait de naissance,naissance,acte de deces,deces,mariage,acte de mariage',
            ],
            'Service des cartes d\'identité nationales' => [
                'categorie' => 'Citoyenneté',
                'motsCles' => 'carte d\'identite,cin,identite,duplicata,renouvellement cin',
            ],
            'Fokontany' => [
                'categorie' => 'Citoyenneté',
                'motsCles' => 'certificat de residence,residence,fokontany',
            ],
            'Greffe du tribunal - casier judiciaire' => [
                'categorie' => 'Justice',
                'motsCles' => 'casier judiciaire,bulletin n3,bulletin numero 3,antecedents judiciaires',
            ],
            'Service des passeports' => [
                'categorie' => 'Tourisme et voyage',
                'motsCles' => 'passeport,voyage,visa',
            ],
            'Service des transports' => [
                'categorie' => 'Conduite et transport',
                'motsCles' => 'permis de conduire,conduire,immatriculation,vehicule,carte grise',
            ],
            'Centre fiscal' => [
                'categorie' => 'Fiscalité',
                'motsCles' => 'nif,impot,impots,fiscal,taxe,declaration fiscale',
            ],
            'Guichet unique de création d\'entreprise' => [
                'categorie' => 'Entreprises et industries',
                'motsCles' => 'entreprise,societe,creer une entreprise,registre du commerce,rcs',
            ],
            'Service de l\'urbanisme et du foncier' => [
                'categorie' => 'Habitat et foncier',
                'motsCles' => 'terrain,foncier,cadastre,titre foncier,permis de construire,urbanisme,construire',
            ],
        ];

        $créés = 0;
        foreach ($services as $nom => $infos) {
            if ($this->serviceRepository->findOneBy(['nom' => $nom])) {
                continue;
            }

            $service = new Service();
            $service->setNom($nom);
            $service->setCategorie($infos['categorie']);
            $service->setMotsCles($infos['motsCles']);
            $service->setActif(true);
            $this->em->persist($service);
            $créés++;
        }

        $this->em->flush();

        // Comptes de test pour les 3 rôles qui n'ont pas encore de commande
        // dédiée (app:create-test-user ne crée qu'un citoyen). Nécessite que
        // les rôles ci-dessus soient déjà enregistrés, d'où le flush juste avant.
        $comptesTest = [
            ['email' => 'agent@test.com', 'nom' => 'Agent', 'prenom' => 'Test', 'role' => 'agent', 'service' => true],
            ['email' => 'admin@test.com', 'nom' => 'Admin', 'prenom' => 'Test', 'role' => 'admin', 'service' => false],
            ['email' => 'responsable@test.com', 'nom' => 'Responsable', 'prenom' => 'Test', 'role' => 'responsable', 'service' => false],
        ];

        $premierService = $this->serviceRepository->findOneBy([], ['id' => 'ASC']);

        foreach ($comptesTest as $infos) {
            if ($this->em->getRepository(Utilisateur::class)->findOneBy(['email' => $infos['email']])) {
                continue;
            }

            $role = $this->roleRepository->findOneBy(['nom' => $infos['role']]);
            $utilisateur = new Utilisateur();
            $utilisateur->setNom($infos['nom']);
            $utilisateur->setPrenom($infos['prenom']);
            $utilisateur->setEmail($infos['email']);
            $utilisateur->setRole($role);
            if ($infos['service'] && $premierService) {
                $utilisateur->setService($premierService);
            }
            $utilisateur->setPassword($this->passwordHasher->hashPassword($utilisateur, 'motdepasse123'));
            $this->em->persist($utilisateur);
            $io->writeln("Compte de test créé : {$infos['email']} / motdepasse123");
        }

        $this->em->flush();

        $io->success("$créés service(s) créé(s). Rôles vérifiés/créés.");
        $io->note('Prochaine étape : ajoute les procédures et pièces requises de chaque service via POST /api/procedures (ROLE_ADMIN).');

        return 0;
    }
}
