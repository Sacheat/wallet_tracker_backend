{{--
    List of lines, title on the left and figure on the right. rows: list of
    ['title' => string, 'subtitle' => ?string, 'value' => string,
    'value_tone' => ?'gain'|'loss'|'muted', 'detail' => ?string,
    'detail_tone' => ?'gain'|'loss'|'muted'].
--}}
@props(['rows'])
<table class="rows" width="100%" cellpadding="0" cellspacing="0" role="presentation">
@foreach ($rows as $row)
<tr>
<td class="row-main" valign="top">
<p class="row-title">{{ $row['title'] }}</p>
@if (! empty($row['subtitle']))
<p class="row-sub">{{ $row['subtitle'] }}</p>
@endif
</td>
<td class="row-figures" valign="top" align="right">
<p class="row-value tone-{{ $row['value_tone'] ?? 'default' }}">{{ $row['value'] }}</p>
@if (! empty($row['detail']))
<p class="row-detail tone-{{ $row['detail_tone'] ?? 'muted' }}">{{ $row['detail'] }}</p>
@endif
</td>
</tr>
@endforeach
</table>
