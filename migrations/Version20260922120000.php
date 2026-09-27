<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Corrige les incohérences trouvées entre les entités Doctrine et le schéma
 * généré par les migrations précédentes :
 *
 *  - etape_procedure.descripton -> description (faute de frappe dans
 *    l'entité EtapeProcedure, reproduite telle quelle dans le schéma).
 *  - rendez_vous.creaneau_id -> creneau_id (faute de frappe dans l'entité
 *    RendezVous).
 *  - Élargissement des colonnes VARCHAR(50) qui étaient trop courtes pour un
 *    usage réel (adresse, horaires, contact, libellés de documents, nom/
 *    prénom/email utilisateur...) : au-delà de 50 caractères, PostgreSQL
 *    rejette l'INSERT/UPDATE avec "value too long for type character
 *    varying(50)", ce qui cassait silencieusement les formulaires du
 *    frontend dès qu'un champ dépassait cette longueur.
 *  - service.mission passe en TEXT (une mission de service tient rarement
 *    en 50 caractères).
 *  - Ajout d'une contrainte UNIQUE sur utilisateur.email : Symfony s'appuie
 *    sur l'e-mail comme identifiant de connexion (UserProvider "entity" /
 *    property: email) ; sans unicité en base, deux comptes avec le même
 *    e-mail provoquent une NonUniqueResultException au login.
 */
final class Version20260922120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Corrige les colonnes descripton/creaneau_id, élargit les VARCHAR(50) à risque, ajoute l\'unicité sur utilisateur.email';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE etape_procedure RENAME COLUMN descripton TO description');
        $this->addSql('ALTER TABLE rendez_vous RENAME COLUMN creaneau_id TO creneau_id');

        $this->addSql('ALTER TABLE service ALTER nom TYPE VARCHAR(150)');
        $this->addSql('ALTER TABLE service ALTER mission TYPE TEXT');
        $this->addSql('ALTER TABLE service ALTER categorie TYPE VARCHAR(100)');
        $this->addSql('ALTER TABLE service ALTER adresse TYPE VARCHAR(255)');
        $this->addSql('ALTER TABLE service ALTER horaires TYPE VARCHAR(150)');
        $this->addSql('ALTER TABLE service ALTER contact TYPE VARCHAR(150)');

        $this->addSql('ALTER TABLE document_requis ALTER libelle TYPE VARCHAR(150)');

        $this->addSql('ALTER TABLE utilisateur ALTER nom TYPE VARCHAR(100)');
        $this->addSql('ALTER TABLE utilisateur ALTER prenom TYPE VARCHAR(100)');
        $this->addSql('ALTER TABLE utilisateur ALTER email TYPE VARCHAR(180)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1D1C63B3E7927C74 ON utilisateur (email)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_1D1C63B3E7927C74');
        $this->addSql('ALTER TABLE utilisateur ALTER email TYPE VARCHAR(50)');
        $this->addSql('ALTER TABLE utilisateur ALTER prenom TYPE VARCHAR(50)');
        $this->addSql('ALTER TABLE utilisateur ALTER nom TYPE VARCHAR(50)');

        $this->addSql('ALTER TABLE document_requis ALTER libelle TYPE VARCHAR(50)');

        $this->addSql('ALTER TABLE service ALTER contact TYPE VARCHAR(50)');
        $this->addSql('ALTER TABLE service ALTER horaires TYPE VARCHAR(50)');
        $this->addSql('ALTER TABLE service ALTER adresse TYPE VARCHAR(50)');
        $this->addSql('ALTER TABLE service ALTER categorie TYPE VARCHAR(50)');
        $this->addSql('ALTER TABLE service ALTER mission TYPE VARCHAR(50)');
        $this->addSql('ALTER TABLE service ALTER nom TYPE VARCHAR(50)');

        $this->addSql('ALTER TABLE rendez_vous RENAME COLUMN creneau_id TO creaneau_id');
        $this->addSql('ALTER TABLE etape_procedure RENAME COLUMN description TO descripton');
    }
}
