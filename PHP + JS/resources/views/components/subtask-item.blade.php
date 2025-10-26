@php
    $subtaskId = $subtask['id'] ?? null;
    $statusBadge = $subtask['status_badge_class'] ?? 'bg-slate-200 text-slate-700';
    $canSubmit = !empty($subtask['can_submit']) && empty($dayLocked);
    $canReview = !empty($subtask['can_review']) && empty($dayLocked);
    $submissions = $subtask['submissions'] ?? [];
    $attachments = $subtask['attachments'] ?? [];
    $subtaskIdJson = json_encode($subtaskId);
@endphp
<li class="p-5">
    <div class="flex flex-col gap-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="space-y-1">
                <p class="text-base font-semibold text-slate-900">{{ $subtask['text'] ?? 'Subtask' }}</p>
                <p class="text-sm text-slate-500">Worth {{ $subtask['xp_value'] ?? 0 }} XP</p>
            </div>
            <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold {{ $statusBadge }}">
                {{ $subtask['status_label'] ?? 'Pending' }}
            </span>
        </div>

        @if (!empty($submissions))
            <div class="space-y-2">
                <h4 class="text-sm font-semibold text-slate-700">Recent submissions</h4>
                <ul class="space-y-2">
                    @foreach ($submissions as $submission)
                        <li class="rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700">
                            <p class="font-medium">{{ $submission['submitted_by'] ?? 'Unknown submitter' }}</p>
                            @if (!empty($submission['comment']))
                                <p class="mt-1 text-slate-600">{{ $submission['comment'] }}</p>
                            @endif
                            <div class="mt-2 flex flex-wrap items-center gap-3 text-xs text-slate-500">
                                <span>{{ $submission['created_display'] ?? 'Recently' }}</span>
                                @if (!empty($submission['photo_path']))
                                    <a
                                        href="{{ $submission['photo_path'] }}"
                                        class="font-semibold text-indigo-600 hover:text-indigo-500"
                                        target="_blank"
                                        rel="noopener"
                                    >
                                        View photo
                                    </a>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if (!empty($attachments))
            <div class="space-y-2">
                <h4 class="text-sm font-semibold text-slate-700">Attachments</h4>
                <ul class="space-y-2 text-sm">
                    @foreach ($attachments as $attachment)
                        <li class="flex flex-wrap items-center justify-between gap-2 rounded-xl bg-slate-100 px-3 py-2">
                            <span class="truncate text-slate-700">{{ $attachment['file_name'] ?? 'Attachment' }}</span>
                            <div class="flex items-center gap-2 text-xs text-slate-500">
                                @if (!empty($attachment['uploaded_by']))
                                    <span>by {{ $attachment['uploaded_by'] }}</span>
                                @endif
                                <a
                                    href="{{ $attachment['file_path'] ?? '#' }}"
                                    class="font-semibold text-indigo-600 hover:text-indigo-500"
                                    target="_blank"
                                    rel="noopener"
                                >
                                    Open
                                </a>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="flex flex-wrap gap-3">
            <button
                type="button"
                class="inline-flex items-center justify-center rounded-md border border-slate-300 px-3 py-2 text-sm font-medium transition {{ $canSubmit ? 'hover:bg-slate-50' : 'cursor-not-allowed opacity-60' }}"
                @if (! $canSubmit) disabled @endif
                @click="$dispatch('open-modal', { type: 'submit', subtaskId: {!! $subtaskIdJson !!} })"
            >
                Submit Update
            </button>
            <button
                type="button"
                class="inline-flex items-center justify-center rounded-md border {{ $canReview ? 'border-indigo-500 text-indigo-600 hover:bg-indigo-50' : 'border-slate-300 text-slate-500 cursor-not-allowed opacity-60' }} px-3 py-2 text-sm font-medium transition"
                @if (! $canReview) disabled @endif
                @click="$dispatch('open-modal', { type: 'review', subtaskId: {!! $subtaskIdJson !!} })"
            >
                Review Submission
            </button>
        </div>
    </div>
</li>
