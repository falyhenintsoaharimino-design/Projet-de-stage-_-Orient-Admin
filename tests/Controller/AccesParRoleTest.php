<?php

namespace App\Tests\Controller;

use App\Entity\Role;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\AbstractBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Accès par rôle sur les routes sensibles : sans jeton (401), rôle insuffisant (403),
 * rôle suffisant (la requête atteint le contrôleur : 200, 404, 400... mais jamais 401/403).
 *
 * Les routes sont appelées avec un identifiant inexistant (ID_INCONNU) ou un corps vide,
 * pour que les rôles autorisés n'écrivent rien en base (réponse 404 ou 400).
 * Les comptes de test (phpunit-<rôle>@orient-admin.test) sont créés au besoin dans la base
 * de TEST (suffixe _test), jamais dans la base de développement.
 */
class AccesParRoleTest extends WebTestCase
{
    private const ID_INCONNU = 999999999;
    private const ROLES = ['anonyme', 'citoyen', 'agent', 'responsable', 'admin'];

    // Rôles autorisés (l'admin hérite des trois autres ; agent et responsable ne s'héritent pas).
    private const RESPONSABLE = ['responsable', 'admin'];
    private const ADMIN = ['admin'];
    private const AGENT = ['agent', 'admin'];
    private const CITOYEN = ['citoyen', 'admin'];
    private const TOUS = ['citoyen', 'agent', 'responsable', 'admin'];

    /** @var array<string, string> jetons déjà générés, un par compte */
    private static array $jetons = [];

    /**
     * @return list<array{0: string, 1: string, 2: string, 3: list<string>, 4: int}>
     *         méthode, URL, corps JSON, rôles autorisés, statut attendu pour un rôle autorisé
     */
    private static function routes(): array
    {
        $id = self::ID_INCONNU;

        return [
            // Statistiques et export CSV
            ['GET', '/api/statistiques/tableau-de-bord', '', self::RESPONSABLE, 200],
            ['GET', '/api/statistiques/demandes', '', self::RESPONSABLE, 200],
            ['GET', '/api/statistiques/rendez-vous', '', self::RESPONSABLE, 200],
            ['GET', '/api/statistiques/affluence', '', self::RESPONSABLE, 200],
            ['GET', '/api/statistiques/satisfaction', '', self::RESPONSABLE, 200],
            ['GET', '/api/statistiques/orientation', '', self::RESPONSABLE, 200],
            ['GET', '/api/statistiques/export?type=synthese', '', self::RESPONSABLE, 200],
            ['GET', '/api/statistiques/export?type=demandes', '', self::RESPONSABLE, 200],
            // Administration du moteur d'orientation
            ['GET', '/api/admin/moteur', '', self::ADMIN, 200],
            ['GET', '/api/admin/moteur/exemples', '', self::ADMIN, 200],
            ['DELETE', "/api/admin/moteur/exemples/$id", '', self::ADMIN, 404],
            // MOTEUR_URL est vide en test : pas de service Python, donc 502 pour l'admin.
            ['POST', '/api/admin/moteur/reentrainer', '', self::ADMIN, 502],
            // Journal d'audit
            ['GET', '/api/journal', '', self::ADMIN, 200],
            ['GET', '/api/journal/actions', '', self::ADMIN, 200],
            // Gestion des utilisateurs (admin)
            ['GET', '/api/admin/roles', '', self::ADMIN, 200],
            ['GET', '/api/admin/utilisateurs', '', self::ADMIN, 200],
            ['GET', "/api/admin/utilisateurs/$id", '', self::ADMIN, 404],
            ['POST', '/api/admin/utilisateurs', '{}', self::ADMIN, 400],
            ['PATCH', "/api/admin/utilisateurs/$id", '{}', self::ADMIN, 404],
            ['PATCH', "/api/admin/utilisateurs/$id/actif", '{"actif":true}', self::ADMIN, 404],
            // Réorientation (agent) et retour du citoyen
            ['PATCH', "/api/demandes/$id/reorienter", '{"serviceId":1}', self::AGENT, 404],
            ['POST', "/api/demandes/$id/retour", '{"pertinent":true}', self::CITOYEN, 404],
            // Mon compte : profil (corps vide -> 400), export des données, suppression (citoyens seulement ;
            // sans mot de passe -> 400, donc rien n'est supprimé)
            ['PATCH', '/api/me', '{}', self::TOUS, 400],
            ['GET', '/api/me/donnees', '', self::TOUS, 200],
            ['DELETE', '/api/me', '{}', ['citoyen'], 400],
            // Catalogue (étape 7) : l'agent (son service) et l'admin gèrent services et procédures ;
            // identifiants inconnus ou corps invalides : rien n'est modifié.
            ['PUT', "/api/services/$id", '{}', self::AGENT, 404],
            ['POST', '/api/procedures', '{}', self::AGENT, 400],
            ['PUT', "/api/procedures/$id", '{}', self::AGENT, 404],
            ['DELETE', "/api/procedures/$id", '', self::AGENT, 404],
            ['GET', "/api/procedures/$id/versions", '', self::AGENT, 404],
            // Rendez-vous (étape 8) : identifiant inconnu = 404 pour tout compte connecté ; report et génération en lot : agent / admin.
            ['PATCH', "/api/rendez-vous/$id", '{}', self::TOUS, 404],
            ['GET', "/api/rendez-vous/$id/recapitulatif", '', self::TOUS, 404],
            ['PATCH', "/api/rendez-vous/$id/reporter", '{}', self::AGENT, 404],
            ['POST', '/api/creneaux/generer', '{}', self::AGENT, 400],
            // Notifications (étape 9) : messages de l'agent, pièces manquantes, boîte d'envoi des SMS simulés.
            ['GET', "/api/demandes/$id/messages", '', self::TOUS, 404],
            ['POST', "/api/demandes/$id/messages", '{}', self::AGENT, 404],
            ['POST', "/api/rendez-vous/$id/pieces-manquantes", '{}', self::AGENT, 404],
            ['GET', '/api/sms-simules', '', self::ADMIN, 200],
            // Paramètres et sauvegardes (étape 10a) ; POST /api/admin/sauvegardes est couvert par SauvegardeTest (il lance pg_dump)
            ['GET', '/api/admin/parametres', '', self::ADMIN, 200],
            ['PUT', '/api/admin/parametres', '{}', self::ADMIN, 400],
            ['GET', '/api/jours-feries', '', self::AGENT, 200],
            ['GET', '/api/admin/sauvegardes', '', self::ADMIN, 200],
            ['GET', '/api/admin/sauvegardes/orient-admin-20200101-000000.dump', '', self::ADMIN, 404],
            ['POST', "/api/procedures/$id/verification", '', self::AGENT, 404],
            // Suppression logique d'un service (identifiant inconnu : rien n'est désactivé)
            ['DELETE', "/api/services/$id", '', self::ADMIN, 404],
        ];
    }

