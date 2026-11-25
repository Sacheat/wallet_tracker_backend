# Authentification & sécurité

## Modèle d'authentification

L'API utilise **Laravel Sanctum en mode SPA** : pas de token Bearer, mais un **cookie de session** protégé par **CSRF**. `$middleware->statefulApi()` active ce mode pour `/api/*`.

### Flux côté frontend

```
1. GET  /sanctum/csrf-cookie            → cookie XSRF-TOKEN
2. POST /login  (header X-XSRF-TOKEN)   → cookie de session
3. GET  /api/... (cookies envoyés, withCredentials: true)
```

Conditions pour que ça marche :

- Le client envoie les cookies (`credentials: 'include'` / `withCredentials: true`) et l'en-tête `Accept: application/json`.
- L'origine du frontend est dans `FRONTEND_URLS` (CORS, `supports_credentials: true`) et son domaine dans `SANCTUM_STATEFUL_DOMAINS`.
- Navigateur et API doivent être **même site**. En production, front et API sont servis par le même NGINX sur le même domaine (`/backend/*`), donc c'est le cas naturellement (voir [deploiement.md](deploiement.md)).

> Toute nouvelle route de compte hors `/api` doit être ajoutée à `paths` dans `config/cors.php`, sinon le navigateur la bloque (curl, lui, continue de fonctionner, d'où des bugs difficiles à voir).

### Routes nommées « techniques »

- `login` : le middleware d'authentification calcule `route('login')` même pour une requête API. Sans cette route nommée, chaque 401 se transformerait en 500.
- `verification.notice` (`GET /email/verify`) : même raison pour le middleware `verified`.

### Session et « se souvenir de moi »

- Durée de session : `SESSION_LIFETIME` (120 min par défaut).
- Cookie « remember » : **13 mois** (`config/auth.php`), plafond habituel de la CNIL.
- `AuthenticateSession` est appliqué sur les routes de compte (`routes/web.php`) comme Sanctum le fait sur `/api/*` : un changement ou une réinitialisation de mot de passe invalide les autres sessions ouvertes.

## Vérification de l'e-mail

- L'inscription envoie un e-mail de vérification (en file d'attente).
- **Toutes les routes `/api/*` exigent un e-mail vérifié.** Les routes de compte (`/user`, `/user/export`, renvoi du lien…) restent accessibles avant vérification.
- Le lien est **signé relativement** (`signed:relative`) puis préfixé par `APP_URL` : l'API est servie sous le préfixe `/backend`, et le lien doit pointer vers l'URL publique. Durée de validité : 60 minutes.
- Le lien redirige vers `{FRONTEND}/?verified=1`.
- **Changer d'e-mail** exige le mot de passe actuel, remet `email_verified_at` à `null` et renvoie un e-mail. Les anciens liens deviennent invalides (ils contiennent un hash de l'adresse).
- Les notifications (alertes, digests) ne partent par e-mail **que vers une adresse vérifiée** : on ne peut pas rediriger ses alertes vers la boîte d'un inconnu.

## Mots de passe

Politique unique définie dans `AppServiceProvider` (`Password::defaults()`), alignée sur NIST SP 800-63B :

- 15 caractères minimum (c'est le seul facteur d'authentification), 64 maximum ;
- aucune règle de composition (majuscule, chiffre…) ;
- vérifié contre HaveIBeenPwned par k-anonymity (seuls les 5 premiers caractères du SHA-1 sortent du serveur), en « fail open » si le service est indisponible.

