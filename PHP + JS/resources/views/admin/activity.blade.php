@extends('layouts.app')

@section('content')
    <section class="space-y-6">
        <header class="space-y-3">
            <h1 class="text-3xl font-bold text-slate-900">Activity Log</h1>
            <p class="text-sm text-slate-600">
                Review recent actions across the portal. Use the filters to narrow the results and HTMX will update the list automatically.
            </p>
            <div class="flex flex-wrap gap-3 text-sm text-slate-600">
                <a class="text-indigo-600 hover:text-indigo-500" href="{{ route('admin.devices.index') }}">Device manager</a>
                <span aria-hidden="true">•</span>
                <a class="text-indigo-600 hover:text-indigo-500" href="{{ route('admin.import.show') }}">Import plan</a>
            </div>
        </header>

        <form
            id="activity-log-filter"
            class="grid gap-4 rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200 md:grid-cols-4"
            hx-get="{{ route('admin.activity.entries') }}"
            hx-include="#activity-log-filter"
            hx-target="#activity-log-results"
            hx-trigger="change delay:300ms, submit"
            hx-vals='@json(['page' => 1])'
            hx-push-url="true"
        >
            <div class="space-y-2">
                <label for="activity-action" class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                    Action
                </label>
                <select
                    id="activity-action"
                    name="action"
                    class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-200"
                >
                    <option value="">All actions</option>
                    @foreach ($actionOptions as $action)
                        <option value="{{ $action }}" @selected($filterForm['action'] === $action)>{{ $action }}</option>
                    @endforeach
                </select>
            </div>

            <div class="space-y-2">
                <label for="activity-entity" class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                    Entity type
                </label>
                <select
                    id="activity-entity"
                    name="entity_type"
                    class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-200"
                >
                    <option value="">All entities</option>
                    @foreach ($entityTypeOptions as $entityType)
                        <option value="{{ $entityType }}" @selected($filterForm['entity_type'] === $entityType)>{{ $entityType }}</option>
                    @endforeach
                </select>
            </div>

            <div class="space-y-2">
                <label for="activity-device" class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                    Device
                </label>
                <select
                    id="activity-device"
                    name="device_id"
                    class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-200"
                >
                    <option value="">All devices</option>
                    @foreach ($deviceOptions as $option)
                        <option value="{{ $option['value'] }}" @selected($filterForm['device_id'] === $option['value'])>{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </div>

            <div class="space-y-2">
                <label for="activity-user" class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                    Actor user
                </label>
                <select
                    id="activity-user"
                    name="user_id"
                    class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-200"
                >
                    <option value="">All users</option>
                    @foreach ($userOptions as $option)
                        <option value="{{ $option['value'] }}" @selected($filterForm['user_id'] === $option['value'])>{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </div>

            <div class="md:col-span-4 flex flex-col gap-3 border-t border-slate-200 pt-4 sm:flex-row sm:items-center sm:justify-between">
                <button
                    type="submit"
                    class="inline-flex items-center justify-center rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-700"
                >
                    Apply filters
                </button>
                <a
                    href="{{ route('admin.activity.index') }}"
                    class="text-xs font-semibold uppercase tracking-wide text-slate-500 transition hover:text-slate-700"
                >
                    Reset filters
                </a>
            </div>
        </form>

        <div id="activity-log-results">
            @include('admin.partials.activity-log-table', ['entries' => $entries, 'pagination' => $pagination])
        </div>
    </section>
@endsection

