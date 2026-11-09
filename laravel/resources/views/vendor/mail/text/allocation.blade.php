@props(['rows'])
@foreach ($rows as $row)
- {{ $row['label'] }} : {{ $row['percent_text'] }} / objectif {{ $row['target_text'] }} ({{ $row['drift_text'] }})
@endforeach

