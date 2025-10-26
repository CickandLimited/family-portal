@extends('layouts.app')

@section('content')
    <style>
        [x-cloak] {
            display: none !important;
        }
    </style>

    <div
        x-data="planView({
            activeModal: @json($active_modal),
            modalSubtaskId: @json($active_subtask_id),
            formErrors: @json($submission_errors),
            formData: @json($submission_form)
        })"
        @open-modal.window="openModal($event.detail)"
        @close-modal.window="closeModal()"
        @keyup.escape.window="closeModal()"
        class="space-y-6"
    >
        <section class="flex flex-col gap-4 rounded-2xl bg-white p-6 shadow">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p class="text-sm uppercase tracking-wide text-slate-500">Plan Overview</p>
                    <h1 class="text-3xl font-bold text-slate-900">{{ $plan['title'] }}</h1>
                    @if (!empty($plan['assignee']))
                        <p class="text-slate-600">
                            Assigned to
                            <span class="font-semibold">{{ $plan['assignee']['display_name'] }}</span>
                        </p>
                    @endif
                </div>
                <div class="flex flex-col items-start gap-2 sm:items-end">
                    <span class="inline-flex items-center rounded-full px-3 py-1 text-sm font-semibold {{ $plan['status_badge_class'] }}">
                        {{ $plan['status_label'] }}
                    </span>
                    <p class="text-sm text-slate-500">
                        Updated {{ optional($plan['updated_at'])->format('M d, Y h:i A') ?? 'recently' }}
                    </p>
                </div>
            </div>

            <div
                id="plan-progress-cards"
                hx-get="{{ route('plan.partials.progress', ['plan' => $plan['id']]) }}"
                hx-trigger="load, planProgressUpdated from:body[detail.plan_id === {{ $plan['id'] }}]"
                hx-target="this"
                hx-swap="innerHTML"
            >
                @include('components.plan-progress-overview', ['plan' => $plan])
            </div>
        </section>

        @if (!empty($plan['attachments']))
            <section class="space-y-3 rounded-2xl bg-white p-6 shadow">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-slate-900">Plan Attachments</h2>
                    <p class="text-sm text-slate-500">Supporting files shared with the whole plan</p>
                </div>
                <ul class="space-y-2">
                    @foreach ($plan['attachments'] as $attachment)
                        <li class="flex flex-col gap-2 rounded-xl border border-slate-200 bg-slate-50 p-4 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <p class="font-medium text-slate-800">{{ $attachment['file_name'] }}</p>
                                @if (!empty($attachment['uploaded_by']))
                                    <p class="text-sm text-slate-500">Uploaded by {{ $attachment['uploaded_by'] }}</p>
                                @endif
                            </div>
                            <div class="flex items-center gap-3 text-sm">
                                <a
                                    class="font-semibold text-indigo-600 hover:text-indigo-500"
                                    href="{{ $attachment['file_path'] }}"
                                    target="_blank"
                                    rel="noopener"
                                >
                                    Open
                                </a>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        <section
            class="space-y-4"
            id="plan-day-list"
            hx-get="{{ route('plan.partials.days', ['plan' => $plan['id']]) }}"
            hx-trigger="load, planProgressUpdated from:body[detail.plan_id === {{ $plan['id'] }}]"
            hx-target="this"
            hx-swap="innerHTML"
        >
            @include('components.plan-day-list', ['plan' => $plan])
        </section>

        <div
            x-cloak
            x-show="activeModal === 'submit'"
            class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 p-4"
            role="dialog"
            aria-modal="true"
        >
            <div class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-lg">
                <div class="flex items-start justify-between">
                    <div>
                        <h3 class="text-xl font-semibold text-slate-900">Submit evidence</h3>
                        <p class="mt-1 text-sm text-slate-600">
                            Add a comment or photo for subtask #<span x-text="modalSubtaskId"></span>.
                        </p>
                    </div>
                    <button
                        type="button"
                        class="text-slate-400 transition hover:text-slate-600"
                        @click="closeModal()"
                        aria-label="Close submission modal"
                    >
                        ✕
                    </button>
                </div>
                <form
                    class="mt-6 space-y-5"
                    method="post"
                    action="{{ route('plan.submit', ['plan' => $plan['id']]) }}"
                    enctype="multipart/form-data"
                    hx-post="{{ route('plan.submit', ['plan' => $plan['id']]) }}"
                    hx-encoding="multipart/form-data"
                    hx-swap="none"
                    hx-on::after-request="handleSubmissionResponse($event)"
                >
                    @csrf
                    <template x-if="formErrors.length">
                        <div class="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">
                            <ul class="list-disc space-y-1 pl-5">
                                <template x-for="(error, index) in formErrors" :key="index">
                                    <li x-text="error"></li>
                                </template>
                            </ul>
                        </div>
                    </template>

                    <input type="hidden" name="subtask_id" :value="modalSubtaskId ?? ''" />

                    <div class="space-y-2">
                        <label for="comment" class="text-sm font-medium text-slate-700">Comment</label>
                        <textarea
                            id="comment"
                            name="comment"
                            rows="3"
                            class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200"
                            placeholder="What did you complete?"
                            x-model="formData.comment"
                        ></textarea>
                        <p class="text-xs text-slate-500">Optional: share a quick update about your progress.</p>
                    </div>

                    <div class="space-y-2">
                        <label for="user_id" class="text-sm font-medium text-slate-700">Who is submitting?</label>
                        <select
                            id="user_id"
                            name="user_id"
                            class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200"
                            x-model="formData.user_id"
                        >
                            <option value="">Anonymous</option>
                            @foreach ($identity_options as $option)
                                <option value="{{ $option['id'] }}">{{ $option['display_name'] }}</option>
                            @endforeach
                        </select>
                        <p class="text-xs text-slate-500">Optional: choose your name so reviewers know who submitted the update.</p>
                    </div>

                    <div class="space-y-2">
                        <label for="photo" class="text-sm font-medium text-slate-700">Upload photo</label>
                        <input
                            id="photo"
                            name="photo"
                            type="file"
                            accept="image/jpeg,image/png,image/webp"
                            class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200"
                            required
                        />
                        <p class="text-xs text-slate-500">Images up to {{ $max_upload_mb }} MB are supported (JPEG, PNG, or WEBP).</p>
                    </div>

                    <div class="flex justify-end gap-2">
                        <button
                            type="button"
                            class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50"
                            @click.prevent="closeModal()"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            class="inline-flex items-center gap-2 rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-indigo-500"
                        >
                            <svg
                                class="h-4 w-4"
                                xmlns="http://www.w3.org/2000/svg"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke-width="1.5"
                                stroke="currentColor"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    d="M7.5 12 10.5 15 16.5 9"
                                />
                            </svg>
                            Submit evidence
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div
            x-cloak
            x-show="activeModal === 'review'"
            class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 p-4"
            role="dialog"
            aria-modal="true"
        >
            <div class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-lg">
                <div class="flex items-start justify-between">
                    <div>
                        <h3 class="text-xl font-semibold text-slate-900">Review submission</h3>
                        <p class="mt-1 text-sm text-slate-600">
                            Approve or deny the latest evidence for subtask #<span x-text="modalSubtaskId"></span>.
                        </p>
                    </div>
                    <button
                        type="button"
                        class="text-slate-400 transition hover:text-slate-600"
                        @click="closeModal()"
                        aria-label="Close review modal"
                    >
                        ✕
                    </button>
                </div>
                <div class="mt-4 rounded-xl border border-dashed border-slate-300 bg-slate-50 p-6 text-center text-sm text-slate-500">
                    The review workflow will live here. Use the buttons above to open the full approval UI.
                </div>
                <div class="mt-6 flex justify-end gap-2">
                    <button
                        type="button"
                        class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50"
                        @click="closeModal()"
                    >
                        Close
                    </button>
                    <button
                        type="button"
                        class="rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-emerald-500"
                    >
                        Launch Review
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        function planView(initial = {}) {
            return {
                activeModal: initial.activeModal || null,
                modalSubtaskId: initial.modalSubtaskId || null,
                formErrors: initial.formErrors || [],
                formData: {
                    comment: initial.formData?.comment || "",
                    user_id: initial.formData?.user_id || "",
                    subtask_id: initial.formData?.subtask_id || null,
                },
                openModal(detail) {
                    if (!detail) {
                        return;
                    }
                    this.activeModal = detail.type || null;
                    this.modalSubtaskId = detail.subtaskId || null;
                    if (this.activeModal === "submit") {
                        this.formErrors = [];
                        this.formData = {
                            comment: "",
                            user_id: "",
                            subtask_id: detail.subtaskId || null,
                        };
                    }
                },
                closeModal() {
                    this.activeModal = null;
                    this.modalSubtaskId = null;
                    this.formErrors = [];
                    this.formData = { comment: "", user_id: "", subtask_id: null };
                },
                handleSubmissionResponse(event) {
                    const detail = event.detail;
                    if (!detail) {
                        return;
                    }

                    if (detail.successful) {
                        const photoField = document.getElementById("photo");
                        if (photoField) {
                            photoField.value = "";
                        }
                        this.formErrors = [];
                        this.closeModal();
                        return;
                    }

                    if (detail.failed) {
                        let payload = null;
                        try {
                            payload = JSON.parse(detail.xhr.responseText);
                        } catch (err) {
                            payload = null;
                        }

                        if (payload && Array.isArray(payload.errors)) {
                            this.formErrors = payload.errors;
                        } else {
                            this.formErrors = [
                                "We couldn't submit your update. Please check your photo and try again.",
                            ];
                        }

                        if (payload && payload.form) {
                            this.formData = {
                                comment: payload.form.comment || "",
                                user_id: payload.form.user_id || "",
                                subtask_id: payload.form.subtask_id || this.formData.subtask_id,
                            };
                            if (payload.form.subtask_id) {
                                this.modalSubtaskId = payload.form.subtask_id;
                            }
                        }
                    }
                },
            };
        }
    </script>
@endsection
