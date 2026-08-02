{{--
    The drag-and-drop visual script editor's JS (see command-script.blade.php)
    - built on top of the DOM/condition/action-field primitives exposed by
    command-builder-scripts.blade.php (window.CommandBuilder), which must be
    included first.

    The whole script is a single JS array-of-objects tree (window.ScriptEditor
    keeps it as one `state` array) - no virtual DOM, every structural mutation
    (add/remove/move a block) triggers a full re-render of the canvas from
    `state`, same convention as CommandBuilder's builders. Each container
    block's child sequence (`then`/`else`/`body`) is rendered by calling
    renderSequence() recursively with a DIRECT REFERENCE to that nested array
    (not a serialized "path"), which is what makes arbitrary nesting work for
    free: every drop-slot/card inside a nested render closes over the correct
    target array by plain JS closure semantics, no matter how deep.
--}}
@push('footer-scripts')
    <script>
        window.ScriptEditor = (function () {
            const CB = window.CommandBuilder;

            const CONTROL_TYPES = ['if', 'repeat', 'stop'];
            const VARIABLE_TYPES = ['set_variable', 'modify_variable', 'random_number'];

            function categoryOf(type) {
                if (CONTROL_TYPES.includes(type)) {
                    return 'control';
                }

                if (VARIABLE_TYPES.includes(type)) {
                    return 'variables';
                }

                return 'actions';
            }

            function categoryClass(type) {
                return {
                    control: 'border-primary',
                    variables: 'border-info',
                    actions: 'border-success',
                }[categoryOf(type)] || 'border-secondary';
            }

            /**
             * "show_modal" only makes sense in a command's script (a form's
             * own script showing another modal isn't a reliably supported
             * flow in Discord's stable API, see Form's docblock) - hidden
             * from both the drag palette and the click-fallback dropdown
             * unless the caller opted in via ctx.allowShowModal (see
             * command-script.blade.php vs. form-script.blade.php).
             */
            function visibleActionTypes(ctx) {
                return CB.allActionTypes().filter(function (type) {
                    return type !== 'show_modal' || ctx.allowShowModal;
                });
            }

            function newId() {
                return 'b' + Date.now().toString(36) + Math.random().toString(36).slice(2, 8);
            }

            function createDefaultBlock(type) {
                const id = newId();

                switch (type) {
                    case 'if': return {id: id, type: type, condition: [], then: [], else: []};
                    case 'repeat': return {id: id, type: type, count: '1', body: []};
                    case 'stop': return {id: id, type: type};
                    case 'set_variable': return {id: id, type: type, name: '', value: ''};
                    case 'modify_variable': return {id: id, type: type, name: '', operation: 'add', value: ''};
                    case 'random_number': return {id: id, type: type, name: '', min: '1', max: '100'};
                    case 'reply': return {id: id, type: type, content: '', ephemeral: true};
                    case 'send_dm': return {id: id, type: type, recipient: '', content: ''};
                    case 'send_channel_message': return {id: id, type: type, guild_id: '', channel_id: '', content: ''};
                    case 'assign_discord_role': case 'remove_discord_role':
                        return {id: id, type: type, guild_id: '', role_id: ''};
                    case 'kick_discord_member': return {id: id, type: type, guild_id: ''};
                    case 'ban_discord_member': return {id: id, type: type, guild_id: '', reason: ''};
                    case 'set_discord_nickname': return {id: id, type: type, guild_id: '', nickname: ''};
                    case 'assign_site_role': case 'remove_site_role':
                        return {id: id, type: type, role_id: null};
                    case 'give_shop_packages': return {id: id, type: type, package_ids: []};
                    case 'modify_balance': return {id: id, type: type, operation: 'add', amount: ''};
                    case 'run_artisan_command': return {id: id, type: type, command: '', parameters: []};
                    case 'show_modal': return {id: id, type: type, form_id: null};
                }

                // A registered extension action type (see CB.registerActionType)
                // - its own renderFields() is expected to lazily default any
                // field it needs on first render, the same convention
                // modify_variable's built-in renderer above already follows
                // for "operation".
                return {id: id, type: type};
            }

            /**
             * Finds {parentArray, index} for the block with the given id by
             * searching recursively through then/else/body sequences,
             * starting from `sequence`. Returns null if not found (stale
             * reference, defensive only).
             */
            function locate(sequence, id) {
                for (let i = 0; i < sequence.length; i++) {
                    if (sequence[i].id === id) {
                        return {parentArray: sequence, index: i};
                    }

                    const block = sequence[i];

                    for (const key of ['then', 'else', 'body']) {
                        if (Array.isArray(block[key])) {
                            const found = locate(block[key], id);

                            if (found) {
                                return found;
                            }
                        }
                    }
                }

                return null;
            }

            function removeById(root, id) {
                const found = locate(root, id);

                if (! found) {
                    return null;
                }

                return found.parentArray.splice(found.index, 1)[0];
            }

            /**
             * Every variable name defined anywhere in the script tree -
             * either by a set_variable/modify_variable/random_number block's
             * "name", or by any leaf action's optional "result_variable" -
             * used to populate the "insert a variable" picker on other
             * fields (see ctx.availablePlaceholders below). Not data-flow
             * aware (a variable defined after the field being edited still
             * shows up) since blocks can be freely reordered and drag-and-
             * drop already lets the admin restructure at will; a stricter
             * "only variables set earlier" analysis would need to track
             * position through every reorder for little practical gain.
             */
            function collectVariableNames(sequence, names) {
                names = names || new Set();

                sequence.forEach(function (block) {
                    if (VARIABLE_TYPES.includes(block.type) && block.name) {
                        names.add(block.name);
                    }

                    if (block.result_variable) {
                        names.add(block.result_variable);
                    }

                    ['then', 'else', 'body'].forEach(function (key) {
                        if (Array.isArray(block[key])) {
                            collectVariableNames(block[key], names);
                        }
                    });
                });

                return names;
            }

            /**
             * Whether `sequence` is (or is nested inside) one of `block`'s
             * own then/else/body sequences - used to refuse dropping a
             * container block inside its own subtree, which would orphan
             * the drop target and corrupt the tree.
             */
            function isSequenceInsideBlock(block, sequence) {
                for (const key of ['then', 'else', 'body']) {
                    if (! Array.isArray(block[key])) {
                        continue;
                    }

                    if (block[key] === sequence) {
                        return true;
                    }

                    for (const child of block[key]) {
                        if (isSequenceInsideBlock(child, sequence)) {
                            return true;
                        }
                    }
                }

                return false;
            }

            // Tracks the single drop-slot currently highlighted as an
            // insertion point, so dragover/dragleave firing on adjacent
            // slots during a fast drag doesn't flicker classes on/off.
            let activeDropSlot = null;

            function setActiveDropSlot(slot) {
                if (activeDropSlot === slot) {
                    return;
                }

                if (activeDropSlot) {
                    activeDropSlot.classList.remove('script-drop-slot-active');
                }

                activeDropSlot = slot;
                slot.classList.add('script-drop-slot-active');
            }

            function clearActiveDropSlot() {
                if (activeDropSlot) {
                    activeDropSlot.classList.remove('script-drop-slot-active');
                }

                activeDropSlot = null;
            }

            function handleDrop(event, targetSequence, insertIndex, ctx) {
                const newBlockType = event.dataTransfer.getData('application/x-script-block-type');
                const draggedBlockId = event.dataTransfer.getData('application/x-script-block-id');

                if (newBlockType) {
                    targetSequence.splice(insertIndex, 0, createDefaultBlock(newBlockType));
                    ctx.render();

                    return;
                }

                if (! draggedBlockId) {
                    return;
                }

                const found = locate(ctx.rootSequence, draggedBlockId);

                if (! found) {
                    return; // stale reference (e.g. concurrent removal) - defensive only
                }

                if (isSequenceInsideBlock(found.parentArray[found.index], targetSequence)) {
                    return; // refuse dropping a container inside its own subtree
                }

                const removed = removeById(ctx.rootSequence, draggedBlockId);

                if (! removed) {
                    return;
                }

                // Moving a block LATER within the SAME sequence it started
                // in: removing it first shifts every subsequent index down
                // by one, so the drop-slot's insertIndex (captured at
                // render time, before the drag began) would land one too
                // far right unless corrected here.
                let finalIndex = insertIndex;

                if (found.parentArray === targetSequence && found.index < insertIndex) {
                    finalIndex -= 1;
                }

                targetSequence.splice(finalIndex, 0, removed);
                ctx.render();
            }

            /**
             * A thin drop target between blocks (and one before the first /
             * after the last in every sequence) - dragover shows a 2px
             * insertion line via the "script-drop-slot-active" class (see
             * command-script.blade.php's <style>).
             */
            function renderDropSlot(sequence, insertIndex, ctx) {
                const slot = CB.el('div', 'script-drop-slot');

                slot.addEventListener('dragover', function (e) {
                    e.preventDefault();
                    setActiveDropSlot(slot);
                });
                slot.addEventListener('dragleave', function () {
                    if (activeDropSlot === slot) {
                        clearActiveDropSlot();
                    }
                });
                slot.addEventListener('drop', function (e) {
                    e.preventDefault();
                    clearActiveDropSlot();
                    handleDrop(e, sequence, insertIndex, ctx);
                });

                return slot;
            }

            /**
             * The palette of draggable block *templates* (one per type,
             * grouped by category) - dragging one out always creates a new
             * instance (effectAllowed "copy"), never moves the template
             * itself.
             */
            function renderPalette(container, ctx) {
                container.innerHTML = '';

                const groups = [
                    ['control', ctx.labels.categoryControl, CONTROL_TYPES],
                    ['variables', ctx.labels.categoryVariables, VARIABLE_TYPES],
                    ['actions', ctx.labels.categoryActions, visibleActionTypes(ctx)],
                ];

                groups.forEach(function ([category, title, types]) {
                    const groupEl = CB.el('div', 'mb-3');
                    groupEl.appendChild(CB.el('div', 'fw-semibold small mb-1', {textContent: title}));

                    types.forEach(function (type) {
                        const card = CB.el('div', 'border rounded p-2 mb-1 small script-palette-item ' + categoryClass(type), {
                            textContent: CB.actionLabel(type, ctx),
                            draggable: true,
                        });
                        card.addEventListener('dragstart', function (e) {
                            e.dataTransfer.setData('application/x-script-block-type', type);
                            e.dataTransfer.effectAllowed = 'copy';
                        });
                        groupEl.appendChild(card);
                    });

                    container.appendChild(groupEl);
                });
            }

            function init(config) {
                const state = JSON.parse(JSON.stringify(config.initial || []));
                const canvas = document.getElementById(config.canvasId);
                const hidden = document.getElementById(config.hiddenId);
                const ctx = {
                    siteRoles: config.siteRoles || [],
                    shopPackages: config.shopPackages || null,
                    guilds: config.guilds || null,
                    guildPickerItems: config.guildPickerItems || [],
                    guildRolesUrl: config.guildRolesUrl,
                    guildChannelsUrl: config.guildChannelsUrl,
                    artisanCommands: config.artisanCommands || [],
                    commandOptions: config.commandOptions || [],
                    forms: config.forms || [],
                    allowShowModal: !! config.allowShowModal,
                    labels: config.labels,
                    rootSequence: state,
                };
                ctx.onChange = function () {
                    sync();
                };

                /**
                 * The {var:name}/{option:name} tokens the "insert a
                 * variable" picker button offers (see
                 * command-builder-scripts.blade.php's renderVariablePickerButton)
                 * - recomputed on every open so a variable added to the
                 * script (or removed) a moment ago is reflected immediately.
                 */
                ctx.availablePlaceholders = function () {
                    const variableItems = Array.from(collectVariableNames(state)).map(function (name) {
                        return {id: '{var:' + name + '}', label: '{var:' + name + '}'};
                    });
                    const optionItems = ctx.commandOptions.map(function (name) {
                        return {id: '{option:' + name + '}', label: '{option:' + name + '}'};
                    });

                    return variableItems.concat(optionItems);
                };

                function serializeSequence(sequence) {
                    return sequence.map(function (block) {
                        const copy = Object.assign({}, block);

                        ['then', 'else', 'body'].forEach(function (key) {
                            if (Array.isArray(copy[key])) {
                                copy[key] = serializeSequence(copy[key]);
                            }
                        });

                        if (copy.type === 'run_artisan_command' && Array.isArray(copy.parameters)) {
                            const obj = {};
                            copy.parameters.forEach(function (pair) {
                                if (pair.key) {
                                    obj[pair.key] = pair.value;
                                }
                            });
                            copy.parameters = obj;
                        }

                        return copy;
                    });
                }

                function sync() {
                    hidden.value = JSON.stringify(serializeSequence(state));
                }

                function render() {
                    renderSequence(state, canvas, ctx);
                    sync();
                }
                ctx.render = render;

                if (config.paletteId) {
                    renderPalette(document.getElementById(config.paletteId), ctx);
                }

                render();

                return {
                    setState: function (newState) {
                        state.length = 0;
                        (newState || []).forEach(function (block) {
                            state.push(block);
                        });
                        render();
                    },
                    getState: function () {
                        return serializeSequence(state);
                    },
                };
            }

            function renderSequence(sequence, container, ctx) {
                container.innerHTML = '';
                container.classList.add('script-sequence');

                container.appendChild(renderDropSlot(sequence, 0, ctx));

                sequence.forEach(function (block, index) {
                    container.appendChild(renderBlockCard(block, sequence, index, ctx));
                    container.appendChild(renderDropSlot(sequence, index + 1, ctx));
                });

                if (sequence.length === 0) {
                    container.appendChild(CB.el('div', 'text-muted small mb-2', {textContent: ctx.labels.dropHint}));
                }

                container.appendChild(renderAppendControl(sequence, ctx));
            }

            /**
             * A small "pick a block type, add it" control appended at the
             * end of every sequence (top-level and every nested then/else/
             * body) - the click-based way to grow the tree until real drag-
             * and-drop is wired in on top of this same data model.
             */
            function renderAppendControl(sequence, ctx) {
                const wrap = CB.el('div', 'd-flex gap-2 align-items-center mb-2');
                const select = CB.el('select', 'form-select form-select-sm w-auto');

                CONTROL_TYPES.concat(VARIABLE_TYPES).concat(visibleActionTypes(ctx)).forEach(function (type) {
                    const opt = CB.el('option', null, {value: type, textContent: CB.actionLabel(type, ctx)});
                    select.appendChild(opt);
                });

                const addBtn = CB.el('button', 'btn btn-sm btn-outline-primary', {type: 'button', textContent: ctx.labels.addBlock});
                addBtn.addEventListener('click', function () {
                    sequence.push(createDefaultBlock(select.value));
                    ctx.render();
                });

                wrap.appendChild(select);
                wrap.appendChild(addBtn);

                return wrap;
            }

            function renderBlockCard(block, parentSequence, index, ctx) {
                const card = CB.el('div', 'border rounded p-2 mb-2 border-2 ' + categoryClass(block.type));
                card.dataset.blockId = block.id;
                card.draggable = true;

                card.addEventListener('dragstart', function (e) {
                    // Don't let an ancestor container block's own dragstart
                    // also fire for a card dragged from inside its body.
                    e.stopPropagation();
                    e.dataTransfer.setData('application/x-script-block-id', block.id);
                    e.dataTransfer.effectAllowed = 'move';
                    card.classList.add('script-block-dragging');
                });
                card.addEventListener('dragend', function () {
                    card.classList.remove('script-block-dragging');
                });

                card.appendChild(renderBlockHeader(block, ctx));

                const fieldsContainer = CB.el('div', 'mb-2');
                renderBlockFields(block, fieldsContainer, ctx);
                card.appendChild(fieldsContainer);

                if (block.type === 'if') {
                    card.appendChild(renderChildSlot(ctx.labels.thenLabel, block.then, ctx));
                    card.appendChild(renderChildSlot(ctx.labels.elseLabel, block.else, ctx));
                } else if (block.type === 'repeat') {
                    card.appendChild(renderChildSlot(ctx.labels.bodyLabel, block.body, ctx));
                }

                return card;
            }

            function renderChildSlot(label, sequence, ctx) {
                const wrap = CB.el('div', 'ps-3 border-start mt-2');
                wrap.appendChild(CB.el('div', 'form-text mb-1', {textContent: label}));

                // renderSequence() clears its container's innerHTML on every
                // (re-)render - it must get its own inner element rather
                // than `wrap` itself, otherwise it would wipe out the label
                // div appended just above on every re-render.
                const inner = CB.el('div');
                renderSequence(sequence, inner, ctx);
                wrap.appendChild(inner);

                return wrap;
            }

            function renderBlockHeader(block, ctx) {
                const header = CB.el('div', 'd-flex align-items-center gap-2 mb-2');
                header.appendChild(CB.el('span', 'fw-semibold small', {textContent: CB.actionLabel(block.type, ctx)}));
                header.appendChild(CB.el('div', 'flex-grow-1'));
                header.appendChild(CB.removeButton(function () {
                    removeById(ctx.rootSequence, block.id);
                    ctx.render();
                }));

                return header;
            }

            function renderBlockFields(block, container, ctx) {
                container.innerHTML = '';

                if (block.type === 'if') {
                    block.condition = block.condition || [];
                    CB.renderConditionGroups(block.condition, container, ctx);

                    return;
                }

                if (block.type === 'repeat') {
                    container.appendChild(CB.textInputWithPicker(block.count, ctx.labels.repeatCount, function (v) {
                        block.count = v;
                        ctx.onChange();
                    }, ctx));

                    return;
                }

                if (block.type === 'stop') {
                    return;
                }

                if (block.type === 'set_variable') {
                    const row = CB.el('div', 'row g-2');

                    const nameCol = CB.el('div', 'col-md-6');
                    nameCol.appendChild(CB.textInput(block.name, ctx.labels.variableName, function (v) {
                        block.name = v;
                        ctx.onChange();
                    }));
                    row.appendChild(nameCol);

                    const valueCol = CB.el('div', 'col-md-6');
                    valueCol.appendChild(CB.textInputWithPicker(block.value, ctx.labels.variableValue, function (v) {
                        block.value = v;
                        ctx.onChange();
                    }, ctx));
                    row.appendChild(valueCol);

                    container.appendChild(row);
                    container.appendChild(CB.el('div', 'form-text', {textContent: ctx.labels.placeholdersHelp}));

                    return;
                }

                if (block.type === 'modify_variable') {
                    const nameRow = CB.el('div', 'row g-2');
                    const nameCol = CB.el('div', 'col-md-12');
                    nameCol.appendChild(CB.textInput(block.name, ctx.labels.variableName, function (v) {
                        block.name = v;
                        ctx.onChange();
                    }));
                    nameRow.appendChild(nameCol);
                    container.appendChild(nameRow);

                    const opRow = CB.el('div', 'row g-2 mt-1');

                    const opCol = CB.el('div', 'col-md-4');
                    const opSelect = CB.el('select', 'form-select form-select-sm');
                    [
                        ['add', ctx.labels.opAdd], ['subtract', ctx.labels.opSubtract],
                        ['multiply', ctx.labels.opMultiply], ['divide', ctx.labels.opDivide],
                        ['set', ctx.labels.opSet],
                    ].forEach(function ([value, label]) {
                        const opt = CB.el('option', null, {value: value, textContent: label});
                        opt.selected = block.operation === value;
                        opSelect.appendChild(opt);
                    });
                    opSelect.addEventListener('change', function () {
                        block.operation = opSelect.value;
                        ctx.onChange();
                    });
                    opCol.appendChild(opSelect);
                    opRow.appendChild(opCol);

                    const valueCol = CB.el('div', 'col-md-8');
                    valueCol.appendChild(CB.textInputWithPicker(block.value, ctx.labels.variableValue, function (v) {
                        block.value = v;
                        ctx.onChange();
                    }, ctx));
                    opRow.appendChild(valueCol);

                    container.appendChild(opRow);
                    container.appendChild(CB.el('div', 'form-text', {textContent: ctx.labels.placeholdersHelp}));

                    if (! block.operation) {
                        block.operation = 'add';
                        ctx.onChange();
                    }

                    return;
                }

                if (block.type === 'random_number') {
                    const nameRow = CB.el('div', 'row g-2');
                    const nameCol = CB.el('div', 'col-md-12');
                    nameCol.appendChild(CB.textInput(block.name, ctx.labels.variableName, function (v) {
                        block.name = v;
                        ctx.onChange();
                    }));
                    nameRow.appendChild(nameCol);
                    container.appendChild(nameRow);

                    const rangeRow = CB.el('div', 'row g-2 mt-1');

                    const minCol = CB.el('div', 'col-md-6');
                    minCol.appendChild(CB.textInputWithPicker(block.min, ctx.labels.randomMin, function (v) {
                        block.min = v;
                        ctx.onChange();
                    }, ctx));
                    rangeRow.appendChild(minCol);

                    const maxCol = CB.el('div', 'col-md-6');
                    maxCol.appendChild(CB.textInputWithPicker(block.max, ctx.labels.randomMax, function (v) {
                        block.max = v;
                        ctx.onChange();
                    }, ctx));
                    rangeRow.appendChild(maxCol);

                    container.appendChild(rangeRow);
                    container.appendChild(CB.el('div', 'form-text', {textContent: ctx.labels.placeholdersHelp}));

                    return;
                }

                // Every leaf action type - reuses the exact same field
                // renderer as the old actions builder, adapted to read/write
                // fields flattened directly on the block (see CustomCommand's
                // docblock) instead of a "config" wrapper.
                CB.renderActionConfigFields(block, container, ctx);
            }

            return {
                init: init,
            };
        })();
    </script>
@endpush
