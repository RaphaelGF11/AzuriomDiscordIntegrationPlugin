{{--
    Shared JS building blocks for the Commands admin pages: the parameters
    ("options") builder used in the quick-create modal (see commands.blade.php),
    and a set of reusable primitives (DOM helpers, the condition/guild-role-
    picker renderers, the leaf action field renderers) exposed on
    window.CommandBuilder for the dedicated script editor
    (script-editor-scripts.blade.php) to build on - kept in its own partial
    since it's considerably larger than the other admin pages' scripts.

    Each builder keeps an in-memory array as the single source of truth,
    re-rendering its own DOM subtree on every add/remove/reorder and
    serializing to its hidden <input> (see CustomCommand's docblock for the
    exact JSON shapes) on every change - individual field edits mutate the
    array in place without a full re-render, so typing doesn't lose focus.

    Also the JS-side half of the plugin extension API (see PHP's own
    Support\ScriptExtensions docblock for the full picture and a PHP-side
    registration example): another plugin's own <script> (loaded via
    admin.partials.script-editor-extensions, once window.CommandBuilder
    below already exists) calls:

        CommandBuilder.registerActionType('my_plugin_give_xp', {
            label: 'Give XP',
            renderFields: function (block, container, ctx) {
                // Populate `container` with this block's field inputs,
                // mutating `block` and calling ctx.onChange() on every
                // change - the exact same convention every built-in leaf
                // action's own field renderer follows just below. Fields
                // read/write directly on `block` (no nested "config"
                // object), and any field left undefined the first time
                // this runs should be defaulted here (mutate + onChange()),
                // the same way e.g. modify_variable's own renderer does for
                // "operation" - there is no separate factory function for a
                // freshly-added block.
            },
        });

    registerConditionType() is the same shape, for an "if" block's
    condition picker instead. Both types show up in the palette/dropdowns
    exactly like a built-in one, are draggable/orderable the same way, and
    their block type is rejected at PHP registration time if it collides
    with a built-in name (see ScriptExtensions::RESERVED_TYPES) - there is
    no separate JS-side collision list to keep in sync with that one.
--}}
@push('footer-scripts')
    <script>
        window.CommandBuilder = (function () {
            function el(tag, className, attrs) {
                const node = document.createElement(tag);

                if (className) {
                    node.className = className;
                }

                Object.entries(attrs || {}).forEach(function ([key, value]) {
                    node[key] = value;
                });

                return node;
            }

            function textInput(value, placeholder, onChange, extraClass) {
                const input = el('input', 'form-control form-control-sm ' + (extraClass || ''), {
                    type: 'text',
                    placeholder: placeholder || '',
                    value: value || '',
                });
                input.addEventListener('input', function () {
                    onChange(input.value);
                });

                return input;
            }

            function removeButton(onClick) {
                const btn = el('button', 'btn btn-sm btn-outline-danger', {type: 'button'});
                btn.innerHTML = '<i class="bi bi-trash"></i>';
                btn.addEventListener('click', onClick);

                return btn;
            }

            /**
             * A small button appended to a short id/text field that opens
             * the shared DiscordPicker modal listing every {var:name}/
             * {option:name} token currently available in the script
             * (ctx.availablePlaceholders(), see script-editor-scripts.blade.php)
             * and inserts the chosen one at the input's cursor position -
             * lets a field that would otherwise only take a literal value
             * (a server id, an amount, a reason...) be driven by a script
             * variable or a command parameter instead.
             */
            function renderVariablePickerButton(input, ctx) {
                const btn = el('button', 'btn btn-outline-secondary', {type: 'button', title: ctx.labels.insertVariable});
                btn.innerHTML = '<i class="bi bi-braces"></i>';
                btn.addEventListener('click', function () {
                    const items = ctx.availablePlaceholders ? ctx.availablePlaceholders() : [];

                    DiscordPicker.open({
                        title: ctx.labels.insertVariableTitle,
                        items: items,
                        emptyText: ctx.labels.insertVariableEmpty,
                        onSelect: function (item) {
                            const start = input.selectionStart != null ? input.selectionStart : input.value.length;
                            const end = input.selectionEnd != null ? input.selectionEnd : input.value.length;
                            input.value = input.value.slice(0, start) + item.id + input.value.slice(end);
                            input.dispatchEvent(new Event('input', {bubbles: true}));
                            DiscordPicker.modal.hide();
                        },
                    });
                });

                return btn;
            }

            /**
             * textInput() wrapped in an input-group with the variable
             * picker button above - the default for any field taking a
             * short id/value rather than a multi-line message (those
             * already document available placeholders as help text
             * instead, see placeholdersHelp).
             */
            function textInputWithPicker(value, placeholder, onChange, ctx, extraClass) {
                const wrap = el('div', 'input-group input-group-sm ' + (extraClass || ''));
                const input = textInput(value, placeholder, onChange);
                wrap.appendChild(input);
                wrap.appendChild(renderVariablePickerButton(input, ctx));

                return wrap;
            }

            /**
             * The parameters ("options") builder - a flat repeatable list,
             * each row optionally expanding a repeatable "choices" sub-list
             * for the types Discord allows choices on (text/number).
             */
            function initOptions(config) {
                const state = JSON.parse(JSON.stringify(config.initial || []));
                const list = document.getElementById(config.listId);
                const hidden = document.getElementById(config.hiddenId);
                const labels = config.labels;
                const typesWithChoices = ['text', 'integer', 'number'];
                const channelTypeOptions = [[0, labels.channelTypeText], [5, labels.channelTypeAnnouncement], [15, labels.channelTypeForum], [2, labels.channelTypeVoice], [13, labels.channelTypeStage], [4, labels.channelTypeCategory]];

                function sync() {
                    hidden.value = JSON.stringify(state);
                }

                function renderChoices(option, container) {
                    container.innerHTML = '';

                    if (! typesWithChoices.includes(option.type)) {
                        return;
                    }

                    option.choices = option.choices || [];

                    const wrapper = el('div', 'mt-2 ps-3 border-start');
                    const choicesTitle = el('div', 'form-text mb-1', {textContent: labels.choices});
                    wrapper.appendChild(choicesTitle);

                    option.choices.forEach(function (choice, ci) {
                        const row = el('div', 'd-flex gap-2 mb-1 align-items-center');

                        row.appendChild(textInput(choice.name, labels.name, function (v) {
                            option.choices[ci].name = v;
                            sync();
                        }));

                        row.appendChild(textInput(choice.value, labels.name, function (v) {
                            option.choices[ci].value = v;
                            sync();
                        }));

                        row.appendChild(removeButton(function () {
                            option.choices.splice(ci, 1);
                            sync();
                            renderChoices(option, container);
                        }));

                        wrapper.appendChild(row);
                    });

                    const addChoiceBtn = el('button', 'btn btn-sm btn-outline-secondary', {
                        type: 'button',
                        textContent: labels.addChoice,
                    });
                    addChoiceBtn.addEventListener('click', function () {
                        option.choices.push({name: '', value: ''});
                        sync();
                        renderChoices(option, container);
                    });
                    wrapper.appendChild(addChoiceBtn);

                    container.appendChild(wrapper);
                }

                /**
                 * Type-specific extra constraints Discord accepts on a
                 * command parameter: length bounds for text, value bounds
                 * for integer/number, allowed channel types for channel.
                 */
                function renderConstraints(option, container) {
                    container.innerHTML = '';

                    if (option.type === 'text') {
                        const wrap = el('div', 'row g-2 mt-2');
                        const minCol = el('div', 'col-md-6');
                        minCol.appendChild(textInput(option.min_length, labels.minLength, function (v) {
                            option.min_length = v === '' ? null : parseInt(v, 10);
                            sync();
                        }));
                        wrap.appendChild(minCol);
                        const maxCol = el('div', 'col-md-6');
                        maxCol.appendChild(textInput(option.max_length, labels.maxLength, function (v) {
                            option.max_length = v === '' ? null : parseInt(v, 10);
                            sync();
                        }));
                        wrap.appendChild(maxCol);
                        container.appendChild(wrap);

                        return;
                    }

                    if (option.type === 'integer' || option.type === 'number') {
                        const wrap = el('div', 'row g-2 mt-2');
                        const minCol = el('div', 'col-md-6');
                        minCol.appendChild(textInput(option.min_value, labels.minValue, function (v) {
                            option.min_value = v === '' ? null : parseFloat(v);
                            sync();
                        }));
                        wrap.appendChild(minCol);
                        const maxCol = el('div', 'col-md-6');
                        maxCol.appendChild(textInput(option.max_value, labels.maxValue, function (v) {
                            option.max_value = v === '' ? null : parseFloat(v);
                            sync();
                        }));
                        wrap.appendChild(maxCol);
                        container.appendChild(wrap);

                        return;
                    }

                    if (option.type === 'channel') {
                        option.channel_types = option.channel_types || [];

                        const wrap = el('div', 'mt-2');
                        wrap.appendChild(el('div', 'form-text mb-1', {textContent: labels.channelTypes}));

                        channelTypeOptions.forEach(function ([value, label]) {
                            const check = el('div', 'form-check form-check-inline');
                            const checkbox = el('input', 'form-check-input', {
                                type: 'checkbox',
                                checked: option.channel_types.includes(value),
                            });
                            checkbox.addEventListener('change', function () {
                                if (checkbox.checked) {
                                    if (! option.channel_types.includes(value)) {
                                        option.channel_types.push(value);
                                    }
                                } else {
                                    option.channel_types = option.channel_types.filter(function (v) { return v !== value; });
                                }
                                sync();
                            });
                            const checkLabel = el('label', 'form-check-label', {textContent: label});
                            check.appendChild(checkbox);
                            check.appendChild(checkLabel);
                            wrap.appendChild(check);
                        });

                        container.appendChild(wrap);
                    }
                }

                function render() {
                    list.innerHTML = '';

                    state.forEach(function (option, index) {
                        const row = el('div', 'border rounded p-2 mb-2');
                        const topRow = el('div', 'row g-2 align-items-start');

                        const nameCol = el('div', 'col-md-3');
                        nameCol.appendChild(textInput(option.name, labels.name, function (v) {
                            state[index].name = v;
                            sync();
                        }));
                        topRow.appendChild(nameCol);

                        const descCol = el('div', 'col-md-4');
                        descCol.appendChild(textInput(option.description, labels.description, function (v) {
                            state[index].description = v;
                            sync();
                        }));
                        topRow.appendChild(descCol);

                        const typeCol = el('div', 'col-md-2');
                        const typeSelect = el('select', 'form-select form-select-sm');
                        [
                            ['text', labels.typeText], ['integer', labels.typeInteger], ['number', labels.typeNumber],
                            ['boolean', labels.typeBoolean], ['user', labels.typeUser], ['role', labels.typeRole],
                            ['channel', labels.typeChannel], ['mentionable', labels.typeMentionable], ['attachment', labels.typeAttachment],
                        ].forEach(function ([value, label]) {
                            const opt = el('option', null, {value: value, textContent: label});
                            opt.selected = option.type === value;
                            typeSelect.appendChild(opt);
                        });
                        typeSelect.addEventListener('change', function () {
                            state[index].type = typeSelect.value;
                            sync();
                            render();
                        });
                        typeCol.appendChild(typeSelect);
                        topRow.appendChild(typeCol);

                        const requiredCol = el('div', 'col-md-2 form-check pt-1');
                        const requiredCheckbox = el('input', 'form-check-input', {
                            type: 'checkbox',
                            checked: !! option.required,
                        });
                        requiredCheckbox.addEventListener('change', function () {
                            state[index].required = requiredCheckbox.checked;
                            sync();
                        });
                        const requiredLabel = el('label', 'form-check-label small', {textContent: labels.required});
                        requiredCol.appendChild(requiredCheckbox);
                        requiredCol.appendChild(requiredLabel);
                        topRow.appendChild(requiredCol);

                        const removeCol = el('div', 'col-md-1 text-end');
                        removeCol.appendChild(removeButton(function () {
                            state.splice(index, 1);
                            sync();
                            render();
                        }));
                        topRow.appendChild(removeCol);

                        row.appendChild(topRow);

                        const constraintsContainer = el('div');
                        renderConstraints(option, constraintsContainer);
                        row.appendChild(constraintsContainer);

                        const choicesContainer = el('div');
                        renderChoices(option, choicesContainer);
                        row.appendChild(choicesContainer);

                        list.appendChild(row);
                    });

                    sync();
                }

                document.getElementById(config.addButtonId).addEventListener('click', function () {
                    state.push({name: '', description: '', type: 'text', required: false, choices: []});
                    render();
                });

                render();

                return {
                    setState: function (newState) {
                        state.length = 0;
                        (newState || []).forEach(function (item) {
                            state.push(item);
                        });
                        render();
                    },
                };
            }

            const CONDITION_TYPES = ['site_role', 'balance_min', 'balance_max', 'shop_package', 'discord_role', 'linked_account', 'compare'];

            const ACTION_TYPES = [
                'reply', 'send_dm', 'send_channel_message',
                'assign_discord_role', 'remove_discord_role',
                'kick_discord_member', 'ban_discord_member', 'set_discord_nickname',
                'assign_site_role', 'remove_site_role', 'give_shop_packages',
                'modify_balance', 'run_artisan_command', 'show_modal',
            ];

            // type -> {label, renderFields} - see this file's own top
            // docblock for the registration shape and contract.
            const registeredActionTypes = {};
            const registeredConditionTypes = {};

            function registerActionType(type, definition) {
                if (ACTION_TYPES.includes(type) || registeredActionTypes[type]) {
                    console.error('CommandBuilder: action type "' + type + '" is already registered, ignoring.');

                    return;
                }

                registeredActionTypes[type] = definition;
            }

            function registerConditionType(type, definition) {
                if (CONDITION_TYPES.includes(type) || registeredConditionTypes[type]) {
                    console.error('CommandBuilder: condition type "' + type + '" is already registered, ignoring.');

                    return;
                }

                registeredConditionTypes[type] = definition;
            }

            // Built-in types first, then registered ones in registration
            // order - what the palette/dropdowns iterate over instead of
            // the bare ACTION_TYPES/CONDITION_TYPES constants.
            function allActionTypes() {
                return ACTION_TYPES.concat(Object.keys(registeredActionTypes));
            }

            function allConditionTypes() {
                return CONDITION_TYPES.concat(Object.keys(registeredConditionTypes));
            }

            /**
             * A block/condition type's label - a built-in's own translated
             * ctx.labels entry if there is one, else a registered
             * extension's own (already-localized, PHP-side) "label", else
             * the raw type as a last-resort fallback.
             */
            function actionLabel(type, ctx) {
                return ctx.labels.blockTypes[type] || (registeredActionTypes[type] || {}).label || type;
            }

            function conditionLabel(type, ctx) {
                return (ctx.labels.conditionTypes || {})[type] || (registeredConditionTypes[type] || {}).label || type;
            }

            /**
             * Renders one condition's type-specific fields (a fresh
             * sub-tree, replaced whenever the condition's type changes) -
             * used by every condition row, wherever a condition appears (an
             * "if" block's condition groups today).
             */
            function renderConditionFields(condition, container, ctx) {
                container.innerHTML = '';

                if (condition.type === 'site_role') {
                    condition.role_ids = condition.role_ids || [];

                    ctx.siteRoles.forEach(function (role) {
                        const wrap = el('div', 'form-check form-check-inline');
                        const checkbox = el('input', 'form-check-input', {
                            type: 'checkbox',
                            checked: condition.role_ids.includes(role.id),
                        });
                        checkbox.addEventListener('change', function () {
                            if (checkbox.checked) {
                                if (! condition.role_ids.includes(role.id)) {
                                    condition.role_ids.push(role.id);
                                }
                            } else {
                                condition.role_ids = condition.role_ids.filter(function (id) { return id !== role.id; });
                            }
                            ctx.onChange();
                        });
                        const label = el('label', 'form-check-label', {textContent: role.name});
                        wrap.appendChild(checkbox);
                        wrap.appendChild(label);
                        container.appendChild(wrap);
                    });

                    return;
                }

                if (condition.type === 'balance_min' || condition.type === 'balance_max') {
                    const input = el('input', 'form-control form-control-sm', {
                        type: 'number',
                        min: '0',
                        step: '0.01',
                        value: condition.value != null ? condition.value : '',
                    });
                    input.addEventListener('input', function () {
                        condition.value = input.value === '' ? null : parseFloat(input.value);
                        ctx.onChange();
                    });
                    container.appendChild(input);

                    return;
                }

                if (condition.type === 'shop_package') {
                    if (! ctx.shopPackages || ctx.shopPackages.length === 0) {
                        return;
                    }

                    const select = el('select', 'form-select form-select-sm');
                    ctx.shopPackages.forEach(function (pkg) {
                        const opt = el('option', null, {value: String(pkg.id), textContent: pkg.name});
                        opt.selected = condition.package_id === pkg.id;
                        select.appendChild(opt);
                    });
                    select.addEventListener('change', function () {
                        condition.package_id = parseInt(select.value, 10);
                        ctx.onChange();
                    });
                    container.appendChild(select);

                    if (condition.package_id == null && ctx.shopPackages.length > 0) {
                        condition.package_id = ctx.shopPackages[0].id;
                        ctx.onChange();
                    }

                    return;
                }

                if (condition.type === 'discord_role') {
                    container.appendChild(renderGuildRolePicker(condition, ctx));

                    return;
                }

                if (condition.type === 'compare') {
                    const row = el('div', 'row g-2 align-items-start');

                    const leftCol = el('div', 'col-md-4');
                    leftCol.appendChild(textInputWithPicker(condition.left, ctx.labels.compareLeft, function (v) {
                        condition.left = v;
                        ctx.onChange();
                    }, ctx));
                    row.appendChild(leftCol);

                    const opCol = el('div', 'col-md-4');
                    const opSelect = el('select', 'form-select form-select-sm');
                    [
                        ['equals', ctx.labels.compareEquals], ['not_equals', ctx.labels.compareNotEquals],
                        ['greater_than', ctx.labels.compareGreaterThan], ['greater_than_or_equal', ctx.labels.compareGreaterThanOrEqual],
                        ['less_than', ctx.labels.compareLessThan], ['less_than_or_equal', ctx.labels.compareLessThanOrEqual],
                        ['contains', ctx.labels.compareContains],
                    ].forEach(function ([value, label]) {
                        const opt = el('option', null, {value: value, textContent: label});
                        opt.selected = condition.operator === value;
                        opSelect.appendChild(opt);
                    });
                    opSelect.addEventListener('change', function () {
                        condition.operator = opSelect.value;
                        ctx.onChange();
                    });
                    opCol.appendChild(opSelect);
                    row.appendChild(opCol);

                    const rightCol = el('div', 'col-md-4');
                    rightCol.appendChild(textInputWithPicker(condition.right, ctx.labels.compareRight, function (v) {
                        condition.right = v;
                        ctx.onChange();
                    }, ctx));
                    row.appendChild(rightCol);

                    container.appendChild(row);
                    container.appendChild(el('div', 'form-text mt-1', {textContent: ctx.labels.placeholdersHelp}));

                    if (! condition.operator) {
                        condition.operator = 'equals';
                        ctx.onChange();
                    }

                    return;
                }

                // linked_account has no extra configuration.

                if (registeredConditionTypes[condition.type]) {
                    registeredConditionTypes[condition.type].renderFields(condition, container, ctx);
                }
            }

            /**
             * The guild id half of every {guild_id, ...} field pair - a text
             * input plus an optional DiscordPicker link, shared by the role
             * picker below, the channel picker, and any action that only
             * needs a bare guild id (kick/ban/nickname).
             */
            function renderGuildIdField(target, ctx) {
                const guildCol = el('div', 'col-md-6');
                const guildInput = el('input', 'form-control form-control-sm', {
                    type: 'text',
                    placeholder: ctx.labels.guildId,
                    value: target.guild_id || '',
                });
                guildInput.addEventListener('input', function () {
                    target.guild_id = guildInput.value;
                    ctx.onChange();
                });
                const guildGroup = el('div', 'input-group input-group-sm');
                guildGroup.appendChild(guildInput);
                guildGroup.appendChild(renderVariablePickerButton(guildInput, ctx));
                guildCol.appendChild(guildGroup);

                if (ctx.guilds) {
                    const pickGuildLink = el('a', 'small d-inline-block mt-1', {href: '#', textContent: ctx.labels.pick});
                    pickGuildLink.addEventListener('click', function (e) {
                        e.preventDefault();
                        DiscordPicker.open({
                            title: ctx.labels.pickGuildTitle,
                            items: ctx.guildPickerItems,
                            emptyText: ctx.labels.pickGuildEmpty,
                            onSelect: function (item) {
                                target.guild_id = item.id;
                                guildInput.value = item.id;
                                ctx.onChange();
                                DiscordPicker.modal.hide();
                            },
                        });
                    });
                    guildCol.appendChild(pickGuildLink);
                }

                return guildCol;
            }

            /**
             * A bare guild id field (no second-step resource picker) - used
             * by actions that only need to know which server to act on
             * (kick/ban/set nickname), since the target is always the
             * invoking user's own linked account.
             */
            function renderGuildOnlyField(target, ctx) {
                const row = el('div', 'row g-2');
                row.appendChild(renderGuildIdField(target, ctx));

                return row;
            }

            /**
             * A guild id + role id field pair with the same two-step
             * guild-then-role DiscordPicker chain used throughout the admin
             * (see roles.blade.php) - shared by the "Has a Discord role"
             * condition and the assign/remove-Discord-role actions, both of
             * which just need a {guild_id, role_id} pair on their target
             * object (a condition or a leaf block).
             */
            function renderGuildRolePicker(target, ctx) {
                const row = el('div', 'row g-2');

                row.appendChild(renderGuildIdField(target, ctx));

                const roleCol = el('div', 'col-md-6');
                const roleInput = el('input', 'form-control form-control-sm', {
                    type: 'text',
                    placeholder: ctx.labels.roleId,
                    value: target.role_id || '',
                });
                roleInput.addEventListener('input', function () {
                    target.role_id = roleInput.value;
                    ctx.onChange();
                });
                const roleGroup = el('div', 'input-group input-group-sm');
                roleGroup.appendChild(roleInput);
                roleGroup.appendChild(renderVariablePickerButton(roleInput, ctx));
                roleCol.appendChild(roleGroup);

                const pickRoleLink = el('a', 'small d-inline-block mt-1', {href: '#', textContent: ctx.labels.pick});
                pickRoleLink.addEventListener('click', function (e) {
                    e.preventDefault();

                    const guildId = (target.guild_id || '').trim();

                    if (! guildId) {
                        DiscordPicker.open({title: ctx.labels.pickRoleTitle, items: [], emptyText: ctx.labels.pickRoleNoGuild, onSelect: function () {}});

                        return;
                    }

                    DiscordPicker.open({title: ctx.labels.pickRoleTitle, items: [], emptyText: ctx.labels.pickRoleLoading, onSelect: function () {}});

                    fetch(ctx.guildRolesUrl + '?guild_id=' + encodeURIComponent(guildId))
                        .then(function (response) { return response.ok ? response.json() : Promise.reject(); })
                        .then(function (roles) {
                            DiscordPicker.open({
                                title: ctx.labels.pickRoleTitle,
                                items: roles.map(function (role) { return {id: role.id, label: role.name}; }),
                                emptyText: ctx.labels.pickRoleEmpty,
                                onSelect: function (item) {
                                    target.role_id = item.id;
                                    roleInput.value = item.id;
                                    ctx.onChange();
                                    DiscordPicker.modal.hide();
                                },
                            });
                        })
                        .catch(function () {
                            DiscordPicker.open({title: ctx.labels.pickRoleTitle, items: [], emptyText: ctx.labels.pickRoleError, onSelect: function () {}});
                        });
                });
                roleCol.appendChild(pickRoleLink);

                row.appendChild(roleCol);

                return row;
            }

            /**
             * A guild id + channel id field pair, the same two-step chain as
             * renderGuildRolePicker but fetching from ctx.guildChannelsUrl -
             * used by the "send a channel message" script action.
             */
            function renderGuildChannelPicker(target, ctx) {
                const row = el('div', 'row g-2');

                row.appendChild(renderGuildIdField(target, ctx));

                const channelCol = el('div', 'col-md-6');
                const channelInput = el('input', 'form-control form-control-sm', {
                    type: 'text',
                    placeholder: ctx.labels.channelId,
                    value: target.channel_id || '',
                });
                channelInput.addEventListener('input', function () {
                    target.channel_id = channelInput.value;
                    ctx.onChange();
                });
                const channelGroup = el('div', 'input-group input-group-sm');
                channelGroup.appendChild(channelInput);
                channelGroup.appendChild(renderVariablePickerButton(channelInput, ctx));
                channelCol.appendChild(channelGroup);

                const pickChannelLink = el('a', 'small d-inline-block mt-1', {href: '#', textContent: ctx.labels.pick});
                pickChannelLink.addEventListener('click', function (e) {
                    e.preventDefault();

                    const guildId = (target.guild_id || '').trim();

                    if (! guildId) {
                        DiscordPicker.open({title: ctx.labels.pickChannelTitle, items: [], emptyText: ctx.labels.pickChannelNoGuild, onSelect: function () {}});

                        return;
                    }

                    DiscordPicker.open({title: ctx.labels.pickChannelTitle, items: [], emptyText: ctx.labels.pickChannelLoading, onSelect: function () {}});

                    fetch(ctx.guildChannelsUrl + '?guild_id=' + encodeURIComponent(guildId))
                        .then(function (response) { return response.ok ? response.json() : Promise.reject(); })
                        .then(function (channels) {
                            DiscordPicker.open({
                                title: ctx.labels.pickChannelTitle,
                                items: channels.map(function (channel) { return {id: channel.id, label: '#' + channel.name}; }),
                                emptyText: ctx.labels.pickChannelEmpty,
                                onSelect: function (item) {
                                    target.channel_id = item.id;
                                    channelInput.value = item.id;
                                    ctx.onChange();
                                    DiscordPicker.modal.hide();
                                },
                            });
                        })
                        .catch(function () {
                            DiscordPicker.open({title: ctx.labels.pickChannelTitle, items: [], emptyText: ctx.labels.pickChannelError, onSelect: function () {}});
                        });
                });
                channelCol.appendChild(pickChannelLink);

                row.appendChild(channelCol);

                return row;
            }

            function buildConditionRow(condition, ctx, onRemove) {
                const row = el('div', 'border rounded p-2 mb-2');
                const headerRow = el('div', 'd-flex gap-2 align-items-center mb-2');

                const typeSelect = el('select', 'form-select form-select-sm');
                allConditionTypes().forEach(function (type) {
                    const opt = el('option', null, {value: type, textContent: conditionLabel(type, ctx)});
                    opt.selected = condition.type === type;
                    typeSelect.appendChild(opt);
                });
                if (! condition.type) {
                    condition.type = CONDITION_TYPES[0];
                }

                const fieldsContainer = el('div');

                typeSelect.addEventListener('change', function () {
                    // Drop the previous type's fields (e.g. role_ids from
                    // site_role) rather than leaving them as unused clutter
                    // in the stored JSON once a different type is picked.
                    Object.keys(condition).forEach(function (key) {
                        if (key !== 'type' && key !== 'negate') {
                            delete condition[key];
                        }
                    });

                    condition.type = typeSelect.value;
                    ctx.onChange();
                    renderConditionFields(condition, fieldsContainer, ctx);
                });
                headerRow.appendChild(typeSelect);

                const negateWrap = el('div', 'form-check flex-shrink-0');
                const negateCheckbox = el('input', 'form-check-input', {type: 'checkbox', checked: !! condition.negate});
                negateCheckbox.addEventListener('change', function () {
                    condition.negate = negateCheckbox.checked;
                    ctx.onChange();
                });
                const negateLabel = el('label', 'form-check-label small', {textContent: ctx.labels.negate});
                negateWrap.appendChild(negateCheckbox);
                negateWrap.appendChild(negateLabel);
                headerRow.appendChild(negateWrap);

                headerRow.appendChild(removeButton(onRemove));

                row.appendChild(headerRow);
                renderConditionFields(condition, fieldsContainer, ctx);
                row.appendChild(fieldsContainer);

                return row;
            }

            /**
             * Renders a flat AND-list of conditions into `container`, with
             * an "add condition" button - one AND-group.
             */
            function renderConditionList(conditions, container, ctx, addButtonLabel) {
                container.innerHTML = '';

                conditions.forEach(function (condition, index) {
                    container.appendChild(buildConditionRow(condition, ctx, function () {
                        conditions.splice(index, 1);
                        ctx.onChange();
                        renderConditionList(conditions, container, ctx, addButtonLabel);
                    }));
                });

                const addBtn = el('button', 'btn btn-sm btn-outline-secondary', {type: 'button', textContent: addButtonLabel});
                addBtn.addEventListener('click', function () {
                    conditions.push({type: CONDITION_TYPES[0], negate: false});
                    ctx.onChange();
                    renderConditionList(conditions, container, ctx, addButtonLabel);
                });
                container.appendChild(addBtn);
            }

            /**
             * Renders an OR-of-AND-groups condition editor into `container`
             * (an "if" block's `condition` field - see CustomCommand's
             * docblock) - self-contained (creates its own "add group"
             * button rather than relying on a page-level fixed element id),
             * so it can be instantiated for as many "if" blocks as the
             * script needs at once.
             */
            function renderConditionGroups(groups, container, ctx) {
                container.innerHTML = '';

                groups.forEach(function (group, groupIndex) {
                    if (groupIndex > 0) {
                        container.appendChild(el('div', 'text-center text-muted small my-1', {textContent: ctx.labels.orSeparator}));
                    }

                    const groupBox = el('div', 'border rounded p-2 mb-2 bg-light-subtle');
                    const groupHeader = el('div', 'd-flex justify-content-end mb-1');
                    groupHeader.appendChild(removeButton(function () {
                        groups.splice(groupIndex, 1);
                        ctx.onChange();
                        renderConditionGroups(groups, container, ctx);
                    }));
                    groupBox.appendChild(groupHeader);

                    const listContainer = el('div');
                    renderConditionList(group, listContainer, ctx, ctx.labels.addCondition);
                    groupBox.appendChild(listContainer);

                    container.appendChild(groupBox);
                });

                const addGroupBtn = el('button', 'btn btn-sm btn-outline-secondary', {type: 'button', textContent: ctx.labels.addGroup});
                addGroupBtn.addEventListener('click', function () {
                    groups.push([]);
                    ctx.onChange();
                    renderConditionGroups(groups, container, ctx);
                });
                container.appendChild(addGroupBtn);
            }

            /**
             * The optional "save this action's result in a variable" field
             * appended to every leaf action except "reply" (whose own
             * effect is the visible message, not something worth capturing).
             * A plain textInput (not textInputWithPicker) since it *defines*
             * a variable name rather than referencing one - same convention
             * as set_variable/modify_variable/random_number's own "name"
             * field.
             */
            function renderResultVariableField(block, ctx) {
                const wrap = el('div', 'mt-2');
                wrap.appendChild(textInput(block.result_variable, ctx.labels.resultVariable, function (v) {
                    block.result_variable = v;
                    ctx.onChange();
                }));
                wrap.appendChild(el('div', 'form-text', {textContent: ctx.labels.resultVariableHelp}));

                return wrap;
            }

            /**
             * Renders one leaf action block's type-specific fields directly
             * onto `block` (flattened - no "config" wrapper, see
             * CustomCommand's docblock) into `container`.
             */
            function renderActionConfigFields(block, container, ctx) {
                container.innerHTML = '';

                if (block.type === 'send_dm') {
                    container.appendChild(textInputWithPicker(block.recipient, ctx.labels.recipient, function (v) {
                        block.recipient = v;
                        ctx.onChange();
                    }, ctx, 'mb-2'));
                }

                if (block.type === 'send_channel_message') {
                    container.appendChild(renderGuildChannelPicker(block, ctx));
                }

                if (block.type === 'reply' || block.type === 'send_dm' || block.type === 'send_channel_message') {
                    const textarea = el('textarea', 'form-control form-control-sm mb-2 mt-2', {rows: 2, value: block.content || ''});
                    textarea.value = block.content || '';
                    textarea.addEventListener('input', function () {
                        block.content = textarea.value;
                        ctx.onChange();
                    });
                    container.appendChild(textarea);

                    container.appendChild(el('div', 'form-text mb-2', {textContent: ctx.labels.placeholdersHelp}));

                    if (block.type === 'reply') {
                        const wrap = el('div', 'form-check');
                        const checkbox = el('input', 'form-check-input', {type: 'checkbox', checked: block.ephemeral !== false});
                        checkbox.addEventListener('change', function () {
                            block.ephemeral = checkbox.checked;
                            ctx.onChange();
                        });
                        const label = el('label', 'form-check-label small', {textContent: ctx.labels.ephemeral});
                        wrap.appendChild(checkbox);
                        wrap.appendChild(label);
                        container.appendChild(wrap);
                    } else {
                        container.appendChild(renderResultVariableField(block, ctx));
                    }

                    return;
                }

                if (block.type === 'assign_discord_role' || block.type === 'remove_discord_role') {
                    container.appendChild(renderGuildRolePicker(block, ctx));
                    container.appendChild(renderResultVariableField(block, ctx));

                    return;
                }

                if (block.type === 'kick_discord_member') {
                    container.appendChild(renderGuildOnlyField(block, ctx));
                    container.appendChild(renderResultVariableField(block, ctx));

                    return;
                }

                if (block.type === 'ban_discord_member') {
                    container.appendChild(renderGuildOnlyField(block, ctx));
                    container.appendChild(textInputWithPicker(block.reason, ctx.labels.banReason, function (v) {
                        block.reason = v;
                        ctx.onChange();
                    }, ctx, 'mt-2'));
                    container.appendChild(el('div', 'form-text', {textContent: ctx.labels.placeholdersHelp}));
                    container.appendChild(renderResultVariableField(block, ctx));

                    return;
                }

                if (block.type === 'set_discord_nickname') {
                    container.appendChild(renderGuildOnlyField(block, ctx));
                    container.appendChild(textInputWithPicker(block.nickname, ctx.labels.nickname, function (v) {
                        block.nickname = v;
                        ctx.onChange();
                    }, ctx, 'mt-2'));
                    container.appendChild(el('div', 'form-text', {textContent: ctx.labels.placeholdersHelp}));
                    container.appendChild(renderResultVariableField(block, ctx));

                    return;
                }

                if (block.type === 'assign_site_role' || block.type === 'remove_site_role') {
                    const select = el('select', 'form-select form-select-sm');
                    ctx.siteRoles.forEach(function (role) {
                        const opt = el('option', null, {value: String(role.id), textContent: role.name});
                        opt.selected = block.role_id === role.id;
                        select.appendChild(opt);
                    });
                    select.addEventListener('change', function () {
                        block.role_id = parseInt(select.value, 10);
                        ctx.onChange();
                    });
                    container.appendChild(select);

                    if (block.role_id == null && ctx.siteRoles.length > 0) {
                        block.role_id = ctx.siteRoles[0].id;
                        ctx.onChange();
                    }

                    container.appendChild(renderResultVariableField(block, ctx));

                    return;
                }

                if (block.type === 'give_shop_packages') {
                    if (! ctx.shopPackages || ctx.shopPackages.length === 0) {
                        container.appendChild(el('div', 'form-text', {textContent: ctx.labels.noShopPackages}));
                    } else {
                        block.package_ids = block.package_ids || [];

                        ctx.shopPackages.forEach(function (pkg) {
                            const wrap = el('div', 'form-check form-check-inline');
                            const checkbox = el('input', 'form-check-input', {
                                type: 'checkbox',
                                checked: block.package_ids.includes(pkg.id),
                            });
                            checkbox.addEventListener('change', function () {
                                if (checkbox.checked) {
                                    if (! block.package_ids.includes(pkg.id)) {
                                        block.package_ids.push(pkg.id);
                                    }
                                } else {
                                    block.package_ids = block.package_ids.filter(function (id) { return id !== pkg.id; });
                                }
                                ctx.onChange();
                            });
                            const label = el('label', 'form-check-label', {textContent: pkg.name});
                            wrap.appendChild(checkbox);
                            wrap.appendChild(label);
                            container.appendChild(wrap);
                        });
                    }

                    container.appendChild(renderResultVariableField(block, ctx));

                    return;
                }

                if (block.type === 'modify_balance') {
                    const row = el('div', 'row g-2');

                    const opCol = el('div', 'col-md-4');
                    const opSelect = el('select', 'form-select form-select-sm');
                    [['add', ctx.labels.opAdd], ['subtract', ctx.labels.opSubtract], ['set', ctx.labels.opSet]].forEach(function ([value, label]) {
                        const opt = el('option', null, {value: value, textContent: label});
                        opt.selected = block.operation === value;
                        opSelect.appendChild(opt);
                    });
                    opSelect.addEventListener('change', function () {
                        block.operation = opSelect.value;
                        ctx.onChange();
                    });
                    opCol.appendChild(opSelect);
                    row.appendChild(opCol);

                    const amountCol = el('div', 'col-md-8');
                    amountCol.appendChild(textInputWithPicker(block.amount, ctx.labels.amount, function (v) {
                        block.amount = v;
                        ctx.onChange();
                    }, ctx));
                    row.appendChild(amountCol);

                    container.appendChild(row);
                    container.appendChild(el('div', 'form-text', {textContent: ctx.labels.placeholdersHelp}));

                    if (! block.operation) {
                        block.operation = 'add';
                        ctx.onChange();
                    }

                    container.appendChild(renderResultVariableField(block, ctx));

                    return;
                }

                if (block.type === 'run_artisan_command') {
                    container.appendChild(el('div', 'alert alert-warning py-2 px-2 small mb-2', {textContent: ctx.labels.runArtisanWarning}));

                    const select = el('select', 'form-select form-select-sm mb-2');
                    const emptyOpt = el('option', null, {value: '', textContent: '—'});
                    select.appendChild(emptyOpt);
                    ctx.artisanCommands.forEach(function (cmd) {
                        const opt = el('option', null, {
                            value: cmd.name,
                            textContent: cmd.name + (cmd.dangerous ? ' ⚠' : ''),
                        });
                        opt.selected = block.command === cmd.name;
                        if (cmd.dangerous) {
                            opt.style.color = '#b45309';
                            opt.title = ctx.labels.runArtisanDangerous;
                        }
                        select.appendChild(opt);
                    });
                    select.addEventListener('change', function () {
                        block.command = select.value;
                        ctx.onChange();
                        renderActionConfigFields(block, container, ctx);
                    });
                    container.appendChild(select);

                    const dangerous = ctx.artisanCommands.some(function (cmd) { return cmd.name === block.command && cmd.dangerous; });
                    if (dangerous) {
                        container.appendChild(el('div', 'alert alert-danger py-2 px-2 small mb-2', {textContent: ctx.labels.runArtisanDangerous}));
                    }

                    block.parameters = block.parameters || [];

                    const paramsContainer = el('div', 'ps-3 border-start mb-2');
                    const paramsTitle = el('div', 'form-text mb-1', {textContent: ctx.labels.parameters});
                    paramsContainer.appendChild(paramsTitle);

                    function renderParams() {
                        Array.from(paramsContainer.querySelectorAll('.param-row')).forEach(function (n) { n.remove(); });
                        const addBtn = paramsContainer.querySelector('.param-add-btn');

                        block.parameters.forEach(function (pair, pi) {
                            const row = el('div', 'd-flex gap-2 mb-1 param-row');

                            row.appendChild(textInput(pair.key, ctx.labels.paramKey, function (v) {
                                block.parameters[pi].key = v;
                                ctx.onChange();
                            }));
                            row.appendChild(textInputWithPicker(pair.value, ctx.labels.paramValue, function (v) {
                                block.parameters[pi].value = v;
                                ctx.onChange();
                            }, ctx, 'flex-grow-1'));
                            row.appendChild(removeButton(function () {
                                block.parameters.splice(pi, 1);
                                ctx.onChange();
                                renderParams();
                            }));

                            paramsContainer.insertBefore(row, addBtn);
                        });
                    }

                    const addParamBtn = el('button', 'btn btn-sm btn-outline-secondary param-add-btn', {
                        type: 'button',
                        textContent: ctx.labels.addParam,
                    });
                    addParamBtn.addEventListener('click', function () {
                        block.parameters.push({key: '', value: ''});
                        ctx.onChange();
                        renderParams();
                    });
                    paramsContainer.appendChild(addParamBtn);
                    renderParams();

                    container.appendChild(paramsContainer);
                    container.appendChild(renderResultVariableField(block, ctx));

                    return;
                }

                if (block.type === 'show_modal') {
                    if (! ctx.forms || ctx.forms.length === 0) {
                        container.appendChild(el('div', 'form-text', {textContent: ctx.labels.noForms}));

                        return;
                    }

                    const select = el('select', 'form-select form-select-sm');
                    ctx.forms.forEach(function (form) {
                        const opt = el('option', null, {value: String(form.id), textContent: form.name});
                        opt.selected = block.form_id === form.id;
                        select.appendChild(opt);
                    });
                    select.addEventListener('change', function () {
                        block.form_id = parseInt(select.value, 10);
                        ctx.onChange();
                    });
                    container.appendChild(select);

                    if (block.form_id == null && ctx.forms.length > 0) {
                        block.form_id = ctx.forms[0].id;
                        ctx.onChange();
                    }

                    // No result_variable field - show_modal is a hard stop,
                    // it never reaches runLeaf()/storeResult() server-side,
                    // so there's nothing meaningful to capture.
                    return;
                }

                if (registeredActionTypes[block.type]) {
                    registeredActionTypes[block.type].renderFields(block, container, ctx);
                }
            }

            return {
                el: el,
                textInput: textInput,
                textInputWithPicker: textInputWithPicker,
                renderVariablePickerButton: renderVariablePickerButton,
                removeButton: removeButton,
                initOptions: initOptions,
                CONDITION_TYPES: CONDITION_TYPES,
                ACTION_TYPES: ACTION_TYPES,
                renderConditionFields: renderConditionFields,
                renderGuildRolePicker: renderGuildRolePicker,
                renderGuildChannelPicker: renderGuildChannelPicker,
                renderGuildOnlyField: renderGuildOnlyField,
                buildConditionRow: buildConditionRow,
                renderConditionList: renderConditionList,
                renderConditionGroups: renderConditionGroups,
                renderActionConfigFields: renderActionConfigFields,
                renderResultVariableField: renderResultVariableField,
                registerActionType: registerActionType,
                registerConditionType: registerConditionType,
                allActionTypes: allActionTypes,
                allConditionTypes: allConditionTypes,
                actionLabel: actionLabel,
                conditionLabel: conditionLabel,
            };
        })();
    </script>
@endpush
