@extends('admin.layouts.admin')

@section('title', trans('discord-integration::admin.nav.configuration'))

@section('content')
    @if($showHttpWarning)
        <div class="alert alert-warning">
            <i class="bi bi-exclamation-triangle"></i>
            {{ trans('discord-integration::admin.http_warning') }}
        </div>
    @endif

    <div class="alert alert-info">
        <p>
            <i class="bi bi-info-circle"></i>
            @lang('discord-integration::admin.info.setup', ['url' => route('admin.roles.index')])
        </p>

        <p class="mb-0">
            @lang('discord-integration::admin.info.redirect_intro')
            <code>{{ route('discord-integration.callback') }}</code>
        </p>
    </div>

    <div class="card">
        <div class="card-body">
            <form method="POST" action="{{ route('discord-integration.admin.configuration.save') }}">
                @csrf

                <div class="mb-3 form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="use_custom_credentials" id="use_custom_credentials" @checked($useCustomCredentials)>

                    <label class="form-check-label" for="use_custom_credentials">
                        {{ trans('discord-integration::admin.custom_credentials') }}
                    </label>

                    <div class="form-text">{{ trans('discord-integration::admin.custom_credentials_help') }}</div>
                </div>

                <div id="customCredentialsFields" @class(['row', 'd-none' => ! $useCustomCredentials && ! old('use_custom_credentials')])>
                    <div class="col-md-6 mb-3">
                        <label class="form-label" for="clientId">{{ trans('admin.roles.discord.client_id') }}</label>
                        <input type="text" class="form-control @error('client_id') is-invalid @enderror" id="clientId" name="client_id" value="{{ old('client_id', $customClientId) }}">

                        @error('client_id')
                        <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                        @enderror
                    </div>

                    <div class="col-md-6 mb-3">
                        <label class="form-label" for="clientSecret">{{ trans('admin.roles.discord.client_secret') }}</label>
                        <input type="text" class="form-control @error('client_secret') is-invalid @enderror" id="clientSecret" name="client_secret" value="{{ old('client_secret', $customClientSecret) }}">

                        @error('client_secret')
                        <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                        @enderror
                    </div>

                    <div class="col-md-6 mb-3">
                        <label class="form-label" for="botToken">{{ trans('discord-integration::admin.bot_token') }}</label>
                        <input type="text" class="form-control @error('bot_token') is-invalid @enderror" id="botToken" name="bot_token" value="{{ old('bot_token', $customBotToken) }}">

                        <div class="form-text">{{ trans('discord-integration::admin.bot_token_help') }}</div>

                        @error('bot_token')
                        <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                        @enderror
                    </div>
                </div>

                <div id="sharedBotTokenHelp" @class(['form-text', 'mb-3', 'd-none' => $useCustomCredentials || old('use_custom_credentials')])>
                    {{ trans('discord-integration::admin.bot_token_shared_help') }}
                </div>

                <div class="mb-3 form-check form-switch">
                    <input class="form-check-input @error('allow_duplicates') is-invalid @enderror" type="checkbox" name="allow_duplicates" id="allow_duplicates" @checked($allowDuplicates)>

                    <label class="form-check-label" for="allow_duplicates">
                        {{ trans('discord-integration::admin.allow_duplicates') }}
                    </label>

                    <div class="form-text">{{ trans('discord-integration::admin.allow_duplicates_help') }}</div>

                    @error('allow_duplicates')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                </div>

                <div class="mb-3 form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="sync_avatar" id="sync_avatar" @checked($syncAvatar)>

                    <label class="form-check-label" for="sync_avatar">
                        {{ trans('discord-integration::admin.sync_avatar') }}
                    </label>

                    <div class="form-text">{{ trans('discord-integration::admin.sync_avatar_help') }}</div>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="requiredGuildId">
                        {{ trans('discord-integration::admin.required_guild') }}

                        @if($guilds !== null)
                            <a href="#" id="requiredGuildIdPicker">({{ trans('discord-integration::admin.pick') }})</a>
                        @endif
                    </label>
                    <div class="form-text mb-2">{{ trans('discord-integration::admin.required_guild_help') }}</div>

                    <input type="text" class="form-control @error('required_guild_id') is-invalid @enderror" id="requiredGuildId" name="required_guild_id" value="{{ old('required_guild_id', $requiredGuildId) }}">

                    @error('required_guild_id')
                    <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                    @enderror
                </div>

                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save"></i> {{ trans('messages.actions.save') }}
                </button>
            </form>
        </div>
    </div>

    <div class="card mt-4">
        <div class="card-body">
            <h5 class="card-title">{{ trans('discord-integration::admin.test.title') }}</h5>
            <p class="text-muted">{{ trans('discord-integration::admin.test.description') }}</p>

            <form method="POST" action="{{ route('discord-integration.admin.configuration.test-credentials') }}" class="d-inline-block me-2">
                @csrf
                <button type="submit" class="btn btn-outline-primary">
                    <i class="bi bi-key"></i> {{ trans('discord-integration::admin.test.credentials_button') }}
                </button>
            </form>

            <form method="POST" action="{{ route('discord-integration.admin.configuration.test-bot-token') }}" class="d-inline-block me-2">
                @csrf
                <button type="submit" class="btn btn-outline-primary">
                    <i class="bi bi-robot"></i> {{ trans('discord-integration::admin.test.bot_token_button') }}
                </button>
            </form>

            <a href="{{ route('discord-integration.admin.configuration.test-callback') }}" class="btn btn-outline-primary">
                <i class="bi bi-discord"></i> {{ trans('discord-integration::admin.test.callback_button') }}
            </a>

            <div class="form-text mt-2">{{ trans('discord-integration::admin.test.callback_help') }}</div>
        </div>
    </div>

    @if($guilds !== null)
        @include('discord-integration::admin.partials.discord-picker-modal')
    @endif
@endsection

@push('footer-scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const customCredentials = document.getElementById('use_custom_credentials');
            const credentialsFields = document.getElementById('customCredentialsFields');
            const sharedBotTokenHelp = document.getElementById('sharedBotTokenHelp');

            customCredentials.addEventListener('change', function () {
                credentialsFields.classList.toggle('d-none', !customCredentials.checked);
                sharedBotTokenHelp.classList.toggle('d-none', customCredentials.checked);
            });

            @if($guilds !== null)
                document.getElementById('requiredGuildIdPicker').addEventListener('click', function (e) {
                    e.preventDefault();

                    DiscordPicker.open({
                        title: @json(trans('discord-integration::admin.pick_guild_title')),
                        items: @json(\Azuriom\Plugin\DiscordIntegration\Support\DiscordAvatar::guildPickerItems($guilds)),
                        emptyText: @json(trans('discord-integration::admin.pick_guild_empty')),
                        onSelect: function (item) {
                            document.getElementById('requiredGuildId').value = item.id;
                            DiscordPicker.modal.hide();
                        },
                    });
                });
            @endif
        });
    </script>
@endpush
