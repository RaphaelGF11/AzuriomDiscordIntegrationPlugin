@extends('admin.layouts.admin')

@section('title', trans('discord-integration::admin.nav.links'))

@section('content')
    <div class="card shadow mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="card-title mb-0">{{ trans('discord-integration::admin.links.title') }}</h5>

            <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#forceLinkModal">
                <i class="bi bi-plus-lg"></i> {{ trans('discord-integration::admin.links.create') }}
            </button>
        </div>
        <div class="card-body">
            @if($links->isEmpty())
                <p class="text-muted mb-0">{{ trans('discord-integration::admin.links.empty') }}</p>
            @else
                <div class="table-responsive">
                    <table class="table table-striped align-middle">
                        <thead>
                            <tr>
                                <th>{{ trans('messages.fields.name') }}</th>
                                <th>{{ trans('discord-integration::admin.links.discord_account') }}</th>
                                <th>{{ trans('admin.users.registered') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($links as $link)
                                <tr>
                                    <td>
                                        @if($link->user !== null)
                                            <div class="d-flex align-items-center gap-2">
                                                <img src="{{ $link->user->getAvatar(24) }}" alt="" width="24" height="24" class="rounded flex-shrink-0">
                                                <a href="{{ route('admin.users.edit', $link->user) }}">{{ $link->user->name }}</a>
                                            </div>
                                        @else
                                            <span class="text-muted">#{{ $link->user_id }}</span>
                                        @endif
                                    </td>
                                    <td>{{ $link->name }} <code class="text-muted">{{ $link->discord_user_id }}</code></td>
                                    <td>{{ format_date_compact($link->created_at) }}</td>
                                    <td class="text-end">
                                        @if($link->user !== null)
                                            <a href="{{ route('admin.users.edit', $link->user) }}" class="btn btn-sm btn-outline-secondary" title="{{ trans('messages.actions.show') }}">
                                                <i class="bi bi-box-arrow-up-right"></i>
                                            </a>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    <div class="card shadow mb-4">
        <div class="card-header">
            <h5 class="card-title mb-0">{{ trans('discord-integration::admin.links.pending_title') }}</h5>
        </div>
        <div class="card-body">
            <p class="text-muted">{{ trans('discord-integration::admin.links.pending_description') }}</p>

            @if($pendingLinks->isEmpty())
                <p class="text-muted mb-0">{{ trans('discord-integration::admin.links.pending_empty') }}</p>
            @else
                <div class="table-responsive">
                    <table class="table table-striped align-middle">
                        <thead>
                            <tr>
                                <th>{{ trans('messages.fields.name') }}</th>
                                <th>{{ trans('discord-integration::admin.links.discord_account') }}</th>
                                <th>{{ trans('admin.users.registered') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($pendingLinks as $pendingLink)
                                <tr>
                                    <td>
                                        @if($pendingLink->user !== null)
                                            <a href="{{ route('admin.users.edit', $pendingLink->user) }}">{{ $pendingLink->user->name }}</a>
                                        @else
                                            <span class="text-muted">#{{ $pendingLink->user_id }}</span>
                                        @endif
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <img src="{{ \Azuriom\Plugin\DiscordIntegration\Support\DiscordAvatar::smallIconUrlFrom(['id' => $pendingLink->discord_user_id, 'avatar' => $pendingLink->discord_avatar]) }}" alt="" width="24" height="24" class="rounded-circle flex-shrink-0">
                                            {{ $pendingLink->discord_username ?? trans('messages.unknown') }}
                                            <code class="text-muted">{{ $pendingLink->discord_user_id }}</code>
                                        </div>
                                    </td>
                                    <td>{{ format_date_compact($pendingLink->created_at) }}</td>
                                    <td class="text-end">
                                        <a href="{{ route('discord-integration.admin.links.destroy-pending', $pendingLink) }}" class="btn btn-sm btn-outline-danger" data-confirm="delete" title="{{ trans('messages.actions.remove') }}">
                                            <i class="bi bi-trash"></i>
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    <div class="modal fade" id="forceLinkModal" tabindex="-1" role="dialog" aria-labelledby="forceLinkModalLabel" aria-modal="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title" id="forceLinkModalLabel">{{ trans('discord-integration::admin.links.create') }}</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="form-text">{{ trans('discord-integration::admin.links.create_help') }}</p>

                    <form method="POST" action="{{ route('discord-integration.admin.links.store') }}" id="forceLinkForm">
                        @csrf

                        <div class="mb-3 position-relative">
                            <label class="form-label" for="linkUsername">{{ trans('messages.fields.name') }}</label>
                            <input type="text" class="form-control @error('username') is-invalid @enderror" name="username" id="linkUsername" value="{{ old('username') }}" autocomplete="off" required>

                            <div class="list-group position-absolute w-100 d-none" id="linkUsernameSuggestions" style="z-index: 1056; max-height: 200px; overflow-y: auto;"></div>

                            @error('username')
                            <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="discordUserId">
                                {{ trans('discord-integration::admin.links.discord_id_label') }}

                                @if($guilds !== null)
                                    <a href="#" id="discordUserIdPicker">({{ trans('discord-integration::admin.pick') }})</a>
                                @endif
                            </label>
                            <div class="form-text mb-2">{{ trans('discord-integration::admin.links.discord_id_help') }}</div>

                            <input type="text" class="form-control @error('discord_user_id') is-invalid @enderror" name="discord_user_id" id="discordUserId" value="{{ old('discord_user_id') }}" required>

                            @error('discord_user_id')
                            <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                            @enderror
                        </div>

                        <div class="d-flex justify-content-end gap-2">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                {{ trans('messages.actions.cancel') }}
                            </button>

                            <button type="submit" class="btn btn-primary">
                                {{ trans('messages.actions.save') }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    @include('discord-integration::admin.partials.discord-picker-modal')
@endsection

@push('footer-scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            (function () {
                const input = document.getElementById('linkUsername');
                const suggestions = document.getElementById('linkUsernameSuggestions');
                let debounceTimer = null;

                function hide() {
                    suggestions.classList.add('d-none');
                    suggestions.innerHTML = '';
                }

                input.addEventListener('input', function () {
                    clearTimeout(debounceTimer);

                    const query = input.value.trim();

                    if (query.length < 2) {
                        hide();
                        return;
                    }

                    debounceTimer = setTimeout(function () {
                        fetch(@json(route('users.search')) + '?q=' + encodeURIComponent(query))
                            .then(function (response) {
                                return response.ok ? response.json() : Promise.reject();
                            })
                            .then(function (users) {
                                suggestions.innerHTML = '';

                                if (users.length === 0) {
                                    const empty = document.createElement('div');
                                    empty.className = 'list-group-item text-muted';
                                    empty.textContent = @json(trans('discord-integration::admin.links.pick_user_empty'));
                                    suggestions.appendChild(empty);
                                    suggestions.classList.remove('d-none');
                                    return;
                                }

                                users.forEach(function (user) {
                                    const item = document.createElement('button');
                                    item.type = 'button';
                                    item.className = 'list-group-item list-group-item-action';
                                    item.textContent = user.name;
                                    item.addEventListener('mousedown', function (e) {
                                        // mousedown (not click) fires before the input's blur
                                        // handler below, so the value is set before it hides.
                                        e.preventDefault();
                                        input.value = user.name;
                                        hide();
                                    });
                                    suggestions.appendChild(item);
                                });

                                suggestions.classList.remove('d-none');
                            })
                            .catch(function () {
                                suggestions.innerHTML = '';

                                const error = document.createElement('div');
                                error.className = 'list-group-item text-danger';
                                error.textContent = @json(trans('discord-integration::admin.links.pick_user_error'));
                                suggestions.appendChild(error);
                                suggestions.classList.remove('d-none');
                            });
                    }, 300);
                });

                input.addEventListener('blur', function () {
                    setTimeout(hide, 150);
                });
            })();

            @if($guilds !== null)
                document.getElementById('discordUserIdPicker').addEventListener('click', function (e) {
                    e.preventDefault();

                    DiscordPicker.open({
                        title: @json(trans('discord-integration::admin.pick_guild_title')),
                        items: @json(\Azuriom\Plugin\DiscordIntegration\Support\DiscordAvatar::guildPickerItems($guilds)),
                        emptyText: @json(trans('discord-integration::admin.pick_guild_empty')),
                        onSelect: function (guild) {
                            DiscordPicker.open({
                                title: @json(trans('discord-integration::admin.pick_member_title')),
                                items: [],
                                emptyText: @json(trans('discord-integration::admin.pick_member_loading')),
                                iconShape: 'circle',
                                onSelect: function () {},
                            });

                            fetch(@json(route('discord-integration.admin.links.guild-members')) + '?guild_id=' + encodeURIComponent(guild.id))
                                .then(function (response) {
                                    return response.ok ? response.json() : Promise.reject();
                                })
                                .then(function (members) {
                                    DiscordPicker.open({
                                        title: @json(trans('discord-integration::admin.pick_member_title')),
                                        items: members.map(function (member) {
                                            return {id: member.id, label: member.name, icon: member.avatar};
                                        }),
                                        emptyText: @json(trans('discord-integration::admin.pick_member_empty')),
                                        iconShape: 'circle',
                                        onSelect: function (member) {
                                            document.getElementById('discordUserId').value = member.id;
                                            DiscordPicker.modal.hide();
                                        },
                                    });
                                })
                                .catch(function () {
                                    DiscordPicker.open({
                                        title: @json(trans('discord-integration::admin.pick_member_title')),
                                        items: [],
                                        emptyText: @json(trans('discord-integration::admin.pick_member_error')),
                                        iconShape: 'circle',
                                        onSelect: function () {},
                                    });
                                });
                        },
                    });
                });
            @endif

            @if($errors->hasAny(['username', 'discord_user_id']))
                bootstrap.Modal.getOrCreateInstance(document.getElementById('forceLinkModal')).show();
            @endif
        });
    </script>
@endpush
