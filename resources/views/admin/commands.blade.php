@extends('admin.layouts.admin')

@section('title', trans('discord-integration::admin.nav.interactions'))

@section('content')
    @if(! $botAvailable)
        <div class="alert alert-info">
            <i class="bi bi-info-circle"></i> {{ trans('discord-integration::admin.commands.bot_unavailable') }}
        </div>
    @else
        <div class="alert alert-info">
            <p><i class="bi bi-info-circle"></i> {!! trans('discord-integration::admin.commands.setup_info', ['url' => route('discord-integration.admin.configuration')]) !!}</p>

            <ol class="mb-0">
                <li>{{ trans('discord-integration::admin.commands.setup_step_1') }}</li>
                <li>{!! trans('discord-integration::admin.commands.setup_step_2', ['url' => $interactionsUrl]) !!}</li>
                <li>{{ trans('discord-integration::admin.commands.setup_step_3') }}</li>
            </ol>

            @if(! $interactionsPublicKey)
                <p class="mb-0 mt-2 text-warning"><i class="bi bi-exclamation-triangle"></i> {{ trans('discord-integration::admin.commands.setup_key_missing') }}</p>
            @endif
        </div>

        <div class="card shadow mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0">{{ trans('discord-integration::admin.commands.interactions_public_key') }}</h5>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('discord-integration.admin.commands.public-key') }}">
                    @csrf

                    <div class="mb-3">
                        <div class="form-text mb-2">{{ trans('discord-integration::admin.commands.interactions_public_key_help') }}</div>

                        <input type="text" class="form-control @error('interactions_public_key') is-invalid @enderror" id="interactionsPublicKey" name="interactions_public_key" value="{{ old('interactions_public_key', $interactionsPublicKey) }}">

                        @error('interactions_public_key')
                        <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                        @enderror
                    </div>

                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-save"></i> {{ trans('messages.actions.save') }}
                    </button>
                </form>
            </div>
        </div>

        <div class="card shadow mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">{{ trans('discord-integration::admin.commands.title') }}</h5>

                <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#commandModal">
                    <i class="bi bi-plus-lg"></i> {{ trans('discord-integration::admin.commands.create') }}
                </button>
            </div>
            <div class="card-body">
                <p class="text-muted">{{ trans('discord-integration::admin.commands.description') }}</p>

                @if($commands->isEmpty())
                    <p class="text-muted mb-0">{{ trans('discord-integration::admin.commands.empty') }}</p>
                @else
                    <div class="table-responsive">
                        <table class="table table-striped align-middle">
                            <thead>
                                <tr>
                                    <th>{{ trans('discord-integration::admin.commands.name') }}</th>
                                    <th>{{ trans('discord-integration::admin.commands.scope') }}</th>
                                    <th>{{ trans('discord-integration::admin.commands.enabled') }}</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @php
                                    $guildLabel = fn ($guildId) => optional(collect($guilds ?? [])->firstWhere('id', $guildId))['name'] ?? $guildId;
                                    $guildIcon = fn ($guildId) => \Azuriom\Plugin\DiscordIntegration\Support\DiscordAvatar::guildIconUrl(
                                        collect($guilds ?? [])->firstWhere('id', $guildId) ?? []
                                    );
                                @endphp

                                @foreach($commands as $command)
                                    <tr>
                                        <td><code>/{{ $command->name }}</code></td>
                                        <td>
                                            @if($command->scope === 'guild')
                                                <span class="d-inline-flex align-items-center gap-1">
                                                    @if($guildIcon($command->discord_guild_id))
                                                        <img src="{{ $guildIcon($command->discord_guild_id) }}" alt="" width="20" height="20" class="rounded flex-shrink-0">
                                                    @endif
                                                    {{ $guildLabel($command->discord_guild_id) }}
                                                </span>
                                            @else
                                                <span class="badge bg-secondary">{{ trans('discord-integration::admin.commands.scope_global') }}</span>
                                            @endif
                                        </td>
                                        <td>
                                            @if($command->enabled)
                                                <i class="bi bi-check-circle text-success"></i>
                                            @else
                                                <i class="bi bi-x-circle text-muted"></i>
                                            @endif
                                        </td>
                                        <td class="text-end">
                                            <a href="{{ route('discord-integration.admin.commands.script', $command) }}" class="btn btn-sm btn-outline-primary" title="{{ trans('discord-integration::admin.commands.edit_script') }}">
                                                <i class="bi bi-diagram-3"></i>
                                            </a>

                                            <button type="button" class="btn btn-sm btn-outline-secondary"
                                                    data-bs-toggle="modal" data-bs-target="#commandModal"
                                                    data-id="{{ $command->id }}"
                                                    data-name="{{ $command->name }}"
                                                    data-description="{{ $command->description }}"
                                                    data-scope="{{ $command->scope }}"
                                                    data-guild-id="{{ $command->discord_guild_id }}"
                                                    data-enabled="{{ $command->enabled ? '1' : '0' }}"
                                                    data-fallback-message="{{ $command->fallback_message }}"
                                                    data-options="{{ json_encode($command->options ?? []) }}"
                                                    title="{{ trans('messages.actions.edit') }}">
                                                <i class="bi bi-pencil"></i>
                                            </button>

                                            <a href="{{ route('discord-integration.admin.commands.destroy', $command) }}" class="btn btn-sm btn-outline-danger" data-confirm="delete" title="{{ trans('messages.actions.remove') }}">
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

        <div class="card shadow mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">{{ trans('discord-integration::admin.forms.title') }}</h5>

                <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#formModal">
                    <i class="bi bi-plus-lg"></i> {{ trans('discord-integration::admin.forms.create') }}
                </button>
            </div>
            <div class="card-body">
                <p class="text-muted">{{ trans('discord-integration::admin.forms.description') }}</p>

                @if($forms->isEmpty())
                    <p class="text-muted mb-0">{{ trans('discord-integration::admin.forms.empty') }}</p>
                @else
                    <div class="table-responsive">
                        <table class="table table-striped align-middle">
                            <thead>
                                <tr>
                                    <th>{{ trans('discord-integration::admin.forms.name') }}</th>
                                    <th>{{ trans('discord-integration::admin.forms.title_label') }}</th>
                                    <th>{{ trans('discord-integration::admin.forms.enabled') }}</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($forms as $form)
                                    <tr>
                                        <td><code>{{ $form->name }}</code></td>
                                        <td>{{ $form->title }}</td>
                                        <td>
                                            @if($form->enabled)
                                                <i class="bi bi-check-circle text-success"></i>
                                            @else
                                                <i class="bi bi-x-circle text-muted"></i>
                                            @endif
                                        </td>
                                        <td class="text-end">
                                            <a href="{{ route('discord-integration.admin.forms.script', $form) }}" class="btn btn-sm btn-outline-primary" title="{{ trans('discord-integration::admin.forms.edit_script') }}">
                                                <i class="bi bi-diagram-3"></i>
                                            </a>

                                            <button type="button" class="btn btn-sm btn-outline-secondary"
                                                    data-bs-toggle="modal" data-bs-target="#formModal"
                                                    data-id="{{ $form->id }}"
                                                    data-name="{{ $form->name }}"
                                                    data-title="{{ $form->title }}"
                                                    data-enabled="{{ $form->enabled ? '1' : '0' }}"
                                                    data-fallback-message="{{ $form->fallback_message }}"
                                                    data-fields="{{ json_encode($form->fields ?? []) }}"
                                                    title="{{ trans('messages.actions.edit') }}">
                                                <i class="bi bi-pencil"></i>
                                            </button>

                                            <a href="{{ route('discord-integration.admin.forms.destroy', $form) }}" class="btn btn-sm btn-outline-danger" data-confirm="delete" title="{{ trans('messages.actions.remove') }}">
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

        <div class="modal fade" id="commandModal" tabindex="-1" role="dialog" aria-labelledby="commandModalLabel" aria-modal="true">
            <div class="modal-dialog modal-lg" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2 class="modal-title" id="commandModalLabel">{{ trans('discord-integration::admin.commands.create') }}</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <form method="POST" action="{{ route('discord-integration.admin.commands.store') }}" id="commandForm">
                            @csrf
                            <input type="hidden" name="_method" id="commandMethod" value="POST">
                            <input type="hidden" name="command_id" id="commandId" value="{{ old('command_id') }}">

                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label class="form-label" for="commandName">{{ trans('discord-integration::admin.commands.name') }}</label>
                                    <input type="text" class="form-control @error('name') is-invalid @enderror" name="name" id="commandName" value="{{ old('name') }}" pattern="[a-z0-9_-]{1,32}" required>

                                    @error('name')
                                    <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                                    @enderror
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label" for="commandDescription">{{ trans('discord-integration::admin.commands.description_label') }}</label>
                                    <input type="text" class="form-control @error('description') is-invalid @enderror" name="description" id="commandDescription" value="{{ old('description') }}" maxlength="100" required>

                                    @error('description')
                                    <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                                    @enderror
                                </div>
                            </div>

                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label class="form-label" for="commandScope">{{ trans('discord-integration::admin.commands.scope') }}</label>
                                    <select class="form-select @error('scope') is-invalid @enderror" name="scope" id="commandScope">
                                        <option value="global" {{ old('scope') === 'global' ? 'selected' : '' }}>{{ trans('discord-integration::admin.commands.scope_global') }}</option>
                                        <option value="guild" {{ old('scope') === 'guild' ? 'selected' : '' }}>{{ trans('discord-integration::admin.commands.scope_guild') }}</option>
                                    </select>

                                    @error('scope')
                                    <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                                    @enderror
                                </div>

                                <div class="col-md-6" id="commandGuildIdWrapper">
                                    <label class="form-label" for="commandGuildId">
                                        {{ trans('discord-integration::admin.role_sync.guild_id') }}

                                        @if($guilds !== null)
                                            <a href="#" id="commandGuildIdPicker">({{ trans('discord-integration::admin.pick') }})</a>
                                        @endif
                                    </label>
                                    <input type="text" class="form-control @error('discord_guild_id') is-invalid @enderror" name="discord_guild_id" id="commandGuildId" value="{{ old('discord_guild_id') }}">

                                    @error('discord_guild_id')
                                    <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                                    @enderror
                                </div>
                            </div>

                            <div class="mb-3 form-check form-switch">
                                <input type="hidden" name="enabled" value="0">
                                <input class="form-check-input" type="checkbox" name="enabled" id="commandEnabled" value="1" {{ old('enabled', '1') ? 'checked' : '' }}>

                                <label class="form-check-label" for="commandEnabled">
                                    {{ trans('discord-integration::admin.commands.enabled') }}
                                </label>
                            </div>

                            <div class="mb-3">
                                <label class="form-label" for="commandFallbackMessage">{{ trans('discord-integration::admin.commands.fallback_message') }}</label>
                                <textarea class="form-control @error('fallback_message') is-invalid @enderror" name="fallback_message" id="commandFallbackMessage" rows="2" maxlength="2000">{{ old('fallback_message') }}</textarea>
                                <div class="form-text">{{ trans('discord-integration::admin.commands.fallback_message_help') }}</div>

                                @error('fallback_message')
                                <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                                @enderror
                            </div>

                            <hr>

                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">{{ trans('discord-integration::admin.commands.options.title') }}</h6>
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="commandOptionsAdd">
                                    <i class="bi bi-plus-lg"></i> {{ trans('discord-integration::admin.commands.options.add') }}
                                </button>
                            </div>
                            <div id="commandOptionsList" class="mb-3"></div>
                            <input type="hidden" name="options" id="commandOptions" value="{{ old('options', '[]') }}">

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

        <div class="modal fade" id="formModal" tabindex="-1" role="dialog" aria-labelledby="formModalLabel" aria-modal="true">
            <div class="modal-dialog modal-lg" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2 class="modal-title" id="formModalLabel">{{ trans('discord-integration::admin.forms.create') }}</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <form method="POST" action="{{ route('discord-integration.admin.forms.store') }}" id="formForm">
                            @csrf
                            <input type="hidden" name="_method" id="formMethod" value="POST">
                            <input type="hidden" name="form_id" id="formId" value="{{ old('form_id') }}">

                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label class="form-label" for="formName">{{ trans('discord-integration::admin.forms.name') }}</label>
                                    <input type="text" class="form-control @error('name') is-invalid @enderror" name="name" id="formName" value="{{ old('name') }}" maxlength="64" required>
                                    <div class="form-text">{{ trans('discord-integration::admin.forms.name_help') }}</div>

                                    @error('name')
                                    <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                                    @enderror
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label" for="formTitle">{{ trans('discord-integration::admin.forms.title_label') }}</label>
                                    <input type="text" class="form-control @error('title') is-invalid @enderror" name="title" id="formTitle" value="{{ old('title') }}" maxlength="45" required>

                                    @error('title')
                                    <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                                    @enderror
                                </div>
                            </div>

                            <div class="mb-3 form-check form-switch">
                                <input type="hidden" name="enabled" value="0">
                                <input class="form-check-input" type="checkbox" name="enabled" id="formEnabled" value="1" {{ old('enabled', '1') ? 'checked' : '' }}>

                                <label class="form-check-label" for="formEnabled">
                                    {{ trans('discord-integration::admin.forms.enabled') }}
                                </label>
                            </div>

                            <div class="mb-3">
                                <label class="form-label" for="formFallbackMessage">{{ trans('discord-integration::admin.forms.fallback_message') }}</label>
                                <textarea class="form-control @error('fallback_message') is-invalid @enderror" name="fallback_message" id="formFallbackMessage" rows="2" maxlength="2000">{{ old('fallback_message') }}</textarea>
                                <div class="form-text">{{ trans('discord-integration::admin.forms.fallback_message_help') }}</div>

                                @error('fallback_message')
                                <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                                @enderror
                            </div>

                            <hr>

                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">{{ trans('discord-integration::admin.forms.fields.title') }}</h6>
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="formFieldsAdd">
                                    <i class="bi bi-plus-lg"></i> {{ trans('discord-integration::admin.forms.fields.add') }}
                                </button>
                            </div>
                            <div id="formFieldsList" class="mb-3"></div>
                            <input type="hidden" name="fields" id="formFields" value="{{ old('fields', '[]') }}">

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
        @include('discord-integration::admin.partials.command-builder-scripts')
        @include('discord-integration::admin.partials.form-builder-scripts')

        @push('footer-scripts')
            <script>
                document.addEventListener('DOMContentLoaded', function () {
                    const modal = document.getElementById('commandModal');
                    const form = document.getElementById('commandForm');
                    const methodField = document.getElementById('commandMethod');
                    const modalLabel = document.getElementById('commandModalLabel');
                    const scopeField = document.getElementById('commandScope');
                    const guildIdWrapper = document.getElementById('commandGuildIdWrapper');
                    const createUrl = '{{ route('discord-integration.admin.commands.store') }}';
                    const updateUrlTemplate = '{{ route('discord-integration.admin.commands.update', ['command' => '__ID__']) }}';
                    const createLabel = @json(trans('discord-integration::admin.commands.create'));
                    const editLabel = @json(trans('discord-integration::admin.commands.edit'));

                    const optionsBuilder = CommandBuilder.initOptions({
                        initial: JSON.parse(document.getElementById('commandOptions').value || '[]'),
                        listId: 'commandOptionsList',
                        hiddenId: 'commandOptions',
                        addButtonId: 'commandOptionsAdd',
                        labels: {
                            name: @json(trans('discord-integration::admin.commands.options.name')),
                            description: @json(trans('discord-integration::admin.commands.options.description')),
                            typeText: @json(trans('discord-integration::admin.commands.options.type_text')),
                            typeNumber: @json(trans('discord-integration::admin.commands.options.type_number')),
                            typeBoolean: @json(trans('discord-integration::admin.commands.options.type_boolean')),
                            typeUser: @json(trans('discord-integration::admin.commands.options.type_user')),
                            typeRole: @json(trans('discord-integration::admin.commands.options.type_role')),
                            required: @json(trans('discord-integration::admin.commands.options.required')),
                            choices: @json(trans('discord-integration::admin.commands.options.choices')),
                            addChoice: @json(trans('discord-integration::admin.commands.options.add_choice')),
                        },
                    });

                    function toggleGuildField() {
                        guildIdWrapper.classList.toggle('d-none', scopeField.value !== 'guild');
                    }

                    scopeField.addEventListener('change', toggleGuildField);
                    toggleGuildField();

                    function setEditMode(id) {
                        modalLabel.textContent = editLabel;
                        form.action = updateUrlTemplate.replace('__ID__', id);
                        methodField.value = 'PUT';
                        document.getElementById('commandId').value = id;
                    }

                    function setCreateMode() {
                        modalLabel.textContent = createLabel;
                        form.action = createUrl;
                        methodField.value = 'POST';
                        document.getElementById('commandId').value = '';
                    }

                    modal.addEventListener('show.bs.modal', function (event) {
                        const button = event.relatedTarget;

                        // A null relatedTarget means the modal was reopened
                        // programmatically after a validation error (see
                        // below) - the form already holds old() values then,
                        // so leave it untouched instead of resetting it.
                        if (! button) {
                            return;
                        }

                        form.reset();

                        const id = button.dataset.id;

                        if (id) {
                            setEditMode(id);

                            document.getElementById('commandName').value = button.dataset.name;
                            document.getElementById('commandDescription').value = button.dataset.description;
                            scopeField.value = button.dataset.scope;
                            document.getElementById('commandGuildId').value = button.dataset.guildId !== 'null' ? button.dataset.guildId : '';
                            document.getElementById('commandEnabled').checked = button.dataset.enabled === '1';
                            document.getElementById('commandFallbackMessage').value = button.dataset.fallbackMessage || '';
                            optionsBuilder.setState(JSON.parse(button.dataset.options || '[]'));
                        } else {
                            setCreateMode();
                            optionsBuilder.setState([]);
                        }

                        toggleGuildField();
                    });

                    @if($errors->hasAny(['name', 'description', 'scope', 'discord_guild_id', 'fallback_message', 'options', 'options.*.name', 'options.*.description', 'options.*.type']))
                        @if(old('command_id'))
                            setEditMode(@json(old('command_id')));
                        @else
                            setCreateMode();
                        @endif

                        toggleGuildField();
                        bootstrap.Modal.getOrCreateInstance(modal).show();
                    @endif

                    @if($guilds !== null)
                        document.getElementById('commandGuildIdPicker').addEventListener('click', function (e) {
                            e.preventDefault();

                            DiscordPicker.open({
                                title: @json(trans('discord-integration::admin.pick_guild_title')),
                                items: @json(\Azuriom\Plugin\DiscordIntegration\Support\DiscordAvatar::guildPickerItems($guilds)),
                                emptyText: @json(trans('discord-integration::admin.pick_guild_empty')),
                                onSelect: function (item) {
                                    document.getElementById('commandGuildId').value = item.id;
                                    DiscordPicker.modal.hide();
                                },
                            });
                        });
                    @endif
                });
            </script>
        @endpush

        @push('footer-scripts')
            <script>
                document.addEventListener('DOMContentLoaded', function () {
                    const modal = document.getElementById('formModal');
                    const form = document.getElementById('formForm');
                    const methodField = document.getElementById('formMethod');
                    const modalLabel = document.getElementById('formModalLabel');
                    const createUrl = '{{ route('discord-integration.admin.forms.store') }}';
                    const updateUrlTemplate = '{{ route('discord-integration.admin.forms.update', ['form' => '__ID__']) }}';
                    const createLabel = @json(trans('discord-integration::admin.forms.create'));
                    const editLabel = @json(trans('discord-integration::admin.forms.edit'));

                    const fieldsBuilder = FormBuilder.initFormFields({
                        initial: JSON.parse(document.getElementById('formFields').value || '[]'),
                        listId: 'formFieldsList',
                        hiddenId: 'formFields',
                        addButtonId: 'formFieldsAdd',
                        labels: {
                            fieldKey: @json(trans('discord-integration::admin.forms.fields.field_key')),
                            label: @json(trans('discord-integration::admin.forms.fields.label')),
                            styleShort: @json(trans('discord-integration::admin.forms.fields.style_short')),
                            styleParagraph: @json(trans('discord-integration::admin.forms.fields.style_paragraph')),
                            required: @json(trans('discord-integration::admin.forms.fields.required')),
                            minLength: @json(trans('discord-integration::admin.forms.fields.min_length')),
                            maxLength: @json(trans('discord-integration::admin.forms.fields.max_length')),
                            placeholder: @json(trans('discord-integration::admin.forms.fields.placeholder')),
                            value: @json(trans('discord-integration::admin.forms.fields.value')),
                            maxFieldsReached: @json(trans('discord-integration::admin.forms.fields.max_reached')),
                        },
                    });

                    function setEditMode(id) {
                        modalLabel.textContent = editLabel;
                        form.action = updateUrlTemplate.replace('__ID__', id);
                        methodField.value = 'PUT';
                        document.getElementById('formId').value = id;
                    }

                    function setCreateMode() {
                        modalLabel.textContent = createLabel;
                        form.action = createUrl;
                        methodField.value = 'POST';
                        document.getElementById('formId').value = '';
                    }

                    modal.addEventListener('show.bs.modal', function (event) {
                        const button = event.relatedTarget;

                        // Same convention as the command modal - a null
                        // relatedTarget means this was reopened
                        // programmatically after a validation error, so the
                        // old() values already in the form are left alone.
                        if (! button) {
                            return;
                        }

                        form.reset();

                        const id = button.dataset.id;

                        if (id) {
                            setEditMode(id);

                            document.getElementById('formName').value = button.dataset.name;
                            document.getElementById('formTitle').value = button.dataset.title;
                            document.getElementById('formEnabled').checked = button.dataset.enabled === '1';
                            document.getElementById('formFallbackMessage').value = button.dataset.fallbackMessage || '';
                            fieldsBuilder.setState(JSON.parse(button.dataset.fields || '[]'));
                        } else {
                            setCreateMode();
                            fieldsBuilder.setState([]);
                        }
                    });

                    @if($errors->hasAny(['name', 'title', 'fallback_message', 'fields', 'fields.*.field_key', 'fields.*.label', 'fields.*.style']))
                        @if(old('form_id'))
                            setEditMode(@json(old('form_id')));
                        @else
                            setCreateMode();
                        @endif

                        bootstrap.Modal.getOrCreateInstance(modal).show();
                    @endif
                });
            </script>
        @endpush
    @endif
@endsection
