/* ==========================================================================
   Configuration de la navigation
   Un menu par type d'utilisateur. Pour ajouter une page au menu, il suffit
   d'ajouter une ligne ici : l'en-tête et le menu latéral sont générés
   automatiquement par assets/js/components/layout.js.
   Les liens (href) sont relatifs à la racine du projet.
   L'identifiant (id) doit correspondre à l'attribut data-page du <body>.
   ========================================================================== */
window.OrientAdmin = window.OrientAdmin || {};

window.OrientAdmin.nav = {
  brand: "Orient-Admin",
  brandTagline: "Orientation administrative intelligente",
  brandMark: "assets/img/logo-orient-admin-icon.png",
  apercu: "pages/apercu.html",
  authLinks: {
    connexion: "pages/auth/connexion.html",
    inscription: "pages/auth/inscription.html"
  },
  roles: {
    /* ---------- Visiteur : menu horizontal ---------- */
    visiteur: {
      label: "Visiteur",
      home: "index.html",
      items: [
        { id: "accueil", label: "Accueil", href: "index.html" },
        { id: "services", label: "Services", href: "pages/public/services.html" },
        { id: "assistant", label: "Assistant", href: "pages/public/assistant.html" },
        { id: "comment-ca-marche", label: "Comment ça marche", href: "pages/public/comment-ca-marche.html" },
        { id: "faq", label: "FAQ", href: "pages/public/faq.html" },
        { id: "contact", label: "Contact", href: "pages/public/contact.html" }
      ]
    },

    /* ---------- Citoyen inscrit ---------- */
    citoyen: {
      label: "Espace citoyen",
      home: "pages/citoyen/tableau-de-bord.html",
      groups: [
        { title: "Mes démarches", items: [
          { id: "tableau-de-bord", label: "Tableau de bord", href: "pages/citoyen/tableau-de-bord.html" },
          { id: "assistant", label: "Assistant d'orientation", href: "pages/citoyen/assistant.html" },
          { id: "mes-demandes", label: "Mes demandes", href: "pages/citoyen/mes-demandes.html" },
          { id: "prendre-rendez-vous", label: "Prendre rendez-vous", href: "pages/citoyen/prendre-rendez-vous.html" },
          { id: "mes-rendez-vous", label: "Mes rendez-vous", href: "pages/citoyen/mes-rendez-vous.html" }
        ] },
        { title: "Information", items: [
          { id: "services-public", label: "Catalogue des services", href: "pages/public/services.html" }
        ] },
        { title: "Mon compte", items: [
          { id: "notifications", label: "Notifications", href: "pages/citoyen/notifications.html" },
          { id: "profil", label: "Profil et préférences", href: "pages/citoyen/profil.html" },
          { id: "donner-avis", label: "Donner mon avis", href: "pages/citoyen/donner-avis.html" }
        ] }
      ]
    },

    /* ---------- Agent administratif ---------- */
    agent: {
      label: "Espace agent",
      home: "pages/agent/tableau-de-bord.html",
      groups: [
        { title: "Mon service", items: [
          { id: "tableau-de-bord", label: "Tableau de bord", href: "pages/agent/tableau-de-bord.html" },
          { id: "demandes", label: "Demandes orientées", href: "pages/agent/demandes.html" },
          { id: "rendez-vous", label: "Rendez-vous", href: "pages/agent/rendez-vous.html" },
          { id: "creneaux", label: "Créneaux et disponibilités", href: "pages/agent/creneaux.html" }
        ] },
        { title: "Contenu du service", items: [
          { id: "mon-service", label: "Fiche du service", href: "pages/agent/mon-service.html" },
          { id: "procedure-edition", label: "Procédures et pièces", href: "pages/agent/procedure-edition.html" },
          { id: "communication", label: "Communication", href: "pages/agent/communication.html" }
        ] }
      ]
    },

    /* ---------- Responsable des services publics ---------- */
    responsable: {
      label: "Espace responsable",
      home: "pages/responsable/vue-ensemble.html",
      groups: [
        { title: "Pilotage", items: [
          { id: "vue-ensemble", label: "Vue d'ensemble", href: "pages/responsable/vue-ensemble.html" },
          { id: "affluence", label: "Affluence", href: "pages/responsable/affluence.html" },
          { id: "satisfaction", label: "Satisfaction", href: "pages/responsable/satisfaction.html" },
          { id: "performance-orientation", label: "Performance de l'orientation", href: "pages/responsable/performance-orientation.html" },
          { id: "rapports", label: "Rapports", href: "pages/responsable/rapports.html" }
        ] }
      ]
    },

    /* ---------- Administrateur système ---------- */
    admin: {
      label: "Administration",
      home: "pages/admin/tableau-de-bord.html",
      groups: [
        { title: "Supervision", items: [
          { id: "tableau-de-bord", label: "Tableau de bord", href: "pages/admin/tableau-de-bord.html" },
          { id: "utilisateurs", label: "Utilisateurs et rôles", href: "pages/admin/utilisateurs.html" },
          { id: "securite-journal", label: "Sécurité et journal", href: "pages/admin/securite-journal.html" }
        ] },
        { title: "Contenu et intelligence", items: [
          { id: "services", label: "Catalogue des services", href: "pages/admin/services.html" },
          { id: "base-connaissances", label: "Base de connaissances", href: "pages/admin/base-connaissances.html" },
          { id: "moteur-orientation", label: "Moteur d'orientation", href: "pages/admin/moteur-orientation.html" }
        ] },
        { title: "Configuration", items: [
          { id: "parametres", label: "Paramètres", href: "pages/admin/parametres.html" }
        ] }
      ]
    }
  }
};
