@php($days = $plan['days'] ?? [])

@if (!empty($days))
    @foreach ($days as $day)
        @php
            $dayLocked = (bool) ($day['locked'] ?? false);
            $progress = $day['progress'] ?? [];
            $caption = ($progress['total_subtasks'] ?? 0) === 0
                ? 'No subtasks scheduled yet.'
                : null;
        @endphp
        <div
            x-data="{ open: {{ json_encode(!$dayLocked && $loop->first) }}, locked: {{ json_encode($dayLocked) }} }"
            class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow"
        >
            <button
                type="button"
                class="flex w-full flex-col gap-4 p-5 text-left transition sm:flex-row sm:items-center sm:justify-between"
                :class="locked ? 'cursor-not-allowed opacity-60' : 'hover:bg-slate-50'"
                @click="if (!locked) { open = !open }"
            >
                <div class="w-full space-y-2">
                    <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:gap-3">
                        <p class="text-lg font-semibold text-slate-900">
                            Day {{ ($day['index'] ?? 0) + 1 }} · {{ $day['title'] ?? 'Untitled day' }}
                        </p>
                        <p class="text-sm text-slate-500">
                            {{ $day['completed_subtasks'] ?? 0 }} of {{ $day['total_subtasks'] ?? 0 }} subtasks complete
                        </p>
                    </div>
                    @include('components.progress-bar', [
                        'label' => 'Day progress',
                        'current' => $progress['approved_subtasks'] ?? 0,
                        'target' => $progress['total_subtasks'] ?? 0,
                        'percent' => $progress['percent'] ?? null,
                        'unit' => 'tasks',
                        'size' => 'sm',
                        'caption' => $caption,
                    ])
                </div>
                <div class="flex items-center gap-3">
                    @if ($dayLocked)
                        <span class="inline-flex items-center rounded-full bg-slate-200 px-3 py-1 text-xs font-semibold text-slate-700">
                            Locked
                        </span>
                    @elseif (!empty($day['complete']))
                        <span class="inline-flex items-center rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-700">
                            Complete
                        </span>
                    @else
                        <span class="inline-flex items-center rounded-full bg-indigo-100 px-3 py-1 text-xs font-semibold text-indigo-700">
                            In Progress
                        </span>
                    @endif
                    <svg
                        class="h-5 w-5 text-slate-500 transition-transform"
                        :class="open ? 'rotate-180' : 'rotate-0'"
                        xmlns="http://www.w3.org/2000/svg"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke-width="1.5"
                        stroke="currentColor"
                    >
                        <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                    </svg>
                </div>
            </button>

            @if ($dayLocked)
                <div class="border-t border-slate-200 bg-slate-50 p-4 text-sm text-slate-500">
                    Complete the previous day to unlock these subtasks.
                </div>
            @endif

            <div x-cloak x-show="open" class="border-t border-slate-200">
                <ul class="divide-y divide-slate-200">
                    @forelse ($day['subtasks'] ?? [] as $subtask)
                        @include('components.subtask-item', ['subtask' => $subtask, 'dayLocked' => $dayLocked])
                    @empty
                        <li class="p-5 text-sm text-slate-500">
                            No subtasks scheduled for this day yet.
                        </li>
                    @endforelse
                </ul>
            </div>
        </div>
    @endforeach
@else
    <div class="rounded-2xl border border-dashed border-slate-300 bg-white p-6 text-center text-slate-500">
        This plan does not have any days yet.
    </div>
@endif
