<x-mail::message :preheader="$preheader" :manage-notifications="true">
# {{ $headline }}

{{ $intro }}

<x-mail::stats :items="$stats" />

@if (! empty($allocation))
<x-mail::allocation :rows="$allocation" />
@endif

<x-mail::button :url="$actionUrl">
{{ $actionText }}
</x-mail::button>

<x-slot:subcopy>
{{ $note }} [Gérer mes alertes]({{ $alertsUrl }})
</x-slot:subcopy>
</x-mail::message>
