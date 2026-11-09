# Architecture

## Vue d'ensemble

```
            SPA (même domaine, /backend/*)
                │  cookie de session + X-XSRF-TOKEN
                ▼
  ┌──────────────────────────────┐
  │ Middleware                    │  TrustVercelProxy, PreventBrowserCaching,
  │                               │  CORS, Sanctum stateful, throttle, verified
  ├──────────────────────────────┤
  │ Controllers (minces)          │  validation déléguée aux FormRequest,
  │                               │  autorisation via Policies
  ├──────────────────────────────┤
  │ Services (logique métier)     │  PortfolioService, AlertService, ...
  ├──────────────────────────────┤
  │ Providers externes            │  CoinGecko, Yahoo Finance, Frankfurter,
  │ (derrière des interfaces)     │  CryptoCompare, Marketaux, Alternative.me,
  │                               │  Gemini, Discord, SMTP (Brevo)
  ├──────────────────────────────┤
  │ Modèles Eloquent              │  + traits Auditable, HasLogo
  └──────────────────────────────┘
       │                  ▲
       ▼                  │
   MySQL/MariaDB    Scheduler + queue (alertes, snapshots, digests, e-mails)
```

Principes suivis dans tout le code :

- **Contrôleurs minces** : validation dans `app/Http/Requests`, autorisation dans `app/Policies`, calculs dans `app/Services`.
- **Tout est stocké en USD** (prix, seuils d'alerte, valeurs). La conversion vers la devise de l'utilisateur se fait à la lecture, ou à l'écriture pour les montants saisis (seuils d'alerte).
- **Les appels externes ne doivent jamais faire tomber une page** : chaque provider attrape ses erreurs, les signale via `report()` et retombe sur une copie de secours en cache ou sur une valeur vide.
- **Aucun champ sensible en mass assignment** : `user_id`, `is_admin`, `created_by`, `logo_path`, `avatar_path`, `fx_rates`, `allocation_targets`, `onboarding_completed_at` sont posés côté serveur uniquement.
- **Les notifications sont toutes en file d'attente**, une tâche par canal.

## Modèle de données

```
users ─┬─< portfolio_entries >── assets ──< price_snapshots
       │        │          └──── platforms
       │        └─< portfolio_transactions
       ├─< price_alerts >── assets (nullable)
       └─< audit_logs (user_id nullable)
```

### `users`

| Colonne | Notes |
|---|---|
| `name`, `email`, `password` | Standard. |
| `email_verified_at` | Requis pour `/api/*` (middleware `verified`). Remis à `null` à chaque changement d'e-mail. |
| `is_admin` | Uniquement via `php artisan user:admin`. |
| `avatar_path` | Fichier sur le disque `public` ; exposé via l'attribut calculé `avatar_url`. |
| `currency` | `USD` (défaut), `EUR`, `GBP`, `CHF`. Devise d'affichage et de saisie des seuils. |
| `email_notifications_enabled` | Défaut `true`. |
| `discord_webhook_url` | Doit commencer par `https://discord.com/api/webhooks/` ou `https://discordapp.com/api/webhooks/`. |
| `daily_digest_enabled`, `weekly_digest_enabled` | Défaut `false`. |
| `onboarding_completed_at` | Posé par `PATCH /user` avec `onboarding_completed: true`. |
| `allocation_targets` | JSON `{type: pourcentage}`, caché du payload utilisateur, géré par `/api/allocation-targets`. |

### `assets` (catalogue partagé)

`name`, `symbol` (unique), `type` (`crypto|stock|etf|gold`), `external_id` (identifiant chez le fournisseur de prix : id CoinGecko comme `bitcoin`, ticker Yahoo comme `AAPL`, `GC=F`), `logo_path`, `created_by`.

### `platforms` (catalogue partagé)

`name` (unique), `logo_path`, `created_by`. Une plateforme référencée par une position ne peut pas être supprimée (`restrictOnDelete`).

### `portfolio_entries` (positions)

Une ligne par couple `(user, asset, platform)` — unicité en base. `quantity` est une **valeur en cache** : la somme nette des transactions, recalculée à chaque écriture du journal. Elle n'est plus modifiable directement.

### `portfolio_transactions`

