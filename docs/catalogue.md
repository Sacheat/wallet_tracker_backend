# Catalogue partagé (actifs & plateformes)

Les **actifs** (`assets`) et **plateformes** (`platforms`) forment un catalogue **commun à tous les utilisateurs**. Les positions et les alertes y font référence.

## Actifs

| Champ | Règle |
|---|---|
| `name` | requis, ≤ 255 |
| `symbol` | requis, ≤ 10, **unique** |
| `type` | `crypto`, `stock`, `etf`, `gold` |
| `external_id` | identifiant chez le fournisseur de prix, unique, caractères `[A-Za-z0-9._=^-]` uniquement |
| `logo_url` | **interdit** en entrée (les logos passent par l'upload) ; exposé en lecture |

Exemples d'`external_id` :

| Type | Fournisseur | Exemple |
|---|---|---|
| crypto | CoinGecko (id, pas le symbole) | `bitcoin`, `usd-coin` |
| stock | Yahoo Finance | `AAPL`, `BRK-B`, `0700.HK` |
| etf | Yahoo Finance | `SPY`, `CW8.PA` |
| gold | Yahoo Finance | `GC=F` |

Sans `external_id`, l'actif n'a pas de prix (valeur 0, pas d'historique).

## Flux « rechercher puis ajouter »

1. `GET /api/assets/search?q=bitcoin` interroge **CoinGecko** (8 cryptos max) et **Yahoo Finance** (8 actions/ETF max) et fusionne les résultats :

   ```json
   { "data": [
     { "external_id": "bitcoin", "name": "Bitcoin", "symbol": "BTC", "type": "crypto" },
     { "external_id": "BITO", "name": "ProShares Bitcoin Strategy ETF", "symbol": "BITO", "type": "etf" }
   ] }
   ```

2. Le frontend envoie le résultat choisi à `POST /api/assets`.

Si l'une des deux sources échoue, l'autre est quand même renvoyée, mais le résultat n'est **pas mis en cache** (sinon une mauvaise passe masquerait la moitié des résultats pendant 5 min). L'or n'est pas recherché : c'est un ticker unique bien connu.

## Plateformes

| Champ | Règle |
|---|---|
| `name` | requis, ≤ 255, **unique** |
| `logo_url` | interdit en entrée |

## Droits

`CatalogPolicy`, enregistrée pour les deux modèles :

| Action | Qui |
|---|---|
| Lire, rechercher | tout utilisateur vérifié |
| Créer | tout utilisateur vérifié (limite 10/min) |
| Modifier, changer le logo, supprimer | **admin**, ou **créateur** tant qu'aucun autre utilisateur ne l'utilise |

« Utilisé par un autre » : une position (actifs et plateformes) ou une alerte (actifs) appartenant à quelqu'un d'autre. Dès lors, un utilisateur ordinaire ne peut plus renommer l'entrée ni changer sa source de prix sous les pieds des autres. Messages de refus en français (403).

`created_by` est posé côté serveur, caché du JSON, et passe à `null` si le créateur supprime son compte (seul un admin peut alors modifier l'entrée).

### Suppression

Même un admin ne peut pas supprimer une entrée référencée : **409** si un actif est utilisé dans une position ou une alerte (de n'importe qui), ou une plateforme dans une position. La base l'interdit aussi (`restrictOnDelete`).

### Devenir admin

```bash
php artisan user:admin alice@exemple.fr
php artisan user:admin alice@exemple.fr --revoke
```

Aucune route API ne permet de modifier `is_admin`. Un admin peut aussi consulter le journal d'audit de tous les utilisateurs (`GET /api/audit-logs?scope=all`) pour modérer le catalogue.

## Logos

`POST /api/assets/{id}/logo` et `POST /api/platforms/{id}/logo`, multipart, champ `logo`.

| Contrainte | Valeur |
|---|---|
| Formats | PNG, JPEG, WebP (contenu vérifié, pas seulement l'extension) |
| Interdits | SVG (peut contenir du script), GIF |
| Taille | ≤ 512 Ko |
| Dimensions | ≤ 1024 × 1024 px |

`LogoStorage` :

- stocke dans `storage/app/public/logos` avec un **nom aléatoire** (le nom envoyé n'est jamais utilisé) ;
- supprime l'ancien fichier **après** l'enregistrement du nouveau chemin (un échec ne laisse jamais l'entrée pointer vers un fichier absent) ;
- `DELETE …/logo` retire le logo (204) ;
- la suppression de l'entrée supprime le fichier (trait `HasLogo`).

`logo_url` est l'URL publique calculée ; `logo_path` n'est jamais exposé. Nécessite `php artisan storage:link`.

## Tests associés

`CatalogPolicyTest`, `LogoUploadTest`, `MassAssignmentTest`.
