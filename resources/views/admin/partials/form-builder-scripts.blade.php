{{--
    The Form fields ("modal text inputs") builder used in the forms quick-
    create modal (see commands.blade.php's "Formulaires" card) - a flat
    repeatable list capped at 5 rows (Discord's own modal limit: one action
    row per field, five action rows max). Mirrors CommandBuilder.initOptions()'s
    shape/conventions closely, but a form field's schema is genuinely
    different from a command option's (style/min_length/max_length/
    placeholder/value vs. Discord option type/choices/channel_types), hence a
    separate module here rather than overloading initOptions - kept in its
    own partial since command-builder-scripts.blade.php is already large.
--}}
@push('footer-scripts')
    <script>
        window.FormBuilder = (function () {
            const CB = window.CommandBuilder;
            const MAX_FIELDS = 5;

            function initFormFields(config) {
                const state = JSON.parse(JSON.stringify(config.initial || []));
                const list = document.getElementById(config.listId);
                const hidden = document.getElementById(config.hiddenId);
                const addButton = document.getElementById(config.addButtonId);
                const labels = config.labels;

                function sync() {
                    hidden.value = JSON.stringify(state);
                }

                function render() {
                    list.innerHTML = '';

                    state.forEach(function (field, index) {
                        const row = CB.el('div', 'border rounded p-2 mb-2');

                        const topRow = CB.el('div', 'row g-2 align-items-start');

                        const keyCol = CB.el('div', 'col-md-3');
                        keyCol.appendChild(CB.textInput(field.field_key, labels.fieldKey, function (v) {
                            state[index].field_key = v;
                            sync();
                        }));
                        topRow.appendChild(keyCol);

                        const labelCol = CB.el('div', 'col-md-3');
                        labelCol.appendChild(CB.textInput(field.label, labels.label, function (v) {
                            state[index].label = v;
                            sync();
                        }));
                        topRow.appendChild(labelCol);

                        const styleCol = CB.el('div', 'col-md-2');
                        const styleSelect = CB.el('select', 'form-select form-select-sm');
                        [['short', labels.styleShort], ['paragraph', labels.styleParagraph]].forEach(function ([value, label]) {
                            const opt = CB.el('option', null, {value: value, textContent: label});
                            opt.selected = field.style === value;
                            styleSelect.appendChild(opt);
                        });
                        styleSelect.addEventListener('change', function () {
                            state[index].style = styleSelect.value;
                            sync();
                        });
                        styleCol.appendChild(styleSelect);
                        topRow.appendChild(styleCol);

                        const requiredCol = CB.el('div', 'col-md-2 form-check pt-1');
                        const requiredCheckbox = CB.el('input', 'form-check-input', {
                            type: 'checkbox',
                            checked: !! field.required,
                        });
                        requiredCheckbox.addEventListener('change', function () {
                            state[index].required = requiredCheckbox.checked;
                            sync();
                        });
                        const requiredLabel = CB.el('label', 'form-check-label small', {textContent: labels.required});
                        requiredCol.appendChild(requiredCheckbox);
                        requiredCol.appendChild(requiredLabel);
                        topRow.appendChild(requiredCol);

                        const removeCol = CB.el('div', 'col-md-2 text-end');
                        removeCol.appendChild(CB.removeButton(function () {
                            state.splice(index, 1);
                            sync();
                            render();
                        }));
                        topRow.appendChild(removeCol);

                        row.appendChild(topRow);

                        const detailsRow = CB.el('div', 'row g-2 mt-1');

                        const minCol = CB.el('div', 'col-md-3');
                        minCol.appendChild(CB.textInput(field.min_length, labels.minLength, function (v) {
                            state[index].min_length = v === '' ? null : parseInt(v, 10);
                            sync();
                        }));
                        detailsRow.appendChild(minCol);

                        const maxCol = CB.el('div', 'col-md-3');
                        maxCol.appendChild(CB.textInput(field.max_length, labels.maxLength, function (v) {
                            state[index].max_length = v === '' ? null : parseInt(v, 10);
                            sync();
                        }));
                        detailsRow.appendChild(maxCol);

                        const placeholderCol = CB.el('div', 'col-md-3');
                        placeholderCol.appendChild(CB.textInput(field.placeholder, labels.placeholder, function (v) {
                            state[index].placeholder = v;
                            sync();
                        }));
                        detailsRow.appendChild(placeholderCol);

                        const valueCol = CB.el('div', 'col-md-3');
                        valueCol.appendChild(CB.textInput(field.value, labels.value, function (v) {
                            state[index].value = v;
                            sync();
                        }));
                        detailsRow.appendChild(valueCol);

                        row.appendChild(detailsRow);

                        list.appendChild(row);
                    });

                    addButton.disabled = state.length >= MAX_FIELDS;
                    addButton.title = addButton.disabled ? labels.maxFieldsReached : '';

                    sync();
                }

                addButton.addEventListener('click', function () {
                    if (state.length >= MAX_FIELDS) {
                        return;
                    }

                    state.push({field_key: '', label: '', style: 'short', required: false, min_length: null, max_length: null, placeholder: '', value: ''});
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

            return {
                initFormFields: initFormFields,
            };
        })();
    </script>
@endpush