`type` (`buy|sell`), `quantity`, `unit_price` (nullable : position créée sans prix d'achat), `fee`, `currency` (devise du prix saisi), `fx_rates` (taux USD→EUR/GBP/CHF du jour de la transaction, JSON, rempli par le serveur), `executed_at`.

### `price_alerts`

`type`, `asset_id` (nullable pour les alertes de portefeuille), `threshold` (USD), `percent_threshold`, `window` (`1h|24h|7d`), `is_active`, `condition_met` (état pour le déclenchement sur front montant), `last_triggered_at`.

### `price_snapshots`

`asset_id`, `price_usd`, `recorded_at`. Alimentée toutes les heures ; sert au graphique 7 jours de la fiche actif et de repli pour les variations %.

### `audit_logs`

`user_id` (nullable, `nullOnDelete`), `auditable_type`/`auditable_id`, `action` (`created|updated|deleted`), `source` (`web|console`), `changes` (JSON).

## Enums (`app/Enums`)

| Enum | Valeurs |
|---|---|
| `AssetType` | `crypto`, `stock`, `etf`, `gold` |
| `Currency` | `USD`, `EUR`, `GBP`, `CHF` |
| `TransactionType` | `buy`, `sell` |
| `AlertType` | `price_above`, `price_below`, `percent_change`, `portfolio_total_above`, `portfolio_total_below`, `allocation_drift` |
| `AlertWindow` | `1h`, `24h`, `7d` (méthode `hours()`, aussi utilisée comme cooldown) |

## Services (`app/Services`)

| Service | Rôle | Doc |
|---|---|---|
| `PortfolioService` | Résumé du portefeuille : valeurs, totaux multi-devises, variations, performance. Renvoie un `PortfolioSummary` (DTO). | [portefeuille](portefeuille-transactions-performance.md) |
| `PortfolioTransactionService` | Seul point d'écriture du journal ; rejoue les transactions, verrouille la position. | idem |
| `PerformanceCalculator` | Plus-values latentes/réalisées au PMP, par devise. | idem |
| `PortfolioHistoryService` | Valeur quotidienne reconstruite à partir des transactions et des cours de clôture. | idem |
| `AllocationService` | Répartition, objectifs, écarts, rééquilibrage, apport. | [répartition](repartition.md) |
| `AlertService` | Snapshots de prix et évaluation des alertes. | [alertes](alertes.md) |
| `PriceChangeCalculator` | Variations 1h/24h/7d d'un actif ou du portefeuille. | [données de marché](donnees-marche.md) |
| `PriceService` | Aiguillage vers le bon fournisseur de prix + taux de change. | idem |
| `PriceProviders/*` | CoinGecko (crypto), Yahoo Finance (actions, ETF, or). | idem |
| `NewsService`, `NewsProviders/*` | CryptoCompare (crypto), Marketaux (actions, ETF). | idem |
| `FearGreedIndexService` | Indice de sentiment crypto (Alternative.me). | idem |
| `AssetSearchService` | Recherche d'actifs chez CoinGecko + Yahoo. | [catalogue](catalogue.md) |
| `AssetDetailService` | Fiche actif : prix, description, capitalisation, variations, historique 7 j. | idem |
| `LogoStorage` | Upload/suppression des logos du catalogue. | idem |
| `PortfolioDigestService` | Chiffres du digest dans la devise de l'utilisateur. | [notifications](notifications-digest.md) |
| `GeminiSummaryService` | Résumé IA du digest. | [IA](ia-gemini.md) |
| `AlertParserService` | Texte libre → alerte structurée via Gemini. | idem |

### Interfaces des fournisseurs de prix

Un fournisseur implémente `PriceProviderInterface` (prix USD courant) et, optionnellement :

| Interface | Capacité | CoinGecko | Yahoo |
|---|---|:-:|:-:|
| `PercentChangeProviderInterface` | variations 1h/24h/7d natives | ✓ | ✓ |
| `PriceHistoryProviderInterface` | clôtures quotidiennes sur 365 jours | ✓ | ✓ |
| `AssetInfoProviderInterface` | description localisée, capitalisation | ✓ | — |

Les consommateurs testent la capacité avec `instanceof` ; ajouter un fournisseur revient à l'enregistrer dans le constructeur de `PriceService` pour le(s) type(s) d'actif concerné(s).

## Traits de modèle

- **`Auditable`** (Asset, Platform, PortfolioEntry, PortfolioTransaction, PriceAlert) : écrit une ligne `audit_logs` à chaque création, modification ou suppression. En console (sans utilisateur connecté), le `user_id` de la ligne est celui du propriétaire de l'enregistrement et `source = console`.
- **`HasLogo`** (Asset, Platform) : expose `logo_url` (calculé), cache `logo_path`, supprime le fichier quand l'enregistrement est supprimé.

## Configuration notable (`AppServiceProvider`)

- **Client HTTP global** : timeout 10 s, connexion 5 s, **IPv4 forcé** (introduit sur l'ancien hébergeur alwaysdata, dont l'IPv6 était refusée par Google ; sans effet gênant sur le VPS).
- **Macro `Http::coingecko()`** : base URL + clé Demo en en-tête si présente.
- **Policies** : `CatalogPolicy` enregistrée pour `Asset` et `Platform`.
- **Rate limiters** : `api`, `auth`, `catalog-writes`, `ai-parse`, `data-export` (voir [sécurité](authentification-securite.md#rate-limiting)).
- **Mots de passe** : `Password::defaults()` = 15 à 64 caractères + vérification HaveIBeenPwned.
- **Lien de réinitialisation** : pointe vers le frontend (`/reset-password?token=…&email=…`).

## Gestion des erreurs

`bootstrap/app.php` force le rendu JSON de **toutes** les exceptions (l'app n'a pas de pages HTML, à l'exception de la page de désinscription). Codes rencontrés :

| Code | Cas |
|---|---|
| 401 | Non authentifié. |
| 403 | E-mail non vérifié, policy refusée, header `X-Proxy-Secret` absent ou faux (uniquement si `PROXY_SECRET` est défini, ce qui n'est pas le cas en prod). |
| 409 | Suppression d'un actif/plateforme encore utilisé. |
| 422 | Validation (format Laravel standard `{message, errors}`), vente supérieure à la quantité détenue, alerte IA non comprise. |
| 429 | Rate limit (en-têtes `Retry-After`, `X-RateLimit-*` exposés en CORS). |
| 503 | Dépendance externe indisponible : taux de change, Gemini, indice Fear & Greed. |
