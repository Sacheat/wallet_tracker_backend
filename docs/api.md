# Référence de l'API

- Toutes les réponses sont en JSON (sauf la page de désinscription et la redirection de vérification d'e-mail).
- Tous les montants stockés sont en **USD**. Les montants **saisis** dans la devise de l'utilisateur (seuils d'alerte, apport) sont convertis par le serveur.
- Les erreurs de validation suivent le format Laravel : `422 {"message": "...", "errors": {"champ": ["..."]}}`.
- Le préfixe `/api` concerne les routes de `routes/api.php`. Les routes d'authentification et de compte sont **à la racine** (`routes/web.php`).

## Légende

| Symbole | Signification |
|---|---|
| 🔓 | Public |
| 🔑 | Session requise (e-mail non vérifié accepté) |
| ✅ | Session + e-mail vérifié (toutes les routes `/api/*`) |
| ⏱ | Limite propre en plus de la limite globale |

Limite globale sur `/api/*` : **60 requêtes/min** par utilisateur.

---

## Authentification

Voir [authentification-securite.md](authentification-securite.md) pour le flux complet (cookie CSRF, session).

| Méthode | Route | Accès | Corps | Réponse |
|---|---|---|---|---|
| GET | `/sanctum/csrf-cookie` | 🔓 | — | 204, pose `XSRF-TOKEN` |
| POST | `/register` | 🔓 ⏱5/min/IP | `name`, `email`, `password`, `password_confirmation` | 201 + utilisateur, session ouverte, e-mail de vérification envoyé |
| POST | `/login` | 🔓 ⏱5/min/IP | `email`, `password`, `remember?` (bool) | 200 + utilisateur |
| POST | `/logout` | 🔑 | — | 204 |
| POST | `/forgot-password` | 🔓 ⏱5/min/IP | `email` | 200, même message que le compte existe ou non |
| POST | `/reset-password` | 🔓 ⏱5/min/IP | `token`, `email`, `password`, `password_confirmation` | 200 |
| GET | `/email/verify/{id}/{hash}` | 🔑 lien signé ⏱6/min | — | Redirection vers `{FRONTEND}/?verified=1` |
| POST | `/email/verification-notification` | 🔑 ⏱6/min | — | 200 `{message}` |
| GET | `/email/verify` | 🔑 | — | 403 `{"message":"Email non vérifié."}` (route technique) |

Règles du mot de passe : 15 à 64 caractères, absent des fuites connues (HaveIBeenPwned), aucune règle de composition.

## Compte

