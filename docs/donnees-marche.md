# Données de marché

Toutes les sources sont gratuites. Seules les actualités nécessitent une clé ; le reste fonctionne sans configuration.

| Donnée | Source | Clé | Service |
|---|---|---|---|
| Prix et variations crypto | CoinGecko `/coins/markets` | optionnelle (`COINGECKO_API_KEY`, plan Demo) | `CoinGeckoPriceProvider` |
| Historique crypto (365 j) | CoinGecko `/coins/{id}/market_chart` | idem | idem |
| Description, capitalisation crypto | CoinGecko `/coins/{id}` | idem | idem |
| Prix, variations, historique actions/ETF/or | Yahoo Finance `v8/finance/chart` (non officiel) | non | `YahooFinancePriceProvider` |
| Recherche d'actifs | CoinGecko `/search` + Yahoo `v1/finance/search` | non | `AssetSearchService` |
| Taux de change | Frankfurter `api.frankfurter.dev/v2/rates` | non | `PriceService` |
| Actualités crypto | CryptoCompare `data/v2/news` | **oui** (`CRYPTOCOMPARE_API_KEY`) | `CryptoCompareNewsProvider` |
| Actualités actions/ETF | Marketaux `v1/news/all` | **oui** (`MARKETAUX_API_KEY`) | `MarketauxNewsProvider` |
| Sentiment crypto | Alternative.me `/fng/` | non | `FearGreedIndexService` |

## Principes communs

- **Client HTTP global** : timeout 10 s, connexion 5 s, IPv4 forcé.
- **Jamais d'erreur visible pour une panne de fournisseur** : chaque appel est dans un `try/catch`, l'erreur est signalée (`report()`), et on sert une **copie de secours** (`*_backup`, cache permanent mise à jour à chaque succès) ou une valeur vide.
- **Les échecs sont aussi mis en cache** (sous la forme `false` ou `[]`, jamais `null`, que `Cache::remember()` traite comme une absence) : un fournisseur en panne ou un ticker invalide n'est pas réinterrogé à chaque requête. Sans cela, un portefeuille plein de tickers invalides faisait un appel Yahoo par ticker à chaque `GET /api/portfolio`, assez pour faire bloquer l'IP du serveur.
- **`external_id` est fourni par l'utilisateur** : il est restreint par validation et encodé avec `rawurlencode` avant d'entrer dans un chemin d'URL.

## Aiguillage par type d'actif — `PriceService`

| Type | Fournisseur de prix | Actualités |
|---|---|---|
| `crypto` | CoinGecko | CryptoCompare |
| `stock` | Yahoo Finance | Marketaux (`equity`) |
| `etf` | Yahoo Finance | Marketaux (`etf`) |
| `gold` | Yahoo Finance (ex. `GC=F`) | aucune (volontaire) |

`getUsdPrices($assets)` regroupe les actifs par type et appelle chaque fournisseur une fois. Résultat indexé par `external_id`.

## CoinGecko

- **Cache par coin**, pas par lot : `crypto_market_{id}`, 45 s. N'importe quel appelant (portefeuille entier, alerte, fiche actif) lit les mêmes entrées, et seuls les coins absents sont récupérés, en une requête groupée. (Avec un cache par lot, N coins coûtaient N+1 requêtes, sur un plan gratuit qui répond vite 429.)
- Les variations 1h/24h/7d viennent du **même appel** que le prix (`price_change_percentage=1h,24h,7d`).
- Requêtes par paquets de 250 identifiants (au-delà, `/coins/markets` tronque silencieusement).
- Clé Demo envoyée dans l'en-tête `x-cg-demo-api-key` via la macro `Http::coingecko()`. Sans clé, les requêtes partagent le quota de l'IP publique du serveur.
- Historique : 365 jours (le plan gratuit refuse au-delà), points quotidiens à 00:00 UTC, cache 1 h + secours.
- Infos : descriptions **dans toutes les langues** mises en cache ensemble (`coingecko_info_{id}`, 1 h) ; la fiche choisit `locale` puis se rabat sur l'anglais.

## Yahoo Finance

API non documentée : peut changer sans préavis. Choisie parce qu'aucune alternative gratuite ne couvrait l'or (metals-api payant, Alpha Vantage refuse XAU) et qu'Alpha Vantage limite les actions à 25 requêtes/jour.

