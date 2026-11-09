@props(['rows'])
@foreach ($rows as $row)
- {{ $row['label'] }} : {{ $row['percent_text'] }}@if (! empty($row['target_text'])) / objectif {{ $row['target_text'] }} ({{ $row['drift_text'] }})@endif

@endforeach