Réinitialisation :
- `/forgot-password` répond toujours le même message, que le compte existe ou non.
- L'e-mail part en file d'attente (réduit aussi l'écart de temps de réponse entre adresse connue et inconnue).
- Le lien pointe vers le frontend : `{FRONTEND}/reset-password?token=…&email=…`. Validité : 60 minutes.

## Autorisations

| Ressource | Règle |
|---|---|
| Positions, transactions | Propriétaire uniquement (`PortfolioEntryPolicy`). Une transaction hérite des droits de sa position. |
| Alertes | Propriétaire uniquement (`PriceAlertPolicy`). |
| Actifs, plateformes | Lecture et création : tout utilisateur vérifié. Modification/suppression : voir [catalogue.md](catalogue.md#droits). |
| Journal d'audit global | Admin uniquement. |

Le rôle admin ne s'attribue qu'en ligne de commande : `php artisan user:admin <email> [--revoke]`.

Protection contre le mass assignment : `user_id`, `is_admin`, `created_by`, `logo_path`, `avatar_path`, `fx_rates`, `allocation_targets` et `onboarding_completed_at` ne sont jamais remplis depuis la requête. Les ressources sont créées via la relation (`$user->priceAlerts()->create(...)`).

## Rate limiting

| Limiteur | Limite | Clé | Routes |
|---|---|---|---|
| `api` | 60/min | utilisateur (ou IP) | toutes les routes `/api/*` |
| `auth` | 5/min | IP | `/register`, `/login`, `/forgot-password`, `/reset-password` |
| `catalog-writes` | 10/min | utilisateur | création d'actif/plateforme, upload/suppression de logo |
| `ai-parse` | 10/min | utilisateur | `POST /api/price-alerts/parse` |
| `data-export` | 3/min | utilisateur | `GET /user/export` |
| inline `6,1` | 6/min | utilisateur | `PATCH /user`, `PATCH /user/password`, `DELETE /user`, renvoi de vérification, lien de vérification |
| inline `20,1` | 20/min | IP | désinscription |

Les limiteurs nommés ont chacun leur compteur ; c'est pour cela qu'ils sont préférés aux limites inline (qui partagent un compteur par utilisateur). Les en-têtes `Retry-After`, `X-RateLimit-Limit` et `X-RateLimit-Remaining` sont exposés en CORS pour que la SPA puisse afficher un compte à rebours.

## IP client et proxys de confiance

En production, NGINX passe les requêtes **directement à PHP-FPM** : `REMOTE_ADDR` est la vraie IP du visiteur, donc `$request->ip()` et les limites par IP fonctionnent sans réglage.

`bootstrap/app.php` ne fait confiance qu'à un proxy local (`127.0.0.1`, `::1`), et seulement pour `X-Forwarded-For` et `X-Forwarded-Proto` : un `proxy_pass` NGINX sur la même machine transmettrait ainsi la vraie IP, tandis qu'un `X-Forwarded-For` envoyé par un client est ignoré. `X-Forwarded-Host` n'est jamais pris en compte.

Si un reverse proxy distant est un jour ajouté devant l'API (Cloudflare…), il faudra ajouter ses adresses à cette liste. Sinon, tous les visiteurs auront l'IP du proxy et partageront le même compteur de limite.

## En-têtes et contenus

- **`Cache-Control: no-store, private`** sur toutes les réponses (`PreventBrowserCaching`), y compris les erreurs : données financières et état d'authentification ne doivent jamais être écrits sur disque ni dans le cache précédent/suivant du navigateur.
- **Exceptions toujours en JSON** : pas de redirection HTML vers une page de login inexistante.
- **Uploads** : contenu vérifié (pas seulement l'extension), SVG refusé (script embarqué possible), nom de fichier aléatoire généré par le serveur.
- **URLs d'actualités** : seuls les schémas `http`/`https` sont conservés (`NewsService`), contre les liens `javascript:`/`data:` dans un flux tiers.
- **Markdown des e-mails** : noms d'actifs et texte IA échappés (`EscapesMarkdown`) pour qu'un nom de catalogue piégé ne devienne pas un lien cliquable dans un vrai e-mail.
- **`external_id` d'un actif** : limité à `[A-Za-z0-9._=^-]` et encodé avec `rawurlencode` avant d'être placé dans une URL de fournisseur.
- **Clés d'API** : toujours envoyées en en-tête, jamais en query string (une erreur de connexion écrit l'URL dans les logs).

## Journal d'audit

Le trait `Auditable` enregistre chaque création, modification et suppression d'actif, plateforme, position, transaction et alerte : qui, quoi, quand, avec les champs modifiés. `source` vaut `web` ou `console` (action du planificateur, par exemple une alerte qui se déclenche). À la suppression d'un compte, les lignes sont conservées avec `user_id = null`.

## RGPD

| Droit / obligation | Mise en œuvre |
|---|---|
| Portabilité (art. 20) | `GET /user/export` : compte, préférences, objectifs, positions et transactions, alertes, journal d'audit, au format JSON téléchargeable. Le catalogue partagé n'y figure que par nom. Les sessions (IP, user agent) sont exclues. |
| Effacement | `DELETE /user` (mot de passe requis) : positions, transactions et alertes supprimées en cascade, avatar supprimé, journal anonymisé. |
| Rétention des logs | 14 jours (`LOG_STACK=daily`, `LOG_DAILY_DAYS=14`). |
| Rétention des jobs échoués | 7 jours (`queue:prune-failed --hours=168`) : ils contiennent destinataire et montants. |
| Cookie « remember » | 13 mois. |
| Désinscription | Lien en un clic dans chaque e-mail de notification (voir [notifications-digest.md](notifications-digest.md#désinscription)). |
