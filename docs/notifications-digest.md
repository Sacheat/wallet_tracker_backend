# Notifications & digests

## Canaux

| Canal | Condition | Implémentation |
|---|---|---|
| E-mail | `email_notifications_enabled` **et** e-mail vérifié | Mailer Laravel → SMTP Brevo en prod |
| Discord | `discord_webhook_url` renseigné | `App\Notifications\Channels\DiscordWebhookChannel` (POST `{content}` sur le webhook) |

Les préférences se règlent via `GET/PATCH /api/notification-settings`. L'URL Discord doit commencer par `https://discord.com/api/webhooks/` ou `https://discordapp.com/api/webhooks/`.

## Notifications existantes

| Classe | Déclencheur | Canaux |
|---|---|---|
| `PriceAlertTriggered` | `alerts:check` | e-mail, Discord |
| `PortfolioDigest` | `digest:send daily\|weekly` | e-mail, Discord |
| `QueuedVerifyEmail` | inscription, changement d'e-mail, renvoi | e-mail |
| `QueuedResetPassword` | `/forgot-password` | e-mail |

Toutes implémentent `ShouldQueue` et utilisent le trait `QueuedWithRetries` :

- Laravel crée **une tâche par canal** : un échec Discord est retenté seul, sans renvoyer l'e-mail.
- **3 tentatives**, avec 10 s puis 60 s d'attente.
- Après échec définitif : table `failed_jobs`, purgée après 7 jours.
- Le canal Discord ne masque pas les erreurs : une erreur HTTP fait échouer la tâche, qui est retentée.

## File d'attente

Driver `database`. Le planificateur vide la file chaque minute (montage hérité d'alwaysdata, qui interdisait les processus permanents ; il suffit aussi sur le VPS) :

```
queue:work --stop-when-empty --max-time=50   (everyMinute, withoutOverlapping)
```

Une tâche en attente de nouvelle tentative est simplement reprise par un passage suivant. Un worker permanent (`php artisan queue:work` ou le service systemd fourni) peut s'y ajouter pour un envoi immédiat. **Sans `schedule:run` chaque minute, aucun e-mail ne part** (voir [deploiement.md](deploiement.md#scheduler-et-file-dattente)).

## Digest de portefeuille

Commande : `digest:send {daily|weekly}` — tous les jours à 08:00 et le lundi à 08:00.

Pour chaque utilisateur abonné (`daily_digest_enabled` / `weekly_digest_enabled`) :

1. `PortfolioService::getPortfolioSummary()` ;
2. `PortfolioDigestService::build()` calcule **une seule fois**, au moment de l'envoi en file, toutes les données affichées (les tâches e-mail et Discord montrent exactement les mêmes chiffres) ;
3. rien n'est envoyé si aucune position n'est détenue (`null`) ;
4. notification `PortfolioDigest`.

### Contenu (dans la devise du compte)

| Bloc | Détail |
|---|---|
| Valeur totale | Total dans la devise du compte. |
| Variation | Sur 24 h (quotidien) ou 7 jours (hebdomadaire), en % et en montant (`total − total / (1 + p)`). |
| Plus-values | Latente (montant + %) et réalisée, au PMP. |
| Résumé IA | Paragraphe rédigé par Gemini, si disponible (voir [ia-gemini.md](ia-gemini.md#1-résumé-ia-du-digest)). |
| Hausses / baisses | 3 meilleurs et 3 pires actifs de la fenêtre (un actif sur deux plateformes compte une fois). |
| Répartition | Part par type, objectif et écart ; un écart ≥ 5 points est mis en avant. |
| Positions | 10 premières (e-mail) / 5 (Discord), avec plus-value depuis l'achat ou part du portefeuille, puis « et N autres ». |
| Avertissement | Nombre de positions sans prix d'achat. |

Les positions vendues jusqu'à zéro sont exclues (c'est de l'historique, pas une détention). Si le taux de change est indisponible, le digest passe en USD.

## Gabarits e-mail

| Fichier | Usage |
|---|---|
| `resources/views/mail/price-alert.blade.php` | Alerte, contenu adapté au type (prix, variation, portefeuille, répartition). |
| `resources/views/mail/portfolio-digest.blade.php` | Digest. |
| `resources/views/vendor/mail/` | Thème Markdown `portfolio` aux couleurs du frontend. |
| `resources/views/vendor/notifications/` | Gabarit des e-mails d'authentification. |
| `resources/views/unsubscribe.blade.php` | Page de désinscription (HTML). |

Marque (`config/mail.php`) : nom fixe « Portfolio Tracker » ; `mail.brand.frontend_url` = première entrée de `FRONTEND_URLS`, base de tous les liens des e-mails.

Mise en forme (`FormatsNumbersForHumans`) : convention française (espace pour les milliers, virgule décimale), symbole après le montant, 2 décimales (jusqu'à 8 sous 1 pour les petites cryptos), signe explicite pour les gains/pertes.

Sécurité (`EscapesMarkdown`) : noms d'actifs/plateformes (catalogue modifiable par tous) et texte IA (construit à partir de titres d'actualités tiers) sont échappés là où le texte est interprété comme du Markdown.

## Désinscription

Chaque e-mail d'alerte et de digest contient (trait `OffersUnsubscribe`) :

- un lien de désinscription en pied de page ;
- les en-têtes `List-Unsubscribe` et `List-Unsubscribe-Post: List-Unsubscribe=One-Click` (attendus par Gmail et Yahoo pour les envois récurrents).

Fonctionnement (`EmailUnsubscribeController`) :

- Lien **signé, sans expiration**, relatif puis préfixé par `APP_URL`.
- `GET` affiche seulement une confirmation (les scanners de messagerie ouvrent les liens tout seuls).
- `POST` désinscrit : depuis le bouton de la page, ou directement depuis le client mail (RFC 8058). Cette route est exemptée de CSRF ; c'est la signature qui la protège.
- Effet : `email_notifications_enabled = false`. **Discord et les e-mails de sécurité** (vérification, réinitialisation) **continuent**. Réactivable depuis la page compte.
- Signature invalide → page « lien invalide » en 403.

## Tester en local

Avec `MAIL_MAILER=log`, les e-mails apparaissent dans `storage/logs/laravel.log`.

```bash
php artisan digest:send daily
php artisan alerts:check
php artisan queue:work --stop-when-empty
```

## Tests associés

`PortfolioDigestTest`, `PriceAlertMailTest`, `MailBrandingTest`, `EmailUnsubscribeTest`, `EscapesMarkdownTest`.
