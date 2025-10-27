@php($entriesCollection = $entries instanceof \Illuminate\Support\Collection ? $entries : collect($entries))

@if ($entriesCollection->isNotEmpty())
    <div class="overflow-hidden rounded-xl bg-white shadow">
        <table class="min-w-full divide-y divide-slate-200">
            <thead class="bg-slate-100">
                <tr>
                    <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">Timestamp</th>
                    <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">Action</th>
                    <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">Entity</th>
                    <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">Device</th>
                    <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">User</th>
                    <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">Metadata</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-200">
                @foreach ($entriesCollection as $entry)
                    <tr class="align-top">
                        <td class="whitespace-nowrap px-4 py-3 text-sm text-slate-700">
                            @php($timestamp = $entry['timestamp'] ?? null)
                            <time datetime="{{ $timestamp instanceof \Illuminate\Support\Carbon ? $timestamp->toIso8601String() : ($timestamp ? (string) $timestamp : '') }}">
                                {{ $entry['timestamp_display'] }}
                            </time>
                        </td>
                        <td class="px-4 py-3 text-sm font-semibold text-slate-800">
                            {{ $entry['action'] }}
                        </td>
                        <td class="px-4 py-3 text-sm text-slate-700">
                            {{ $entry['entity_type'] }} #{{ $entry['entity_identifier'] }}
                        </td>
                        <td class="px-4 py-3 text-sm text-slate-700">
                            {{ $entry['device_label'] ?? '—' }}
                        </td>
                        <td class="px-4 py-3 text-sm text-slate-700">
                            {{ $entry['user_label'] ?? '—' }}
                        </td>
                        <td class="px-4 py-3 text-xs text-slate-600">
                            <pre class="whitespace-pre-wrap break-words font-mono text-xs">{{ $entry['metadata_json'] }}</pre>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <nav class="mt-4 flex items-center justify-between">
        <div class="flex gap-2">
            @if (!empty($pagination['has_previous']))
                <button
                    type="button"
                    class="rounded border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100"
                    hx-get="{{ route('admin.activity.entries') }}"
                    hx-target="#activity-log-results"
                    hx-include="#activity-log-filter"
                    hx-vals='@json(['page' => $pagination['previous_page']])'
                    hx-push-url="true"
                >
                    Previous
                </button>
            @endif
            @if (!empty($pagination['has_next']))
                <button
                    type="button"
                    class="rounded border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100"
                    hx-get="{{ route('admin.activity.entries') }}"
                    hx-target="#activity-log-results"
                    hx-include="#activity-log-filter"
                    hx-vals='@json(['page' => $pagination['next_page']])'
                    hx-push-url="true"
                >
                    Next
                </button>
            @endif
        </div>
        <p class="text-sm text-slate-600">Page {{ $pagination['page'] ?? 1 }}</p>
    </nav>
@else
    <div class="rounded-xl border border-dashed border-slate-300 bg-white p-8 text-center text-slate-500">
        No activity log entries match your filters yet.
    </div>
@endif

