@php
    $displayLabel = $label ?? 'Progress';
    $unitLabel = $unit ?? null;
    $valueLabel = $value ?? null;
    $captionText = $caption ?? null;
    $sizeValue = $size ?? 'md';
    $accentClass = $accent ?? 'bg-indigo-500';
    $currentValue = $current ?? null;
    $targetValue = $target ?? null;
    $showRatio = $currentValue !== null && $targetValue !== null;
    $percentValue = $percent ?? null;

    if ($percentValue === null && $showRatio && (int) $targetValue !== 0) {
        $percentValue = ($currentValue / $targetValue) * 100;
    }

    if ($percentValue === null) {
        $percentValue = 0;
    }

    $percentValue = max(0, min(100, (float) $percentValue));
    $percentDisplay = (int) floor($percentValue);

    $trackHeight = match ($sizeValue) {
        'sm' => 'h-1.5',
        'lg' => 'h-3',
        default => 'h-2',
    };

    $trackClasses = $trackHeight . ' w-full rounded-full bg-slate-200';
    $barClasses = $trackHeight . ' rounded-full ' . $accentClass . ' transition-all duration-300';
@endphp

<div class="w-full">
    <div class="flex items-center justify-between text-xs text-slate-500">
        <span class="font-medium text-slate-600">{{ $displayLabel }}</span>
        @if ($valueLabel !== null)
            <span>{{ $valueLabel }}</span>
        @elseif ($showRatio)
            <span>{{ $currentValue }} / {{ $targetValue }}@if ($unitLabel) {{ $unitLabel }}@endif</span>
        @else
            <span>{{ $percentDisplay }}%</span>
        @endif
    </div>
    <div class="mt-2 {{ $trackClasses }}" aria-hidden="true">
        <div
            class="{{ $barClasses }}"
            style="width: {{ $percentValue }}%;"
            role="progressbar"
            aria-valuenow="{{ $percentDisplay }}"
            aria-valuemin="0"
            aria-valuemax="100"
        ></div>
    </div>
    @if ($captionText)
        <p class="mt-2 text-xs text-slate-500">{{ $captionText }}</p>
    @endif
</div>