- Prix + variations : **une requête** de bougies horaires sur 8 jours (`interval=60m&range=8d`), cache 15 min + secours. La variation sur N heures compare le prix actuel à la dernière clôture d'il y a au moins N heures.
- Historique : bougies quotidiennes sur 1 an, cache 1 h + secours.
- Pas de description ni de capitalisation (l'endpoint de profil exige un « crumb » de session).
- En-tête `User-Agent: Mozilla/5.0` requis.

## Taux de change — Frankfurter

Devises gérées : `USD` (base), `EUR`, `GBP`, `CHF`.

| Méthode | Usage | Cache |
|---|---|---|
| `getUsdToCurrencyRates()` | taux du jour (totaux, affichage, conversions) | 45 s + secours permanent |
| `getUsdToCurrencyRatesWithTimestamp()` | `/api/exchange-rates` (avec horodatage) | idem |
| `convertToUsd($montant, $devise)` | seuils d'alerte, apport ; USD court-circuité sans appel | idem |
| `getHistoricalUsdRates($date)` | `fx_rates` d'une transaction | **permanent** pour un jour passé, 1 h pour aujourd'hui |
| `getDailyUsdRates($du, $au)` | série quotidienne de l'historique de portefeuille, en un seul appel | 1 h |

Particularités : `/v2/rates` ignore le filtre `symbols` (422) et renvoie toutes les devises, mais le filtre `quotes` fonctionne. Historique EUR disponible depuis le 4 janvier 1999.

Si aucun taux n'est disponible (ni direct, ni secours), une `RuntimeException` est levée : les contrôleurs la traduisent en **503**, les notifications se rabattent sur l'USD.

## Variations — `PriceChangeCalculator`

1. Variation native du fournisseur (`PercentChangeProviderInterface`).
2. Sinon, historique local `price_snapshots`.

Variation du portefeuille : reconstruction de la valeur de début de fenêtre position par position, pas une moyenne de pourcentages (voir [portefeuille](portefeuille-transactions-performance.md#variation-du-portefeuille)).

## Fiche actif — `AssetDetailService`

`GET /api/assets/{id}/details?locale=fr`

```json
{
  "price_usd": 96000.0,
  "description": "Le Bitcoin est…",
  "market_cap_usd": 1900000000000,
  "change_percent": { "1h": 0.1, "24h": -1.2, "7d": 3.4 },
  "history_7d": [ { "price_usd": "95000.00000000", "recorded_at": "…" } ]
}
```

`description` et `market_cap_usd` sont `null` pour les actifs Yahoo. `history_7d` vient des snapshots horaires.

## Actualités — `NewsService`

- Par actif : 5 articles max. Pour le portefeuille : dédoublonnées par URL, triées par date, 20 max.
- **Filtre de sécurité centralisé** : seules les URLs `http`/`https` valides sont conservées (protection XSS côté frontend).
- `published_at` reste une **chaîne ISO 8601** de bout en bout : un objet Carbon stocké dans le cache `database` ne se désérialise pas correctement.
- CryptoCompare : clé en en-tête `authorization: Apikey …`, cache 30 min, articles en anglais.
- Marketaux : clé en Bearer, cache **3 h** (plan gratuit : 100 requêtes/jour), articles en français.
- Sans clé : avertissement dans les logs, liste vide.
- Or : pas de source fiable (Marketaux ne gère pas les matières premières, la recherche « gold » renvoie des actualités de sociétés minières).

## Indice Fear & Greed

`GET /api/market/fear-greed-index` → `{value, classification, updated_at}`. Sentiment **crypto** uniquement (peu représentatif d'un portefeuille actions/or). Mis à jour environ une fois par jour à la source ; cache 1 h + secours. 503 si rien n'est disponible. Utilisé aussi dans le prompt du résumé IA.

## Clés de cache (référence)

| Clé | Durée |
|---|---|
| `crypto_market_{id}` | 45 s (`_backup` permanent) |
| `coingecko_history_{id}` | 1 h (`_backup`) |
| `coingecko_info_{id}` | 1 h |
| `yahoo_finance_chart_{symbole}` | 15 min (`_backup`) |
| `yahoo_finance_history_{symbole}` | 1 h (`_backup`) |
| `usd_exchange_rates` | 45 s (`usd_exchange_rates_backup` permanent) |
| `usd_exchange_rates_on_{date}` | permanent (1 h si aujourd'hui) |
| `usd_exchange_rates_{du}_{au}` | 1 h |
| `asset_search_{md5}` | 5 min, seulement si les deux sources ont répondu |
| `news_{symbole}` | 30 min |
| `news_marketaux_{symbole}` | 3 h |
| `fear_greed_index` | 1 h (`_backup`) |
| `ai_digest_summary_{user}_{fréquence}_{date}` | 1 h |

## Tests associés

`CoinGeckoPriceProviderTest`, `ExchangeRatesTest`, `HistoricalExchangeRatesTest`, `MarketauxNewsProviderTest`, `NewsUrlFilterTest`, `OutboundHttpTest`.
