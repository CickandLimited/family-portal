@php
    $totals = $board['totals'] ?? [];
    $planCount = $totals['plan_count'] ?? 0;
@endphp

@if ($planCount === 0)
    <div class="sm:col-span-2 xl:col-span-4 rounded-lg border-2 border-dashed border-indigo-200 bg-indigo-50/60 p-6 text-center">
        <h3 class="text-lg font-semibold text-indigo-800">No plans yet</h3>
        <p class="mt-2 text-sm text-indigo-700">
            Import your first plan from the admin tools to start tracking XP and daily progress.
        </p>
        <a
            class="mt-4 inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700"
            href="{{ url('/admin/devices') }}"
        >
            Go to admin tools
        </a>
    </div>
@else
    @php($progress = $totals['active_plan_progress'] ?? [])
    <div class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Active plans</p>
        <p class="mt-2 text-3xl font-bold text-slate-900">{{ $totals['active_plan_count'] ?? 0 }}</p>
        <p class="mt-1 text-xs text-slate-500">{{ $planCount }} total plans</p>
        @if (($progress['total_subtasks'] ?? 0) > 0)
            <div class="mt-4 space-y-3">
                @include('components.progress-bar', [
                    'label' => 'Subtasks approved',
                    'current' => $progress['approved_subtasks'] ?? 0,
                    'target' => $progress['total_subtasks'] ?? 0,
                    'percent' => $progress['percent'] ?? null,
                    'unit' => 'tasks',
                    'size' => 'sm',
                ])
                @if (($progress['total_days'] ?? 0) > 0)
                    @include('components.progress-bar', [
                        'label' => 'Days unlocked',
                        'current' => $progress['completed_days'] ?? 0,
                        'target' => $progress['total_days'] ?? 0,
                        'percent' => $progress['day_percent'] ?? null,
                        'unit' => 'days',
                        'size' => 'sm',
                    ])
                @endif
            </div>
        @else
            <p class="mt-4 text-xs text-slate-500">Assign plans with subtasks to start tracking progress.</p>
        @endif
    </div>
    <div class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Family XP</p>
        <p class="mt-2 text-3xl font-bold text-slate-900">{{ $totals['family_total_xp'] ?? 0 }}</p>
        <p class="mt-1 text-xs text-slate-500">Combined across all users</p>
    </div>
    <div class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Family members</p>
        <p class="mt-2 text-3xl font-bold text-slate-900">{{ $totals['user_count'] ?? 0 }}</p>
        <p class="mt-1 text-xs text-slate-500">{{ !empty($board['has_any_users']) ? 'Active profiles' : 'Set up your first member' }}</p>
    </div>
    <div class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Linked devices</p>
        <p class="mt-2 text-3xl font-bold text-slate-900">{{ $totals['device_count'] ?? 0 }}</p>
        <p class="mt-1 text-xs text-slate-500">Connected check-in devices</p>
    </div>
@endif
