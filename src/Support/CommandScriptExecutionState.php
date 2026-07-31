<?php

namespace Azuriom\Plugin\DiscordIntegration\Support;

use Azuriom\Models\User;
use Illuminate\Support\Collection;

/**
 * Carries everything a CommandScriptRunner needs while walking a script's
 * block tree, so individual block handlers don't each need 4-5 parameters
 * threaded through them: the invoking user/option values/raw interaction
 * payload (constant for the whole run), the script's variables map (mutated
 * by "set_variable" blocks), the first "reply" or "show_modal" block's
 * result (Discord only accepts one response per interaction - a message or
 * a modal, never both, which is why these are two separate nullable fields
 * rather than a single tagged union: at most one of them is ever set, see
 * CommandScriptRunner::runShowModal()), and a step counter used to enforce
 * the execution budget.
 */
class CommandScriptExecutionState
{
    /** @var array<string, string> */
    public array $variables = [];

    /** @var array{content: string, ephemeral: bool}|null */
    public ?array $reply = null;

    /** @var array{form_id: int}|null */
    public ?array $modal = null;

    public int $stepsExecuted = 0;

    public function __construct(
        public readonly User $user,
        public readonly Collection $optionValues,
        public readonly array $rawPayload,
    ) {
    }
}
