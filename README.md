# Wallet Tracker — Backend

API du **Portfolio Tracker** : suivi d'un portefeuille multi-actifs (crypto, actions, ETF, or) réparti sur plusieurs plateformes, avec journal d'achats/ventes, plus-values par devise, historique de valeur, objectifs de répartition, alertes de prix, résumés périodiques par e-mail/Discord et deux fonctionnalités d'IA (Gemini).

Le frontend (SPA React/Vite) vit dans un dépôt séparé (`wallet_tracker_frontend`). Ce dépôt ne contient que l'API.

| | |
|---|---|
| Framework | Laravel 13 (PHP ≥ 8.3) |
| Auth | Laravel Sanctum, mode SPA (cookie de session + CSRF) |
| Base de données | MySQL/MariaDB en prod, SQLite ou MySQL (Docker) en local |
| File d'attente | driver `database` |
| Cache | driver `database` |
| E-mails | SMTP (Brevo en prod) |
| Hébergement | VPS : NGINX + PHP-FPM, front et API sur le même domaine (`/backend/*`) |
| IA | Google Gemini (API REST) |
| Langue | Français uniquement (messages d'erreur, e-mails) |

---

## Sommaire

- [Démarrage rapide](#démarrage-rapide)
- [Structure du dépôt](#structure-du-dépôt)
- [Variables d'environnement](#variables-denvironnement)
- [Commandes utiles](#commandes-utiles)
- [Tâches planifiées](#tâches-planifiées)
- [Tests](#tests)
- [Documentation détaillée](#documentation-détaillée)

---

## Démarrage rapide

Prérequis : PHP 8.3+, Composer, Node (uniquement pour `npm run build` du squelette), Docker (optionnel, pour MySQL).

```bash
cd laravel
composer setup            # install, .env, key:generate, migrate, npm install + build
```

Ou à la main :

```bash
cd laravel
composer install
cp .env.example .env
php artisan key:generate
```

### Base de données

**Option A — SQLite (par défaut dans `.env.example`)** : rien à faire, `php artisan migrate` crée `database/database.sqlite`.

**Option B — MySQL via Docker** (depuis la racine du dépôt) :

```bash
docker compose up -d       # MySQL 8 sur :3306, phpMyAdmin sur :8080
```

puis dans `laravel/.env` :

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=wallet_tracker
DB_USERNAME=wallet_tracker
DB_PASSWORD=secret
```

```bash
php artisan migrate --seed          # seed : test@example.com + quelques actifs/positions
php artisan storage:link            # nécessaire pour servir avatars et logos
```

### Lancer l'API

```bash
composer dev               # environnement de dev (php artisan dev)
# ou séparément :
php artisan serve          # http://localhost:8000
php artisan queue:work     # e-mails et notifications sont mis en file
php artisan schedule:work  # alertes, snapshots, digests
```

Le frontend local est attendu sur `http://localhost:5173` (`FRONTEND_URLS`, `SANCTUM_STATEFUL_DOMAINS`).

> Par défaut `MAIL_MAILER=log` : les e-mails (vérification, reset, alertes) sont écrits dans `storage/logs/laravel.log`. Un compte doit avoir vérifié son e-mail pour accéder à `/api/*` — récupérez le lien dans le log, ou utilisez le compte seedé (déjà vérifié).

### Donner les droits admin

```bash
php artisan user:admin vous@exemple.fr           # accorder
php artisan user:admin vous@exemple.fr --revoke  # retirer
```

C'est le **seul** moyen de devenir admin (aucune route API ne le permet).

---

## Structure du dépôt

```
.
├── docker-compose.yml        # MySQL + phpMyAdmin pour le dev local
├── docs/                     # documentation détaillée (voir plus bas)
└── laravel/
    ├── app/
    │   ├── Console/Commands/ # alerts:check, snapshots:record, digest:send, user:admin
    │   ├── DTO/              # PortfolioSummary
    │   ├── Enums/            # AssetType, AlertType, AlertWindow, Currency, TransactionType
    │   ├── Http/
    │   │   ├── Controllers/
    │   │   ├── Middleware/   # PreventBrowserCaching, TrustVercelProxy
    │   │   ├── Requests/     # toute la validation (FormRequest)
    │   │   └── Resources/
    │   ├── Models/           # + Concerns/Auditable, Concerns/HasLogo
    │   ├── Notifications/    # alertes, digest, e-mails d'auth, canal Discord
    │   ├── Policies/         # CatalogPolicy, PortfolioEntryPolicy, PriceAlertPolicy
    │   ├── Providers/        # AppServiceProvider : rate limiters, HTTP global, mots de passe
    │   └── Services/         # toute la logique métier (voir docs/architecture.md)
    ├── config/
    ├── database/migrations/
    ├── deploy/systemd/       # scheduler + worker en unités systemd --user
    ├── resources/views/      # gabarits e-mail + page de désinscription
    ├── routes/
    │   ├── api.php           # /api/*  (auth + e-mail vérifié)
    │   ├── web.php           # auth, compte, vérification, désinscription
    │   └── console.php       # planification
    └── tests/
```

---

## Variables d'environnement

Seules les variables propres à l'application ou importantes sont listées ; le reste suit les conventions Laravel.

| Variable | Rôle |
|---|---|
| `APP_URL` | URL **publique** de l'API. En prod : `https://<domaine>/backend`. Sert à construire les liens signés des e-mails. |
| `FRONTEND_URLS` | Origines autorisées en CORS, séparées par des virgules. La **première** sert de base à tous les liens des e-mails. |
| `SANCTUM_STATEFUL_DOMAINS` | Domaines (sans schéma) dont les requêtes sont authentifiées par cookie de session. |
| `SESSION_DOMAIN`, `SESSION_SECURE_COOKIE`, `SESSION_SAME_SITE` | Cookie de session (voir [déploiement](docs/deploiement.md)). |
| `PROXY_SECRET` | Hérité de l'ancien hébergement Vercel. **À laisser vide** (local et VPS) : si défini, toute requête sans en-tête `X-Proxy-Secret` est refusée (403). |
| `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS` | `log` en local ; en prod SMTP Brevo (`smtp-relay.brevo.com:587`). `RESEND_API_KEY` ne sert que si `MAIL_MAILER=resend`. |
| `GEMINI_API_KEY` | IA : résumé des digests et création d'alerte en langage naturel. Optionnel (dégradation propre). |
| `COINGECKO_API_KEY` | Clé « Demo » CoinGecko, optionnelle (quota propre au lieu de celui de l'IP de l'hébergeur). |
| `CRYPTOCOMPARE_API_KEY` | Actualités crypto. Optionnel (sinon pas d'actus crypto). |
| `MARKETAUX_API_KEY` | Actualités actions/ETF. Optionnel. |
| `QUEUE_CONNECTION`, `CACHE_STORE` | `database` tous les deux. |
| `LOG_STACK`, `LOG_DAILY_DAYS` | En prod : `daily` / `14` (rétention annoncée dans la politique de confidentialité). |

Aucune clé n'est obligatoire pour faire tourner l'app : les prix (CoinGecko sans clé, Yahoo Finance), les taux de change (Frankfurter) et l'indice Fear & Greed (Alternative.me) sont gratuits et sans clé.

---

## Commandes utiles

| Commande | Effet |
|---|---|
| `php artisan alerts:check` | Évalue toutes les alertes actives et notifie. |
| `php artisan snapshots:record` | Enregistre un prix USD pour chaque actif détenu ou surveillé. |
| `php artisan digest:send daily\|weekly` | Envoie le résumé de portefeuille aux utilisateurs abonnés. |
| `php artisan user:admin <email> [--revoke]` | Gère le rôle admin. |
| `php artisan route:list --except-vendor` | Liste des routes. |
| `vendor/bin/pint` | Formatage du code. |

---

## Tâches planifiées

Définies dans `routes/console.php` :

| Fréquence | Commande |
|---|---|
| toutes les heures | `snapshots:record` |
| toutes les heures | `alerts:check` |
| tous les jours à 08:00 | `digest:send daily` |
| lundi à 08:00 | `digest:send weekly` |
| toutes les minutes | `queue:work --stop-when-empty --max-time=50` (sans chevauchement) |
| tous les jours | `queue:prune-failed --hours=168` |

Le scheduler doit être appelé chaque minute (`php artisan schedule:run`) : cron en prod, timer systemd ou `schedule:work` en local. Voir [docs/deploiement.md](docs/deploiement.md).

---

## Tests

```bash
cd laravel
composer test                                   # toute la suite
php artisan test --compact tests/Feature/AlertParserServiceTest.php
php artisan test --compact --filter=nomDuTest
```

PHPUnit 12. Les appels HTTP externes sont simulés avec `Http::fake()` ; aucun test n'appelle de vraie API.

---

## Documentation détaillée

| Document | Contenu |
|---|---|
| [Architecture](docs/architecture.md) | Couches, services, modèle de données, conventions. |
| [Référence de l'API](docs/api.md) | Toutes les routes, paramètres, réponses, codes d'erreur. |
| [Authentification & sécurité](docs/authentification-securite.md) | Flux SPA Sanctum, vérification d'e-mail, mots de passe, rate limiting, proxy, en-têtes, RGPD, audit log. |
| [Portefeuille, transactions & performance](docs/portefeuille-transactions-performance.md) | Positions, journal d'achats/ventes, calcul PMP, plus-values multi-devises, historique de valeur. |
| [Répartition & objectifs](docs/repartition.md) | Allocation par type/plateforme/actif, objectifs, rééquilibrage, apport. |
| [Alertes](docs/alertes.md) | Types d'alertes, déclenchement, cooldown, devise des seuils. |
| [Notifications & digests](docs/notifications-digest.md) | E-mail, Discord, file d'attente, gabarits, désinscription en un clic. |
| [Intelligence artificielle (Gemini)](docs/ia-gemini.md) | Résumé de digest et création d'alerte en langage naturel. |
| [Données de marché](docs/donnees-marche.md) | Prix, variations, historiques, taux de change, actualités, Fear & Greed, cache. |
| [Catalogue partagé](docs/catalogue.md) | Actifs et plateformes, recherche, logos, droits, rôle admin. |
| [Déploiement](docs/deploiement.md) | Production sur VPS (NGINX + PHP-FPM, Brevo), scheduler et file d'attente. |
