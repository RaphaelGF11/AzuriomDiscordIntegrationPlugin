@extends('admin.layouts.admin')

@section('title', trans('discord-integration::admin.commands.script.title'))

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h1 class="h3 mb-0">{{ trans('discord-integration::admin.commands.script.editing', ['name' => $command->name]) }}</h1>
        </div>
        <div class="d-flex gap-2">
            <button type="button" id="scriptImportBtn" class="btn btn-outline-secondary">
                <i class="bi bi-upload"></i> {{ trans('discord-integration::admin.commands.script.import') }}
            </button>
            <input type="file" id="scriptImportInput" accept="application/json" class="d-none">

            <button type="button" id="scriptExportBtn" class="btn btn-outline-secondary">
                <i class="bi bi-download"></i> {{ trans('discord-integration::admin.commands.script.export') }}
            </button>

            <a href="{{ route('discord-integration.admin.commands') }}" class="btn btn-outline-secondary">
                {{ trans('messages.actions.back') }}
            </a>
        </div>
    </div>

    <div class="alert alert-info">
        {{ trans('discord-integration::admin.commands.script.help') }}
    </div>

    <form method="POST" action="{{ route('discord-integration.admin.commands.script.update', $command) }}" id="scriptForm">
        @csrf
        @method('PUT')
        <input type="hidden" name="script" id="scriptHidden" value="{{ old('script', json_encode($command->script ?? [])) }}">

        <div class="row">
            <div class="col-md-3">
                <div class="position-sticky" style="top: 1rem;">
                    <div id="scriptPalette"></div>
                </div>
            </div>
            <div class="col-md-9">
                <div id="scriptCanvas" class="mb-3"></div>

                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save"></i> {{ trans('messages.actions.save') }}
                </button>
            </div>
        </div>
    </form>

    <style>
        .script-drop-slot {
            height: 8px;
            margin: -4px 0;
            position: relative;
            z-index: 1;
        }
        .script-drop-slot-active {
            background: var(--bs-primary);
            height: 3px;
            margin: 3px 0;
            border-radius: 2px;
        }
        .script-block-dragging {
            opacity: 0.4;
        }
        .script-palette-item {
            cursor: grab;
        }
    </style>

    @include('discord-integration::admin.partials.discord-picker-modal')
    @include('discord-integration::admin.partials.command-builder-scripts')
    @include('discord-integration::admin.partials.script-editor-scripts')

    @push('footer-scripts')
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                const editor = ScriptEditor.init({
                    initial: JSON.parse(document.getElementById('scriptHidden').value || '[]'),
                    canvasId: 'scriptCanvas',
                    hiddenId: 'scriptHidden',
                    paletteId: 'scriptPalette',
                    siteRoles: @json($siteRoles->map(fn ($role) => ['id' => $role->id, 'name' => $role->name])),
                    shopPackages: @json($shopPackages?->map(fn ($package) => ['id' => $package->id, 'name' => $package->name])),
                    guilds: @json($guilds),
                    guildPickerItems: @json($guilds !== null ? \Azuriom\Plugin\DiscordIntegration\Support\DiscordAvatar::guildPickerItems($guilds) : []),
                    guildRolesUrl: @json(route('discord-integration.admin.commands.guild-roles')),
                    guildChannelsUrl: @json(route('discord-integration.admin.commands.guild-channels')),
                    artisanCommands: @json($artisanCommands),
                    commandOptions: @json(collect($command->options ?? [])->pluck('name')->filter()->values()),
                    forms: @json($forms->map(fn ($form) => ['id' => $form->id, 'name' => $form->name])),
                    allowShowModal: true,
                    labels: {
                        guildId: @json(trans('discord-integration::admin.role_sync.guild_id')),
                        roleId: @json(trans('discord-integration::admin.role_sync.role_id')),
                        pick: @json(trans('discord-integration::admin.pick')),
                        pickGuildTitle: @json(trans('discord-integration::admin.pick_guild_title')),
                        pickGuildEmpty: @json(trans('discord-integration::admin.pick_guild_empty')),
                        pickRoleTitle: @json(trans('discord-integration::admin.pick_role_title')),
                        pickRoleNoGuild: @json(trans('discord-integration::admin.pick_role_no_guild')),
                        pickRoleLoading: @json(trans('discord-integration::admin.pick_role_loading')),
                        pickRoleEmpty: @json(trans('discord-integration::admin.pick_role_empty')),
                        pickRoleError: @json(trans('discord-integration::admin.pick_role_error')),
                        channelId: @json(trans('discord-integration::admin.commands.script.channel_id')),
                        pickChannelTitle: @json(trans('discord-integration::admin.pick_channel_title')),
                        pickChannelNoGuild: @json(trans('discord-integration::admin.pick_channel_no_guild')),
                        pickChannelLoading: @json(trans('discord-integration::admin.pick_channel_loading')),
                        pickChannelEmpty: @json(trans('discord-integration::admin.pick_channel_empty')),
                        pickChannelError: @json(trans('discord-integration::admin.pick_channel_error')),
                        insertVariable: @json(trans('discord-integration::admin.commands.script.insert_variable')),
                        insertVariableTitle: @json(trans('discord-integration::admin.commands.script.insert_variable_title')),
                        insertVariableEmpty: @json(trans('discord-integration::admin.commands.script.insert_variable_empty')),
                        recipient: @json(trans('discord-integration::admin.commands.script.recipient')),
                        resultVariable: @json(trans('discord-integration::admin.commands.script.result_variable')),
                        resultVariableHelp: @json(trans('discord-integration::admin.commands.script.result_variable_help')),
                        negate: @json(trans('discord-integration::admin.commands.condition.negate')),
                        addCondition: @json(trans('discord-integration::admin.commands.condition.add_condition')),
                        addGroup: @json(trans('discord-integration::admin.commands.condition.add_group')),
                        orSeparator: @json(trans('discord-integration::admin.commands.condition.or_separator')),
                        conditionTypes: {
                            site_role: @json(trans('discord-integration::admin.commands.condition.condition_site_role')),
                            balance_min: @json(trans('discord-integration::admin.commands.condition.condition_balance_min')),
                            balance_max: @json(trans('discord-integration::admin.commands.condition.condition_balance_max')),
                            shop_package: @json(trans('discord-integration::admin.commands.condition.condition_shop_package')),
                            discord_role: @json(trans('discord-integration::admin.commands.condition.condition_discord_role')),
                            linked_account: @json(trans('discord-integration::admin.commands.condition.condition_linked_account')),
                            compare: @json(trans('discord-integration::admin.commands.condition.condition_compare')),
                        },
                        compareLeft: @json(trans('discord-integration::admin.commands.condition.compare_left')),
                        compareRight: @json(trans('discord-integration::admin.commands.condition.compare_right')),
                        compareEquals: @json(trans('discord-integration::admin.commands.condition.compare_equals')),
                        compareNotEquals: @json(trans('discord-integration::admin.commands.condition.compare_not_equals')),
                        compareGreaterThan: @json(trans('discord-integration::admin.commands.condition.compare_greater_than')),
                        compareGreaterThanOrEqual: @json(trans('discord-integration::admin.commands.condition.compare_greater_than_or_equal')),
                        compareLessThan: @json(trans('discord-integration::admin.commands.condition.compare_less_than')),
                        compareLessThanOrEqual: @json(trans('discord-integration::admin.commands.condition.compare_less_than_or_equal')),
                        compareContains: @json(trans('discord-integration::admin.commands.condition.compare_contains')),
                        blockTypes: {
                            if: @json(trans('discord-integration::admin.commands.script.type_if')),
                            repeat: @json(trans('discord-integration::admin.commands.script.type_repeat')),
                            stop: @json(trans('discord-integration::admin.commands.script.type_stop')),
                            set_variable: @json(trans('discord-integration::admin.commands.script.type_set_variable')),
                            modify_variable: @json(trans('discord-integration::admin.commands.script.type_modify_variable')),
                            random_number: @json(trans('discord-integration::admin.commands.script.type_random_number')),
                            reply: @json(trans('discord-integration::admin.commands.script.type_reply')),
                            send_dm: @json(trans('discord-integration::admin.commands.script.type_send_dm')),
                            send_channel_message: @json(trans('discord-integration::admin.commands.script.type_send_channel_message')),
                            assign_discord_role: @json(trans('discord-integration::admin.commands.script.type_assign_discord_role')),
                            remove_discord_role: @json(trans('discord-integration::admin.commands.script.type_remove_discord_role')),
                            kick_discord_member: @json(trans('discord-integration::admin.commands.script.type_kick_discord_member')),
                            ban_discord_member: @json(trans('discord-integration::admin.commands.script.type_ban_discord_member')),
                            set_discord_nickname: @json(trans('discord-integration::admin.commands.script.type_set_discord_nickname')),
                            assign_site_role: @json(trans('discord-integration::admin.commands.script.type_assign_site_role')),
                            remove_site_role: @json(trans('discord-integration::admin.commands.script.type_remove_site_role')),
                            give_shop_packages: @json(trans('discord-integration::admin.commands.script.type_give_shop_packages')),
                            modify_balance: @json(trans('discord-integration::admin.commands.script.type_modify_balance')),
                            run_artisan_command: @json(trans('discord-integration::admin.commands.script.type_run_artisan_command')),
                            show_modal: @json(trans('discord-integration::admin.commands.script.type_show_modal')),
                        },
                        formId: @json(trans('discord-integration::admin.commands.script.form_id')),
                        noForms: @json(trans('discord-integration::admin.commands.script.no_forms')),
                        dropHint: @json(trans('discord-integration::admin.commands.script.drop_hint')),
                        addBlock: @json(trans('discord-integration::admin.commands.script.add_block')),
                        categoryControl: @json(trans('discord-integration::admin.commands.script.category_control')),
                        categoryVariables: @json(trans('discord-integration::admin.commands.script.category_variables')),
                        categoryActions: @json(trans('discord-integration::admin.commands.script.category_actions')),
                        thenLabel: @json(trans('discord-integration::admin.commands.script.then_label')),
                        elseLabel: @json(trans('discord-integration::admin.commands.script.else_label')),
                        bodyLabel: @json(trans('discord-integration::admin.commands.script.body_label')),
                        repeatCount: @json(trans('discord-integration::admin.commands.script.repeat_count')),
                        variableName: @json(trans('discord-integration::admin.commands.script.variable_name')),
                        variableValue: @json(trans('discord-integration::admin.commands.script.variable_value')),
                        placeholdersHelp: @json(trans('discord-integration::admin.commands.script.placeholders_help')),
                        ephemeral: @json(trans('discord-integration::admin.commands.script.ephemeral')),
                        noShopPackages: @json(trans('discord-integration::admin.commands.script.no_shop_packages')),
                        opAdd: @json(trans('discord-integration::admin.commands.script.op_add')),
                        opSubtract: @json(trans('discord-integration::admin.commands.script.op_subtract')),
                        opMultiply: @json(trans('discord-integration::admin.commands.script.op_multiply')),
                        opDivide: @json(trans('discord-integration::admin.commands.script.op_divide')),
                        opSet: @json(trans('discord-integration::admin.commands.script.op_set')),
                        amount: @json(trans('discord-integration::admin.commands.script.amount')),
                        randomMin: @json(trans('discord-integration::admin.commands.script.random_min')),
                        randomMax: @json(trans('discord-integration::admin.commands.script.random_max')),
                        banReason: @json(trans('discord-integration::admin.commands.script.ban_reason')),
                        nickname: @json(trans('discord-integration::admin.commands.script.nickname')),
                        runArtisanWarning: @json(trans('discord-integration::admin.commands.script.run_artisan_command_warning')),
                        runArtisanDangerous: @json(trans('discord-integration::admin.commands.script.run_artisan_command_dangerous')),
                        parameters: @json(trans('discord-integration::admin.commands.script.parameters')),
                        paramKey: @json(trans('discord-integration::admin.commands.script.param_key')),
                        paramValue: @json(trans('discord-integration::admin.commands.script.param_value')),
                        addParam: @json(trans('discord-integration::admin.commands.script.add_param')),
                    },
                });

                document.getElementById('scriptExportBtn').addEventListener('click', function () {
                    const blob = new Blob([JSON.stringify(editor.getState(), null, 2)], {type: 'application/json'});
                    const url = URL.createObjectURL(blob);
                    const link = document.createElement('a');
                    link.href = url;
                    link.download = @json($command->name).replace(/[^a-z0-9_-]/gi, '_') + '-script.json';
                    link.click();
                    URL.revokeObjectURL(url);
                });

                const importInput = document.getElementById('scriptImportInput');
                document.getElementById('scriptImportBtn').addEventListener('click', function () {
                    importInput.click();
                });
                importInput.addEventListener('change', function (e) {
                    const file = e.target.files[0];

                    if (! file) {
                        return;
                    }

                    const reader = new FileReader();
                    reader.onload = function () {
                        try {
                            const parsed = JSON.parse(reader.result);

                            if (! Array.isArray(parsed)) {
                                throw new Error('not an array');
                            }

                            editor.setState(parsed);
                        } catch (err) {
                            alert(@json(trans('discord-integration::admin.commands.script.import_error')));
                        }

                        importInput.value = '';
                    };
                    reader.readAsText(file);
                });
            });
        </script>
    @endpush
@endsection
