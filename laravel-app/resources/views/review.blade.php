@extends('layouts.app')

@section('content')
    <section class="space-y-6">
        <div class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
            <div>
                <h1 class="text-3xl font-bold text-slate-900">Review Queue</h1>
                <p class="mt-2 text-slate-600">
                    Review recent submissions, choose your mood, and approve or deny the latest evidence for each task.
                </p>
            </div>
            @if ($device !== null)
                <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Reviewing as</p>
                    <p class="mt-1 font-medium text-slate-800">{{ $device['label'] }}</p>
                    @if (! empty($device['linked_user_name']))
                        <p class="mt-1 text-xs text-slate-500">Linked to {{ $device['linked_user_name'] }}</p>
                    @endif
                </div>
            @endif
        </div>

        <div
            id="review-queue"
            hx-get="{{ route('review.partials.queue') }}"
            hx-trigger="load, every 30s, reviewQueueRefresh from:body"
            hx-swap="innerHTML"
        >
            @include('components.review-queue-items', [
                'items' => $items,
                'moodOptions' => $moodOptions,
                'defaultMood' => $defaultMood,
            ])
        </div>
    </section>
@endsection