| Méthode | Route | Accès | Corps | Réponse |
|---|---|---|---|---|
| GET | `/user` | 🔑 | — | Utilisateur courant |
| PATCH | `/user` | 🔑 ⏱6/min | `name?`, `email?`, `current_password` (requis si l'e-mail change), `currency?`, `onboarding_completed?` (seule valeur acceptée : `true`) | Utilisateur. Un changement d'e-mail dé-vérifie le compte et renvoie un e-mail de vérification. |
| PATCH | `/user/password` | 🔑 ⏱6/min | `current_password`, `password`, `password_confirmation` | 204 |
| POST | `/user/avatar` | 🔑 | multipart `avatar` (jpeg/png/webp/gif, ≤ 2 Mo, ≤ 4096 px) | Utilisateur |
| DELETE | `/user/avatar` | 🔑 | — | 204 |
| DELETE | `/user` | 🔑 ⏱6/min | `password` | 204, compte supprimé et session fermée |
| GET | `/user/export` | 🔑 ⏱3/min | — | Fichier JSON `export-donnees-AAAA-MM-JJ.json` (RGPD) |

### Objet utilisateur

```json
{
  "id": 1,
  "name": "Alice",
  "email": "alice@exemple.fr",
  "email_verified_at": "2026-09-21T10:00:00.000000Z",
  "is_admin": false,
  "currency": "EUR",
  "email_notifications_enabled": true,
  "discord_webhook_url": null,
  "daily_digest_enabled": false,
  "weekly_digest_enabled": true,
  "onboarding_completed_at": null,
  "avatar_url": "https://…/storage/avatars/abc.webp",
  "created_at": "…",
  "updated_at": "…"
}
```

---

## Portefeuille

### `GET /api/portfolio` ✅

Résumé complet, positions triées par valeur décroissante.

```json
{
  "data": [
    {
      "id": 12,
      "asset": "Bitcoin",
      "asset_type": "crypto",
      "platform": "Binance",
      "quantity": "0.50000000",
      "value_usd": 48000.0,
      "value_eur": 44160.0,
      "performance": {
        "USD": {
          "cost_basis": 30000.0,
          "average_buy_price": 60000.0,
          "unrealized_pnl": 18000.0,
          "unrealized_pnl_percent": 60.0,
          "realized_pnl": 0.0
        },
        "EUR": { "…": "…" }, "GBP": { "…": "…" }, "CHF": { "…": "…" }
      }
    }
  ],
  "total_value_usd": 52000.0,
  "total_value_eur": 47840.0,
  "total_value_by_currency": { "USD": 52000.0, "EUR": 47840.0, "GBP": 40000.0, "CHF": 45000.0 },
  "change_percent": { "1h": 0.12, "24h": -1.8, "7d": 4.5 },
  "performance": {
    "USD": { "cost_basis": 34000.0, "unrealized_pnl": 18000.0, "unrealized_pnl_percent": 52.94, "realized_pnl": 250.0 }
  },
  "positions_without_price": 1
}
```

- `performance` d'une position vaut `null` si une de ses transactions n'a pas de prix ; elle est alors exclue du total et comptée dans `positions_without_price`.
- `change_percent` : variation de la **valeur totale** (pas une moyenne des %), `null` si incalculable.

### `GET /api/portfolio/history?range=7d|30d|90d|1y` ✅

Défaut `30d`. Un point par jour.

```json
{
  "range": "30d",
  "from": "2026-10-11",
  "to": "2026-11-09",
  "points": [
    {
      "date": "2026-10-11",
      "value": { "USD": 50100.0, "EUR": 46000.0, "GBP": 38500.0, "CHF": 43600.0 },
      "invested": { "USD": 34000.0, "EUR": 31200.0, "GBP": 26100.0, "CHF": 29500.0 }
    }
  ],
  "missing_history": ["Mon Actif Exotique"]
}
```

- `invested` vaut `null` dès qu'une transaction sans prix est en jeu.
- `missing_history` : actifs exclus faute d'historique de cours.
- 503 si la série de taux de change est indisponible.

### `GET /api/portfolio/news` ✅

Jusqu'à 20 actualités sur les actifs détenus, les plus récentes en premier.

```json
{ "data": [ { "title": "…", "url": "https://…", "source": "…", "published_at": "2026-11-09T07:00:00+00:00", "asset_symbol": "BTC" } ] }
```

---

## Positions (`portfolio-entries`)

| Méthode | Route | Corps | Réponse |
|---|---|---|---|
| GET | `/api/portfolio-entries` | — | Positions de l'utilisateur avec `asset` et `platform` |
| POST | `/api/portfolio-entries` | voir ci-dessous | Position créée (avec une première transaction d'achat) |
| GET | `/api/portfolio-entries/{id}` | — | Position (propriétaire uniquement) |
| PATCH/PUT | `/api/portfolio-entries/{id}` | `quantity` est **interdit** | Position |
| DELETE | `/api/portfolio-entries/{id}` | — | 204 (supprime aussi ses transactions) |

Création :

| Champ | Règle |
|---|---|
| `asset_id` | requis, existe |
| `platform_id` | requis, existe, unique par (utilisateur, actif) |
| `quantity` | requis, > 0, ≤ 999 999 999 999 |
| `unit_price` | optionnel (sans prix, la performance est « inconnue ») |
| `fee` | optionnel, ≥ 0 |
| `currency` | optionnel, défaut = devise du compte |
| `executed_at` | optionnel, défaut = maintenant |

## Transactions

| Méthode | Route | Corps | Réponse |
|---|---|---|---|
| GET | `/api/portfolio-entries/{id}/transactions` | — | Liste (plus récente d'abord) |
| POST | `/api/portfolio-entries/{id}/transactions` | `type`, `quantity`, `unit_price`, `fee?`, `currency?`, `executed_at` | Transaction |
| PATCH | `/api/portfolio-transactions/{id}` | n'importe quel sous-ensemble des champs | Transaction |
| DELETE | `/api/portfolio-transactions/{id}` | — | 204 |

Objet transaction :

```json
{
  "id": 41, "portfolio_entry_id": 12, "type": "sell",
  "quantity": "0.10000000", "unit_price": "95000.0000000000", "fee": "2.50000000",
  "currency": "EUR", "executed_at": "2026-11-01T14:00:00+00:00",
  "position_quantity": "0.40000000",
  "created_at": "…", "updated_at": "…"
}
```

`position_quantity` est la nouvelle quantité nette de la position, pour rafraîchir l'écran sans seconde requête.

Erreurs spécifiques :
- **422** `quantity` : « Vente impossible : au AAAA-MM-JJ, la quantité détenue sur cette position est insuffisante. » — la vente ferait passer la position sous zéro à cette date.
- **503** : taux de change historiques indisponibles.

---

## Répartition

| Méthode | Route | Corps / Query | Réponse |
|---|---|---|---|
| GET | `/api/allocation` | `?contribution=` (montant dans la devise du compte, optionnel) | Répartition complète |
| GET | `/api/allocation-targets` | — | `{"targets": {"crypto": 40, "stock": 60}}` |
| PUT | `/api/allocation-targets` | `{"targets": {...}}` — somme = 100 (± 0,01), `{}` pour effacer | `{"targets": {...}}` |

Détail et exemple de réponse : [repartition.md](repartition.md).

---

## Alertes

| Méthode | Route | Accès | Réponse |
|---|---|---|---|
| GET | `/api/price-alerts` | ✅ | Alertes de l'utilisateur avec `asset` |
| POST | `/api/price-alerts` | ✅ | Alerte créée |
| GET | `/api/price-alerts/{id}` | ✅ | Alerte |
| PATCH/PUT | `/api/price-alerts/{id}` | ✅ | Alerte |
| DELETE | `/api/price-alerts/{id}` | ✅ | 204 |
| POST | `/api/price-alerts/parse` | ✅ ⏱10/min | Aperçu IA (ne crée rien) |

Champs requis selon le type : voir [alertes.md](alertes.md). L'IA : voir [ia-gemini.md](ia-gemini.md).

---

## Catalogue

| Méthode | Route | Accès | Notes |
|---|---|---|---|
| GET | `/api/assets` | ✅ | Tout le catalogue |
| GET | `/api/assets/search?q=` | ✅ | `q` 2 à 100 caractères ; recherche chez CoinGecko et Yahoo |
| GET | `/api/assets/{id}` | ✅ | |
| GET | `/api/assets/{id}/details?locale=fr` | ✅ | Prix, description, capitalisation, variations, historique 7 j |
| GET | `/api/assets/{id}/news` | ✅ | 5 actualités max |
| POST | `/api/assets` | ✅ ⏱10/min | `name`, `symbol` (unique, ≤ 10), `type`, `external_id?` |
| PATCH/PUT | `/api/assets/{id}` | ✅ + policy | |
| DELETE | `/api/assets/{id}` | ✅ + policy | 409 si utilisé |
| POST | `/api/assets/{id}/logo` | ✅ ⏱10/min + policy | multipart `logo` |
| DELETE | `/api/assets/{id}/logo` | ✅ ⏱10/min + policy | 204 |
| GET | `/api/platforms` | ✅ | |
| GET | `/api/platforms/{id}` | ✅ | |
| POST | `/api/platforms` | ✅ ⏱10/min | `name` (unique) |
| PATCH/PUT | `/api/platforms/{id}` | ✅ + policy | |
| DELETE | `/api/platforms/{id}` | ✅ + policy | 409 si utilisée |
| POST/DELETE | `/api/platforms/{id}/logo` | ✅ ⏱10/min + policy | |

Détail : [catalogue.md](catalogue.md).

---

## Marché

| Méthode | Route | Réponse |
|---|---|---|
| GET | `/api/market/fear-greed-index` | `{"value": 72, "classification": "Greed", "updated_at": "…"}` ou 503 |
| GET | `/api/exchange-rates` | `{"base": "USD", "rates": {"EUR": 0.92, "GBP": 0.78, "CHF": 0.87}, "updated_at": "…"}` |

---

## Notifications

| Méthode | Route | Corps | Réponse |
|---|---|---|---|
| GET | `/api/notification-settings` | — | `{email_notifications_enabled, discord_webhook_url, daily_digest_enabled, weekly_digest_enabled}` |
| PATCH | `/api/notification-settings` | n'importe quel sous-ensemble de ces champs | idem |

| Méthode | Route | Accès | Notes |
|---|---|---|---|
| GET | `/email/unsubscribe/{user}?signature=…` | 🔓 lien signé ⏱20/min | Page HTML de confirmation |
| POST | `/email/unsubscribe/{user}?signature=…` | 🔓 lien signé ⏱20/min, sans CSRF | Désinscription (RFC 8058 one-click) |

---

## Journal d'audit

`GET /api/audit-logs?scope=mine|all&page=N` ✅ — paginé par 25, plus récent d'abord. `scope=all` est réservé aux admins (403 sinon) et inclut l'auteur (`user: {id, name, avatar_url}`).

```json
{
  "data": [
    { "id": 300, "user_id": 1, "auditable_type": "App\\Models\\PriceAlert", "auditable_id": 8,
      "action": "updated", "source": "console", "changes": { "condition_met": true }, "created_at": "…" }
  ],
  "current_page": 1, "per_page": 25, "total": 120, "…": "…"
}
```

---

## Divers

| Route | Rôle |
|---|---|
| `GET /up` | Health check Laravel. |
| `GET /storage/...` | Avatars et logos (nécessite `php artisan storage:link`). |
