@if (empty($items))
    <div class="rounded-2xl border border-dashed border-emerald-300 bg-emerald-50 px-6 py-10 text-center">
        <h2 class="text-xl font-semibold text-emerald-800">The queue is clear!</h2>
        <p class="mt-2 text-sm text-emerald-700">No submissions are waiting for review right now.</p>
    </div>
@else
    <ul class="space-y-6">
        @foreach ($items as $item)
            @php
                $latest = $item['latest_submission'];
                $approvalDisabled = ! $item['approval_allowed'];
                $planProgress = $item['plan_progress'];
                $dayProgress = $item['day_progress'];
                $approveAction = route('review.approve', ['subtask' => $item['subtask_id']]);
                $denyAction = route('review.deny', ['subtask' => $item['subtask_id']]);
            @endphp
            <li class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div class="space-y-3">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                            Plan #{{ $item['plan_id'] }} • Day {{ $item['day_number'] }} – {{ $item['day_title'] }}
                        </p>
                        <h2 class="text-xl font-semibold text-slate-900">{{ $item['subtask_text'] }}</h2>
                        <p class="text-sm text-slate-500">
                            Worth {{ $item['xp_value'] }} XP
                            @if (! empty($item['assignee_name']))
                                • Assigned to {{ $item['assignee_name'] }}
                            @endif
                        </p>
                        <div class="space-y-2">
                            @include('components.progress-bar', [
                                'label' => 'Plan progress',
                                'current' => $planProgress['approved_subtasks'],
                                'target' => $planProgress['total_subtasks'],
                                'percent' => $planProgress['percent'],
                                'unit' => 'tasks',
                                'size' => 'sm',
                            ])
                            @if ($planProgress['total_days'] > 0)
                                @include('components.progress-bar', [
                                    'label' => 'Day progress',
                                    'current' => $dayProgress['approved_subtasks'],
                                    'target' => $dayProgress['total_subtasks'],
                                    'percent' => $dayProgress['percent'],
                                    'unit' => 'tasks',
                                    'size' => 'sm',
                                ])
                            @endif
                        </div>
                    </div>
                    <span class="inline-flex items-center rounded-full bg-indigo-100 px-3 py-1 text-xs font-semibold text-indigo-700">
                        Awaiting Review
                    </span>
                </div>

                <div class="mt-4 grid gap-6 lg:grid-cols-2">
                    <div class="space-y-4">
                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600">
                            <p class="font-semibold text-slate-800">Latest submission</p>
                            <p class="mt-1">Submitted by {{ $latest['submitted_by'] }}</p>
                            @if (! empty($latest['submitted_display']))
                                <p class="text-xs text-slate-500">{{ $latest['submitted_display'] }}</p>
                            @endif
                            @if (! empty($latest['device_label']))
                                <p class="mt-2 text-xs text-slate-500">
                                    Device: {{ $latest['device_label'] }}@if (! empty($latest['device_linked_user'])) • Linked to {{ $latest['device_linked_user'] }}@endif
                                </p>
                            @endif
                            @if (! empty($latest['comment']))
                                <p class="mt-3 text-slate-700">“{{ $latest['comment'] }}”</p>
                            @endif
                        </div>

                        @if (! empty($latest['photo_path']))
                            <div>
                                <p class="text-sm font-semibold text-slate-700">Photo evidence</p>
                                <img
                                    src="{{ $latest['photo_path'] }}"
                                    alt="Submission photo for {{ $item['subtask_text'] }}"
                                    class="mt-2 w-full rounded-xl border border-slate-200 object-cover"
                                >
                            </div>
                        @endif
                    </div>

                    <div class="space-y-5">
                        @if (! $item['approval_allowed'])
                            <div class="rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                                {{ $item['approval_message'] ?? 'Approval is currently blocked for this submission.' }}
                            </div>
                        @endif

                        <form
                            method="post"
                            action="{{ $approveAction }}"
                            class="space-y-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-4"
                            hx-post="{{ $approveAction }}"
                            hx-swap="none"
                            hx-disabled-elt="button, fieldset, textarea"
                        >
                            @csrf
                            <input type="hidden" name="submission_id" value="{{ $latest['id'] }}">
                            <fieldset class="space-y-2">
                                <legend class="text-sm font-semibold text-emerald-900">How did this submission make you feel?</legend>
                                <div class="flex flex-wrap gap-3 text-sm">
                                    @foreach ($moodOptions as $mood)
                                        @php
                                            $isChecked = ($mood['value'] ?? '') === $defaultMood;
                                        @endphp
                                        <label class="inline-flex items-center gap-2 rounded-lg bg-white px-3 py-2 shadow-sm">
                                            <input
                                                type="radio"
                                                name="mood"
                                                value="{{ $mood['value'] ?? '' }}"
                                                @checked($isChecked)
                                                required
                                                @disabled($approvalDisabled)
                                            >
                                            <span class="font-medium text-slate-700">{{ $mood['label'] ?? $mood['value'] ?? '' }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            </fieldset>
                            <div class="space-y-2">
                                <label for="approval-notes-{{ $item['subtask_id'] }}" class="text-sm font-semibold text-emerald-900">Optional note</label>
                                <textarea
                                    id="approval-notes-{{ $item['subtask_id'] }}"
                                    name="notes"
                                    rows="2"
                                    class="w-full rounded-lg border border-emerald-200 px-3 py-2 text-sm text-slate-900 focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-200"
                                    placeholder="Share any encouragement or observations"
                                    @disabled($approvalDisabled)
                                ></textarea>
                            </div>
                            <div class="flex justify-end">
                                <button
                                    type="submit"
                                    class="inline-flex items-center gap-2 rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-emerald-500 disabled:cursor-not-allowed disabled:opacity-60"
                                    @disabled($approvalDisabled)
                                >
                                    Approve &amp; award XP
                                </button>
                            </div>
                        </form>

                        <form
                            method="post"
                            action="{{ $denyAction }}"
                            class="space-y-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-4"
                            hx-post="{{ $denyAction }}"
                            hx-swap="none"
                            hx-disabled-elt="button, fieldset, textarea"
                        >
                            @csrf
                            <input type="hidden" name="submission_id" value="{{ $latest['id'] }}">
                            <fieldset class="space-y-2">
                                <legend class="text-sm font-semibold text-rose-900">Mood when denying</legend>
                                <div class="flex flex-wrap gap-3 text-sm">
                                    @foreach ($moodOptions as $mood)
                                        @php
                                            $isChecked = ($mood['value'] ?? '') === $defaultMood;
                                        @endphp
                                        <label class="inline-flex items-center gap-2 rounded-lg bg-white px-3 py-2 shadow-sm">
                                            <input
                                                type="radio"
                                                name="mood"
                                                value="{{ $mood['value'] ?? '' }}"
                                                @checked($isChecked)
                                                required
                                                @disabled($approvalDisabled)
                                            >
                                            <span class="font-medium text-slate-700">{{ $mood['label'] ?? $mood['value'] ?? '' }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            </fieldset>
                            <div class="space-y-2">
                                <label for="deny-reason-{{ $item['subtask_id'] }}" class="text-sm font-semibold text-rose-900">Reason for denial</label>
                                <textarea
                                    id="deny-reason-{{ $item['subtask_id'] }}"
                                    name="reason"
                                    rows="3"
                                    class="w-full rounded-lg border border-rose-200 px-3 py-2 text-sm text-slate-900 focus:border-rose-400 focus:outline-none focus:ring-2 focus:ring-rose-200"
                                    placeholder="Explain what needs to change before approval"
                                    required
                                    @disabled($approvalDisabled)
                                ></textarea>
                            </div>
                            <div class="flex justify-end">
                                <button
                                    type="submit"
                                    class="inline-flex items-center gap-2 rounded-md bg-rose-600 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-rose-500 disabled:cursor-not-allowed disabled:opacity-60"
                                    @disabled($approvalDisabled)
                                >
                                    Deny &amp; request follow-up
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </li>
        @endforeach
    </ul>
@endif
