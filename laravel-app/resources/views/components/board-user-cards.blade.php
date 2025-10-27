@php($users = $board['users'] ?? [])

@forelse ($users as $member)
    @php
        $activePlan = $member['active_plan'] ?? null;
        $planProgress = $activePlan['progress'] ?? null;
        $planCounts = $member['plan_counts'] ?? [];
        $xpHistory = $member['xp_history'] ?? [];
    @endphp
    <article class="flex h-full flex-col justify-between rounded-lg bg-white p-6 shadow-sm ring-1 ring-slate-200">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h3 class="text-lg font-semibold text-slate-900">{{ $member['display_name'] ?? 'Family member' }}</h3>
                <p class="text-sm text-slate-500">Level {{ $member['level'] ?? 0 }} • {{ $member['total_xp'] ?? 0 }} XP</p>
            </div>
            <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600">
                {{ $planCounts['active'] ?? 0 }} active
            </span>
        </div>

        <div class="mt-4 flex flex-col gap-3">
            @include('components.progress-bar', [
                'label' => 'Progress to next level',
                'current' => $member['xp_into_level'] ?? 0,
                'target' => 100,
                'percent' => $member['progress_percent'] ?? null,
            ])
            <p class="text-xs text-slate-500">
                {{ $member['xp_to_next_level'] ?? 0 }} XP to level {{ ($member['level'] ?? 0) + 1 }}
            </p>
        </div>

        <div class="mt-4 rounded-md border border-dashed border-slate-200 bg-slate-50 p-4">
            @if ($activePlan)
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Current plan</p>
                <p class="mt-1 text-sm font-semibold text-slate-900">{{ $activePlan['title'] ?? 'Plan' }}</p>
                <p class="text-xs text-slate-500">{{ $activePlan['status'] ?? 'Status unknown' }} • {{ $activePlan['total_xp'] ?? 0 }} XP total</p>
                @if ($planProgress && ($planProgress['total_subtasks'] ?? 0) > 0)
                    <div class="mt-3 space-y-2">
                        @include('components.progress-bar', [
                            'label' => 'Plan progress',
                            'current' => $planProgress['approved_subtasks'] ?? 0,
                            'target' => $planProgress['total_subtasks'] ?? 0,
                            'percent' => $planProgress['percent'] ?? null,
                            'unit' => 'tasks',
                            'size' => 'sm',
                        ])
                        @if (($planProgress['total_days'] ?? 0) > 0)
                            <p class="text-[11px] text-slate-500">
                                {{ $planProgress['completed_days'] ?? 0 }} of {{ $planProgress['total_days'] ?? 0 }} days complete
                            </p>
                        @endif
                    </div>
                @elseif ($planProgress)
                    <p class="mt-3 text-xs text-slate-500">No subtasks scheduled for this plan yet.</p>
                @endif
            @else
                <p class="text-sm text-slate-600">
                    No plan assigned yet. Import a plan from the admin tools to get {{ $member['display_name'] ?? 'them' }} started.
                </p>
            @endif
        </div>

        <div class="mt-4 rounded-md border border-slate-200 bg-white p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Recent XP</p>
            @if (!empty($xpHistory))
                <ul class="mt-2 space-y-2">
                    @foreach ($xpHistory as $event)
                        <li class="text-xs text-slate-600">
                            <div class="flex items-center justify-between gap-4">
                                <span class="font-semibold text-slate-700">{{ $event['label'] ?? 'XP event' }}</span>
                                <span class="font-semibold text-slate-900">+{{ $event['delta'] ?? 0 }} XP</span>
                            </div>
                            <p class="mt-1 text-[11px] text-slate-400">{{ $event['created_display'] ?? 'Recently' }}</p>
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="mt-2 text-xs text-slate-500">No XP activity recorded yet.</p>
            @endif
        </div>

        <div class="mt-4 flex flex-wrap items-center gap-3 text-xs text-slate-500">
            <span class="rounded-full bg-slate-100 px-2 py-1">{{ $planCounts['total'] ?? 0 }} total plans</span>
            <span class="rounded-full bg-slate-100 px-2 py-1">{{ $planCounts['completed'] ?? 0 }} completed</span>
            <span class="rounded-full bg-slate-100 px-2 py-1">{{ $member['device_count'] ?? 0 }} devices</span>
        </div>
    </article>
@empty
    <div class="sm:col-span-2 xl:col-span-3 rounded-lg border-2 border-dashed border-slate-300 bg-white/70 p-6 text-center shadow-sm">
        <h3 class="text-lg font-semibold text-slate-800">No family members yet</h3>
        <p class="mt-2 text-sm text-slate-600">
            Import a plan from the admin tools to create your first family member profile and begin tracking XP.
        </p>
        <a
            class="mt-4 inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700"
            href="{{ url('/admin/devices') }}"
        >
            Go to admin tools
        </a>
    </div>
@endforelse
