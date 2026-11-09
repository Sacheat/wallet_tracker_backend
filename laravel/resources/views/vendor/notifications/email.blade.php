{{--
    Every MailMessage-based email (verification, password reset, alerts,
    digests) goes through this view. viewData keys it understands:
    preheader (string) and manageNotifications (bool), see mail::message.
--}}
<x-mail::message :preheader="$preheader ?? null" :manage-notifications="$manageNotifications ?? false">
{{-- Greeting --}}
@if (! empty($greeting))
# {{ $greeting }}
@elseif ($level === 'error')
# Oups !
@else
# Bonjour,
@endif

{{-- Intro Lines --}}
@foreach ($introLines as $line)
{{ $line }}

@endforeach

{{-- Action Button --}}
@isset($actionText)
<?php
    $color = match ($level) {
        'success', 'error' => $level,
        default => 'primary',
    };
?>
<x-mail::button :url="$actionUrl" :color="$color">
{{ $actionText }}
</x-mail::button>
@endisset

{{-- Outro Lines --}}
@foreach ($outroLines as $line)
{{ $line }}

@endforeach

{{-- Salutation --}}
@if (! empty($salutation))
{{ $salutation }}
@else
À bientôt,<br>
L'équipe {{ config('mail.brand.name') }}
@endif

{{-- Subcopy --}}
@isset($actionText)
<x-slot:subcopy>
Si le bouton « {{ $actionText }} » ne fonctionne pas, copiez ce lien dans votre navigateur : <span class="break-all">[{{ $displayableActionUrl }}]({{ $actionUrl }})</span>
</x-slot:subcopy>
@endisset
</x-mail::message>
