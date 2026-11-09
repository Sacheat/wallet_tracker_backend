# Déploiement

## Production actuelle : VPS de l'école

```
Navigateur ──► https://<domaine>
                 │   /            → SPA (fichiers statiques du build Vite)
                 │   /backend/*   → Laravel via PHP-FPM
                 ▼
               NGINX ──fastcgi──► PHP-FPM (laravel/public/index.php)  ──►  base de données
```

Frontend et API sont servis par le **même NGINX, sur le même domaine**. Le navigateur ne voit qu'une origine : le cookie de session Sanctum est un cookie « first-party » et aucune configuration inter-site n'est nécessaire.

Historique : l'app a d'abord tourné sur Vercel (front) + alwaysdata (API), avec une réécriture `vercel.json` et un secret de proxy pour rester même-site. Ce montage a été abandonné ; le code qui le gérait (`TrustVercelProxy`) reste en place mais ne fait rien tant que `PROXY_SECRET` est vide (voir plus bas).

### `.env` de production

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://<domaine>/backend

# Vide : NGINX parle directement à PHP-FPM, il n'y a pas de proxy HTTP intermédiaire.
PROXY_SECRET=

DB_CONNECTION=mysql            # ou mariadb
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=...
DB_USERNAME=...
DB_PASSWORD=...

SESSION_DOMAIN=null
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax
FRONTEND_URLS=https://<domaine>
SANCTUM_STATEFUL_DOMAINS=<domaine>

# E-mails via Brevo (SMTP)
MAIL_MAILER=smtp
MAIL_HOST=smtp-relay.brevo.com
MAIL_PORT=587
MAIL_USERNAME=<identifiant SMTP Brevo>
MAIL_PASSWORD=<clé SMTP Brevo>
MAIL_FROM_ADDRESS=<expéditeur vérifié dans Brevo>
MAIL_FROM_NAME="Portfolio Tracker"

QUEUE_CONNECTION=database
CACHE_STORE=database

LOG_STACK=daily
LOG_DAILY_DAYS=14
LOG_LEVEL=warning

