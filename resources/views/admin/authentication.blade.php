@extends('admin.layouts.admin')

@section('title', trans('discord-integration::admin.nav.authentication'))

@section('content')
    <div class="card">
        <div class="card-body">
            <form method="POST" action="{{ route('discord-integration.admin.authentication.save') }}">
                @csrf

                <div class="mb-3 form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="enabled" id="enabled" @checked($enabled)>

                    <label class="form-check-label" for="enabled">
                        {{ trans('discord-integration::admin.enabled') }}
                    </label>

                    <div class="form-text">{{ trans('discord-integration::admin.enabled_help') }}</div>
                </div>

                <div class="mb-3 form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="force_register" id="force_register" @checked($forceRegister)>

                    <label class="form-check-label" for="force_register">
                        {{ trans('discord-integration::admin.force_register') }}
                    </label>

                    <div class="form-text">{{ trans('discord-integration::admin.force_register_help') }}</div>
                </div>

                <div class="mb-3 form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="allow_passwordless" id="allow_passwordless" @checked($allowPasswordless)>

                    <label class="form-check-label" for="allow_passwordless">
                        {{ trans('discord-integration::admin.allow_passwordless') }}
                    </label>

                    <div class="form-text">{{ trans('discord-integration::admin.allow_passwordless_help') }}</div>
                </div>

                <div class="mb-3 form-check form-switch">
                    <input class="form-check-input @error('customizable_email') is-invalid @enderror" type="checkbox" name="customizable_email" id="customizable_email" @checked($customizableEmail)>

                    <label class="form-check-label" for="customizable_email">
                        {{ trans('discord-integration::admin.customizable_email') }}
                    </label>

                    <div class="form-text">{{ trans('discord-integration::admin.customizable_email_help') }}</div>

                    @error('customizable_email')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                </div>

                <div class="mb-3 form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="match_by_email" id="match_by_email" @checked($matchByEmail)>

                    <label class="form-check-label" for="match_by_email">
                        {{ trans('discord-integration::admin.match_by_email') }}
                    </label>

                    <div class="form-text">{{ trans('discord-integration::admin.match_by_email_help') }}</div>
                </div>

                <div class="mb-3 form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="bypass_maintenance" id="bypass_maintenance" @checked($bypassMaintenance)>

                    <label class="form-check-label" for="bypass_maintenance">
                        {{ trans('discord-integration::admin.bypass_maintenance') }}
                    </label>

                    <div class="form-text">{{ trans('discord-integration::admin.bypass_maintenance_help') }}</div>
                </div>

                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save"></i> {{ trans('messages.actions.save') }}
                </button>
            </form>
        </div>
    </div>

    <div class="card mt-4">
        <div class="card-body">
            <h5 class="card-title text-danger">
                <i class="bi bi-exclamation-triangle"></i> {{ trans('discord-integration::admin.force_login') }}
            </h5>
            <p class="form-text">{{ trans('discord-integration::admin.force_login_help') }}</p>

            @if($forceLogin)
                <div class="alert alert-warning">{{ trans('discord-integration::admin.force_login_active') }}</div>

                <form method="POST" action="{{ route('discord-integration.admin.authentication.force-login.disable') }}">
                    @csrf
                    @method('DELETE')

                    <button type="submit" class="btn btn-outline-secondary">
                        {{ trans('discord-integration::admin.force_login_disable') }}
                    </button>
                </form>
            @elseif($unlinkedUsersCount > 0)
                <div class="alert alert-info mb-0">
                    {{ trans('discord-integration::admin.force_login_precondition', ['count' => $unlinkedUsersCount]) }}
                </div>
            @else
                <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#forceLoginModal">
                    {{ trans('discord-integration::admin.force_login_enable') }}
                </button>
            @endif
        </div>
    </div>

    @if(! $forceLogin && $unlinkedUsersCount === 0)
        <div class="modal fade" id="forceLoginModal" tabindex="-1" role="dialog" aria-labelledby="forceLoginModalLabel" aria-modal="true" data-bs-backdrop="static">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2 class="modal-title text-danger" id="forceLoginModalLabel">
                            <i class="bi bi-exclamation-triangle"></i> {{ trans('discord-integration::admin.force_login') }}
                        </h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p>{{ trans('discord-integration::admin.force_login_confirm_body') }}</p>

                        <form method="POST" action="{{ route('discord-integration.admin.authentication.force-login') }}" id="forceLoginForm">
                            @csrf

                            <div class="mb-3">
                                <label class="form-label" for="forceLoginPassword">{{ trans('auth.current_password') }}</label>
                                <input type="password" class="form-control @error('password') is-invalid @enderror" name="password" id="forceLoginPassword" required>

                                @error('password')
                                <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                                @enderror
                            </div>

                            @include('elements.captcha', ['center' => true])

                            <div class="d-flex justify-content-end gap-2 mt-3">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                    {{ trans('messages.actions.cancel') }}
                                </button>

                                <button type="submit" class="btn btn-danger">
                                    {{ trans('discord-integration::admin.force_login_enable') }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <div class="modal fade" id="emailMatchWarningModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title text-danger">
                        <i class="bi bi-exclamation-triangle"></i>
                        {{ trans('discord-integration::admin.email_warning.title') }}
                    </h5>
                </div>
                <div class="modal-body">
                    <p>{{ trans('discord-integration::admin.email_warning.body') }}</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" id="emailMatchCancel">
                        {{ trans('messages.actions.cancel') }}
                    </button>
                    <button type="button" class="btn btn-danger" id="emailMatchConfirm" disabled>
                        {{ trans('discord-integration::admin.email_warning.confirm') }}
                    </button>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('footer-scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const matchByEmail = document.getElementById('match_by_email');
            const customizableEmail = document.getElementById('customizable_email');
            const modalElement = document.getElementById('emailMatchWarningModal');
            const modal = new bootstrap.Modal(modalElement);
            const confirmButton = document.getElementById('emailMatchConfirm');
            const confirmLabel = confirmButton.textContent.trim();
            let countdownTimer = null;

            // customizable_email is kept mutually exclusive with match_by_email
            // (see Admin\AuthenticationController::save() - "allow duplicates"
            // is checked the same way, but it now lives on the Configuration
            // page, so it can't be reflected here client-side), so enabling it
            // here turns match_by_email back off instead of letting an invalid
            // combination reach the server.
            customizableEmail.addEventListener('change', function () {
                if (customizableEmail.checked) {
                    matchByEmail.checked = false;
                }
            });

            matchByEmail.addEventListener('change', function () {
                if (!matchByEmail.checked) {
                    return; // disabling never needs a warning
                }

                matchByEmail.checked = false;

                let remaining = 5;
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

                modal.show();
            });

            confirmButton.addEventListener('click', function () {
                matchByEmail.checked = true;
                customizableEmail.checked = false;
                modal.hide();
            });

            modalElement.addEventListener('hidden.bs.modal', function () {
                clearInterval(countdownTimer);
            });

            @if($errors->has('password'))
                bootstrap.Modal.getOrCreateInstance(document.getElementById('forceLoginModal')).show();
            @endif
        });
    </script>
@endpush
