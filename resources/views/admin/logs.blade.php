@extends('admin.layouts.admin')

@section('title', trans('discord-integration::admin.nav.logs'))

@section('content')
    <div class="card shadow mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="card-title mb-0">{{ trans('discord-integration::admin.logs.title') }}</h5>

            @if($entries->total() > 0)
                <a href="{{ route('discord-integration.admin.logs.clear') }}" class="btn btn-sm btn-outline-danger" data-confirm="delete">
                    <i class="bi bi-trash"></i> {{ trans('discord-integration::admin.logs.clear') }}
                </a>
            @endif
        </div>
        <div class="card-body">
            <p class="text-muted">{{ trans('discord-integration::admin.logs.description') }}</p>

            @php
                $levels = ['info' => 'secondary', 'warning' => 'warning', 'error' => 'danger'];
            @endphp

            <div class="btn-group mb-3" role="group">
                <a href="{{ route('discord-integration.admin.logs') }}" class="btn btn-sm btn-outline-secondary {{ $level === null ? 'active' : '' }}">
                    {{ trans('discord-integration::admin.logs.filter_all') }}
                </a>

                @foreach($levels as $levelKey => $color)
                    <a href="{{ route('discord-integration.admin.logs', ['level' => $levelKey]) }}" class="btn btn-sm btn-outline-secondary {{ $level === $levelKey ? 'active' : '' }}">
                        {{ trans('discord-integration::admin.logs.level_'.$levelKey) }}
                    </a>
                @endforeach
            </div>

            @if($entries->isEmpty())
                <p class="text-muted mb-0">{{ trans('discord-integration::admin.logs.empty') }}</p>
            @else
                <div class="table-responsive">
                    <table class="table table-striped align-middle">
                        <thead>
                            <tr>
                                <th>{{ trans('discord-integration::admin.logs.level') }}</th>
                                <th>{{ trans('discord-integration::admin.logs.message') }}</th>
                                <th>{{ trans('discord-integration::admin.logs.date') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($entries as $entry)
                                <tr>
                                    <td>
                                        <span class="badge bg-{{ $levels[$entry->level] ?? 'secondary' }}">
                                            {{ trans('discord-integration::admin.logs.level_'.$entry->level) }}
                                        </span>
                                    </td>
                                    <td>
                                        {{ $entry->message }}

                                        <div class="text-muted small">
                                            @if($entry->discord_guild_id)
                                                {{ trans('discord-integration::admin.logs.guild') }}: <code>{{ $entry->discord_guild_id }}</code>
                                            @endif

                                            @if($entry->discord_role_id)
                                                &middot; {{ trans('discord-integration::admin.logs.role') }}: <code>{{ $entry->discord_role_id }}</code>
                                            @endif

                                            @if($entry->status)
                                                &middot; {{ trans('discord-integration::admin.logs.status') }}: <code>{{ $entry->status }}</code>
                                            @endif
                                        </div>

                                        @if($entry->context)
                                            <details class="mt-1">
                                                <summary class="text-muted small" style="cursor: pointer;">{{ trans('discord-integration::admin.logs.details') }}</summary>
                                                <pre class="small bg-light p-2 rounded mt-1 mb-0">{{ json_encode($entry->context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                            </details>
                                        @endif
                                    </td>
                                    <td class="text-nowrap">{{ format_date_compact($entry->created_at) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                {{ $entries->links() }}
            @endif
        </div>
    </div>
@endsection
