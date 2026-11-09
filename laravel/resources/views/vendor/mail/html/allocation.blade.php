{{--
    Current share (and target, when set) per asset type, as bars. rows:
    list of ['label' => string, 'percent' => float, 'percent_text' => string,
    'target_text' => ?string, 'drift_text' => ?string,
    'off' => bool (gap at or over the alert's threshold)].
--}}
@props(['rows'])
<table class="allocation" width="100%" cellpadding="0" cellspacing="0" role="presentation">
@foreach ($rows as $row)
<tr>
<td class="allocation-label">{{ $row['label'] }}</td>
<td class="allocation-figures" align="right">{{ $row['percent_text'] }}@if (! empty($row['target_text'])) <span class="muted">/ objectif {{ $row['target_text'] }}</span> &nbsp;<span class="{{ $row['off'] ? 'drift-off' : 'drift-ok' }}">{{ $row['drift_text'] }}</span>@endif</td>
</tr>
<tr>
<td colspan="2" class="allocation-bar-cell">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" class="allocation-track">
<tr>
@if ($row['percent'] > 0)
<td class="allocation-fill {{ $row['off'] ? 'allocation-fill-off' : '' }}" width="{{ max(1, min(100, round($row['percent']))) }}%" height="8"></td>
@endif
@if ($row['percent'] < 100)
<td height="8"></td>
@endif
</tr>
</table>
</td>
</tr>
@endforeach
</table>
