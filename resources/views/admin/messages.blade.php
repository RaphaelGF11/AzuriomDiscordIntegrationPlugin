@extends('admin.layouts.admin')

@section('title', trans('discord-integration::admin.nav.messages'))

@section('content')
    @if(! $botAvailable)
        <div class="alert alert-info">
            <i class="bi bi-info-circle"></i> {{ trans('discord-integration::admin.messages.bot_unavailable') }}
        </div>
    @else
        <div class="card shadow mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0">{{ trans('discord-integration::admin.messages.title') }}</h5>
            </div>
            <div class="card-body">
                <p class="text-muted">{{ trans('discord-integration::admin.messages.description') }}</p>

                <ul class="nav nav-tabs mb-3">
                    <li class="nav-item">
                        <button type="button" class="nav-link active" id="msgModeChannelTab">{{ trans('discord-integration::admin.messages.mode_channel') }}</button>
                    </li>
                    <li class="nav-item">
                        <button type="button" class="nav-link" id="msgModeDmTab">{{ trans('discord-integration::admin.messages.mode_dm') }}</button>
                    </li>
                </ul>

                <div id="msgModeChannel">
                    <div class="row g-2">
                        <div class="col-md-4">
                            <label class="form-label" for="msgGuildId">{{ trans('discord-integration::admin.messages.guild_id') }}</label>
                            <input type="text" class="form-control form-control-sm" id="msgGuildId" autocomplete="off">
                            @if($guilds !== null)
                                <a href="#" id="msgGuildIdPicker" class="small d-inline-block mt-1">({{ trans('discord-integration::admin.pick') }})</a>
                            @endif
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="msgChannelId">{{ trans('discord-integration::admin.messages.channel_id') }}</label>
                            <input type="text" class="form-control form-control-sm" id="msgChannelId" autocomplete="off">
                            <a href="#" id="msgChannelIdPicker" class="small d-inline-block mt-1">({{ trans('discord-integration::admin.pick') }})</a>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="msgThreadId">{{ trans('discord-integration::admin.messages.thread_id') }}</label>
                            <input type="text" class="form-control form-control-sm" id="msgThreadId" autocomplete="off">
                            <a href="#" id="msgThreadIdPicker" class="small d-inline-block mt-1">({{ trans('discord-integration::admin.pick') }})</a>
                        </div>
                    </div>
                </div>

                <div id="msgModeDm" class="d-none">
                    <label class="form-label" for="msgDmUserId">
                        {{ trans('discord-integration::admin.messages.discord_user_id') }}

                        @if($guilds !== null)
                            <a href="#" id="msgDmUserIdPicker">({{ trans('discord-integration::admin.pick') }})</a>
                        @endif
                    </label>
                    <input type="text" class="form-control form-control-sm" id="msgDmUserId" autocomplete="off">
                </div>

                <div class="mt-3">
                    <button type="button" class="btn btn-primary btn-sm" id="msgLoadButton">{{ trans('discord-integration::admin.messages.load') }}</button>
                    <span class="text-danger small d-none ms-2" id="msgLoadError">{{ trans('discord-integration::admin.messages.load_error') }}</span>
                </div>
            </div>
        </div>

        <div class="card shadow mb-4 d-none" id="msgListCard">
            <div class="card-header">
                <h5 class="card-title mb-0">{{ trans('discord-integration::admin.messages.messages_title') }}</h5>
            </div>
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-sm btn-outline-danger" id="msgPurgeButton" data-bs-toggle="modal" data-bs-target="#msgPurgeModal">{{ trans('discord-integration::admin.messages.purge') }}</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="msgSelectToggleButton">{{ trans('discord-integration::admin.messages.select') }}</button>
                    </div>

                    <button type="button" class="btn btn-sm btn-outline-danger d-none" id="msgBulkDeleteButton"></button>
                </div>

                <div id="msgListScroll" style="max-height: 60vh; overflow-y: auto;">
                    <div class="text-center mb-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary d-none" id="msgLoadOlderButton">{{ trans('discord-integration::admin.messages.load_older') }}</button>
                    </div>

                    <div id="msgList"></div>
                    <p class="text-muted mb-0 d-none" id="msgEmpty">{{ trans('discord-integration::admin.messages.empty') }}</p>
                </div>

                <div class="d-none align-items-center gap-2 small text-muted mb-1 mt-2" id="msgReplyIndicator">
                    <span id="msgReplyIndicatorText"></span>
                    <button type="button" class="btn btn-sm btn-link p-0" id="msgReplyCancel">{{ trans('messages.actions.cancel') }}</button>
                </div>

                <div class="d-none align-items-center gap-2 small mb-1" id="msgFilePreview">
                    <span id="msgFileName"></span>
                    <button type="button" class="btn btn-sm btn-link p-0" id="msgFileRemove">{{ trans('messages.actions.remove') }}</button>
                </div>
                <div class="text-danger small d-none mb-1" id="msgFileError"></div>

                <div class="mt-1 d-flex gap-2 align-items-end">
                    <input type="file" class="d-none" id="msgComposeFile">
                    <button type="button" class="btn btn-outline-secondary" id="msgAttachButton" title="{{ trans('discord-integration::admin.messages.attach_file') }}">
                        <i class="bi bi-paperclip"></i>
                    </button>
                    <div class="position-relative flex-grow-1">
                        <div class="list-group position-absolute w-100 d-none" id="msgMentionAutocomplete" style="bottom: 100%; z-index: 1050; max-height: 220px; overflow-y: auto;"></div>
                        <textarea class="form-control" id="msgComposeText" rows="2" maxlength="2000" placeholder="{{ trans('discord-integration::admin.messages.compose_placeholder') }}"></textarea>
                    </div>
                    <button type="button" class="btn btn-primary" id="msgSendButton">{{ trans('discord-integration::admin.messages.send') }}</button>
                </div>
            </div>
        </div>

        @include('discord-integration::admin.partials.discord-picker-modal')

        <div class="modal fade" id="msgPurgeModal" tabindex="-1" role="dialog" aria-labelledby="msgPurgeModalLabel" aria-modal="true">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="msgPurgeModalLabel">{{ trans('discord-integration::admin.messages.purge') }}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle"></i> {{ trans('discord-integration::admin.messages.purge_warning') }}
                        </div>

                        <label class="form-label" for="msgPurgeCount">{{ trans('discord-integration::admin.messages.purge_count_label') }}</label>
                        <input type="number" min="1" max="1000" class="form-control" id="msgPurgeCount">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ trans('messages.actions.cancel') }}</button>
                        <button type="button" class="btn btn-danger" id="msgPurgeConfirmButton">
                            <i class="bi bi-trash"></i> {{ trans('discord-integration::admin.messages.purge') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="modal fade" id="msgUserDetailModal" tabindex="-1" role="dialog" aria-labelledby="msgUserDetailModalLabel" aria-modal="true">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="msgUserDetailModalLabel">{{ trans('discord-integration::admin.messages.user_detail_title') }}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body" id="msgUserDetailBody">
                        <div class="text-center text-muted">{{ trans('discord-integration::admin.messages.user_detail_loading') }}</div>
                    </div>
                </div>
            </div>
        </div>

        <style>
            #msgList:not(.selecting) .msg-select-checkbox {
                display: none;
            }
            .discord-spoiler {
                background-color: currentColor;
                color: transparent !important;
                border-radius: 3px;
                cursor: pointer;
            }
            .discord-spoiler * {
                color: transparent !important;
            }
            .discord-spoiler.discord-spoiler-revealed {
                background-color: transparent;
                color: inherit !important;
            }
            .discord-spoiler.discord-spoiler-revealed * {
                color: inherit !important;
            }
        </style>

        @push('footer-scripts')
            <script>
                (function () {
                    const csrfToken = document.querySelector('meta[name="csrf-token"]').content;

                    let currentChannelId = null;
                    let oldestMessageId = null;
                    let replyToId = null;
                    const selectedIds = new Set();

                    // Guild-wide id->name lookups for resolving <@&roleId>
                    // and <#channelId> mentions inside message content (see
                    // renderMessageContent() below) - refetched on every
                    // "Charger" in channel mode (there's no guild in DM mode,
                    // so both stay empty there and those mention types fall
                    // back to a generic placeholder).
                    let guildRolesMap = {};
                    let guildChannelsMap = {};

                    // id->Discord numeric channel type, fetched alongside
                    // guildChannelsMap - kept separate rather than folded
                    // into it since guildChannelsMap's values are plain
                    // strings elsewhere (mention resolution just needs a
                    // name). Only used by the "#" autocomplete to show a
                    // distinct icon per channel type (text/voice/announcement/
                    // stage/forum/category...).
                    let guildChannelTypesMap = {};

                    // {roleId: {color, position}} - the color/position half
                    // of the same guild role list above, kept separate from
                    // guildRolesMap (a plain id->name map) since that one is
                    // also used as-is to resolve <@&roleId> mentions. Used
                    // together with guildMembersInfoMap below to show each
                    // author's name in their highest-ranked colored role's
                    // color, the same way Discord's own client does.
                    let guildRoleColorsMap = {};

                    // {userId: {roles: [roleId, ...], avatar_url: ?string}} -
                    // a message's own "author" never carries guild-specific
                    // data (roles, per-server avatar override), so this is
                    // fetched separately - see MessageController::guildMembersInfo().
                    let guildMembersInfoMap = {};

                    // The largest file the compose box will let the admin
                    // pick for the current target - refetched alongside the
                    // maps above (see MessageController::uploadLimit()), so
                    // it accounts for the target server's actual Boost tier.
                    let uploadLimitBytes = null;
                    let selectedFile = null;

                    function updateBulkDeleteButton() {
                        const btn = document.getElementById('msgBulkDeleteButton');
                        btn.classList.toggle('d-none', selectedIds.size === 0);
                        btn.textContent = @json(trans('discord-integration::admin.messages.bulk_delete')).replace(':count', selectedIds.size);
                    }

                    function setReplyTarget(message) {
                        replyToId = message ? message.id : null;

                        const indicator = document.getElementById('msgReplyIndicator');
                        indicator.classList.toggle('d-none', ! message);
                        indicator.classList.toggle('d-flex', !! message);

                        if (message) {
                            const snippet = (message.content || '').slice(0, 80);
                            document.getElementById('msgReplyIndicatorText').textContent = @json(trans('discord-integration::admin.messages.replying_to'))
                                .replace(':author', message.author_name)
                                .replace(':content', snippet);
                            document.getElementById('msgComposeText').focus();
                        }
                    }

                    document.getElementById('msgReplyCancel').addEventListener('click', function () {
                        setReplyTarget(null);
                    });

                    function formatFileSize(bytes) {
                        return bytes < 1024 * 1024
                            ? Math.round(bytes / 1024) + ' KB'
                            : (bytes / (1024 * 1024)).toFixed(1) + ' MB';
                    }

                    function clearSelectedFile() {
                        selectedFile = null;
                        document.getElementById('msgComposeFile').value = '';
                        document.getElementById('msgFilePreview').classList.add('d-none');
                        document.getElementById('msgFilePreview').classList.remove('d-flex');
                        document.getElementById('msgFileError').classList.add('d-none');
                    }

                    document.getElementById('msgAttachButton').addEventListener('click', function () {
                        document.getElementById('msgComposeFile').click();
                    });

                    document.getElementById('msgComposeFile').addEventListener('change', function () {
                        const file = this.files[0];
                        const errorEl = document.getElementById('msgFileError');
                        errorEl.classList.add('d-none');

                        if (! file) {
                            return;
                        }

                        // Checked the moment a file is picked (rather than
                        // only when Send is clicked) so a file already known
                        // to be too big for the current target never gets
                        // uploaded to this server at all - the whole point
                        // of this client-side check (see
                        // MessageController::uploadLimit()).
                        if (uploadLimitBytes !== null && file.size > uploadLimitBytes) {
                            // Resets the selection without going through
                            // clearSelectedFile() - that also hides this
                            // very error message, which would defeat the
                            // point of showing it.
                            selectedFile = null;
                            this.value = '';
                            document.getElementById('msgFilePreview').classList.add('d-none');
                            document.getElementById('msgFilePreview').classList.remove('d-flex');

                            errorEl.textContent = @json(trans('discord-integration::admin.messages.file_too_large')).replace(':max', formatFileSize(uploadLimitBytes));
                            errorEl.classList.remove('d-none');

                            return;
                        }

                        selectedFile = file;
                        document.getElementById('msgFileName').textContent = file.name + ' (' + formatFileSize(file.size) + ')';
                        document.getElementById('msgFilePreview').classList.remove('d-none');
                        document.getElementById('msgFilePreview').classList.add('d-flex');
                    });

                    document.getElementById('msgSelectToggleButton').addEventListener('click', function () {
                        const list = document.getElementById('msgList');
                        const selecting = list.classList.toggle('selecting');
                        this.classList.toggle('active', selecting);

                        // Leaving selection mode drops whatever was checked
                        // rather than just hiding it - an invisible
                        // selection the admin can no longer see would be
                        // confusing to still be in effect.
                        if (! selecting) {
                            resetSelection();
                        }
                    });

                    document.getElementById('msgFileRemove').addEventListener('click', function () {
                        clearSelectedFile();
                    });

                    // Discord's own "X is typing..." indicator expires after
                    // 10s (or once a message is sent there) - renewed every
                    // 8s here for as long as the admin keeps typing, rather
                    // than opening any kind of persistent/streaming
                    // connection, since Discord's API has no such thing:
                    // it's only ever a discrete "trigger" call that must be
                    // repeated to stay visible.
                    let typingRenewInterval = null;
                    let typingStopTimeout = null;

                    function sendTypingSignal() {
                        if (! currentChannelId) {
                            return;
                        }

                        fetch(@json(route('discord-integration.admin.messages.typing')), {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken},
                            body: JSON.stringify({channel_id: currentChannelId}),
                        }).catch(function () {
                            // Best-effort - a failed typing ping shouldn't
                            // interrupt composing the actual message.
                        });
                    }

                    function noteTypingActivity() {
                        if (! currentChannelId) {
                            return;
                        }

                        if (! typingRenewInterval) {
                            sendTypingSignal();
                            typingRenewInterval = setInterval(sendTypingSignal, 8000);
                        }

                        clearTimeout(typingStopTimeout);
                        typingStopTimeout = setTimeout(stopTypingIndicator, 5000);
                    }

                    function stopTypingIndicator() {
                        clearInterval(typingRenewInterval);
                        typingRenewInterval = null;
                        clearTimeout(typingStopTimeout);
                        typingStopTimeout = null;
                    }

                    document.getElementById('msgComposeText').addEventListener('input', function () {
                        if (this.value.trim() === '') {
                            stopTypingIndicator();

                            return;
                        }

                        noteTypingActivity();
                    });

                    // "@"/"#" autocomplete - the trigger character must sit
                    // at the very start of the text or right after
                    // whitespace (matching Discord's own client), so typing
                    // an email address mid-word doesn't spuriously open it.
                    // Only ever suggests from data already loaded for the
                    // current target (guildMembersInfoMap/guildRolesMap/
                    // guildChannelsMap - all empty in DM mode, or if the
                    // "Server Members Intent" isn't available for members),
                    // never a fresh fetch per keystroke.
                    let mentionCandidatesList = [];
                    let mentionSelectedIndex = 0;
                    let mentionRange = null;

                    function detectMentionTrigger() {
                        const textarea = document.getElementById('msgComposeText');
                        const upToCursor = textarea.value.slice(0, textarea.selectionStart);
                        const match = upToCursor.match(/(?:^|\s)([@#])([^\s@#]*)$/);

                        if (! match) {
                            return null;
                        }

                        return {
                            trigger: match[1],
                            query: match[2],
                            start: textarea.selectionStart - match[2].length - 1,
                            end: textarea.selectionStart,
                        };
                    }

                    // Discord's own numeric channel types (see the Channel
                    // resource in the API docs) - used purely to pick a
                    // distinguishing icon in the "#" autocomplete, the same
                    // way Discord's own client does per channel type. Note:
                    // there's no such thing as a "Channels & Roles" channel -
                    // that's the name of a tab in Discord's onboarding/Server
                    // Guide settings, not a channel object the API ever
                    // returns, so it has no icon of its own here.
                    function channelTypeIcon(type) {
                        switch (type) {
                            case 2: // GUILD_VOICE
                                return 'bi-volume-up-fill';
                            case 4: // GUILD_CATEGORY
                                return 'bi-folder-fill';
                            case 5: // GUILD_ANNOUNCEMENT
                                return 'bi-megaphone-fill';
                            case 13: // GUILD_STAGE_VOICE
                                return 'bi-broadcast';
                            case 15: // GUILD_FORUM
                            case 16: // GUILD_MEDIA
                                return 'bi-chat-square-text-fill';
                            default: // GUILD_TEXT and thread types
                                return 'bi-hash';
                        }
                    }

                    function mentionCandidatesFor(trigger, query) {
                        const q = query.toLowerCase();

                        if (trigger === '#') {
                            // Categories (type 4) aren't a channel you can
                            // actually mention/jump to in Discord - they're
                            // just a grouping header - so they're excluded
                            // here entirely. The icon already conveys the
                            // type, so the label is just the bare name (no
                            // "#" prefix - that would double up with the "#"
                            // icon shown for text channels).
                            return Object.keys(guildChannelsMap)
                                .filter(function (id) { return guildChannelTypesMap[id] !== 4 && guildChannelsMap[id].toLowerCase().includes(q); })
                                .map(function (id) {
                                    const type = guildChannelTypesMap[id];

                                    return {
                                        label: guildChannelsMap[id],
                                        insert: '<#' + id + '>',
                                        icon: null,
                                        iconClass: channelTypeIcon(type),
                                    };
                                })
                                .slice(0, 8);
                        }

                        const users = Object.keys(guildMembersInfoMap)
                            .filter(function (id) { return guildMembersInfoMap[id].name && guildMembersInfoMap[id].name.toLowerCase().includes(q); })
                            .map(function (id) { return {label: guildMembersInfoMap[id].name, insert: '<@' + id + '>', icon: guildMembersInfoMap[id].avatar_url}; });

                        const roles = Object.keys(guildRolesMap)
                            .filter(function (id) { return guildRolesMap[id] !== '@everyone' && guildRolesMap[id].toLowerCase().includes(q); })
                            .map(function (id) { return {label: '@' + guildRolesMap[id], insert: '<@&' + id + '>', icon: null}; });

                        return users.concat(roles).slice(0, 8);
                    }

                    function closeMentionAutocomplete() {
                        mentionCandidatesList = [];
                        mentionRange = null;
                        document.getElementById('msgMentionAutocomplete').classList.add('d-none');
                    }

                    function renderMentionAutocomplete() {
                        const list = document.getElementById('msgMentionAutocomplete');
                        list.innerHTML = '';

                        mentionCandidatesList.forEach(function (candidate, index) {
                            const item = document.createElement('button');
                            item.type = 'button';
                            item.className = 'list-group-item list-group-item-action d-flex align-items-center gap-2 py-1' + (index === mentionSelectedIndex ? ' active' : '');

                            if (candidate.icon) {
                                const img = document.createElement('img');
                                img.src = candidate.icon;
                                img.alt = '';
                                img.width = 20;
                                img.height = 20;
                                img.className = 'rounded-circle flex-shrink-0';
                                item.appendChild(img);
                            } else if (candidate.iconClass) {
                                const icon = document.createElement('i');
                                icon.className = 'bi ' + candidate.iconClass + ' flex-shrink-0';
                                item.appendChild(icon);
                            }

                            const label = document.createElement('span');
                            label.textContent = candidate.label;
                            item.appendChild(label);

                            item.addEventListener('mousedown', function (e) {
                                // mousedown (not click) - fires before the
                                // textarea loses focus/its selection, which
                                // a click would otherwise clear first.
                                e.preventDefault();
                                applyMentionCandidate(candidate);
                            });

                            list.appendChild(item);
                        });

                        list.classList.remove('d-none');
                    }

                    function applyMentionCandidate(candidate) {
                        const textarea = document.getElementById('msgComposeText');
                        const value = textarea.value;
                        const insertText = candidate.insert + ' ';
                        textarea.value = value.slice(0, mentionRange.start) + insertText + value.slice(mentionRange.end);

                        const cursor = mentionRange.start + insertText.length;
                        textarea.setSelectionRange(cursor, cursor);
                        textarea.focus();

                        closeMentionAutocomplete();
                        noteTypingActivity();
                    }

                    document.getElementById('msgComposeText').addEventListener('input', function () {
                        const trigger = detectMentionTrigger();

                        if (! trigger) {
                            closeMentionAutocomplete();

                            return;
                        }

                        mentionRange = {start: trigger.start, end: trigger.end};
                        mentionCandidatesList = mentionCandidatesFor(trigger.trigger, trigger.query);
                        mentionSelectedIndex = 0;

                        if (mentionCandidatesList.length === 0) {
                            closeMentionAutocomplete();

                            return;
                        }

                        renderMentionAutocomplete();
                    });

                    document.getElementById('msgComposeText').addEventListener('keydown', function (e) {
                        if (mentionCandidatesList.length === 0) {
                            return;
                        }

                        if (e.key === 'ArrowDown') {
                            e.preventDefault();
                            mentionSelectedIndex = (mentionSelectedIndex + 1) % mentionCandidatesList.length;
                            renderMentionAutocomplete();
                        } else if (e.key === 'ArrowUp') {
                            e.preventDefault();
                            mentionSelectedIndex = (mentionSelectedIndex - 1 + mentionCandidatesList.length) % mentionCandidatesList.length;
                            renderMentionAutocomplete();
                        } else if (e.key === 'Enter' || e.key === 'Tab') {
                            e.preventDefault();
                            applyMentionCandidate(mentionCandidatesList[mentionSelectedIndex]);
                        } else if (e.key === 'Escape') {
                            closeMentionAutocomplete();
                        }
                    });

                    document.getElementById('msgComposeText').addEventListener('blur', function () {
                        // Deferred - a candidate's own "mousedown" handler
                        // above needs to still see mentionRange/the list
                        // before this clears them, since mousedown fires
                        // (and blurs the textarea) before its own click.
                        setTimeout(closeMentionAutocomplete, 150);
                    });

                    function resetSelection() {
                        selectedIds.clear();
                        updateBulkDeleteButton();
                    }

                    document.getElementById('msgBulkDeleteButton').addEventListener('click', function (e) {
                        const ids = Array.from(selectedIds);

                        if (ids.length === 0) {
                            return;
                        }

                        // Same Shift-click-skips-confirmation convention as
                        // the per-message delete button below.
                        if (! e.shiftKey && ! confirm(@json(trans('discord-integration::admin.messages.bulk_delete_confirm')).replace(':count', ids.length))) {
                            return;
                        }

                        fetch(@json(route('discord-integration.admin.messages.bulk-delete')), {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken},
                            body: JSON.stringify({channel_id: currentChannelId, message_ids: ids}),
                        })
                            .then(function (response) { return response.ok ? response.json() : Promise.reject(); })
                            .then(function (result) {
                                if (! result.success) {
                                    return Promise.reject();
                                }

                                ids.forEach(function (id) {
                                    const row = document.querySelector('[data-message-id="' + CSS.escape(id) + '"]');

                                    if (row) {
                                        row.remove();
                                    }
                                });

                                resetSelection();

                                if (ids.includes(replyToId)) {
                                    setReplyTarget(null);
                                }
                            })
                            .catch(function () { alert(@json(trans('discord-integration::admin.messages.bulk_delete_error'))); });
                    });

                    const msgPurgeModal = document.getElementById('msgPurgeModal');

                    msgPurgeModal.addEventListener('show.bs.modal', function () {
                        document.getElementById('msgPurgeCount').value = '';
                    });

                    document.getElementById('msgPurgeConfirmButton').addEventListener('click', function () {
                        const count = parseInt(document.getElementById('msgPurgeCount').value, 10);

                        if (! currentChannelId || ! count || count < 1) {
                            return;
                        }

                        fetch(@json(route('discord-integration.admin.messages.purge')), {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken},
                            body: JSON.stringify({channel_id: currentChannelId, count: count}),
                        })
                            .then(function (response) { return response.ok ? response.json() : Promise.reject(); })
                            .then(function (result) {
                                if (! result.success) {
                                    return Promise.reject();
                                }

                                bootstrap.Modal.getInstance(msgPurgeModal).hide();
                                oldestMessageId = null;
                                document.getElementById('msgList').innerHTML = '';
                                resetSelection();
                                setReplyTarget(null);

                                return loadMessages(null);
                            })
                            .catch(function () { alert(@json(trans('discord-integration::admin.messages.purge_error'))); });
                    });

                    document.getElementById('msgModeChannelTab').addEventListener('click', function () {
                        this.classList.add('active');
                        document.getElementById('msgModeDmTab').classList.remove('active');
                        document.getElementById('msgModeChannel').classList.remove('d-none');
                        document.getElementById('msgModeDm').classList.add('d-none');
                    });

                    document.getElementById('msgModeDmTab').addEventListener('click', function () {
                        this.classList.add('active');
                        document.getElementById('msgModeChannelTab').classList.remove('active');
                        document.getElementById('msgModeDm').classList.remove('d-none');
                        document.getElementById('msgModeChannel').classList.add('d-none');
                    });

                    @if($guilds !== null)
                        document.getElementById('msgGuildIdPicker').addEventListener('click', function (e) {
                            e.preventDefault();

                            DiscordPicker.open({
                                title: @json(trans('discord-integration::admin.pick_guild_title')),
                                items: @json(\Azuriom\Plugin\DiscordIntegration\Support\DiscordAvatar::guildPickerItems($guilds)),
                                emptyText: @json(trans('discord-integration::admin.pick_guild_empty')),
                                onSelect: function (item) {
                                    document.getElementById('msgGuildId').value = item.id;
                                    DiscordPicker.modal.hide();
                                },
                            });
                        });
                    @endif

                    document.getElementById('msgChannelIdPicker').addEventListener('click', function (e) {
                        e.preventDefault();

                        const guildId = document.getElementById('msgGuildId').value.trim();

                        if (! guildId) {
                            DiscordPicker.open({title: @json(trans('discord-integration::admin.pick_channel_title')), items: [], emptyText: @json(trans('discord-integration::admin.pick_channel_no_guild')), onSelect: function () {}});

                            return;
                        }

                        DiscordPicker.open({title: @json(trans('discord-integration::admin.pick_channel_title')), items: [], emptyText: @json(trans('discord-integration::admin.pick_channel_loading')), onSelect: function () {}});

                        fetch(@json(route('discord-integration.admin.commands.guild-channels')) + '?guild_id=' + encodeURIComponent(guildId))
                            .then(function (response) { return response.ok ? response.json() : Promise.reject(); })
                            .then(function (channels) {
                                DiscordPicker.open({
                                    title: @json(trans('discord-integration::admin.pick_channel_title')),
                                    items: channels.map(function (channel) { return {id: channel.id, label: '#' + channel.name}; }),
                                    emptyText: @json(trans('discord-integration::admin.pick_channel_empty')),
                                    onSelect: function (item) {
                                        document.getElementById('msgChannelId').value = item.id;
                                        DiscordPicker.modal.hide();
                                    },
                                });
                            })
                            .catch(function () {
                                DiscordPicker.open({title: @json(trans('discord-integration::admin.pick_channel_title')), items: [], emptyText: @json(trans('discord-integration::admin.pick_channel_error')), onSelect: function () {}});
                            });
                    });

                    document.getElementById('msgThreadIdPicker').addEventListener('click', function (e) {
                        e.preventDefault();

                        const guildId = document.getElementById('msgGuildId').value.trim();
                        const channelId = document.getElementById('msgChannelId').value.trim();

                        if (! guildId || ! channelId) {
                            DiscordPicker.open({title: @json(trans('discord-integration::admin.pick_thread_title')), items: [], emptyText: @json(trans('discord-integration::admin.pick_thread_no_channel')), onSelect: function () {}});

                            return;
                        }

                        DiscordPicker.open({title: @json(trans('discord-integration::admin.pick_thread_title')), items: [], emptyText: @json(trans('discord-integration::admin.pick_thread_loading')), onSelect: function () {}});

                        fetch(@json(route('discord-integration.admin.messages.threads')) + '?guild_id=' + encodeURIComponent(guildId) + '&channel_id=' + encodeURIComponent(channelId))
                            .then(function (response) { return response.ok ? response.json() : Promise.reject(); })
                            .then(function (threads) {
                                DiscordPicker.open({
                                    title: @json(trans('discord-integration::admin.pick_thread_title')),
                                    items: threads.map(function (thread) { return {id: thread.id, label: thread.name}; }),
                                    emptyText: @json(trans('discord-integration::admin.pick_thread_empty')),
                                    onSelect: function (item) {
                                        document.getElementById('msgThreadId').value = item.id;
                                        DiscordPicker.modal.hide();
                                    },
                                });
                            })
                            .catch(function () {
                                DiscordPicker.open({title: @json(trans('discord-integration::admin.pick_thread_title')), items: [], emptyText: @json(trans('discord-integration::admin.pick_thread_error')), onSelect: function () {}});
                            });
                    });

                    @if($guilds !== null)
                        document.getElementById('msgDmUserIdPicker').addEventListener('click', function (e) {
                            e.preventDefault();

                            DiscordPicker.open({
                                title: @json(trans('discord-integration::admin.pick_guild_title')),
                                items: @json(\Azuriom\Plugin\DiscordIntegration\Support\DiscordAvatar::guildPickerItems($guilds)),
                                emptyText: @json(trans('discord-integration::admin.pick_guild_empty')),
                                onSelect: function (guild) {
                                    DiscordPicker.open({
                                        title: @json(trans('discord-integration::admin.pick_member_title')),
                                        items: [],
                                        emptyText: @json(trans('discord-integration::admin.pick_member_loading')),
                                        iconShape: 'circle',
                                        onSelect: function () {},
                                    });

                                    fetch(@json(route('discord-integration.admin.links.guild-members')) + '?guild_id=' + encodeURIComponent(guild.id))
                                        .then(function (response) { return response.ok ? response.json() : Promise.reject(); })
                                        .then(function (members) {
                                            DiscordPicker.open({
                                                title: @json(trans('discord-integration::admin.pick_member_title')),
                                                items: members.map(function (member) { return {id: member.id, label: member.name, icon: member.avatar}; }),
                                                emptyText: @json(trans('discord-integration::admin.pick_member_empty')),
                                                iconShape: 'circle',
                                                onSelect: function (member) {
                                                    document.getElementById('msgDmUserId').value = member.id;
                                                    DiscordPicker.modal.hide();
                                                },
                                            });
                                        })
                                        .catch(function () {
                                            DiscordPicker.open({
                                                title: @json(trans('discord-integration::admin.pick_member_title')),
                                                items: [],
                                                emptyText: @json(trans('discord-integration::admin.pick_member_error')),
                                                iconShape: 'circle',
                                                onSelect: function () {},
                                            });
                                        });
                                },
                            });
                        });
                    @endif

                    function formatTimestamp(iso) {
                        return iso ? new Date(iso).toLocaleString() : '';
                    }

                    // Discord message types that carry real, author-written
                    // content (a normal message, a reply, a slash/context
                    // command invocation, a thread's starter message). Every
                    // other type is one of Discord's own "system messages"
                    // (someone joined, a message got pinned, a boost...) -
                    // the API always sends an empty "content" for those, and
                    // the text a real Discord client shows is generated
                    // entirely client-side from the type instead, so this
                    // synthesizes an equivalent message rather than showing
                    // a confusing blank line.
                    const CONTENT_MESSAGE_TYPES = [0, 19, 20, 21, 23];

                    // Covers every documented Discord message type, plus 65
                    // (undocumented as of writing - confirmed empirically,
                    // see the translation file's comment on system_voice_call).
                    const SYSTEM_MESSAGE_TEMPLATES = {
                        1: @json(trans('discord-integration::admin.messages.system_recipient_add')),
                        2: @json(trans('discord-integration::admin.messages.system_recipient_remove')),
                        3: @json(trans('discord-integration::admin.messages.system_call')),
                        4: @json(trans('discord-integration::admin.messages.system_channel_name')),
                        5: @json(trans('discord-integration::admin.messages.system_channel_icon')),
                        6: @json(trans('discord-integration::admin.messages.system_pin')),
                        7: @json(trans('discord-integration::admin.messages.system_join')),
                        8: @json(trans('discord-integration::admin.messages.system_boost')),
                        9: @json(trans('discord-integration::admin.messages.system_boost')),
                        10: @json(trans('discord-integration::admin.messages.system_boost')),
                        11: @json(trans('discord-integration::admin.messages.system_boost')),
                        12: @json(trans('discord-integration::admin.messages.system_channel_follow_add')),
                        14: @json(trans('discord-integration::admin.messages.system_discovery_disqualified')),
                        15: @json(trans('discord-integration::admin.messages.system_discovery_requalified')),
                        16: @json(trans('discord-integration::admin.messages.system_discovery_grace_initial')),
                        17: @json(trans('discord-integration::admin.messages.system_discovery_grace_final')),
                        18: @json(trans('discord-integration::admin.messages.system_thread_created')),
                        22: @json(trans('discord-integration::admin.messages.system_invite_reminder')),
                        24: @json(trans('discord-integration::admin.messages.system_automod_action')),
                        25: @json(trans('discord-integration::admin.messages.system_role_subscription')),
                        26: @json(trans('discord-integration::admin.messages.system_premium_upsell')),
                        27: @json(trans('discord-integration::admin.messages.system_stage_start')),
                        28: @json(trans('discord-integration::admin.messages.system_stage_end')),
                        29: @json(trans('discord-integration::admin.messages.system_stage_speaker')),
                        30: @json(trans('discord-integration::admin.messages.system_stage_raise_hand')),
                        31: @json(trans('discord-integration::admin.messages.system_stage_topic')),
                        32: @json(trans('discord-integration::admin.messages.system_guild_app_subscription')),
                        36: @json(trans('discord-integration::admin.messages.system_incident_alert_enabled')),
                        37: @json(trans('discord-integration::admin.messages.system_incident_alert_disabled')),
                        38: @json(trans('discord-integration::admin.messages.system_incident_report_raid')),
                        39: @json(trans('discord-integration::admin.messages.system_incident_report_false_alarm')),
                        44: @json(trans('discord-integration::admin.messages.system_purchase')),
                        46: @json(trans('discord-integration::admin.messages.system_poll_result')),
                        65: @json(trans('discord-integration::admin.messages.system_voice_call')),
                    };

                    const SYSTEM_MESSAGE_FALLBACK = @json(trans('discord-integration::admin.messages.system_generic'));

                    function displayContentFor(message) {
                        if (message.content || CONTENT_MESSAGE_TYPES.includes(message.type)) {
                            return message.content;
                        }

                        return (SYSTEM_MESSAGE_TEMPLATES[message.type] || SYSTEM_MESSAGE_FALLBACK).replace(':author', message.author_name);
                    }

                    // Discord's inline markdown subset, plus its special
                    // <@id>/<@&id>/<#id>/<t:...>/<:name:id> tokens, matched in
                    // one pass (earlier branches take priority at the same
                    // position, e.g. "**" is tried before a lone "*"). Only
                    // http(s) URLs are ever captured as links (both the bare
                    // one and the [label](url) form), which also rules out a
                    // "javascript:" URL slipping through as a clickable link.
                    // Capture groups: 1 code block, 2 inline code, 3 emoji
                    // name, 4 user mention id, 5 role mention id, 6 channel
                    // mention id, 7 timestamp unix, 8 timestamp format,
                    // 9 bold, 10 underline, 11 strikethrough, 12 spoiler,
                    // 13/14 italic, 15 link label, 16 link url, 17 bare url.
                    const TOKEN_REGEX = /```([\s\S]*?)```|`([^`]+?)`|<a?:(\w+):\d+>|<@!?(\d+)>|<@&(\d+)>|<#(\d+)>|<t:(-?\d+)(?::([tTdDfFR]))?>|\*\*([\s\S]+?)\*\*|__([\s\S]+?)__|~~([\s\S]+?)~~|\|\|([\s\S]+?)\|\||\*([\s\S]+?)\*|_([\s\S]+?)_|\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)|(https?:\/\/[^\s<]+)/g;

                    // Never uses innerHTML on message text - every node here
                    // is built with createElement/textContent, so nothing in
                    // an arbitrary Discord user's message can be interpreted
                    // as markup, regardless of what markdown/tokens it fakes.
                    function renderMessageContent(text, ctx) {
                        const fragment = document.createDocumentFragment();

                        if (! text) {
                            return fragment;
                        }

                        let lastIndex = 0;

                        // Bold/italic/underline/strikethrough/spoiler all
                        // recursively call this same function on their inner
                        // text (to allow light nesting) - a shared global
                        // RegExp's "lastIndex" is mutable state, so driving
                        // the loop with TOKEN_REGEX.exec() directly would let
                        // a recursive (inner) call's lastIndex bleed into the
                        // outer call once it resumes, corrupting where it
                        // resumes matching and, in the worst case, spinning
                        // forever appending nodes until the tab runs out of
                        // memory. matchAll() sidesteps this entirely - it
                        // clones the regex internally, so nested calls never
                        // share mutable state.
                        for (const match of text.matchAll(TOKEN_REGEX)) {
                            if (match.index > lastIndex) {
                                fragment.appendChild(document.createTextNode(text.slice(lastIndex, match.index)));
                            }

                            fragment.appendChild(renderToken(match, ctx));
                            lastIndex = match.index + match[0].length;
                        }

                        if (lastIndex < text.length) {
                            fragment.appendChild(document.createTextNode(text.slice(lastIndex)));
                        }

                        return fragment;
                    }

                    function renderMention(label) {
                        const span = document.createElement('span');
                        span.textContent = label;
                        span.style.color = '#5865f2';
                        span.style.fontWeight = '500';

                        return span;
                    }

                    function renderTimestamp(unixSeconds, format) {
                        const date = new Date(parseInt(unixSeconds, 10) * 1000);
                        const span = document.createElement('span');
                        span.className = 'text-muted';

                        if (format === 'R') {
                            span.textContent = formatRelativeTime(date);
                        } else if (format === 'd') {
                            span.textContent = date.toLocaleDateString();
                        } else if (format === 'D') {
                            span.textContent = date.toLocaleDateString(undefined, {year: 'numeric', month: 'long', day: 'numeric'});
                        } else if (format === 't') {
                            span.textContent = date.toLocaleTimeString(undefined, {hour: '2-digit', minute: '2-digit'});
                        } else if (format === 'T') {
                            span.textContent = date.toLocaleTimeString();
                        } else if (format === 'F') {
                            span.textContent = date.toLocaleString(undefined, {weekday: 'long', year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit'});
                        } else {
                            span.textContent = date.toLocaleString();
                        }

                        return span;
                    }

                    function formatRelativeTime(date) {
                        const rtf = new Intl.RelativeTimeFormat(document.documentElement.lang || 'fr', {numeric: 'auto'});
                        const divisions = [
                            {amount: 60, unit: 'seconds'},
                            {amount: 60, unit: 'minutes'},
                            {amount: 24, unit: 'hours'},
                            {amount: 7, unit: 'days'},
                            {amount: 4.34524, unit: 'weeks'},
                            {amount: 12, unit: 'months'},
                            {amount: Number.POSITIVE_INFINITY, unit: 'years'},
                        ];

                        let duration = (date.getTime() - Date.now()) / 1000;

                        for (let i = 0; i < divisions.length; i++) {
                            if (Math.abs(duration) < divisions[i].amount) {
                                return rtf.format(Math.round(duration), divisions[i].unit);
                            }

                            duration /= divisions[i].amount;
                        }

                        return '';
                    }

                    function renderToken(match, ctx) {
                        if (match[1] !== undefined) {
                            const pre = document.createElement('pre');
                            pre.className = 'mb-1 p-2 bg-body-secondary rounded small';
                            const code = document.createElement('code');
                            code.textContent = match[1];
                            pre.appendChild(code);

                            return pre;
                        }

                        if (match[2] !== undefined) {
                            const code = document.createElement('code');
                            code.textContent = match[2];

                            return code;
                        }

                        if (match[3] !== undefined) {
                            return renderMention(':' + match[3] + ':');
                        }

                        if (match[4] !== undefined) {
                            return renderMention('@' + (ctx.users[match[4]] || match[4]));
                        }

                        if (match[5] !== undefined) {
                            return renderMention('@' + (ctx.roles[match[5]] || @json(trans('discord-integration::admin.messages.unknown_role'))));
                        }

                        if (match[6] !== undefined) {
                            return renderMention('#' + (ctx.channels[match[6]] || @json(trans('discord-integration::admin.messages.unknown_channel'))));
                        }

                        if (match[7] !== undefined) {
                            return renderTimestamp(match[7], match[8]);
                        }

                        if (match[9] !== undefined) {
                            const strong = document.createElement('strong');
                            strong.appendChild(renderMessageContent(match[9], ctx));

                            return strong;
                        }

                        if (match[10] !== undefined) {
                            const u = document.createElement('u');
                            u.appendChild(renderMessageContent(match[10], ctx));

                            return u;
                        }

                        if (match[11] !== undefined) {
                            const s = document.createElement('s');
                            s.appendChild(renderMessageContent(match[11], ctx));

                            return s;
                        }

                        if (match[12] !== undefined) {
                            const spoiler = document.createElement('span');
                            spoiler.className = 'discord-spoiler';
                            spoiler.appendChild(renderMessageContent(match[12], ctx));
                            spoiler.addEventListener('click', function () {
                                spoiler.classList.toggle('discord-spoiler-revealed');
                            });

                            return spoiler;
                        }

                        if (match[13] !== undefined) {
                            const em = document.createElement('em');
                            em.appendChild(renderMessageContent(match[13], ctx));

                            return em;
                        }

                        if (match[14] !== undefined) {
                            const em = document.createElement('em');
                            em.appendChild(renderMessageContent(match[14], ctx));

                            return em;
                        }

                        if (match[15] !== undefined) {
                            const a = document.createElement('a');
                            a.href = match[16];
                            a.target = '_blank';
                            a.rel = 'noopener noreferrer';
                            a.textContent = match[15];

                            return a;
                        }

                        const a = document.createElement('a');
                        a.href = match[17];
                        a.target = '_blank';
                        a.rel = 'noopener noreferrer';
                        a.textContent = match[17];

                        return a;
                    }

                    // The color a member's name is shown in - Discord's own
                    // rule: among their roles that have a non-default color,
                    // the highest-positioned one wins. Returns null (no
                    // override) if the author isn't a resolved guild member
                    // (DM mode, or "Server Members Intent" unavailable) or
                    // holds no colored role.
                    function authorRoleColor(authorId) {
                        const info = guildMembersInfoMap[authorId];

                        if (! info || ! info.roles) {
                            return null;
                        }

                        let best = null;

                        info.roles.forEach(function (roleId) {
                            const role = guildRoleColorsMap[roleId];

                            if (role && role.color !== 0 && (! best || role.position > best.position)) {
                                best = role;
                            }
                        });

                        return best ? '#' + best.color.toString(16).padStart(6, '0') : null;
                    }

                    function buildBotBadge(verified) {
                        const badge = document.createElement('span');
                        badge.className = 'badge ms-1';
                        badge.style.backgroundColor = '#5865f2';
                        badge.style.fontSize = '0.6rem';
                        badge.style.verticalAlign = 'middle';
                        badge.textContent = verified ? '✓ APP' : 'APP';

                        return badge;
                    }

                    function openUserDetail(discordUserId) {
                        if (! discordUserId) {
                            return;
                        }

                        const body = document.getElementById('msgUserDetailBody');
                        body.innerHTML = '';

                        const loading = document.createElement('div');
                        loading.className = 'text-center text-muted';
                        loading.textContent = @json(trans('discord-integration::admin.messages.user_detail_loading'));
                        body.appendChild(loading);

                        bootstrap.Modal.getOrCreateInstance(document.getElementById('msgUserDetailModal')).show();

                        fetch(@json(route('discord-integration.admin.messages.user-details')) + '?discord_user_id=' + encodeURIComponent(discordUserId))
                            .then(function (response) { return response.ok ? response.json() : Promise.reject(); })
                            .then(function (user) {
                                body.innerHTML = '';

                                const header = document.createElement('div');
                                header.className = 'd-flex align-items-center gap-3 mb-3';

                                const img = document.createElement('img');
                                img.src = user.avatar_url;
                                img.alt = '';
                                img.width = 64;
                                img.height = 64;
                                img.className = 'rounded-circle flex-shrink-0';
                                header.appendChild(img);

                                const nameBlock = document.createElement('div');
                                const name = document.createElement('div');
                                name.className = 'fw-bold';
                                name.appendChild(document.createTextNode(user.global_name || user.username));

                                if (user.is_bot) {
                                    name.appendChild(buildBotBadge(user.is_verified_bot));
                                }

                                const username = document.createElement('div');
                                username.className = 'text-muted small';
                                username.textContent = '@' + user.username;

                                nameBlock.appendChild(name);
                                nameBlock.appendChild(username);
                                header.appendChild(nameBlock);
                                body.appendChild(header);

                                const idRow = document.createElement('p');
                                idRow.className = 'mb-1';
                                const idLabel = document.createElement('strong');
                                idLabel.textContent = @json(trans('discord-integration::admin.messages.user_detail_id')) + ' ';
                                idRow.appendChild(idLabel);
                                idRow.appendChild(document.createTextNode(user.id));
                                body.appendChild(idRow);

                                // Roles - resolved from data already fetched
                                // for the currently loaded channel (see
                                // guildMembersInfoMap/guildRolesMap above),
                                // not a fresh lookup - so this only has
                                // anything to show in channel mode, for a
                                // member the guild-wide member fetch actually
                                // returned (needs the "Server Members
                                // Intent"). @everyone is never listed, same
                                // as Discord's own client.
                                const memberInfo = guildMembersInfoMap[user.id];

                                if (memberInfo && memberInfo.roles && memberInfo.roles.length > 0) {
                                    const roles = memberInfo.roles
                                        .map(function (roleId) { return guildRoleColorsMap[roleId] ? Object.assign({id: roleId, name: guildRolesMap[roleId]}, guildRoleColorsMap[roleId]) : null; })
                                        .filter(function (role) { return role && role.name !== '@everyone'; })
                                        .sort(function (a, b) { return b.position - a.position; });

                                    if (roles.length > 0) {
                                        const rolesRow = document.createElement('p');
                                        rolesRow.className = 'mb-1';
                                        const rolesLabel = document.createElement('strong');
                                        rolesLabel.className = 'd-block';
                                        rolesLabel.textContent = @json(trans('discord-integration::admin.messages.user_detail_roles'));
                                        rolesRow.appendChild(rolesLabel);

                                        const rolesList = document.createElement('div');
                                        rolesList.className = 'd-flex flex-wrap gap-1 mt-1';

                                        roles.forEach(function (role) {
                                            const badge = document.createElement('span');
                                            badge.className = 'badge';
                                            const hexColor = role.color !== 0 ? '#' + role.color.toString(16).padStart(6, '0') : '#99aab5';
                                            badge.style.backgroundColor = hexColor;
                                            badge.textContent = role.name;
                                            rolesList.appendChild(badge);
                                        });

                                        rolesRow.appendChild(rolesList);
                                        body.appendChild(rolesRow);
                                    }
                                }

                                if (! user.is_bot) {
                                    const linkRow = document.createElement('p');
                                    linkRow.className = 'mb-0';
                                    const linkLabel = document.createElement('strong');
                                    linkLabel.className = 'd-block';
                                    linkLabel.textContent = @json(trans('discord-integration::admin.messages.user_detail_link_status'));
                                    linkRow.appendChild(linkLabel);

                                    if (user.link_status === 'unlinked' || ! user.linked_site_user_url) {
                                        const unlinkedText = document.createElement('span');
                                        unlinkedText.className = 'text-muted';
                                        unlinkedText.textContent = @json(trans('discord-integration::admin.messages.user_detail_unlinked'));
                                        linkRow.appendChild(unlinkedText);
                                    } else {
                                        const statusBadge = document.createElement('span');
                                        statusBadge.className = 'badge me-1 ' + (user.link_status === 'linked' ? 'text-bg-success' : 'text-bg-warning');
                                        statusBadge.textContent = user.link_status === 'linked'
                                            ? @json(trans('discord-integration::admin.messages.user_detail_linked_badge'))
                                            : @json(trans('discord-integration::admin.messages.user_detail_pending_badge'));
                                        linkRow.appendChild(statusBadge);

                                        // Opens the linked site account's own
                                        // admin edit page in a new tab -
                                        // this modal doesn't navigate away
                                        // from the message log itself.
                                        const siteLink = document.createElement('a');
                                        siteLink.href = user.linked_site_user_url;
                                        siteLink.target = '_blank';
                                        siteLink.rel = 'noopener noreferrer';
                                        siteLink.className = 'd-inline-flex align-items-center gap-1 text-decoration-none';

                                        if (user.linked_site_user_avatar) {
                                            const siteAvatar = document.createElement('img');
                                            siteAvatar.src = user.linked_site_user_avatar;
                                            siteAvatar.alt = '';
                                            siteAvatar.width = 20;
                                            siteAvatar.height = 20;
                                            siteAvatar.className = 'rounded-circle';
                                            siteLink.appendChild(siteAvatar);
                                        }

                                        siteLink.appendChild(document.createTextNode(user.linked_site_user || '?'));
                                        linkRow.appendChild(siteLink);
                                    }

                                    body.appendChild(linkRow);
                                }
                            })
                            .catch(function () {
                                body.innerHTML = '';
                                const error = document.createElement('div');
                                error.className = 'text-danger';
                                error.textContent = @json(trans('discord-integration::admin.messages.user_detail_error'));
                                body.appendChild(error);
                            });
                    }

                    // Always built with textContent (never innerHTML) - a
                    // message's content/author name is admin-visible but
                    // ultimately came from an arbitrary Discord user.
                    function buildMessageRow(message) {
                        const row = document.createElement('div');
                        row.className = 'border-bottom py-2';
                        row.dataset.messageId = message.id;

                        const header = document.createElement('div');
                        header.className = 'd-flex justify-content-between align-items-center';

                        const leftGroup = document.createElement('div');
                        leftGroup.className = 'd-flex align-items-center gap-2';

                        const checkbox = document.createElement('input');
                        checkbox.type = 'checkbox';
                        checkbox.className = 'form-check-input mt-0 msg-select-checkbox';
                        checkbox.checked = selectedIds.has(message.id);
                        checkbox.addEventListener('change', function () {
                            if (checkbox.checked) {
                                selectedIds.add(message.id);
                            } else {
                                selectedIds.delete(message.id);
                            }

                            updateBulkDeleteButton();
                        });
                        leftGroup.appendChild(checkbox);

                        const memberInfo = guildMembersInfoMap[message.author_id];

                        const avatar = document.createElement('img');
                        avatar.src = (memberInfo && memberInfo.avatar_url) || message.author_avatar;
                        avatar.alt = '';
                        avatar.width = 28;
                        avatar.height = 28;
                        avatar.className = 'rounded-circle flex-shrink-0';
                        avatar.style.cursor = 'pointer';
                        avatar.addEventListener('click', function () { openUserDetail(message.author_id); });
                        leftGroup.appendChild(avatar);

                        const authorGroup = document.createElement('span');
                        const author = document.createElement('strong');
                        author.textContent = message.author_name;
                        author.style.cursor = 'pointer';
                        author.addEventListener('click', function () { openUserDetail(message.author_id); });

                        const roleColor = authorRoleColor(message.author_id);

                        if (roleColor) {
                            author.style.color = roleColor;
                        }

                        authorGroup.appendChild(author);

                        if (message.author_is_bot) {
                            authorGroup.appendChild(buildBotBadge(message.author_is_verified_bot));
                        }

                        const time = document.createElement('span');
                        time.className = 'text-muted small ms-2 time-text';
                        time.textContent = formatTimestamp(message.timestamp) + (message.edited_timestamp ? ' ' + @json(trans('discord-integration::admin.messages.edited_suffix')) : '');
                        authorGroup.appendChild(time);
                        leftGroup.appendChild(authorGroup);

                        const actions = document.createElement('div');

                        if (message.is_own_message) {
                            const editBtn = document.createElement('button');
                            editBtn.type = 'button';
                            editBtn.className = 'btn btn-sm btn-link p-0 me-2';
                            editBtn.textContent = @json(trans('discord-integration::admin.messages.edit'));
                            editBtn.addEventListener('click', function () { startEdit(row, message); });
                            actions.appendChild(editBtn);
                        }

                        const replyBtn = document.createElement('button');
                        replyBtn.type = 'button';
                        replyBtn.className = 'btn btn-sm btn-link p-0 me-2';
                        replyBtn.textContent = @json(trans('discord-integration::admin.messages.reply'));
                        replyBtn.addEventListener('click', function () { setReplyTarget(message); });
                        actions.appendChild(replyBtn);

                        const deleteBtn = document.createElement('button');
                        deleteBtn.type = 'button';
                        deleteBtn.className = 'btn btn-sm btn-link text-danger p-0';
                        deleteBtn.textContent = @json(trans('discord-integration::admin.messages.delete'));
                        deleteBtn.title = @json(trans('discord-integration::admin.messages.shift_delete_hint'));
                        deleteBtn.addEventListener('click', function (e) { deleteMessage(row, message, e.shiftKey); });
                        actions.appendChild(deleteBtn);

                        header.appendChild(leftGroup);
                        header.appendChild(actions);
                        row.appendChild(header);

                        // A forward with no comment of its own has empty
                        // "content" (like an attachment-only message) - the
                        // forwarded block appended below already carries the
                        // visible text, so an empty content line above it
                        // would just be a confusing blank gap.
                        if (message.content || ! message.forwarded) {
                            const content = document.createElement('div');
                            content.className = 'content-text' + (! message.content && ! CONTENT_MESSAGE_TYPES.includes(message.type) ? ' text-muted fst-italic' : '');
                            content.style.whiteSpace = 'pre-wrap';
                            content.appendChild(renderMessageContent(displayContentFor(message), mentionContext(message.mentions)));
                            row.appendChild(content);
                        }

                        (message.attachments || []).forEach(function (attachment) {
                            row.appendChild(buildAttachmentElement(attachment));
                        });

                        (message.stickers || []).forEach(function (sticker) {
                            row.appendChild(buildStickerElement(sticker));
                        });

                        (message.gifs || []).forEach(function (gif) {
                            row.appendChild(buildGifElement(gif));
                        });

                        if (message.forwarded) {
                            row.appendChild(buildForwardedBlock(message.forwarded));
                        }

                        return row;
                    }

                    // Images render inline (clicking still opens the
                    // full-size original in a new tab) and audio - including
                    // Discord's own voice messages, which are just a regular
                    // audio attachment under the hood - gets a playable
                    // <audio> element; anything else stays a plain download
                    // link, same as before this existed.
                    function buildAttachmentElement(attachment) {
                        const contentType = attachment.content_type || '';

                        if (contentType.startsWith('image/')) {
                            const link = document.createElement('a');
                            link.href = attachment.url;
                            link.target = '_blank';
                            link.rel = 'noopener noreferrer';

                            const img = document.createElement('img');
                            img.src = attachment.url;
                            img.alt = attachment.filename;
                            img.className = 'd-block mt-1 rounded';
                            img.style.maxWidth = '280px';
                            img.style.maxHeight = '280px';
                            link.appendChild(img);

                            return link;
                        }

                        if (contentType.startsWith('audio/')) {
                            const audio = document.createElement('audio');
                            audio.controls = true;
                            audio.className = 'd-block mt-1';
                            audio.style.maxWidth = '320px';
                            // Set directly on <audio> rather than as a
                            // <source type="..."> hint - Discord doesn't
                            // always report a canonical MIME type (an MP3
                            // attachment came back as "audio/mpeg3", not the
                            // real "audio/mpeg"), and an unrecognized "type"
                            // makes the browser skip the source without even
                            // fetching it. No "type" hint means it sniffs the
                            // real content instead, which actually works.
                            audio.src = attachment.url;

                            return audio;
                        }

                        if (contentType.startsWith('video/')) {
                            const video = document.createElement('video');
                            video.controls = true;
                            video.className = 'd-block mt-1 rounded';
                            video.style.maxWidth = '360px';
                            video.style.maxHeight = '360px';
                            // Same reasoning as the audio branch above - set
                            // directly rather than via a <source type="...">
                            // hint, since that can make the browser skip a
                            // real, playable file over a mismatched/unusual
                            // reported MIME type.
                            video.src = attachment.url;

                            return video;
                        }

                        const attachmentRow = document.createElement('div');
                        const link = document.createElement('a');
                        link.href = attachment.url;
                        link.target = '_blank';
                        link.rel = 'noopener noreferrer';
                        link.textContent = attachment.filename;
                        attachmentRow.appendChild(link);

                        return attachmentRow;
                    }

                    // A LOTTIE-format sticker (a vector animation, not a
                    // still/raster image) has no "url" - see
                    // MessageController::mapStickers() - shown as just its
                    // name in that case rather than a broken image.
                    function buildStickerElement(sticker) {
                        if (! sticker.url) {
                            const fallback = document.createElement('div');
                            fallback.className = 'text-muted small fst-italic';
                            fallback.textContent = @json(trans('discord-integration::admin.messages.sticker_fallback')).replace(':name', sticker.name);

                            return fallback;
                        }

                        const img = document.createElement('img');
                        img.src = sticker.url;
                        img.alt = sticker.name;
                        img.title = sticker.name;
                        img.style.maxWidth = '120px';
                        img.style.maxHeight = '120px';
                        img.className = 'd-block mt-1';

                        return img;
                    }

                    // A GIF picker (Tenor/Klipy) result - see
                    // MessageController::mapGifEmbeds() - is actually always
                    // a small MP4 under the hood, not a real .gif file.
                    // Autoplaying it muted and looping matches how Discord's
                    // own client shows these.
                    function buildGifElement(gif) {
                        const video = document.createElement('video');
                        video.src = gif.url;
                        video.autoplay = true;
                        video.loop = true;
                        video.muted = true;
                        video.playsInline = true;
                        video.className = 'd-block mt-1 rounded';
                        video.style.maxWidth = '280px';
                        video.style.maxHeight = '280px';

                        return video;
                    }

                    function mentionContext(mentions) {
                        const users = {};
                        (mentions || []).forEach(function (mention) { users[mention.id] = mention.name; });

                        return {users: users, roles: guildRolesMap, channels: guildChannelsMap};
                    }

                    // A forwarded message's own "content" is empty (or just
                    // whatever comment the forwarder added alongside it,
                    // already rendered above by the normal content block) -
                    // the actual forwarded text/attachments are a frozen
                    // snapshot of the original message, shown here in a
                    // bordered block to set it apart, the same way Discord's
                    // own client visually distinguishes a forward.
                    function buildForwardedBlock(forwarded) {
                        const block = document.createElement('div');
                        block.className = 'border-start border-3 ps-2 mt-1 text-muted';

                        const label = document.createElement('div');
                        label.className = 'small fst-italic';
                        label.textContent = @json(trans('discord-integration::admin.messages.forwarded_label'));
                        block.appendChild(label);

                        const content = document.createElement('div');
                        content.style.whiteSpace = 'pre-wrap';
                        content.appendChild(renderMessageContent(forwarded.content, mentionContext(forwarded.mentions)));
                        block.appendChild(content);

                        (forwarded.attachments || []).forEach(function (attachment) {
                            block.appendChild(buildAttachmentElement(attachment));
                        });

                        (forwarded.stickers || []).forEach(function (sticker) {
                            block.appendChild(buildStickerElement(sticker));
                        });

                        (forwarded.gifs || []).forEach(function (gif) {
                            block.appendChild(buildGifElement(gif));
                        });

                        return block;
                    }

                    function startEdit(row, message) {
                        const content = row.querySelector('.content-text');
                        const textarea = document.createElement('textarea');
                        textarea.className = 'form-control form-control-sm mb-1';
                        textarea.value = message.content;
                        content.replaceWith(textarea);

                        const controls = document.createElement('div');

                        const saveBtn = document.createElement('button');
                        saveBtn.type = 'button';
                        saveBtn.className = 'btn btn-sm btn-primary me-1';
                        saveBtn.textContent = @json(trans('discord-integration::admin.messages.save'));
                        saveBtn.addEventListener('click', function () {
                            fetch(@json(route('discord-integration.admin.messages.edit')), {
                                method: 'PUT',
                                headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken},
                                body: JSON.stringify({channel_id: currentChannelId, message_id: message.id, content: textarea.value}),
                            })
                                .then(function (response) { return response.ok ? response.json() : Promise.reject(); })
                                .then(function (result) {
                                    if (! result.success) {
                                        return Promise.reject();
                                    }

                                    message.content = textarea.value;
                                    message.edited_timestamp = new Date().toISOString();

                                    const timeText = row.querySelector('.time-text');
                                    timeText.textContent = formatTimestamp(message.timestamp) + ' ' + @json(trans('discord-integration::admin.messages.edited_suffix'));

                                    const newContent = document.createElement('div');
                                    newContent.className = 'content-text';
                                    newContent.style.whiteSpace = 'pre-wrap';
                                    newContent.appendChild(renderMessageContent(message.content, mentionContext(message.mentions)));
                                    textarea.replaceWith(newContent);
                                    controls.remove();
                                })
                                .catch(function () { alert(@json(trans('discord-integration::admin.messages.edit_error'))); });
                        });

                        const cancelBtn = document.createElement('button');
                        cancelBtn.type = 'button';
                        cancelBtn.className = 'btn btn-sm btn-secondary';
                        cancelBtn.textContent = @json(trans('messages.actions.cancel'));
                        cancelBtn.addEventListener('click', function () {
                            const newContent = document.createElement('div');
                            newContent.className = 'content-text';
                            newContent.style.whiteSpace = 'pre-wrap';
                            newContent.appendChild(renderMessageContent(message.content, mentionContext(message.mentions)));
                            textarea.replaceWith(newContent);
                            controls.remove();
                        });

                        controls.appendChild(saveBtn);
                        controls.appendChild(cancelBtn);
                        textarea.insertAdjacentElement('afterend', controls);
                    }

                    // skipConfirm mirrors Discord's own client: holding
                    // Shift while clicking a message's delete button skips
                    // the "are you sure" prompt and deletes right away.
                    function deleteMessage(row, message, skipConfirm) {
                        if (! skipConfirm && ! confirm(@json(trans('discord-integration::admin.messages.delete_confirm')))) {
                            return;
                        }

                        fetch(@json(route('discord-integration.admin.messages.destroy')) + '?channel_id=' + encodeURIComponent(currentChannelId) + '&message_id=' + encodeURIComponent(message.id), {
                            method: 'DELETE',
                            headers: {'X-CSRF-TOKEN': csrfToken},
                        })
                            .then(function (response) { return response.ok ? response.json() : Promise.reject(); })
                            .then(function (result) {
                                if (! result.success) {
                                    return Promise.reject();
                                }

                                row.remove();
                                selectedIds.delete(message.id);
                                updateBulkDeleteButton();

                                if (replyToId === message.id) {
                                    setReplyTarget(null);
                                }
                            })
                            .catch(function () { alert(@json(trans('discord-integration::admin.messages.delete_error'))); });
                    }

                    // Discord returns newest-first. On the initial load the
                    // (empty) list is filled oldest-to-newest via
                    // appendChild. On a "load older" page, the whole batch
                    // (also put oldest-to-newest) is inserted as one block
                    // right before whatever was previously the first (i.e.
                    // oldest-so-far) row, by repeatedly inserting before the
                    // same fixed anchor - this keeps the batch's own internal
                    // order intact instead of reversing it.
                    function loadMessages(before) {
                        let url = @json(route('discord-integration.admin.messages.list')) + '?channel_id=' + encodeURIComponent(currentChannelId);

                        if (before) {
                            url += '&before=' + encodeURIComponent(before);
                        }

                        return fetch(url)
                            .then(function (response) { return response.ok ? response.json() : Promise.reject(); })
                            .then(function (messages) {
                                const list = document.getElementById('msgList');
                                const scrollBox = document.getElementById('msgListScroll');

                                // "Load older" prepends content above whatever
                                // is currently in view - without this, the
                                // browser keeps the same scrollTop (pixels
                                // from the top), which shoves the messages the
                                // admin was looking at further down out of
                                // view. Capturing the height beforehand and
                                // adding the difference back afterwards keeps
                                // them anchored in place instead.
                                const previousScrollHeight = scrollBox.scrollHeight;
                                const previousScrollTop = scrollBox.scrollTop;

                                document.getElementById('msgEmpty').classList.toggle('d-none', ! (messages.length === 0 && ! before));
                                document.getElementById('msgLoadOlderButton').classList.toggle('d-none', messages.length < 50);

                                if (messages.length > 0) {
                                    oldestMessageId = messages[messages.length - 1].id;
                                }

                                const orderedOldestFirst = messages.slice().reverse();

                                if (! before) {
                                    orderedOldestFirst.forEach(function (message) {
                                        list.appendChild(buildMessageRow(message));
                                    });
                                } else {
                                    const anchor = list.firstChild;
                                    orderedOldestFirst.forEach(function (message) {
                                        list.insertBefore(buildMessageRow(message), anchor);
                                    });
                                }

                                // Default view is the bottom (most recent
                                // message) on a fresh load; an older-messages
                                // page keeps the admin's place instead.
                                scrollBox.scrollTop = before
                                    ? previousScrollTop + (scrollBox.scrollHeight - previousScrollHeight)
                                    : scrollBox.scrollHeight;
                            });
                    }

                    document.getElementById('msgLoadButton').addEventListener('click', function () {
                        document.getElementById('msgLoadError').classList.add('d-none');

                        const isDm = ! document.getElementById('msgModeDm').classList.contains('d-none');
                        const guildId = document.getElementById('msgGuildId').value.trim();

                        const resolveChannelId = isDm
                            ? fetch(@json(route('discord-integration.admin.messages.dm-channel')) + '?discord_user_id=' + encodeURIComponent(document.getElementById('msgDmUserId').value.trim()))
                                .then(function (response) { return response.ok ? response.json() : Promise.reject(); })
                                .then(function (result) { return result.channel_id; })
                            : Promise.resolve(document.getElementById('msgThreadId').value.trim() || document.getElementById('msgChannelId').value.trim());

                        // Best-effort: fetched alongside the channel id so
                        // role/channel mentions resolve to real names as soon
                        // as the first page of messages renders, rather than
                        // arriving late - a failure here just means those
                        // mentions fall back to a generic placeholder, not
                        // that loading the messages themselves should fail.
                        const resolveMaps = (! isDm && guildId)
                            ? Promise.all([
                                fetch(@json(route('discord-integration.admin.messages.guild-role-names')) + '?guild_id=' + encodeURIComponent(guildId))
                                    .then(function (response) { return response.ok ? response.json() : []; })
                                    .catch(function () { return []; }),
                                fetch(@json(route('discord-integration.admin.messages.guild-channel-names')) + '?guild_id=' + encodeURIComponent(guildId))
                                    .then(function (response) { return response.ok ? response.json() : []; })
                                    .catch(function () { return []; }),
                                fetch(@json(route('discord-integration.admin.messages.guild-members-info')) + '?guild_id=' + encodeURIComponent(guildId))
                                    .then(function (response) { return response.ok ? response.json() : {}; })
                                    .catch(function () { return {}; }),
                            ]).then(function (results) {
                                guildRolesMap = {};
                                guildRoleColorsMap = {};
                                results[0].forEach(function (role) {
                                    guildRolesMap[role.id] = role.name;
                                    guildRoleColorsMap[role.id] = {color: role.color, position: role.position};
                                });
                                guildChannelsMap = {};
                                guildChannelTypesMap = {};
                                results[1].forEach(function (channel) {
                                    guildChannelsMap[channel.id] = channel.name;
                                    guildChannelTypesMap[channel.id] = channel.type;
                                });
                                guildMembersInfoMap = results[2];
                            })
                            : Promise.resolve().then(function () {
                                guildRolesMap = {};
                                guildRoleColorsMap = {};
                                guildChannelsMap = {};
                                guildChannelTypesMap = {};
                                guildMembersInfoMap = {};
                            });

                        const resolveUploadLimit = fetch(@json(route('discord-integration.admin.messages.upload-limit')) + (! isDm && guildId ? '?guild_id=' + encodeURIComponent(guildId) : ''))
                            .then(function (response) { return response.ok ? response.json() : Promise.reject(); })
                            .then(function (result) { uploadLimitBytes = result.max_bytes; })
                            .catch(function () { uploadLimitBytes = null; });

                        Promise.all([resolveChannelId, resolveMaps, resolveUploadLimit]).then(function (results) {
                            const channelId = results[0];

                            if (! channelId) {
                                return Promise.reject();
                            }

                            currentChannelId = channelId;
                            oldestMessageId = null;
                            document.getElementById('msgList').innerHTML = '';
                            resetSelection();
                            setReplyTarget(null);
                            clearSelectedFile();
                            stopTypingIndicator();
                            closeMentionAutocomplete();
                            document.getElementById('msgListCard').classList.remove('d-none');

                            return loadMessages(null);
                        }).catch(function () {
                            document.getElementById('msgLoadError').classList.remove('d-none');
                        });
                    });

                    document.getElementById('msgLoadOlderButton').addEventListener('click', function () {
                        loadMessages(oldestMessageId);
                    });

                    document.getElementById('msgSendButton').addEventListener('click', function () {
                        const textarea = document.getElementById('msgComposeText');
                        const content = textarea.value.trim();

                        if ((! content && ! selectedFile) || ! currentChannelId) {
                            return;
                        }

                        let body;
                        const headers = {'X-CSRF-TOKEN': csrfToken};

                        if (selectedFile) {
                            body = new FormData();
                            body.append('channel_id', currentChannelId);
                            body.append('content', content);
                            body.append('file', selectedFile);

                            if (replyToId) {
                                body.append('reply_to_message_id', replyToId);
                            }

                            // No Content-Type header here on purpose - the
                            // browser sets it itself (multipart/form-data
                            // with the correct boundary) only when left
                            // unset for a FormData body.
                        } else {
                            headers['Content-Type'] = 'application/json';
                            body = JSON.stringify({channel_id: currentChannelId, content: content, reply_to_message_id: replyToId});
                        }

                        fetch(@json(route('discord-integration.admin.messages.send')), {
                            method: 'POST',
                            headers: headers,
                            body: body,
                        })
                            .then(function (response) { return response.ok ? response.json() : Promise.reject(); })
                            .then(function (result) {
                                if (! result.success) {
                                    return Promise.reject();
                                }

                                // Reloading the latest page (rather than
                                // fabricating a message row) is what gives
                                // the sent message its real id/timestamp,
                                // needed right away for its own Edit/Delete
                                // buttons to work.
                                textarea.value = '';
                                clearSelectedFile();
                                stopTypingIndicator();
                                closeMentionAutocomplete();
                                oldestMessageId = null;
                                document.getElementById('msgList').innerHTML = '';
                                resetSelection();
                                setReplyTarget(null);

                                return loadMessages(null);
                            })
                            .catch(function () { alert(@json(trans('discord-integration::admin.messages.send_error'))); });
                    });
                })();
            </script>
        @endpush
    @endif
@endsection
