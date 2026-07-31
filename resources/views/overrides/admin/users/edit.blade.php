@extends('admin.layouts.admin')

@section('title', trans('admin.users.edit', ['user' => $user->name]))

@php
    $discordPasswordless = \Azuriom\Plugin\DiscordIntegration\Support\DiscordPasswordless::isPasswordless($user);
@endphp

@section('content')
    @if($user->discordAccount !== null && $discordPasswordless)
        <div class="alert alert-warning" role="alert">
            <i class="bi bi-exclamation-triangle"></i> {{ trans('discord-integration::admin.users.no_password_warning') }}
        </div>
    @elseif($user->discordAccount === null && $discordPasswordless)
        <div class="alert alert-danger" role="alert">
            <i class="bi bi-exclamation-triangle"></i> {{ trans('discord-integration::admin.users.no_password_error') }}
        </div>
    @endif

    @if($user->isDeleted())
        <div class="alert alert-warning" role="alert">
            <i class="bi bi-exclamation-triangle"></i> {{ trans('admin.users.alert-deleted') }}
        </div>
    @elseif($user->isBanned())
        <div class="alert alert-warning shadow" role="alert">
            <h5><i class="bi bi-exclamation-triangle"></i> {{ trans('admin.users.alert-banned.title') }}</h5>
            <ul>
                <li>{{ trans('admin.users.alert-banned.banned-by', ['author' => $user->ban->author->name]) }}</li>
                <li>{{ trans('admin.users.alert-banned.reason', ['reason' => $user->ban->reason]) }}</li>
                <li>{{ trans('admin.users.alert-banned.date', ['date' => format_date_compact($user->ban->created_at)]) }}</li>
            </ul>

            <form method="POST" action="{{ route('admin.users.bans.destroy', [$user, $user->ban]) }}">
                @method('DELETE')
                @csrf

                <button type="submit" class="btn btn-warning">
                    <i class="bi bi-slash-circle"></i> {{ trans('admin.users.unban') }}
                </button>
            </form>
        </div>
    @endif

    <div class="row">
        <div class="col-md-6">
            <div class="card shadow mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">{{ trans('admin.users.edit_profile') }}</h5>
                </div>
                <div class="card-body">
                    <form action="{{ route('admin.users.update', $user) }}" method="POST">
                        @method('PATCH')
                        @csrf

                        <div class="row">
                            <div class="col-md-9">
                                <div class="mb-3">
                                    <label class="form-label" for="nameInput">{{ trans('auth.name') }}</label>
                                    <input type="text" class="form-control @error('name') is-invalid @enderror" id="nameInput" name="name" value="{{ old('name', $user->name) }}" required @disabled($user->isDeleted())>

                                    @error('name')
                                    <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                                    @enderror
                                </div>

                                @can('admin.users.personal')
                                    <div class="mb-3">
                                        <label class="form-label" for="emailInput">{{ trans('auth.email') }}</label>
                                        <input type="email" class="form-control @error('email') is-invalid @enderror" id="emailInput" name="email" value="{{ old('email', $user->email ?? '') }}" @disabled($user->isDeleted())>

                                        @error('email')
                                        <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                                        @enderror
                                    </div>
                                @endcan
                            </div>

                            <div class="col-md-3 text-center">
                                <img src="{{ $user->getAvatar(256) }}" alt="{{ $user->name }}" class="rounded img-fluid mb-3" height="150">
                            </div>
                        </div>

                        @if(! oauth_login())
                            <div class="mb-3">
                                <label class="form-label" for="passwordInput">{{ trans('auth.password') }}</label>
                                <input type="password" class="form-control @error('password') is-invalid @enderror" id="passwordInput" name="password" placeholder="**********" @disabled($user->isDeleted())>

                                @error('password')
                                <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                                @enderror
                            </div>
                        @endif

                        <div class="mb-3">
                            <label class="form-label" for="roleSelect">{{ trans('messages.fields.role') }}</label>
                            <select class="form-select @error('role_id') is-invalid @enderror" id="roleSelect" name="role" @disabled($user->isDeleted())>
                                @foreach($roles as $role)
                                    <option value="{{ $role->id }}" @selected($user->role->is($role))>{{ $role->name }}</option>
                                @endforeach
                            </select>

                            @error('role_id')
                            <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="moneyInput">{{ trans('messages.fields.money') }}</label>
                            <div class="input-group @error('money') has-validation @enderror">
                                <input type="number" min="0" max="999999999999" step="0.01" class="form-control @error('money') is-invalid @enderror" id="moneyInput" name="money" value="{{ old('money', $user->money) }}" required @disabled($user->isDeleted())>
                                <span class="input-group-text">{{ money_name() }}</span>

                                @error('money')
                                <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                                @enderror
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary" @disabled($user->isDeleted())>
                            <i class="bi bi-save"></i> {{ trans('messages.actions.save') }}
                        </button>

                        @if(! $user->isDeleted())
                            <button type="button" class="btn btn-secondary" data-bs-toggle="modal" data-bs-target="#notificationModal">
                                <i class="bi bi-megaphone"></i> {{ trans('admin.users.notify') }}
                            </button>
                        @endif

                        @if(! $user->isDeleted() && ! $user->isAdmin() && ! $user->is(Auth::user()))
                            @if(! $user->isBanned())
                                <button type="button" class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#banModal">
                                    <i class="bi bi-slash-circle"></i> {{ trans('admin.users.ban') }}
                                </button>
                            @endif

                            <a href="{{ route('admin.users.destroy', $user) }}" class="btn btn-danger" data-confirm="delete">
                                <i class="bi bi-trash"></i> {{ trans('admin.users.delete') }}
                            </a>
                        @endif
                    </form>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card shadow mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">{{ trans('admin.users.info') }}</h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label" for="registerInput">{{ trans('admin.users.registered') }}</label>
                        <input type="text" class="form-control" id="registerInput" value="{{ format_date_compact($user->created_at) }}" disabled>
                    </div>

                    @if($user->last_login_at)
                        <div class="mb-3">
                            <label class="form-label" for="lastLoginInput">{{ trans('admin.users.last_login') }}</label>
                            <input type="text" class="form-control" id="lastLoginInput" value="{{ format_date_compact($user->last_login_at) }}" disabled>
                        </div>
                    @endif

                    @if($user->email !== null)
                        <form action="{{ route('admin.users.verify', $user) }}" method="POST">
                            @csrf

                            <div class="mb-3">
                                <label class="form-label" for="emailVerifiedInput">{{ trans('admin.users.email.verified') }}</label>

                                @if($user->hasVerifiedEmail())
                                    <input type="text" class="form-control text-success" id="emailVerifiedInput"
                                           value="{{ trans('admin.users.email.date', ['date' => format_date_compact($user->email_verified_at)]) }}" disabled>
                                @else
                                    <div class="input-group mb-3">
                                        <input type="text" class="form-control text-danger" id="emailVerifiedInput" value="{{ trans('messages.no') }}" disabled>

                                        @if(! $user->isDeleted())
                                            <button class="btn btn-outline-success" type="submit">
                                                {{ trans('admin.users.email.verify') }}
                                            </button>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        </form>
                    @endif

                    @if(! oauth_login())
                        <form action="{{ route('admin.users.2fa', $user) }}" method="POST">
                            @csrf

                            <div class="mb-3">
                                <label class="form-label" for="2faInput">{{ trans('admin.users.2fa.title') }}</label>

                                @if(! $user->hasTwoFactorAuth())
                                    <input type="text" class="form-control text-danger" id="2faInput" value="{{ trans('messages.no') }}" disabled>
                                @else
                                    <div class="input-group mb-3">
                                        <input type="text" class="form-control text-success" id="2faInput" value="{{ trans('messages.yes') }}" disabled>

                                        <button class="btn btn-outline-danger" type="submit">
                                            {{ trans('admin.users.2fa.disable') }}
                                        </button>
                                    </div>
                                @endif
                            </div>
                        </form>

                        <form action="{{ route('admin.users.force-password', $user) }}" method="POST">
                            @csrf

                            <div class="mb-3">
                                <label class="form-label" for="forcePassword">{{ trans('admin.users.password.title') }}</label>

                                @if($user->mustChangePassword())
                                    <input type="text" class="form-control" id="forcePassword" value="{{ trans('admin.users.password.forced') }}" disabled>
                                @else
                                    <div class="input-group mb-3">
                                        <input type="text" class="form-control" id="forcePassword" value="{{ format_date_compact($user->password_changed_at) }}" disabled>

                                        <button class="btn btn-outline-danger" type="submit">
                                            {{ trans('admin.users.password.force') }}
                                        </button>
                                    </div>
                                @endif
                            </div>
                        </form>
                    @endif

                    @can('admin.users.personal-data')
                        <div class="mb-3">
                            <label class="form-label" for="addressInput">{{ trans('admin.users.ip') }}</label>
                            <input type="text" class="form-control" id="addressInput" value="{{ $user->last_login_ip ?? trans('messages.unknown') }}" disabled>
                        </div>
                    @endcan

                    @if($user->game_id)
                        <div class="mb-3">
                            <label class="form-label" for="idInput">{{ game()->trans('id') }}</label>
                            <input type="text" class="form-control" id="idInput" value="{{ $user->game_id }}" disabled>
                        </div>
                    @endif

                    @if($user->discordAccount !== null)
                        <div class="mb-3">
                            <label class="form-label" for="discordInput">{{ trans('admin.users.discord') }}</label>

                            @if(! $discordPasswordless)
                                <form action="{{ route('admin.users.discord.unlink', $user) }}" method="POST">
                                    @csrf

                                    <div class="input-group mb-3">
                                        <input type="text" class="form-control" id="discordInput" value="{{ $user->discordAccount->name }}" disabled>

                                        <button class="btn btn-outline-danger" type="submit">
                                            {{ trans('messages.actions.remove') }}
                                        </button>
                                    </div>
                                </form>
                            @else
                                <div class="input-group mb-3">
                                    <input type="text" class="form-control" id="discordInput" value="{{ $user->discordAccount->name }}" disabled>

                                    <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#forceUnlinkModal">
                                        {{ trans('discord-integration::admin.force_unlink.button') }}
                                    </button>
                                </div>
                            @endif
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    @if($user->discordAccount !== null)
        <div class="card shadow mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0">{{ trans('discord-integration::admin.tools.title') }}</h5>
            </div>
            <div class="card-body">
                <button type="button" class="btn btn-outline-secondary me-2 mb-2" data-bs-toggle="modal" data-bs-target="#refreshInfoModal">
                    <i class="bi bi-arrow-repeat"></i> {{ trans('discord-integration::admin.tools.refresh.button') }}
                </button>

                @if(\Azuriom\Plugin\DiscordIntegration\Support\DiscordBotClient::available())
                    <button type="button" class="btn btn-outline-primary me-2 mb-2" data-bs-toggle="modal" data-bs-target="#sendDmModal">
                        <i class="bi bi-envelope"></i> {{ trans('discord-integration::admin.tools.dm.button') }}
                    </button>

                    <button type="button" class="btn btn-outline-warning me-2 mb-2" data-bs-toggle="modal" data-bs-target="#recoveryPasswordModal">
                        <i class="bi bi-key"></i> {{ trans('discord-integration::admin.tools.recovery_password.button') }}
                    </button>

                    @if($user->hasTwoFactorAuth())
                        <button type="button" class="btn btn-outline-danger me-2 mb-2" data-bs-toggle="modal" data-bs-target="#recoveryCodesModal">
                            <i class="bi bi-shield-lock"></i> {{ trans('discord-integration::admin.tools.recovery_codes.button') }}
                        </button>
                    @endif
                @else
                    <div class="form-text mt-2">{{ trans('discord-integration::admin.tools.bot_unavailable') }}</div>
                @endif
            </div>
        </div>
    @endif

    @if(! $user->isBanned())
        <div class="modal fade" id="banModal" tabindex="-1" role="dialog" aria-labelledby="banLabel" aria-modal="true">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2 class="modal-title" id="banLabel">{{ trans('admin.users.ban-title', ['user' => $user->name]) }}</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p>{{ trans('admin.users.ban-description') }}</p>

                        <form method="POST" action="{{ route('admin.users.bans.store', $user) }}">
                            @csrf

                            <div class="mb-3">
                                <label class="form-label" for="reasonInput">{{ trans('admin.bans.reason') }}</label>
                                <input type="text" class="form-control @error('reason') is-invalid @enderror" id="reasonInput" name="reason" required>

                                @error('reason')
                                <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                                @enderror
                            </div>

                            <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">
                                {{ trans('messages.actions.cancel') }}
                            </button>

                            <button class="btn btn-danger" type="submit">
                                <i class="bi bi-slash-circle"></i> {{ trans('admin.users.ban') }}
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    @endif

    @if($user->discordAccount !== null && $discordPasswordless)
        <div class="modal fade" id="forceUnlinkModal" tabindex="-1" role="dialog" aria-labelledby="forceUnlinkLabel" aria-modal="true" data-bs-backdrop="static">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2 class="modal-title text-danger" id="forceUnlinkLabel">
                            <i class="bi bi-exclamation-triangle"></i> {{ trans('discord-integration::admin.force_unlink.title') }}
                        </h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p>{{ trans('discord-integration::admin.force_unlink.warning') }}</p>

                        <form method="POST" action="{{ route('discord-integration.admin.users.force-unlink', $user) }}" id="captcha-form">
                            @csrf

                            @include('elements.captcha', ['center' => true])

                            <div class="d-flex justify-content-end gap-2 mt-3">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                    {{ trans('messages.actions.cancel') }}
                                </button>

                                <button type="submit" class="btn btn-danger" data-confirm-delay="5" disabled>
                                    {{ trans('discord-integration::admin.force_unlink.confirm') }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    @endif

    @if($user->discordAccount !== null)
        <div class="modal fade" id="refreshInfoModal" tabindex="-1" role="dialog" aria-labelledby="refreshInfoLabel" aria-modal="true" data-bs-backdrop="static">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2 class="modal-title" id="refreshInfoLabel">
                            <i class="bi bi-arrow-repeat"></i> {{ trans('discord-integration::admin.tools.refresh.title') }}
                        </h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p>{{ trans('discord-integration::admin.tools.refresh.description') }}</p>

                        <form method="POST" action="{{ route('discord-integration.admin.users.refresh-discord-info', $user) }}" id="refreshInfoForm">
                            @csrf

                            @include('elements.captcha', ['center' => true])

                            <div class="d-flex justify-content-end gap-2 mt-3">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                    {{ trans('messages.actions.cancel') }}
                                </button>

                                <button type="submit" class="btn btn-secondary" data-confirm-delay="5" disabled>
                                    {{ trans('discord-integration::admin.tools.refresh.confirm') }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    @endif

    @if($user->discordAccount !== null && \Azuriom\Plugin\DiscordIntegration\Support\DiscordBotClient::available())
        <div class="modal fade" id="sendDmModal" tabindex="-1" role="dialog" aria-labelledby="sendDmLabel" aria-modal="true" data-bs-backdrop="static">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2 class="modal-title" id="sendDmLabel">
                            <i class="bi bi-envelope"></i> {{ trans('discord-integration::admin.tools.dm.title') }}
                        </h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <form method="POST" action="{{ route('discord-integration.admin.users.send-dm', $user) }}" id="sendDmForm">
                            @csrf

                            <div class="mb-3">
                                <label class="form-label" for="dmContent">{{ trans('discord-integration::admin.tools.dm.content_label') }}</label>
                                <textarea class="form-control @error('content') is-invalid @enderror" id="dmContent" name="content" rows="4" maxlength="2000" required></textarea>

                                @error('content')
                                    <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                                @enderror
                            </div>

                            @include('elements.captcha', ['center' => true])

                            <div class="d-flex justify-content-end gap-2 mt-3">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                    {{ trans('messages.actions.cancel') }}
                                </button>

                                <button type="submit" class="btn btn-primary" data-confirm-delay="5" disabled>
                                    {{ trans('discord-integration::admin.tools.dm.confirm') }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <div class="modal fade" id="recoveryPasswordModal" tabindex="-1" role="dialog" aria-labelledby="recoveryPasswordLabel" aria-modal="true" data-bs-backdrop="static">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2 class="modal-title text-warning" id="recoveryPasswordLabel">
                            <i class="bi bi-key"></i> {{ trans('discord-integration::admin.tools.recovery_password.title') }}
                        </h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p>{{ trans('discord-integration::admin.tools.recovery_password.warning') }}</p>

                        <form method="POST" action="{{ route('discord-integration.admin.users.send-recovery-password', $user) }}" id="recoveryPasswordForm">
                            @csrf

                            <div class="mb-3 form-check">
                                <input class="form-check-input" type="checkbox" name="invalidate_sessions" id="invalidateSessions">

                                <label class="form-check-label" for="invalidateSessions">
                                    {{ trans('discord-integration::admin.tools.recovery_password.invalidate_sessions') }}
                                </label>

                                <div class="form-text">{{ trans('discord-integration::admin.tools.recovery_password.invalidate_sessions_help') }}</div>
                            </div>

                            @include('elements.captcha', ['center' => true])

                            <div class="d-flex justify-content-end gap-2 mt-3">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                    {{ trans('messages.actions.cancel') }}
                                </button>

                                <button type="submit" class="btn btn-warning" data-confirm-delay="5" disabled>
                                    {{ trans('discord-integration::admin.tools.recovery_password.confirm') }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        @if($user->hasTwoFactorAuth())
            <div class="modal fade" id="recoveryCodesModal" tabindex="-1" role="dialog" aria-labelledby="recoveryCodesLabel" aria-modal="true" data-bs-backdrop="static">
                <div class="modal-dialog" role="document">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h2 class="modal-title text-danger" id="recoveryCodesLabel">
                                <i class="bi bi-shield-lock"></i> {{ trans('discord-integration::admin.tools.recovery_codes.title') }}
                            </h2>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <p>{{ trans('discord-integration::admin.tools.recovery_codes.warning') }}</p>

                            <form method="POST" action="{{ route('discord-integration.admin.users.send-2fa-recovery-codes', $user) }}" id="recoveryCodesForm">
                                @csrf

                                @include('elements.captcha', ['center' => true])

                                <div class="d-flex justify-content-end gap-2 mt-3">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                        {{ trans('messages.actions.cancel') }}
                                    </button>

                                    <button type="submit" class="btn btn-danger" data-confirm-delay="5" disabled>
                                        {{ trans('discord-integration::admin.tools.recovery_codes.confirm') }}
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    @endif

    @can('admin.logs')
        @if(! $logs->isEmpty())
            <div class="card shadow mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">{{ trans('admin.logs.title') }}</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                            <tr>
                                <th scope="col">#</th>
                                <th scope="col">{{ trans('messages.fields.action') }}</th>
                                <th scope="col">{{ trans('messages.fields.date') }}</th>
                                <th scope="col">{{ trans('messages.fields.action') }}</th>
                            </tr>
                            </thead>
                            <tbody>

                            @foreach($logs as $log)
                                <tr>
                                    <th scope="row">{{ $log->id }}</th>
                                    <td>
                                        <i class="text-{{ $log->getActionFormat()['color'] }} bi bi-{{ $log->getActionFormat()['icon'] }}"></i>
                                        {{ $log->getActionMessage() }}
                                    </td>
                                    <td>{{ format_date_compact($log->created_at) }}</td>
                                    <td>
                                        <a href="{{ route('admin.logs.show', $log) }}" class="mx-1" title="{{ trans('messages.actions.show') }}" data-bs-toggle="tooltip"><i class="bi bi-eye"></i></a>
                                    </td>
                                </tr>
                            @endforeach

                            </tbody>
                        </table>
                    </div>

                    {{ $logs->links() }}
                </div>
            </div>
        @endif
    @endcan

    <div class="row gy-4">
        @foreach($cards ?? [] as $card)
            <div class="col-12">
                <div class="card shadow-sm mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">{{ $card['name'] }}</h5>
                    </div>
                    <div class="card-body">
                        @include($card['view'])
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    @include('admin.users._notify', ['route' => route('admin.users.notify', ['user' => $user])])
@endsection

@push('footer-scripts')
    <script>
        // Generic confirm-delay countdown: any modal containing a button with a
        // [data-confirm-delay="N"] attribute gets that button disabled for N
        // seconds after the modal opens, to slow down abuse of destructive or
        // sensitive actions (Discord unlink, DM, recovery password/codes, ...).
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('.modal').forEach(function (modalElement) {
                const confirmButton = modalElement.querySelector('[data-confirm-delay]');

                if (! confirmButton) {
                    return;
                }

                const delay = parseInt(confirmButton.dataset.confirmDelay, 10);
                const confirmLabel = confirmButton.textContent.trim();
                let countdownTimer = null;

                modalElement.addEventListener('show.bs.modal', function () {
                    let remaining = delay;
                    confirmButton.disabled = true;
                    confirmButton.textContent = confirmLabel + ' (' + remaining + ')';

                    clearInterval(countdownTimer);
                    countdownTimer = setInterval(function () {
                        remaining--;

                        if (remaining <= 0) {
                            clearInterval(countdownTimer);
                            confirmButton.disabled = false;
                            confirmButton.textContent = confirmLabel;
                        } else {
                            confirmButton.textContent = confirmLabel + ' (' + remaining + ')';
                        }
                    }, 1000);
                });

                modalElement.addEventListener('hidden.bs.modal', function () {
                    clearInterval(countdownTimer);
                });
            });
        });
    </script>
@endpush
