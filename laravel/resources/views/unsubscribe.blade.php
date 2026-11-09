<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Notifications par e-mail · {{ config('mail.brand.name') }}</title>
<link href="https://fonts.googleapis.com/css2?family=Geist:wght@400;600;700&display=swap" rel="stylesheet">
<style>
    /* Same tokens as the emails and the frontend's light theme. */
    :root { --bg: #F5F6F8; --card: #FFFFFF; --fg: #12141A; --muted: #6B7280; --border: #E4E6EC; --primary: #6C5CE7; --gain: #006300; --loss: #d03b3b; }
    * { box-sizing: border-box; }
    body { margin: 0; min-height: 100vh; background: var(--bg); color: var(--fg); font-family: 'Geist', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; display: flex; flex-direction: column; align-items: center; padding: 48px 16px; }
    .brand { display: flex; align-items: center; gap: 10px; font-weight: 700; font-size: 17px; color: var(--fg); text-decoration: none; margin-bottom: 24px; }
    .brand img { width: 32px; height: 32px; border-radius: 8px; }
    .card { width: 100%; max-width: 480px; background: var(--card); border: 1px solid var(--border); border-top: 4px solid var(--primary); border-radius: 10px; padding: 32px; }
    h1 { font-size: 21px; margin: 0 0 12px; letter-spacing: -0.01em; }
    p { line-height: 1.6; margin: 0 0 14px; }
    .muted { color: var(--muted); font-size: 14px; }
    .email { font-weight: 600; }
    button, .button { display: inline-block; border: 0; border-radius: 8px; background: var(--primary); color: #fff; font: inherit; font-weight: 600; font-size: 15px; padding: 12px 24px; cursor: pointer; text-decoration: none; margin-top: 6px; }
    button:hover, .button:hover { filter: brightness(1.08); }
    a { color: var(--primary); }
    .status { font-weight: 600; }
    .status.ok { color: var(--gain); }
    .status.error { color: var(--loss); }
</style>
</head>
<body>
<a class="brand" href="{{ config('mail.brand.frontend_url') }}"><img src="{{ url('images/logo-email.png') }}" alt="">{{ config('mail.brand.name') }}</a>
<main class="card">
@switch ($state)
    @case('confirm')
        <h1>Ne plus recevoir nos e-mails de notification ?</h1>
        <p>Les alertes et les résumés de portefeuille ne seront plus envoyés à <span class="email">{{ $email }}</span>.</p>
        <p class="muted">Les notifications Discord, si vous en avez, et les e-mails liés à la sécurité de votre compte continueront d'arriver.</p>
        <form method="POST" action="{{ $actionUrl }}">
            <button type="submit">Me désabonner</button>
        </form>
        @break
    @case('done')
        <h1>C'est fait</h1>
        <p class="status ok">Vous ne recevrez plus d'alertes ni de résumés par e-mail à <span class="email">{{ $email }}</span>.</p>
        <p class="muted">Changé d'avis ? Vous pouvez les réactiver à tout moment dans votre compte.</p>
        <a class="button" href="{{ $accountUrl }}">Gérer mes notifications</a>
        @break
    @default
        <h1>Lien invalide</h1>
        <p class="status error">Ce lien de désinscription est incomplet ou a été modifié.</p>
        <p class="muted">Vous pouvez gérer vos notifications directement depuis votre compte.</p>
        <a class="button" href="{{ $accountUrl }}">Gérer mes notifications</a>
@endswitch
</main>
</body>
</html>
