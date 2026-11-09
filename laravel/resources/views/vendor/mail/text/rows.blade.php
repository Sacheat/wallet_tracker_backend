@props(['rows'])
@foreach ($rows as $row)
- {{ $row['title'] }}@if (! empty($row['subtitle'])) ({{ $row['subtitle'] }})@endif : {{ $row['value'] }}@if (! empty($row['detail'])) · {{ $row['detail'] }}@endif

@endforeach
