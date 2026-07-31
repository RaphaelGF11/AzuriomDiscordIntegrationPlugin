{{--
    Shared picker modal: one instance per page, reused by every "(select)"
    link that needs to pick a Discord server or role instead of typing a raw
    ID by hand. See DiscordPicker.open() below for how callers use it.
--}}
<div class="modal fade" id="discordPickerModal" tabindex="-1" role="dialog" aria-labelledby="discordPickerModalLabel" aria-modal="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="discordPickerModalLabel"></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="search" class="form-control form-control-sm mb-2" id="discordPickerSearch" placeholder="{{ trans('discord-integration::admin.pick_search_placeholder') }}" autocomplete="off">
                <div class="list-group" id="discordPickerList" style="max-height: 320px; overflow-y: auto;"></div>
                <p class="text-muted mb-0 d-none" id="discordPickerEmpty"></p>
            </div>
        </div>
    </div>
</div>

@push('footer-scripts')
    <script>
        // Reusable picker for any "(select)" link that fills a text input with
        // a Discord server or role id instead of the admin typing it by hand.
        // When opened from a link inside another (already visible) modal, the
        // parent stays open underneath rather than being hidden/reshown -
        // Bootstrap doesn't actually raise a second modal's z-index above an
        // already-open one on its own (that only happens for its own
        // data-bs-toggle-driven nesting), so both ended up drawn at the same
        // level and visually collided. This bumps the picker (and the fresh
        // backdrop Bootstrap creates for it) above whatever's already open,
        // so the picker is unambiguously on top and its own backdrop dims
        // the parent modal behind it, the same way a backdrop normally dims
        // the page behind a single modal.
        window.DiscordPicker = {
            modal: null,

            init: function () {
                if (this.modal) {
                    return;
                }

                this.modalEl = document.getElementById('discordPickerModal');
                this.modal = new bootstrap.Modal(this.modalEl);
            },

            // options: { title, items: [{id, label, icon}], emptyText, onSelect, iconShape }
            // "icon" (a CDN URL, or null) is optional per item - only
            // guild/member items carry it; role items have no icon at all.
            // "iconShape" is 'square' (Azuriom's own convention - rounded
            // corners, not a full circle) by default, matching every icon
            // except Discord user/member avatars, which stay circular - the
            // one caller picking a Discord member (links.blade.php) passes
            // iconShape: 'circle' explicitly.
            // onSelect is responsible for closing the picker itself (call
            // DiscordPicker.modal.hide()) once it's made a final choice - it
            // is NOT closed automatically after a click, so a multi-step pick
            // (e.g. server, then one of its members) can call open() again
            // from inside onSelect to show the next step instead.
            open: function (options) {
                this.init();

                document.getElementById('discordPickerModalLabel').textContent = options.title;

                const list = document.getElementById('discordPickerList');
                const empty = document.getElementById('discordPickerEmpty');
                const search = document.getElementById('discordPickerSearch');
                const items = options.items || [];
                const shapeClass = options.iconShape === 'circle' ? 'rounded-circle' : 'rounded';

                const renderItems = function (filteredItems) {
                    list.innerHTML = '';

                    if (filteredItems.length === 0) {
                        empty.textContent = options.emptyText || '';
                        empty.classList.remove('d-none');
                    } else {
                        empty.classList.add('d-none');

                        filteredItems.forEach(function (item) {
                            const btn = document.createElement('button');
                            btn.type = 'button';
                            btn.className = 'list-group-item list-group-item-action d-flex align-items-center gap-2';

                            // A blank placeholder (rather than skipping the icon
                            // slot) keeps every row's label aligned when only
                            // some items in the list have one (e.g. a guild with
                            // no custom icon set among ones that do).
                            if ('icon' in item) {
                                if (item.icon) {
                                    const img = document.createElement('img');
                                    img.src = item.icon;
                                    img.alt = '';
                                    img.width = 24;
                                    img.height = 24;
                                    img.className = shapeClass + ' flex-shrink-0';
                                    btn.appendChild(img);
                                } else {
                                    const placeholder = document.createElement('span');
                                    placeholder.className = shapeClass + ' bg-secondary bg-opacity-25 flex-shrink-0';
                                    placeholder.style.width = '24px';
                                    placeholder.style.height = '24px';
                                    btn.appendChild(placeholder);
                                }
                            }

                            const label = document.createElement('span');
                            label.textContent = item.label;
                            btn.appendChild(label);

                            btn.addEventListener('click', function () {
                                options.onSelect(item);
                            });
                            list.appendChild(btn);
                        });
                    }
                };

                renderItems(items);

                // Cleared on every open() (rather than persisted across
                // pickers) since each open() call is typically a different
                // step of a cascade (e.g. servers, then that server's
                // members) - keeping a previous step's filter text around
                // would silently hide items in the next, unrelated list.
                search.value = '';
                search.classList.toggle('d-none', items.length === 0);
                search.oninput = function () {
                    const query = search.value.trim().toLowerCase();

                    renderItems(query === '' ? items : items.filter(function (item) {
                        return item.label.toLowerCase().includes(query);
                    }));
                };

                const stackedOnTop = document.querySelectorAll('.modal.show').length > 0;
                this.modalEl.style.zIndex = stackedOnTop ? 1065 : '';

                this.modal.show();

                // This modal/backdrop pair is a singleton reused for every
                // step of a multi-step pick (e.g. a server, then one of its
                // members) - show() no-ops while already shown, so the same
                // backdrop element can persist across several open() calls
                // instead of being destroyed and recreated each time. That
                // means a bump applied on one (genuinely stacked) call would
                // otherwise stick around on a later, non-stacked call too,
                // leaving the backdrop above a modal that reset back to its
                // default z-index - dimmed and unclickable. Always resolving
                // it here (not just bumping it inside an "if stacked" branch)
                // keeps it in sync with the modal's own z-index every time.
                const backdrops = document.querySelectorAll('.modal-backdrop');
                const ownBackdrop = backdrops[backdrops.length - 1];

                if (ownBackdrop) {
                    ownBackdrop.style.zIndex = stackedOnTop ? 1060 : '';
                }
            },
        };
    </script>
@endpush
