<?php

namespace Azuriom\Plugin\DiscordIntegration\Support\Exceptions;

use Exception;

/**
 * Thrown when a script's execution step budget (see
 * CommandScriptRunner::MAX_STEPS) is exceeded - a runaway "repeat" is the
 * likely cause. Unlike CommandScriptStopSignal (a deliberate, silent early
 * exit), this is report()'d so admins can spot misconfigured loops in the
 * logs, while still letting whatever reply was already queued reach Discord
 * within its 3-second response window.
 */
class CommandScriptStepBudgetExceeded extends Exception
{
}