    /** @return \Generator<string, array{0: string, 1: string, 2: string, 3: string, 4: int}> */
    public static function accesParRole(): \Generator
    {
        foreach (self::routes() as [$methode, $url, $corps, $autorises, $statutAutorise]) {
            foreach (self::ROLES as $role) {
                $attendu = match (true) {
                    $role === 'anonyme' => 401,
                    in_array($role, $autorises, true) => $statutAutorise,
                    default => 403,
                };
                yield "$methode $url [$role]" => [$methode, $url, $corps, $role, $attendu];
            }
        }
    }

    #[DataProvider('accesParRole')]
    public function testAccesParRole(string $methode, string $url, string $corps, string $role, int $attendu): void
    {
        $client = static::createClient();
        $jeton = $role === 'anonyme' ? null : $this->jeton($role, $role);

        $this->appeler($client, $methode, $url, $jeton, $corps);

        self::assertSame(
            $attendu,
            $client->getResponse()->getStatusCode(),
            sprintf('%s %s avec le rôle « %s »', $methode, $url, $role)
        );
    }

    public function testJetonInvalideRefuse(): void
    {
        $client = static::createClient();

        $this->appeler($client, 'GET', '/api/statistiques/tableau-de-bord', 'jeton.invalide.xyz');

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testCompteDesactiveRefuseMemeAvecUnJetonValide(): void
    {
        $client = static::createClient();
        $jeton = $this->jeton('desactive', 'admin', false);

        $this->appeler($client, 'GET', '/api/journal', $jeton);

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    #[DataProvider('rolesExport')]
    public function testExportCsvEstUnFichierCsv(string $role): void
    {
        $client = static::createClient();
        $jeton = $this->jeton($role, $role);

        $this->appeler($client, 'GET', '/api/statistiques/export?type=synthese', $jeton);

        $reponse = $client->getResponse();
        self::assertSame(200, $reponse->getStatusCode());
        self::assertStringStartsWith('text/csv', (string) $reponse->headers->get('Content-Type'));
        self::assertStringContainsString('attachment', (string) $reponse->headers->get('Content-Disposition'));
    }

    /** @return array<string, array{0: string}> */
    public static function rolesExport(): array
    {
        return ['responsable' => ['responsable'], 'admin' => ['admin']];
    }

    private function appeler(AbstractBrowser $client, string $methode, string $url, ?string $jeton, string $corps = ''): void
    {
        $serveur = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        if ($jeton !== null) {
            $serveur['HTTP_AUTHORIZATION'] = 'Bearer ' . $jeton;
        }
        $client->request($methode, $url, [], [], $serveur, $corps);
    }

    /** Jeton JWT d'un compte de test (créé dans la base de test s'il n'existe pas encore). */
    private function jeton(string $cle, string $roleNom, bool $actif = true): string
    {
        if (!isset(self::$jetons[$cle])) {
            $utilisateur = $this->compte($cle, $roleNom, $actif);
            self::$jetons[$cle] = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($utilisateur);
        }

        return self::$jetons[$cle];
    }

    private function compte(string $cle, string $roleNom, bool $actif): Utilisateur
    {
        $conteneur = static::getContainer();
        $em = $conteneur->get(EntityManagerInterface::class);
        $email = sprintf('phpunit-%s@orient-admin.test', $cle);

        $utilisateur = $em->getRepository(Utilisateur::class)->findOneBy(['email' => $email]);
        if ($utilisateur) {
            return $utilisateur;
        }

        $role = $em->getRepository(Role::class)->findOneBy(['nom' => $roleNom]);
        if (!$role) {
            $role = (new Role())->setNom($roleNom);
            $em->persist($role);
        }

        $utilisateur = new Utilisateur();
        $utilisateur->setNom('PHPUnit');
        $utilisateur->setPrenom(ucfirst($cle));
        $utilisateur->setEmail($email);
        $utilisateur->setRole($role);
        $utilisateur->setActif($actif);
        $utilisateur->setPassword($conteneur->get(UserPasswordHasherInterface::class)->hashPassword($utilisateur, bin2hex(random_bytes(8))));
        $em->persist($utilisateur);
        $em->flush();

        return $utilisateur;
    }
}
