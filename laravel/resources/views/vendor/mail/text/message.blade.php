@props(['preheader' => null, 'manageNotifications' => false, 'unsubscribeUrl' => null])
<x-mail::layout>
    {{-- Header --}}
    <x-slot:header>
        <x-mail::header :url="config('mail.brand.frontend_url')">
            {{ config('mail.brand.name') }}
        </x-mail::header>
    </x-slot:header>

    {{-- Body --}}
    {{ $slot }}

    {{-- Subcopy --}}
    @isset($subcopy)
        <x-slot:subcopy>
            <x-mail::subcopy>
                {{ $subcopy }}
            </x-mail::subcopy>
        </x-slot:subcopy>
    @endisset

    {{-- Footer --}}
    <x-slot:footer>
        <x-mail::footer>
{{ config('mail.brand.name') }} · {{ config('mail.brand.frontend_url') }}
@if ($manageNotifications)
Gérer mes notifications : {{ config('mail.brand.frontend_url') }}/account
@endif
@if ($unsubscribeUrl)
Se désabonner : {{ $unsubscribeUrl }}
@endif
        </x-mail::footer>
    </x-slot:footer>
</x-mail::layout>
