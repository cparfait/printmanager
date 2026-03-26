# 🖨️ PrintManager v1.0

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
- **Thème clair / sombre** — Interface moderne avec basculement de thème

## Prérequis

- PHP 7.4+ (avec extensions PDO MySQL et SNMP)
- MySQL / MariaDB
- Un serveur web (Apache, Nginx, Laragon…)

> **Note :** L'extension PHP SNMP est optionnelle. Sans elle, le monitoring réseau des imprimantes ne sera pas disponible mais le reste de l'application fonctionnera normalement.

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
   Renseignez les informations du compte administrateur (mot de passe de 6 caractères minimum) et lancez l'installation.

4. Connectez-vous à l'application via `index.php`.

5. ⚠️ **Supprimez les fichiers `install.php`, `reset.php` et `import.php` en production.**

## Structure du projet

```
cartouches/
├── config.php     # Configuration (DB, helpers, auth, sécurité)
├── index.php      # Application principale (routage, vues, logique métier, SNMP)
├── install.php    # Script d'installation (création DB, tables, index, admin)
├── import.php     # Génération de données de test (dev uniquement)
└── reset.php      # Réinitialisation de la base de données (dev uniquement)
```

## Sécurité

- Sessions avec `session_regenerate_id()` à la connexion
- Mots de passe hachés avec `password_hash()` (bcrypt)
- Échappement des sorties HTML avec `htmlspecialchars()`
- Requêtes préparées PDO contre les injections SQL
- Rôles utilisateurs (admin / user) avec contrôle d'accès

## Licence

Projet interne — Tous droits réservés.
