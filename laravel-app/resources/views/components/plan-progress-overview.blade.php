@php($progress = $plan['progress'] ?? [])
<div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
    <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Plan Progress</p>
        <div class="mt-3 space-y-3">
            @include('components.progress-bar', [
                'label' => 'Plan progress',
                'percent' => $progress['percent'] ?? 0,
                'value' => isset($progress['percent']) ? ($progress['percent'] . '%') : null,
                'size' => 'lg',
            ])
            <p class="text-xs text-slate-500">
                {{ $progress['approved_subtasks'] ?? 0 }} of {{ $progress['total_subtasks'] ?? 0 }} subtasks approved.
            </p>
        </div>
    </div>
    <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Days Complete</p>
        <div class="mt-3 space-y-3">
            @php
                $dayCaption = ($progress['total_days'] ?? 0) === 0
                    ? 'No days scheduled yet.'
                    : ($progress['completed_days'] ?? 0) . ' of ' . ($progress['total_days'] ?? 0) . ' days unlocked.';
            @endphp
            @include('components.progress-bar', [
                'label' => 'Days complete',
                'current' => $progress['completed_days'] ?? 0,
                'target' => $progress['total_days'] ?? 0,
                'percent' => $progress['day_percent'] ?? null,
                'unit' => 'days',
                'caption' => $dayCaption,
            ])
        </div>
    </div>
    <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Subtasks Approved</p>
        <div class="mt-3 space-y-3">
            @php
                $subtaskCaption = ($progress['total_subtasks'] ?? 0) === 0
                    ? 'Waiting for first approval.'
                    : ($progress['approved_subtasks'] ?? 0) . ' of ' . ($progress['total_subtasks'] ?? 0) . ' tasks complete.';
            @endphp
            @include('components.progress-bar', [
                'label' => 'Approved subtasks',
                'current' => $progress['approved_subtasks'] ?? 0,
                'target' => $progress['total_subtasks'] ?? 0,
                'percent' => $progress['percent'] ?? null,
                'unit' => 'tasks',
                'caption' => $subtaskCaption,
            ])
        </div>
    </div>
    <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Total XP</p>
        <p class="mt-4 text-3xl font-bold text-slate-900">{{ $plan['total_xp'] ?? 0 }}</p>
        <p class="mt-2 text-xs text-slate-500">XP awarded across all approved subtasks.</p>
    </div>
</div>
