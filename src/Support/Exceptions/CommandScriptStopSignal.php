<?php

namespace Azuriom\Plugin\DiscordIntegration\Support\Exceptions;

use Exception;

/**
 * Thrown by a "stop" block to abort the rest of a script immediately,
 * regardless of how deeply nested the current block is - caught at the top
 * of CommandScriptRunner::run(). Not a real error (never report()'d), just
 * the cleanest way to unwind an arbitrarily deep recursive block tree in
 * PHP, which has no multi-level "break".
 */
class CommandScriptStopSignal extends Exception
{
}
