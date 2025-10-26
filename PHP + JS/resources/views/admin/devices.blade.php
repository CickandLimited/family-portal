@extends('layouts.app')

@section('content')
    <section class="space-y-3">
        <h1 class="text-3xl font-bold text-slate-900">Device Manager</h1>
        <p class="text-sm text-slate-600">
            Review detected devices, assign friendly names, and connect them to family members.
        </p>
        <div class="flex flex-wrap gap-3 text-sm text-slate-600">
            <a class="text-indigo-600 hover:text-indigo-500" href="{{ route('admin.import.show') }}">Import plan</a>
            <span aria-hidden="true">•</span>
            <a class="text-indigo-600 hover:text-indigo-500" href="{{ route('admin.activity.index') }}">Activity log</a>
        </div>
    </section>

    @if (session('status'))
        <div class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
            {{ session('status') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
            <ul class="list-disc space-y-1 pl-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <section class="mt-6 rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
        @if ($devices->isNotEmpty())
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm text-slate-700">
                    <thead class="bg-slate-50 text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-3 py-2 text-left">Device ID</th>
                            <th class="px-3 py-2 text-left">Friendly name</th>
                            <th class="px-3 py-2 text-left">Linked user</th>
                            <th class="px-3 py-2 text-left">Created</th>
                            <th class="px-3 py-2 text-left">Last seen</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($devices as $device)
                            <tr class="align-top">
                                <td class="px-3 py-3 font-mono text-xs text-slate-500">{{ $device->getKey() }}</td>
                                <td class="px-3 py-3">
                                    <form class="flex flex-col gap-2 sm:flex-row sm:items-center" method="post" action="{{ route('admin.devices.rename', $device) }}">
                                        @csrf
                                        <input
                                            class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-200"
                                            type="text"
                                            name="friendly_name"
                                            value="{{ old('friendly_name', $device->friendly_name) }}"
                                            placeholder="Add a nickname"
                                        />
                                        <button class="inline-flex items-center justify-center rounded-md bg-slate-900 px-3 py-2 text-xs font-semibold uppercase tracking-wide text-white shadow-sm transition hover:bg-slate-700" type="submit">
                                            Save
                                        </button>
                                    </form>
                                </td>
                                <td class="px-3 py-3">
                                    <form class="flex flex-col gap-2 sm:flex-row sm:items-center" method="post" action="{{ route('admin.devices.link-user', $device) }}">
                                        @csrf
                                        <select class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-200" name="user_id">
                                            <option value="" @selected($device->linked_user_id === null)>Unassigned</option>
                                            @foreach ($users as $user)
                                                <option value="{{ $user->getKey() }}" @selected($device->linked_user_id === $user->getKey())>
                                                    {{ $user->display_name }}
                                                </option>
                                            @endforeach
                                        </select>
                                        <button class="inline-flex items-center justify-center rounded-md bg-slate-900 px-3 py-2 text-xs font-semibold uppercase tracking-wide text-white shadow-sm transition hover:bg-slate-700" type="submit">
                                            Update
                                        </button>
                                    </form>
                                </td>
                                <td class="px-3 py-3 text-xs text-slate-500">
                                    {{ optional($device->created_at)->format('Y-m-d H:i') ?? '—' }}
                                </td>
                                <td class="px-3 py-3 text-xs text-slate-500">
                                    {{ optional($device->last_seen_at)->format('Y-m-d H:i') ?? '—' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 p-6 text-center text-sm text-slate-600">
                No devices have checked in yet. Once a browser opens the portal, it will appear here.
            </div>
        @endif
    </section>
@endsection

