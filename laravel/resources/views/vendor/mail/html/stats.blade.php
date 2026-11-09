{{--
    Row of figure cards. items: list of ['label' => string, 'value' => string,
    'tone' => 'default'|'accent'|'gain'|'loss', 'hint' => ?string].
    Stacks vertically on narrow screens (see layout's media query).
--}}
@props(['items'])
<table class="stats" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
@foreach ($items as $item)
@if (! $loop->first)
<td class="stat-gap" width="10">&nbsp;</td>
@endif
<td class="stat" valign="top">
<p class="stat-label">{{ $item['label'] }}</p>
<p class="stat-value stat-{{ $item['tone'] ?? 'default' }}">{{ $item['value'] }}</p>
@if (! empty($item['hint']))
<p class="stat-hint">{{ $item['hint'] }}</p>
@endif
</td>
@endforeach
</tr>
</table>
