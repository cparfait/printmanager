# 🖨️ PrintManager v1.1

**Gestion des Cartouches & du Parc d'Imprimantes — DSI**

PrintManager est une application web PHP permettant de gérer l'ensemble du parc d'impression d'une organisation : imprimantes, cartouches, stock, commandes fournisseurs et demandes des services. Elle intègre un monitoring SNMP en temps réel pour surveiller les niveaux d'encre et l'état des imprimantes sur le réseau.

## Fonctionnalités

- **Tableau de bord** — Vue d'ensemble avec KPI (imprimantes actives, modèles de cartouches, commandes en cours, alertes de stock), raccourcis rapides, dernières sorties et demandes en attente
- **Monitoring SNMP** — Interrogation en temps réel des imprimantes réseau via SNMP pour connaître les niveaux de toner/encre, le nombre de pages imprimées et l'état de l'appareil
- **Gestion des cartouches** — Catalogue des modèles de cartouches (laser, jet d'encre, toner, ruban) avec marque, référence, couleur, rendement pages, prix unitaire et seuil d'alerte
- **Parc imprimantes** — Inventaire complet des imprimantes avec numéro de série, adresse IP, localisation, service affecté, dates d'achat et de garantie, association aux modèles de cartouches compatibles
- **Gestion du stock** — Suivi des quantités disponibles et réservées par modèle de cartouche, avec alertes automatiques en cas de stock bas
- **Entrées de stock** — Enregistrement des réceptions avec fournisseur, quantité, prix unitaire et référence de facture
- **Sorties de stock** — Enregistrement des distributions de cartouches par service, imprimante et personne
- **Commandes fournisseurs** — Création et suivi des bons de commande avec lignes détaillées, statut (en attente, partielle, reçue, annulée) et réception progressive
- **Demandes / Réservations** — Les services peuvent demander des cartouches, avec suivi du statut (en attente, partielle, honorée, annulée)
- **Référentiels** — Gestion des services/directions, fournisseurs et modèles d'imprimantes
- **Statistiques** — Tableaux de bord analytiques avec suivi de la consommation par période
- **Journal d'activité** — Traçabilité complète de toutes les actions utilisateurs
- **Gestion des utilisateurs** — Authentification sécurisée avec rôles (admin / utilisateur)
- **Annulation des mouvements** — Les administrateurs peuvent annuler une entrée ou une sortie de stock erronée (le stock et les demandes liées sont recalculés)
- **Thème clair / sombre** — Interface moderne avec basculement de thème

## Rôles

- **Utilisateur** : consultation, entrées/sorties de stock, demandes, commandes, imprimantes et cartouches (ajout/modification)
- **Administrateur** : tout ce qui précède, plus les référentiels (services, fournisseurs, modèles d'imprimantes), les suppressions, l'annulation de mouvements de stock et la gestion des comptes

Ces règles sont appliquées **côté serveur** (pas seulement masquées dans l'interface).

## Prérequis

- PHP 8.0+ (avec extensions PDO MySQL et mbstring ; SNMP optionnelle)
- MySQL / MariaDB
- Un serveur web (Apache, Nginx, Laragon…)

> **Note :** L'extension PHP SNMP est optionnelle. Sans elle, le monitoring réseau des imprimantes ne sera pas disponible (l'application l'indique clairement) mais le reste fonctionnera normalement.
>
> **Fonctionnement hors ligne / RGPD :** aucune police Google n'est chargée. Chart.js et jsQR sont chargés depuis `assets/` s'ils y sont présents (voir `assets/README.md`), avec repli CDN sinon.

## Installation

1. Clonez le dépôt dans votre répertoire web :
   ```bash
   git clone https://github.com/cparfait/printmanager.git
   ```

2. Modifiez le fichier `config.php` avec vos identifiants de base de données :
   ```php
   define('DB_HOST', 'localhost');
   define('DB_NAME', 'cartouches');
   define('DB_USER', 'votre_user');
   define('DB_PASS', 'votre_mot_de_passe');
   ```

3. Accédez à `install.php` depuis votre navigateur :
   ```
   http://localhost/cartouches/install.php
   ```
   Renseignez les informations du compte administrateur (mot de passe de 12 caractères minimum, configurable via `MIN_PASSWORD_LEN` dans `config.php`) et lancez l'installation.

4. Connectez-vous à l'application via `index.php`.

5. ⚠️ **Supprimez les fichiers `install.php`, `reset.php` et `import.php` en production.**

## Structure du projet

```
cartouches/
├── config.php       # Configuration (DB, helpers, auth, session, CSRF)
├── index.php        # Point d'entrée : bootstrap, authentification, routage
├── install.php      # Script d'installation (création DB, schéma complet, index, admin)
├── import.php       # Génération de données de test (dev uniquement)
├── reset.php        # Réinitialisation de la base de données (dev uniquement)
├── inc/
│   ├── snmp.php     # Monitoring SNMP (OIDs, interrogation des imprimantes)
│   ├── ajax.php     # Points d'entrée AJAX (SNMP, recherches, lignes de commande)
│   ├── actions.php  # Traitement des formulaires (doPost) et contrôle des rôles
│   ├── helpers.php  # Helpers d'affichage (pagination, journal, badges)
│   ├── layout.php   # Gabarit HTML (head, sidebar, topbar, JS global)
│   └── pages/       # Une vue par écran (dashboard, stock, commandes…)
└── assets/
    ├── app.css      # Feuille de style principale (thèmes sombre/clair)
    ├── chart.umd.min.js  # Chart.js 4.4 (local)
    └── jsQR.min.js  # jsQR 1.4 (local)
```

## Sécurité

- Jeton **CSRF** vérifié sur chaque formulaire (y compris connexion, reset et import)
- Contrôle d'accès par rôles appliqué **côté serveur** (référentiels, suppressions et comptes réservés aux admins)
- Mots de passe hachés avec `password_hash()` (bcrypt), longueur minimale imposée côté serveur (12 caractères)
- Limitation des tentatives de connexion : 5 échecs max par IP sur 15 minutes (journalisés)
- Sessions durcies (`HttpOnly`, `SameSite=Lax`, `Secure` en HTTPS), régénérées à la connexion, rôle/statut rechargés depuis la base à chaque requête
- Requêtes préparées PDO contre les injections SQL, échappement des sorties avec `htmlspecialchars()`
- Actions métier exécutées dans une **transaction** (tout ou rien)
- Export CSV protégé contre l'injection de formule Excel
- Erreurs consignées dans le log serveur, jamais affichées en production

## Licence

Projet interne — Tous droits réservés.
