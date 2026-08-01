@extends('admin.layouts.admin')

@section('title', trans('discord-integration::admin.nav.roles'))

@section('content')
    @if(! $botAvailable)
        <div class="alert alert-info">
            <i class="bi bi-info-circle"></i> {{ trans('discord-integration::admin.role_sync.bot_unavailable') }}
        </div>
    @else
        <div class="card shadow mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">{{ trans('discord-integration::admin.role_sync.title') }}</h5>

                <div class="d-flex gap-2">
                    <a href="{{ route('discord-integration.admin.roles.export') }}" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-download"></i> {{ trans('discord-integration::admin.role_sync.export') }}
                    </a>

                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#roleSyncImportModal">
                        <i class="bi bi-upload"></i> {{ trans('discord-integration::admin.role_sync.import') }}
                    </button>

                    <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#roleSyncModal">
                        <i class="bi bi-plus-lg"></i> {{ trans('discord-integration::admin.role_sync.create') }}
                    </button>
                </div>
            </div>
            <div class="card-body">
                <p class="text-muted">{{ trans('discord-integration::admin.role_sync.description') }}</p>

                @if($roleSyncs->isEmpty())
                    <p class="text-muted mb-0">{{ trans('discord-integration::admin.role_sync.empty') }}</p>
                @else
                    <div class="table-responsive">
                        <table class="table table-striped align-middle">
                            <thead>
                                <tr>
                                    <th>{{ trans('discord-integration::admin.role_sync.guild_id') }}</th>
                                    <th>{{ trans('discord-integration::admin.role_sync.role_id') }}</th>
                                    <th>{{ trans('discord-integration::admin.role_sync.conditions') }}</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @php
                                    $guildIcon = fn ($guildId) => \Azuriom\Plugin\DiscordIntegration\Support\DiscordAvatar::guildIconUrl(
                                        collect($guilds ?? [])->firstWhere('id', $guildId) ?? []
                                    );
                                @endphp

                                @foreach($roleSyncs as $roleSync)
                                    <tr>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                @if($guildIcon($roleSync->discord_guild_id))
                                                    <img src="{{ $guildIcon($roleSync->discord_guild_id) }}" alt="" width="20" height="20" class="rounded flex-shrink-0">
                                                @endif
                                                {{ optional(collect($guilds ?? [])->firstWhere('id', $roleSync->discord_guild_id))['name'] ?? $roleSync->discord_guild_id }}
                                            </div>
                                        </td>
                                        <td>
                                            <code>{{ $roleSync->discord_role_id }}</code>

                                            @if($roleSync->overwrite)
                                                <span class="badge bg-warning text-dark" title="{{ trans('discord-integration::admin.role_sync.overwrite_help') }}">
                                                    {{ trans('discord-integration::admin.role_sync.overwrite') }}
                                                </span>
                                            @endif

                                            @php
                                                $permissionError = $permissionErrors->get($roleSync->discord_guild_id.'|'.$roleSync->discord_role_id);
                                            @endphp

                                            @if($permissionError)
                                                <div class="text-danger small mt-1">
                                                    <i class="bi bi-exclamation-triangle-fill"></i>
                                                    {{ trans('discord-integration::admin.role_sync.permission_error', ['when' => $permissionError->created_at->diffForHumans()]) }}
                                                </div>
                                            @endif
                                        </td>
                                        <td>
                                            <ul class="mb-0 ps-3">
                                                @if($roleSync->site_role_ids)
                                                    <li>{{ trans('discord-integration::admin.role_sync.condition_site_roles', ['roles' => $siteRoles->whereIn('id', $roleSync->site_role_ids)->pluck('name')->join(', ')]) }}</li>
                                                @endif

                                                @if($roleSync->shop_package_id && $shopPackages !== null)
                                                    <li>{{ trans('discord-integration::admin.role_sync.condition_shop_package', ['package' => optional($shopPackages->firstWhere('id', $roleSync->shop_package_id))->name ?? $roleSync->shop_package_id]) }}</li>
                                                @endif

                                                @if($roleSync->balance_min !== null || $roleSync->balance_max !== null)
                                                    <li>{{ trans('discord-integration::admin.role_sync.condition_balance', ['min' => $roleSync->balance_min ?? '-', 'max' => $roleSync->balance_max ?? '-']) }}</li>
                                                @endif

                                                @if(! $roleSync->site_role_ids && ! $roleSync->shop_package_id && $roleSync->balance_min === null && $roleSync->balance_max === null)
                                                    <li class="text-muted">{{ trans('discord-integration::admin.role_sync.no_conditions') }}</li>
                                                @endif
                                            </ul>
                                        </td>
                                        <td class="text-end">
                                            <button type="button" class="btn btn-sm btn-outline-secondary"
                                                    data-bs-toggle="modal" data-bs-target="#roleSyncModal"
                                                    data-id="{{ $roleSync->id }}"
                                                    data-guild-id="{{ $roleSync->discord_guild_id }}"
                                                    data-role-id="{{ $roleSync->discord_role_id }}"
                                                    data-overwrite="{{ $roleSync->overwrite ? '1' : '0' }}"
                                                    data-site-role-ids="{{ json_encode($roleSync->site_role_ids ?? []) }}"
                                                    data-shop-package-id="{{ $roleSync->shop_package_id }}"
                                                    data-balance-min="{{ $roleSync->balance_min }}"
                                                    data-balance-max="{{ $roleSync->balance_max }}"
                                                    title="{{ trans('messages.actions.edit') }}">
                                                <i class="bi bi-pencil"></i>
                                            </button>

                                            <a href="{{ route('discord-integration.admin.roles.destroy', $roleSync) }}" class="btn btn-sm btn-outline-danger" data-confirm="delete" title="{{ trans('messages.actions.remove') }}">
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

        <div class="modal fade" id="roleSyncModal" tabindex="-1" role="dialog" aria-labelledby="roleSyncModalLabel" aria-modal="true">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2 class="modal-title" id="roleSyncModalLabel">{{ trans('discord-integration::admin.role_sync.create') }}</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <form method="POST" action="{{ route('discord-integration.admin.roles.store') }}" id="roleSyncForm">
                            @csrf
                            <input type="hidden" name="_method" id="roleSyncMethod" value="POST">
                            <input type="hidden" name="role_sync_id" id="roleSyncId" value="{{ old('role_sync_id') }}">

                            <h6>{{ trans('discord-integration::admin.role_sync.conditions_title') }}</h6>
                            <p class="form-text">{{ trans('discord-integration::admin.role_sync.conditions_help') }}</p>

                            @if($siteRoles->isNotEmpty())
                                <div class="mb-3">
                                    <label class="form-label">{{ trans('discord-integration::admin.role_sync.condition_site_roles_label') }}</label>
                                    <div class="form-text mb-1">{{ trans('discord-integration::admin.role_sync.condition_site_roles_help') }}</div>

                                    @foreach($siteRoles as $role)
                                        <div class="form-check">
                                            <input class="form-check-input role-sync-site-role" type="checkbox" name="site_role_ids[]" value="{{ $role->id }}" id="siteRole{{ $role->id }}" {{ collect(old('site_role_ids'))->contains($role->id) ? 'checked' : '' }}>

                                            <label class="form-check-label" for="siteRole{{ $role->id }}">{{ $role->name }}</label>
                                        </div>
                                    @endforeach

                                    @error('site_role_ids.*')
                                    <div class="text-danger small">{{ $message }}</div>
                                    @enderror
                                </div>
                            @endif

                            @if($shopPackages !== null && $shopPackages->isNotEmpty())
                                <div class="mb-3">
                                    <label class="form-label" for="shopPackageId">{{ trans('discord-integration::admin.role_sync.condition_shop_package_label') }}</label>
                                    <select class="form-select @error('shop_package_id') is-invalid @enderror" name="shop_package_id" id="shopPackageId">
                                        <option value="">{{ trans('discord-integration::admin.role_sync.no_condition') }}</option>

                                        @foreach($shopPackages as $package)
                                            <option value="{{ $package->id }}" {{ (string) old('shop_package_id') === (string) $package->id ? 'selected' : '' }}>{{ $package->name }}</option>
                                        @endforeach
                                    </select>

                                    @error('shop_package_id')
                                    <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                                    @enderror
                                </div>
                            @endif

                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label class="form-label" for="balanceMin">{{ trans('discord-integration::admin.role_sync.balance_min') }}</label>
                                    <input type="number" min="0" step="0.01" class="form-control @error('balance_min') is-invalid @enderror" name="balance_min" id="balanceMin" value="{{ old('balance_min') }}">

                                    @error('balance_min')
                                    <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                                    @enderror
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label" for="balanceMax">{{ trans('discord-integration::admin.role_sync.balance_max') }}</label>
                                    <input type="number" min="0" step="0.01" class="form-control @error('balance_max') is-invalid @enderror" name="balance_max" id="balanceMax" value="{{ old('balance_max') }}">

                                    @error('balance_max')
                                    <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                                    @enderror
                                </div>
                            </div>

                            <hr>

                            <h6>{{ trans('discord-integration::admin.role_sync.discord_role_title') }}</h6>

                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label class="form-label" for="discordGuildId">
                                        {{ trans('discord-integration::admin.role_sync.guild_id') }}

                                        @if($guilds !== null)
                                            <a href="#" id="discordGuildIdPicker">({{ trans('discord-integration::admin.pick') }})</a>
                                        @endif
                                    </label>
                                    <input type="text" class="form-control @error('discord_guild_id') is-invalid @enderror" name="discord_guild_id" id="discordGuildId" value="{{ old('discord_guild_id') }}" required>

                                    @error('discord_guild_id')
                                    <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                                    @enderror
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label" for="discordRoleId">
                                        {{ trans('discord-integration::admin.role_sync.role_id') }}
                                        <a href="#" id="discordRoleIdPicker">({{ trans('discord-integration::admin.pick') }})</a>
                                    </label>
                                    <input type="text" class="form-control @error('discord_role_id') is-invalid @enderror" name="discord_role_id" id="discordRoleId" value="{{ old('discord_role_id') }}" required>

                                    @error('discord_role_id')
                                    <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                                    @enderror
                                </div>
                            </div>

                            <div class="mb-3 form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="overwrite" id="overwrite" {{ old('overwrite') ? 'checked' : '' }}>

                                <label class="form-check-label" for="overwrite">
                                    {{ trans('discord-integration::admin.role_sync.overwrite') }}
                                </label>

                                <div class="form-text">{{ trans('discord-integration::admin.role_sync.overwrite_help') }}</div>
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

        <div class="modal fade" id="roleSyncImportModal" tabindex="-1" role="dialog" aria-labelledby="roleSyncImportModalLabel" aria-modal="true">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2 class="modal-title" id="roleSyncImportModalLabel">{{ trans('discord-integration::admin.role_sync.import') }}</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p class="form-text">{{ trans('discord-integration::admin.role_sync.import_help') }}</p>

                        <form method="POST" action="{{ route('discord-integration.admin.roles.import') }}" enctype="multipart/form-data">
                            @csrf

                            <div class="mb-3">
                                <input type="file" class="form-control @error('file') is-invalid @enderror" name="file" accept="application/json,.json" required>

                                @error('file')
                                <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                                @enderror
                            </div>

                            <div class="d-flex justify-content-end gap-2">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                    {{ trans('messages.actions.cancel') }}
                                </button>

                                <button type="submit" class="btn btn-primary">
                                    {{ trans('discord-integration::admin.role_sync.import') }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        @include('discord-integration::admin.partials.discord-picker-modal')

        @push('footer-scripts')
            <script>
                document.addEventListener('DOMContentLoaded', function () {
                    const modal = document.getElementById('roleSyncModal');
                    const form = document.getElementById('roleSyncForm');
                    const methodField = document.getElementById('roleSyncMethod');
                    const modalLabel = document.getElementById('roleSyncModalLabel');
                    const createUrl = '{{ route('discord-integration.admin.roles.store') }}';
                    const updateUrlTemplate = '{{ route('discord-integration.admin.roles.update', ['roleSync' => '__ID__']) }}';
                    const createLabel = @json(trans('discord-integration::admin.role_sync.create'));
                    const editLabel = @json(trans('discord-integration::admin.role_sync.edit'));

                    function setEditMode(id) {
                        modalLabel.textContent = editLabel;
                        form.action = updateUrlTemplate.replace('__ID__', id);
                        methodField.value = 'PUT';
                        document.getElementById('roleSyncId').value = id;
                    }

                    function setCreateMode() {
                        modalLabel.textContent = createLabel;
                        form.action = createUrl;
                        methodField.value = 'POST';
                        document.getElementById('roleSyncId').value = '';
                    }

                    modal.addEventListener('show.bs.modal', function (event) {
                        const button = event.relatedTarget;

                        // A null relatedTarget means the modal was reopened
                        // programmatically (see the validation-error check
                        // below) rather than via a create/edit button click -
                        // the form already holds the previous submission's
                        // old() values in that case, so leave it untouched
                        // instead of wiping them out with form.reset().
                        if (! button) {
                            return;
                        }

                        form.reset();

                        const id = button.dataset.id;

                        if (id) {
                            setEditMode(id);

                            document.getElementById('discordGuildId').value = button.dataset.guildId;
                            document.getElementById('discordRoleId').value = button.dataset.roleId;
                            document.getElementById('overwrite').checked = button.dataset.overwrite === '1';
                            document.getElementById('balanceMin').value = button.dataset.balanceMin !== 'null' ? button.dataset.balanceMin : '';
                            document.getElementById('balanceMax').value = button.dataset.balanceMax !== 'null' ? button.dataset.balanceMax : '';

                            const shopSelect = document.getElementById('shopPackageId');
                            if (shopSelect) {
                                shopSelect.value = button.dataset.shopPackageId !== 'null' ? button.dataset.shopPackageId : '';
                            }

                            JSON.parse(button.dataset.siteRoleIds || '[]').forEach(function (roleId) {
                                const checkbox = document.getElementById('siteRole' + roleId);

                                if (checkbox) {
                                    checkbox.checked = true;
                                }
                            });
                        } else {
                            setCreateMode();
                        }
                    });

                    @if($errors->hasAny(['discord_guild_id', 'discord_role_id', 'site_role_ids', 'site_role_ids.*', 'shop_package_id', 'balance_min', 'balance_max']))
                        @if(old('role_sync_id'))
                            setEditMode(@json(old('role_sync_id')));
                        @else
                            setCreateMode();
                        @endif

                        bootstrap.Modal.getOrCreateInstance(modal).show();
                    @endif

                    @if($errors->has('file'))
                        bootstrap.Modal.getOrCreateInstance(document.getElementById('roleSyncImportModal')).show();
                    @endif

                    @if($guilds !== null)
                        document.getElementById('discordGuildIdPicker').addEventListener('click', function (e) {
                            e.preventDefault();

                            DiscordPicker.open({
                                title: @json(trans('discord-integration::admin.pick_guild_title')),
                                items: @json(\Azuriom\Plugin\DiscordIntegration\Support\DiscordAvatar::guildPickerItems($guilds)),
                                emptyText: @json(trans('discord-integration::admin.pick_guild_empty')),
                                onSelect: function (item) {
                                    document.getElementById('discordGuildId').value = item.id;
                                    DiscordPicker.modal.hide();
                                },
                            });
                        });
                    @endif

                    document.getElementById('discordRoleIdPicker').addEventListener('click', function (e) {
                        e.preventDefault();

                        const guildId = document.getElementById('discordGuildId').value.trim();

                        if (! guildId) {
                            DiscordPicker.open({
                                title: @json(trans('discord-integration::admin.pick_role_title')),
                                items: [],
                                emptyText: @json(trans('discord-integration::admin.pick_role_no_guild')),
                                onSelect: function () {},
                            });

                            return;
                        }

                        DiscordPicker.open({
                            title: @json(trans('discord-integration::admin.pick_role_title')),
                            items: [],
                            emptyText: @json(trans('discord-integration::admin.pick_role_loading')),
                            onSelect: function () {},
                        });

                        fetch(@json(route('discord-integration.admin.roles.guild-roles')) + '?guild_id=' + encodeURIComponent(guildId))
                            .then(function (response) {
                                return response.ok ? response.json() : Promise.reject();
                            })
                            .then(function (roles) {
                                DiscordPicker.open({
                                    title: @json(trans('discord-integration::admin.pick_role_title')),
                                    items: roles.map(function (role) {
                                        return {id: role.id, label: role.name};
                                    }),
                                    emptyText: @json(trans('discord-integration::admin.pick_role_empty')),
                                    onSelect: function (item) {
                                        document.getElementById('discordRoleId').value = item.id;
                                        DiscordPicker.modal.hide();
                                    },
                                });
                            })
                            .catch(function () {
                                DiscordPicker.open({
                                    title: @json(trans('discord-integration::admin.pick_role_title')),
                                    items: [],
                                    emptyText: @json(trans('discord-integration::admin.pick_role_error')),
                                    onSelect: function () {},
                                });
                            });
                    });
                });
            </script>
        @endpush
    @endif
@endsection
