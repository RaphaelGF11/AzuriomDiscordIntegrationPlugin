<?php

namespace Azuriom\Plugin\DiscordIntegration\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An admin-defined Discord modal ("form"): a small set of text fields shown
 * to the user, and what happens with the submitted values (see
 * Support\CommandScriptRunner - a form's script runs through the exact same
 * interpreter as a CustomCommand's, see that model's docblock for the full
 * block AST). A form is never registered with Discord as an application
 * command - it only ever appears as the response to a command's
 * "show_modal" script action (see CustomCommand's docblock), and its
 * submission arrives as a completely independent HTTP request
 * (Controllers\InteractionsController::handleModalSubmit()) with no
 * continuation of whatever state the triggering command's script had built
 * up - only the form's own id (via the modal's custom_id) survives that
 * round-trip.
 *
 * The submitted value of each field is available to this form's own script
 * via {option:field_key} - the exact same placeholder mechanism a
 * CustomCommand's Discord parameters use (see CommandPlaceholderResolver),
 * just sourced from modal fields instead of slash-command options.
 *
 * A form's own script should not contain a "show_modal" block itself -
 * chaining a modal directly off a modal submission isn't a reliably
 * supported flow in Discord's stable API. This isn't enforced here (the
 * runtime behaves the same either way, see CommandScriptRunner::runShowModal()),
 * only hidden from the palette in the form script editor (see
 * resources/views/admin/form-script.blade.php).
 *
 * @property int $id
 * @property string $name
 * @property string $title
 * @property bool $enabled
 * @property array $fields
 * @property array $script
 * @property string|null $fallback_message
 *
 * "fields" is an ordered array (at most 5 - Discord's own modal limit, one
 * text input per action row), each shaped:
 *   {id, field_key: string (≤100 chars, [a-zA-Z0-9_-] only, unique within
 *    this form's fields), label: string (≤45 chars), style: "short"|
 *    "paragraph", required: bool, min_length: int|null, max_length: int|null,
 *    placeholder: string|null, value: string|null (a pre-filled default)}
 * "field_key" is deliberately not called "custom_id" here, even though it
 * becomes exactly that on the Discord text input component at render time -
 * the modal itself also has its own custom_id (encoding this form's id, see
 * InteractionsController), and conflating the two names invites confusion
 * between "which field was submitted" and "which form was submitted".
 *
 * "fallback_message" is placeholder-resolvable and sent only when this
 * form's script produced no reply - same convention as CustomCommand's own
 * fallback_message, see that model's docblock.
 */
class Form extends Model
{
    protected $table = 'discord_integration_forms';

    protected $fillable = [
        'name', 'title', 'enabled', 'fields', 'script', 'fallback_message',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'fields' => 'array',
        'script' => 'array',
    ];
}
