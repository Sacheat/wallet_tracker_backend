{{--
    preheader: the grey line inboxes show after the subject.
    manageNotifications: notification emails (alerts, digests) link to the
    settings page where they can be switched off; account emails don't.
--}}
@props(['preheader' => null, 'manageNotifications' => false])
@php($frontendUrl = config('mail.brand.frontend_url'))
<x-mail::layout>
{{-- Preheader --}}
@if ($preheader)
<x-slot:preheader>
<div style="display: none; max-height: 0; max-width: 0; opacity: 0; overflow: hidden; mso-hide: all;">{{ $preheader }}{!! str_repeat('&#847;&zwnj;&nbsp;', 60) !!}</div>
</x-slot:preheader>
@endif

{{-- Header --}}
<x-slot:header>
<x-mail::header :url="$frontendUrl">
{{ config('mail.brand.name') }}
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
**{{ config('mail.brand.name') }}** · suivi de portefeuille crypto, actions, ETF et or

[Ouvrir l'application]({{ $frontendUrl }})@if ($manageNotifications) · [Gérer mes notifications]({{ $frontendUrl }}/account)@endif


@if ($manageNotifications)
Vous recevez cet e-mail parce que les notifications par e-mail sont activées sur votre compte.
@else
Cet e-mail concerne la sécurité de votre compte : il est envoyé même si les notifications sont désactivées.
@endif
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
