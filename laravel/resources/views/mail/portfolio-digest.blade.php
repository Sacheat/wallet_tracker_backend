<x-mail::message :preheader="$preheader" :manage-notifications="true">
# {{ $headline }}

<p class="dateline">{{ $dateline }}</p>

<x-mail::stats :items="$stats" />

@if ($aiSummary)
<x-mail::panel>
<p class="ai-label">Le point de l'assistant</p>

{{ $aiSummary }}
</x-mail::panel>
@endif

@if ($risers || $fallers)
## Plus fortes variations sur {{ $windowLabel }}

@if ($risers)
### En hausse

<x-mail::rows :rows="$risers" />
@endif

@if ($fallers)
### En baisse

<x-mail::rows :rows="$fallers" />
@endif
@endif

## Répartition

<x-mail::allocation :rows="$allocation" />

@unless ($hasTargets)
<p class="note">Fixez-vous des objectifs de répartition pour voir ici l'écart à la cible. <a href="{{ $allocationUrl }}">Définir mes objectifs</a></p>
@endunless

## Vos positions

<x-mail::rows :rows="$positions" />

@if ($morePositions > 0)
<p class="note">Et {{ $morePositions }} autre{{ $morePositions > 1 ? 's' : '' }} position{{ $morePositions > 1 ? 's' : '' }} dans l'application.</p>
@endif

@if ($positionsWithoutPrice > 0)
<p class="note">{{ $positionsWithoutPrice }} position{{ $positionsWithoutPrice > 1 ? 's ne sont' : ' n\'est' }} pas comptée{{ $positionsWithoutPrice > 1 ? 's' : '' }} dans la plus-value : prix d'achat à compléter dans l'application.</p>
@endif

<x-mail::button :url="$portfolioUrl">
Ouvrir mon portefeuille
</x-mail::button>

<x-slot:subcopy>
Vous recevez ce résumé {{ $frequencyLabel }}. [Changer la fréquence]({{ $accountUrl }})@if ($aiSummary) — Le point de l'assistant est rédigé par une IA (Gemini) à partir de vos chiffres et de l'actualité : ce n'est pas un conseil en investissement.@endif
</x-slot:subcopy>
</x-mail::message>
