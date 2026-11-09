# Portefeuille, transactions & performance

## Concepts

- Une **position** (`portfolio_entries`) = un actif détenu sur une plateforme par un utilisateur. Unique par `(utilisateur, actif, plateforme)`.
- Chaque position a un **journal de transactions** (`portfolio_transactions`) : achats et ventes datés, avec prix unitaire, frais et devise.
- `portfolio_entries.quantity` est le **total net en cache** du journal. Tous les lecteurs l'utilisent, mais seul `PortfolioTransactionService` l'écrit.

## Journal de transactions — `PortfolioTransactionService`

C'est le **seul chemin d'écriture** du journal.

### Ouvrir une position

`POST /api/portfolio-entries` crée la position **et** sa première transaction d'achat, dans une transaction SQL. Le prix est optionnel pour garder le formulaire « ajouter une position » simple ; la performance de la position sera alors `null` (« inconnue ») tant que le prix n'est pas renseigné sur cette transaction.

Défauts : `currency` = devise du compte, `executed_at` = maintenant, `fee` = 0.

### Ajouter, modifier, supprimer une transaction

Chaque écriture :

1. Prépare les **taux de change historiques** du jour de la transaction (hors transaction SQL : un appel HTTP ne doit pas tenir un verrou).
2. Ouvre une transaction SQL et **verrouille la ligne de la position** (`lockForUpdate`) : deux ventes simultanées ne peuvent pas passer la vérification toutes les deux.
3. Applique l'écriture.
4. **Rejoue tout le journal** dans l'ordre chronologique (`executed_at`, puis `id`). Si la quantité détenue passe sous zéro **à un moment quelconque**, tout est annulé avec une 422 : « Vente impossible : au AAAA-MM-JJ, la quantité détenue sur cette position est insuffisante. »
5. Enregistre la quantité nette résultante dans `portfolio_entries.quantity`.

Conséquence : vendre le 1er mars ce qui n'a été acheté que le 5 mars est refusé, même si le total final est positif. Une tolérance de `1e-9` absorbe le bruit des flottants.

### Taux de change des transactions (`fx_rates`)

Dès qu'une transaction a un prix, le serveur stocke les taux **USD→EUR, USD→GBP, USD→CHF du jour de la transaction** (Frankfurter, cache permanent pour les jours passés). Ils sont recalculés si la date change, pas si seule la devise change (les trois taux sont toujours stockés ensemble). Indisponibles → **503**.

Ce champ est caché de l'API et n'est jamais accepté en entrée.

## Calcul de performance — `PerformanceCalculator`

Méthode du **prix moyen pondéré (PMP)**, celle de la fiscalité française sur les valeurs mobilières :

- **Achat** : la quantité et le coût (quantité × prix + frais) s'ajoutent ; le prix moyen se recalcule.
- **Vente** : la plus-value réalisée = quantité × (prix de vente − prix moyen) − frais. Le prix moyen ne change pas ; le coût diminue de quantité × prix moyen.
- **Frais** : toujours au détriment de l'investisseur (ajoutés au coût d'achat, retirés du produit de vente).

### Par devise, au taux de chaque transaction

Le calcul est fait **séparément dans chaque devise** (USD, EUR, GBP, CHF). Chaque transaction est convertie au taux **de sa propre date**, la valeur actuelle au taux du jour. Un investisseur en euros voit donc l'effet de change que le chiffre en dollars masquerait.

Conversion entre deux devises quelconques via l'USD : `montant / taux(source) × taux(cible)`, avec USD = 1.

### Champs produits

Par position et par devise :

| Champ | Définition |
|---|---|
| `cost_basis` | Coût de la quantité encore détenue. |
| `average_buy_price` | `cost_basis / quantité détenue`, `null` si plus rien n'est détenu. |
| `unrealized_pnl` | Valeur actuelle − `cost_basis`. |
| `unrealized_pnl_percent` | `unrealized_pnl / cost_basis × 100`, `null` si coût nul. |
| `realized_pnl` | Cumul des plus/moins-values des ventes. |

Une position sans prix sur **au moins une** transaction a `performance: null`. Le total du portefeuille (`total()`) n'additionne que les positions connues et `positions_without_price` indique combien ont été exclues : elles ne sont pas comptées comme zéro.

## Résumé du portefeuille — `PortfolioService`

`getPortfolioSummary(User)` est le point d'entrée utilisé par `/api/portfolio`, l'allocation, les alertes de portefeuille et les digests. Il :

1. charge les positions avec actif, plateforme et transactions triées ;
2. récupère les prix USD de tous les actifs (groupés par fournisseur) ;
3. récupère les taux USD→EUR/GBP/CHF du jour ;
4. calcule les variations 1h/24h/7d du portefeuille (`PriceChangeCalculator::portfolioPercentChanges`) ;
5. calcule valeur et performance de chaque position ;
6. renvoie un `PortfolioSummary` (DTO immuable) : positions triées par valeur, totaux USD/EUR, totaux par devise, variations, performance globale, nombre de positions sans prix.

### Variation du portefeuille

Pour chaque fenêtre, la valeur de chaque position **au début de la fenêtre** est reconstruite à partir de sa propre variation (`prix_ancien = prix / (1 + variation)`), puis on compare la somme d'alors à la somme actuelle. Ce n'est **pas** une moyenne pondérée des pourcentages, qui sous-estimerait le résultat quand les actifs vont dans des sens opposés. Une variation ≤ −100 % est ignorée (division par zéro).

## Historique de valeur — `PortfolioHistoryService`

`GET /api/portfolio/history?range=7d|30d|90d|1y`

Rien n'est stocké : la courbe est **reconstruite à chaque requête** à partir des transactions et des cours de clôture quotidiens. Avantages : rien à planifier, courbe complète dès la première requête, et corriger une transaction passée corrige aussi le passé de la courbe.

Pour chaque jour J de la plage :

- **Valeur** = Σ (quantité détenue à J, d'après les transactions datées ≤ J) × clôture de J, convertie au taux de change **de J**.
- **Investi** = coût (PMP) de la quantité détenue à J, chaque transaction à son propre taux. L'écart entre les deux courbes est la plus-value latente dans le temps.

Détails :

- Les historiques de prix et de taux couvrent toujours **un an** (limite du plan gratuit CoinGecko) et sont mis en cache 1 h ; chaque plage n'en est qu'une tranche.
- **Remplissage vers l'avant** : un jour sans cotation (week-end, jour férié, aujourd'hui avant mise à jour) prend la dernière valeur connue ; les jours avant le premier point prennent le premier point.
- Une position détenue pendant la plage mais **sans historique** de cours est exclue et listée dans `missing_history` (la compter à zéro dessinerait un faux krach).
- `invested` vaut `null` pour la journée si une transaction sans prix est en jeu.
- Taux de change indisponibles → **503**.

## Migration des données existantes

La migration `2026_11_08_213810_create_portfolio_transactions_table` a créé, pour chaque position existante, une transaction d'achat unique (quantité actuelle, sans prix, devise du compte, date de création de la position). Ces positions affichent donc une performance inconnue tant que l'utilisateur ne renseigne pas un prix.

## Tests associés

`PerformanceCalculatorTest`, `PortfolioHistoryServiceTest`, `HistoricalExchangeRatesTest`, `ExchangeRatesTest`, `Unit/PortfolioTransactionReplayTest`.
