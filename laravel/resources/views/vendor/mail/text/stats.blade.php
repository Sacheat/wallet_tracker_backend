@props(['items'])
@foreach ($items as $item)
{{ $item['label'] }} : {{ $item['value'] }}@if (! empty($item['hint'])) ({{ $item['hint'] }})@endif

@endforeach
