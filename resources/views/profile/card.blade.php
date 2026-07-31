<p>{{ trans('discord-integration::messages.profile.linked_as', ['name' => $discordAccount->name]) }}</p>

@if($showBypass2fa)
    <form method="POST" action="{{ route('discord-integration.bypass-2fa') }}" class="mb-3">
        @csrf
        <input type="hidden" name="bypass_2fa" value="0">

        <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" name="bypass_2fa" id="bypass_2fa" value="1" onchange="this.form.submit()" @checked($discordAccount->bypass_2fa)>

            <label class="form-check-label" for="bypass_2fa">
                {{ trans('discord-integration::messages.profile.bypass_2fa') }}
            </label>
        </div>
    </form>
@endif

@if($passwordless)
    <form method="POST" action="{{ route('discord-integration.set-password') }}">
        @csrf

        <p class="text-muted">{{ trans('discord-integration::messages.profile.no_password') }}</p>

        <div class="mb-2">
            <input type="password" class="form-control @error('password') is-invalid @enderror" name="password" placeholder="{{ trans('auth.password') }}" required autocomplete="new-password">

            @error('password')
                <span class="invalid-feedback" role="alert">
                    <strong>{{ $message }}</strong>
                </span>
            @enderror
        </div>

        <div class="mb-2">
            <input type="password" class="form-control" name="password_confirmation" placeholder="{{ trans('auth.confirm_password') }}" required autocomplete="new-password">
        </div>

        <button type="submit" class="btn btn-primary btn-sm">
            {{ trans('discord-integration::messages.profile.set_password') }}
        </button>
    </form>
@endif
