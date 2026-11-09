# Alertes

Services : `AlertService`, `PriceChangeCalculator`. Commande : `alerts:check` (toutes les heures). Notification : `PriceAlertTriggered`.

## Types

| Type | Porte sur | Champs requis | Champs interdits |
|---|---|---|---|
| `price_above` | un actif | `asset_id`, `threshold` | `percent_threshold`, `window` |
| `price_below` | un actif | `asset_id`, `threshold` | `percent_threshold`, `window` |
| `percent_change` | un actif | `asset_id`, `percent_threshold` (≤ 1000), `window` | `threshold` |
| `portfolio_total_above` | la valeur totale | `threshold` | `asset_id`, `percent_threshold`, `window` |
| `portfolio_total_below` | la valeur totale | `threshold` | `asset_id`, `percent_threshold`, `window` |
| `allocation_drift` | la répartition | `percent_threshold` (points, ≤ 100) | `asset_id`, `threshold`, `window` |

- `window` : `1h`, `24h` ou `7d`.
- `threshold` : > 0, ≤ 100 000 000 000.
- `allocation_drift` exige que l'utilisateur ait défini des objectifs de répartition.
- `is_active` (bool) permet de mettre une alerte en pause.

### Le type est figé après création

En modification (`PATCH /api/price-alerts/{id}`), on ne peut changer que `threshold`, `percent_threshold`, `window` et `is_active`. Pour une alerte `allocation_drift`, seuls `percent_threshold` et `is_active` sont acceptés.

## Devise des seuils

Le `threshold` est **saisi dans la devise du compte** et **stocké en USD**, comme tous les prix de l'app (`PriceService::convertToUsd`). Les comptes en USD ne déclenchent aucun appel externe. Pour un compte non-USD sans taux disponible (ni en direct, ni en cache de secours), la création/modification renvoie **503** avec un message exploitable.

À l'envoi, les montants sont reconvertis dans la devise du compte au taux du jour (repli en USD si aucun taux n'est disponible : mieux vaut une alerte en dollars que pas d'alerte).

## Déclenchement

`alerts:check` traite trois familles :

### Seuils (`price_*`, `portfolio_total_*`) — sur front montant

```
condition = valeur ≥ seuil   (above)
          | valeur ≤ seuil   (below)

si condition ET NON condition_met → notifier, last_triggered_at = maintenant
condition_met = condition
```

On n'est notifié **qu'au franchissement**, pas à chaque passage du planificateur tant que la condition reste vraie. L'alerte se « réarme » quand la valeur repasse de l'autre côté du seuil.

- Alertes d'actif : prix USD courant de l'actif ; les actifs sont récupérés en une fois pour toutes les alertes.
- Alertes de portefeuille : valeur totale USD, un seul calcul de `PortfolioSummary` par utilisateur.

### Variation (`percent_change`) — avec cooldown

```
si |variation(fenêtre)| ≥ percent_threshold ET cooldown écoulé → notifier
cooldown = durée de la fenêtre (1 h, 24 h ou 7 jours)
```

Hausse comme baisse déclenchent. Une alerte 24 h ne peut pas se redéclencher plus d'une fois par 24 h.

### Écart de répartition (`allocation_drift`) — sur front montant

Même logique que les seuils, avec `max_drift ≥ percent_threshold`. La notification indique le type le plus éloigné de sa cible et le détail de chaque type. Des objectifs supprimés depuis ou un portefeuille vide ne remplissent jamais la condition.

## Calcul des variations

`PriceChangeCalculator::percentChange(asset, window, prix)` :

1. Utilise d'abord la variation **native du fournisseur** (CoinGecko `/coins/markets`, ou bougies horaires Yahoo sur 8 jours) : elle reflète le marché immédiatement.
2. Sinon, se rabat sur l'historique local `price_snapshots` (prix enregistré le plus récent avant `maintenant − fenêtre`).

Le même calcul sert aux alertes, à la fiche actif et aux digests : tout le monde affiche le même chiffre.

## Snapshots de prix

`snapshots:record` (toutes les heures) enregistre le prix USD de chaque actif **détenu par quelqu'un** ou **surveillé par une alerte active**. Ils servent au graphique 7 jours de la fiche actif et de repli pour les variations.

## Création en langage naturel

`POST /api/price-alerts/parse` transforme une phrase (« préviens-moi si le bitcoin dépasse 50000 ») en champs d'alerte via Gemini. C'est un **aperçu** : rien n'est créé, le frontend affiche le résultat puis appelle `POST /api/price-alerts` normalement. Voir [ia-gemini.md](ia-gemini.md#2-création-dalerte-en-langage-naturel).

## Notifications

Par e-mail (si activé et adresse vérifiée) et/ou Discord (si un webhook est configuré), en file d'attente avec 3 tentatives. Gabarit e-mail dédié par type (`resources/views/mail/price-alert.blade.php`). Voir [notifications-digest.md](notifications-digest.md).

Si l'alerte ou l'utilisateur est supprimé entre l'envoi en file et la livraison, la tâche est abandonnée silencieusement (`deleteWhenMissingModels`).

## Tests associés

`AllocationDriftAlertTest`, `PriceAlertMailTest`, `AlertParserServiceTest`.
