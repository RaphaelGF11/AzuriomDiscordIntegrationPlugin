@extends('layouts.app')

@section('title', trans('discord-integration::messages.choose_method.title'))

@section('content')
<div class="row justify-content-center">
    <div class="col-md-9 col-lg-6">
        <h1>{{ trans('discord-integration::messages.choose_method.title') }}</h1>

        <div class="card">
            <div class="card-body d-grid gap-2">
                <a href="{{ route($intent === 'register' ? 'register' : 'login', ['sso' => 1]) }}" class="btn btn-primary btn-lg">
                    {{ trans('discord-integration::messages.choose_method.sso_button', ['name' => game()->name()]) }}
                </a>

                <a href="{{ route('discord-integration.redirect', ['intent' => $intent]) }}" class="btn btn-lg" style="background-color: #5865F2; border-color: #5865F2; color: #fff;">
                    <i class="bi bi-discord"></i> {{ trans('discord-integration::messages.choose_method.discord_button') }}
                </a>
            </div>
        </div>
    </div>
</div>
@endsection
