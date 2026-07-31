@extends('admin.layouts.admin')

@section('title', trans('discord-integration::admin.nav.gateway'))

@section('content')
    @if(! $botAvailable)
        <div class="alert alert-warning">
            <i class="bi bi-exclamation-triangle"></i> {{ trans('discord-integration::admin.gateway.bot_unavailable') }}
        </div>
    @endif

    <div class="card mb-4">
        <div class="card-body">
            <h5 class="card-title">{{ trans('discord-integration::admin.gateway.status_title') }}</h5>

            <p class="mb-1">
                <span class="badge bg-secondary" id="gatewayStatusBadge">{{ trans('discord-integration::admin.gateway.status_checking') }}</span>
                <span class="text-muted ms-2" id="gatewayStatusDetail"></span>
            </p>

            <p class="text-danger mb-0 d-none" id="gatewayLastError">
                <i class="bi bi-exclamation-triangle"></i> <span id="gatewayLastErrorText"></span>
            </p>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-body">
            <h5 class="card-title">{{ trans('discord-integration::admin.gateway.settings_title') }}</h5>

            <p class="text-muted">{{ trans('discord-integration::admin.gateway.intro') }}</p>

            <form method="POST" action="{{ route('discord-integration.admin.gateway.save') }}">
                @csrf

                <div class="mb-3 form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="gateway_enabled" id="gatewayEnabled" @checked($enabled)>
                    <label class="form-check-label" for="gatewayEnabled">{{ trans('discord-integration::admin.gateway.enabled') }}</label>
                    <div class="form-text">{{ trans('discord-integration::admin.gateway.enabled_help') }}</div>
                </div>

                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label class="form-label" for="gatewayPresenceStatus">{{ trans('discord-integration::admin.gateway.presence_status') }}</label>
                        <select class="form-select" id="gatewayPresenceStatus" name="gateway_presence_status">
                            @foreach(['online', 'idle', 'dnd', 'invisible'] as $status)
                                <option value="{{ $status }}" @selected($presenceStatus === $status)>
                                    {{ trans('discord-integration::admin.gateway.presence_statuses.'.$status) }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-4 mb-3">
                        <label class="form-label" for="gatewayActivityType">{{ trans('discord-integration::admin.gateway.activity_type') }}</label>
                        <select class="form-select" id="gatewayActivityType" name="gateway_activity_type">
                            @foreach(['none', 'playing', 'listening', 'watching', 'custom', 'competing'] as $type)
                                <option value="{{ $type }}" @selected($activityType === $type)>
                                    {{ trans('discord-integration::admin.gateway.activity_types.'.$type) }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-4 mb-3">
                        <label class="form-label" for="gatewayActivityText">{{ trans('discord-integration::admin.gateway.activity_text') }}</label>
                        <input type="text" class="form-control @error('gateway_activity_text') is-invalid @enderror"
                               id="gatewayActivityText" name="gateway_activity_text" maxlength="128"
                               value="{{ old('gateway_activity_text', $activityText) }}">
                        @error('gateway_activity_text')
                            <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                        @enderror
                    </div>
                </div>

                <div class="form-text mb-3">{{ trans('discord-integration::admin.gateway.live_apply_note') }}</div>

                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save"></i> {{ trans('messages.actions.save') }}
                </button>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <h5 class="card-title">{{ trans('discord-integration::admin.gateway.tutorial_title') }}</h5>

            @if($installer['installed'])
                <p class="text-success">
                    <i class="bi bi-check-circle"></i> {{ trans('discord-integration::admin.gateway.install_already') }}
                    {{ $installer['active'] ? trans('discord-integration::admin.gateway.install_active') : trans('discord-integration::admin.gateway.install_inactive') }}
                </p>
            @else
                {{-- The privileged install deliberately never happens from
                     the web process (a page that can reach root is a red
                     flag for any sysadmin) - it's a one-liner the admin
                     runs in their own SSH session instead. --}}
                <div class="mb-4">
                    <p class="mb-2">{{ trans('discord-integration::admin.gateway.install_cli_intro') }}</p>
                    <pre class="bg-dark text-light p-3 rounded mb-2"><code>{{ $cliInstallCommand }}</code></pre>
                </div>

                <p class="text-muted">{{ trans('discord-integration::admin.gateway.install_or_manual') }}</p>
            @endif

            <div class="alert alert-info">
                <p><i class="bi bi-info-circle"></i> {{ trans('discord-integration::admin.gateway.tutorial_intro') }}</p>

                <ol class="mb-0">
                    <li>
                        {!! trans('discord-integration::admin.gateway.tutorial_step_1', ['file' => '/etc/systemd/system/azuriom-discord-gateway.service']) !!}
<pre class="bg-dark text-light p-3 rounded mt-2 mb-2"><code>{{ $unitFileContent }}</code></pre>
                        <span class="form-text">{{ trans('discord-integration::admin.gateway.tutorial_step_1_hint') }}</span>
                    </li>
                    <li>{!! trans('discord-integration::admin.gateway.tutorial_step_2') !!}</li>
                    <li>{!! trans('discord-integration::admin.gateway.tutorial_step_3') !!}</li>
                    <li>{!! trans('discord-integration::admin.gateway.tutorial_step_4') !!}</li>
                </ol>
            </div>

            <p class="text-muted mb-0">{{ trans('discord-integration::admin.gateway.tutorial_note') }}</p>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const typeSelect = document.getElementById('gatewayActivityType');
            const textInput = document.getElementById('gatewayActivityText');

            function syncActivityText() {
                textInput.disabled = typeSelect.value === 'none';
            }

            typeSelect.addEventListener('change', syncActivityText);
            syncActivityText();

            const badge = document.getElementById('gatewayStatusBadge');
            const detail = document.getElementById('gatewayStatusDetail');
            const errorBox = document.getElementById('gatewayLastError');
            const errorText = document.getElementById('gatewayLastErrorText');

            function refreshStatus() {
                fetch(@json(route('discord-integration.admin.gateway.status')))
                    .then(function (response) { return response.ok ? response.json() : Promise.reject(); })
                    .then(function (status) {
                        badge.className = 'badge ' + (status.connected ? 'bg-success' : 'bg-danger');
                        badge.textContent = status.connected
                            ? @json(trans('discord-integration::admin.gateway.status_connected'))
                            : @json(trans('discord-integration::admin.gateway.status_disconnected'));

                        detail.textContent = status.connected
                            ? @json(trans('discord-integration::admin.gateway.status_detail'))
                                .replace(':since', status.since || '')
                                .replace(':guilds', status.guilds)
                            : '';

                        // Only meaningful while something is actually wrong -
                        // a healthy connection clears it server-side.
                        if (status.last_error && ! status.connected) {
                            errorText.textContent = status.last_error;
                            errorBox.classList.remove('d-none');
                        } else {
                            errorBox.classList.add('d-none');
                        }
                    })
                    .catch(function () {
                        badge.className = 'badge bg-secondary';
                        badge.textContent = @json(trans('discord-integration::admin.gateway.status_unknown'));
                        detail.textContent = '';
                    });
            }

            refreshStatus();
            setInterval(refreshStatus, 5000);
        });
    </script>
@endsection
