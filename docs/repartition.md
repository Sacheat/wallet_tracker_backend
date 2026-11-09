# Répartition & objectifs

Service : `AllocationService`. Contrôleur : `AllocationController`.

## Ce que fait la fonctionnalité

- Montre où se trouve la valeur du portefeuille : **par type d'actif**, **par plateforme** et **par actif**.
- Permet de fixer un **objectif en %** par type d'actif (`crypto`, `stock`, `etf`, `gold`).
- Calcule l'**écart** (en points) entre la part actuelle et l'objectif, et deux manières d'y revenir :
  - **Rééquilibrage complet** : montant à acheter (+) ou vendre (−) par type pour tomber pile sur la cible, à valeur totale constante.
  - **Répartition d'un apport** : où placer de l'argent frais **sans rien vendre** (pas de plus-value imposable, pas de frais de vente — c'est ainsi que la plupart des particuliers rééquilibrent).
- Alimente l'alerte `allocation_drift` et la section « répartition » du digest.

## Objectifs

`PUT /api/allocation-targets`

```json
{ "targets": { "crypto": 30, "stock": 50, "etf": 20 } }
```

- Les objectifs sont **remplacés en bloc** (ils n'ont de sens qu'ensemble).
- Clés autorisées : les valeurs de `AssetType`. Chaque valeur entre 0 et 100.
- La somme doit faire **100 %**, à 0,01 près (33,33 + 33,33 + 33,34 passe).
- Les objectifs à 0 sont supprimés : « pas d'objectif » et « 0 % » se comportent pareil, un type non listé est visé à 0 %.
- `{"targets": {}}` efface les objectifs.
- Stockés dans `users.allocation_targets`, jamais renvoyés dans l'objet utilisateur.

`GET /api/allocation-targets` → `{"targets": {...}}` (objet vide si aucun).

## Répartition

`GET /api/allocation?contribution=1000`

`contribution` (optionnel) est saisi **dans la devise du compte** et converti en USD. Il exige des objectifs (sinon 422) ; taux de change indisponible → 503.

```json
{
  "total_value_usd": 10000.0,
  "has_targets": true,
  "max_drift": 12.0,
  "by_type": [
    {
      "type": "crypto",
      "value_usd": 4200.0,
      "percent": 42.0,
      "target_percent": 30.0,
      "drift": 12.0,
      "rebalance_usd": -1200.0,
      "contribution_usd": 0.0
    },
    {
      "type": "stock",
      "value_usd": 3800.0,
      "percent": 38.0,
      "target_percent": 50.0,
      "drift": -12.0,
      "rebalance_usd": 1200.0,
      "contribution_usd": 1000.0
    }
  ],
  "by_platform": [ { "platform": "Binance", "value_usd": 4200.0, "percent": 42.0 } ],
  "by_asset": [ { "asset": "Bitcoin", "asset_type": "crypto", "value_usd": 3000.0, "percent": 30.0 } ]
}
```

- Tous les montants sont en **USD** ; la conversion à l'affichage au taux du jour est exacte ici (rien d'historique).
- `by_type` contient les types qui ont une valeur **ou** un objectif.
- Sans objectifs : `target_percent`, `drift`, `rebalance_usd`, `contribution_usd` valent `null`.
- `contribution_usd` n'est rempli que si `contribution` est passé.
- `max_drift` : plus grand écart absolu, en points ; `null` sans objectifs ou si le portefeuille est vide.

## Formules

Rééquilibrage, pour chaque type :

```
rebalance = total × cible − valeur_actuelle
```

Répartition d'un apport `C` :

```
nouveau_total = total + C
déficit(type) = max(0, nouveau_total × cible − valeur_actuelle)
apport(type)  = C × déficit(type) / Σ déficits
```

Seuls les types en sous-pondération reçoivent de l'argent ; un type surpondéré n'est jamais corrigé, seulement dilué.

## Alerte d'écart

Une alerte `allocation_drift` avec `percent_threshold` (points, ≤ 100) se déclenche quand `max_drift` atteint ce seuil, puis se tait jusqu'à ce que la répartition revienne sous le seuil. Elle nécessite des objectifs à la création. Voir [alertes.md](alertes.md).

## Tests associés

`AllocationServiceTest`, `AllocationDriftAlertTest`.