GEMINI_API_KEY=...
COINGECKO_API_KEY=...
CRYPTOCOMPARE_API_KEY=...
MARKETAUX_API_KEY=...
```

Points importants :

- **`APP_URL`** est l'URL publique de l'API, **préfixe `/backend` compris**. Les liens des e-mails (vérification, désinscription) sont signés sur un chemin relatif puis préfixés par `APP_URL`.
- **`FRONTEND_URLS`** : la première entrée sert de base aux liens des e-mails qui pointent vers la SPA (`/?verified=1`, `/reset-password`, `/account`…).
- **`PROXY_SECRET` doit rester vide** sur ce montage. S'il était défini, chaque requête sans en-tête `X-Proxy-Secret` serait refusée en 403, et NGINX ne l'envoie pas.
- **Adresse IP client** : avec PHP-FPM en direct, `REMOTE_ADDR` est la vraie IP du visiteur. Les limites de requêtes par IP (`/login`, `/register`…) fonctionnent donc sans configuration. Si un jour un reverse proxy HTTP est placé devant (Cloudflare, `proxy_pass`…), il faudra configurer les proxys de confiance, sinon tous les visiteurs partageront la même IP et le même compteur.
- **Brevo** : le transport SMTP de Laravel suffit, aucun paquet supplémentaire. Le paquet `resend/resend-php` est encore installé ; il ne sert plus que si l'on repasse à `MAIL_MAILER=resend`.

### Étapes de déploiement

```bash
cd laravel
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan storage:link        # première fois uniquement
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart       # si un worker permanent tourne
```

`storage/` et `bootstrap/cache/` doivent être accessibles en écriture par l'utilisateur de PHP-FPM.

## Scheduler et file d'attente

> ⚠️ **À vérifier sur le VPS.** Sans `schedule:run` exécuté chaque minute, **aucun e-mail ne part** : vérification d'adresse, mot de passe oublié, alertes et digests sont tous mis en file d'attente. Les alertes et les snapshots de prix ne tournent pas non plus.

Pour savoir ce qui tourne :

```bash
crontab -l                                   # cron de l'utilisateur
sudo crontab -l -u www-data                  # ou de l'utilisateur PHP
systemctl --user list-timers                 # timers systemd utilisateur
systemctl list-timers | grep -i wallet
php artisan schedule:list                    # tâches et prochaine exécution
php artisan queue:monitor database:default   # taille de la file
```

Si la table `jobs` se remplit et ne se vide jamais, rien ne consomme la file.

Deux montages possibles ; le premier suffit.

### Option A : cron seul (le plus simple)

```
* * * * *  cd /chemin/vers/laravel && php artisan schedule:run >> /dev/null 2>&1
```

Le planificateur (`routes/console.php`) lance alors tout, y compris un passage de la file chaque minute :

| Fréquence | Commande |
|---|---|
| chaque minute | `queue:work --stop-when-empty --max-time=50` (`withoutOverlapping`) |
| toutes les 5 minutes | `alerts:check` (rafraîchit les prix en appels groupés, puis évalue les alertes ; sans chevauchement) |
| chaque heure | `snapshots:record` |
| 08:00 chaque jour | `digest:send daily` |
| lundi 08:00 | `digest:send weekly` |
| chaque jour | `queue:prune-failed --hours=168` |

Un e-mail part donc au plus une minute après la demande.

### Option B : systemd (worker permanent)

Un VPS autorise les processus permanents : un worker `queue:work` continu envoie les e-mails immédiatement. Le dépôt fournit des unités **systemd utilisateur** dans `laravel/deploy/systemd/` :

| Unité | Rôle |
|---|---|
| `wallet-tracker-scheduler.timer` | lance `schedule:run` chaque minute (`Persistent=true`) |
| `wallet-tracker-scheduler.service` | `php artisan schedule:run` (oneshot) |
| `wallet-tracker-queue.service` | `php artisan queue:work --sleep=3 --max-time=3600`, redémarré automatiquement |

Avant de lancer `install.sh`, adapter `WorkingDirectory` (actuellement `%h/Dev/wallet_tracker_backend/laravel`) et le chemin de `php` (`/bin/php`) au VPS.

```bash
laravel/deploy/systemd/install.sh
loginctl enable-linger $USER          # pour tourner sans session ouverte
journalctl --user -u wallet-tracker-queue.service -f
```

Le passage `queue:work` planifié chaque minute dans `routes/console.php` coexiste sans problème avec le worker permanent (les deux consomment la même file).

## Contraintes réseau

- **IPv4 forcé** pour tout appel sortant (`AppServiceProvider`). Ce réglage date d'alwaysdata, dont l'IPv6 était refusée par Google (403 « User location is not supported »). Il ne gêne pas sur le VPS : tous les fournisseurs utilisés répondent en IPv4.
- Sans `COINGECKO_API_KEY`, CoinGecko applique le quota de l'IP publique du VPS ; s'il est partagé avec d'autres projets de l'école, des 429 sont possibles.

## Vérifications après déploiement

- [ ] `GET https://<domaine>/backend/up` répond 200.
- [ ] Connexion depuis la SPA : le cookie de session est posé et `GET /backend/user` répond 200.
- [ ] Inscription → l'e-mail de vérification arrive en moins d'une minute et son lien ramène sur la SPA avec `?verified=1`.
- [ ] `php artisan schedule:list` affiche les tâches ; la table `jobs` se vide ; `failed_jobs` reste vide.
- [ ] Le lien de désinscription d'un e-mail d'alerte fonctionne.
- [ ] Les logos et avatars s'affichent (`storage:link`).

## Développement local

Le frontend en dev (`vite.config.ts`) relaie `/backend/*` vers `http://localhost:8000`, comme NGINX en production. Voir le [README](../README.md#démarrage-rapide).
