<?php

namespace Azuriom\Plugin\DiscordIntegration\Support;

use Azuriom\Models\User;
use Illuminate\Support\Collection;

/**
 * Resolves the fixed set of template placeholders a command's text/number
 * fields can use ({user}, {mention}, {site_user}, {balance}, {option:name},
 * {var:name}) via plain string replacement - deliberately not a general
 * expression language (no eval, no arithmetic), see CustomCommand's docblock
 * for why. Script variables (set_variable blocks) are untyped strings for
 * the same reason - a variable's value is just more text to substitute, not
 * something to compute with.
 *
 * The one exception is {property@placeholder} - a small, fixed set of
 * formatting transforms (hex/bin/length) that can wrap any other
 * placeholder, e.g. {hex@balance}, {length@option:name}. Still not general
 * computation: "placeholder" itself must be one of the placeholders this
 * class already knows how to resolve (fixed, {option:}, or {var:}), and
 * "property" is one of a hardcoded list, not an arbitrary function call.
 */
class CommandPlaceholderResolver
{
    /**
     * @param  Collection<string, mixed>  $optionValues  the values the
     *                                                    Discord user supplied for the command's parameters, keyed by name
     * @param  array  $rawPayload  the raw Discord interaction payload, used
     *                             to read the invoking Discord user's display name/id
     * @param  array<string, string>  $variables  the script's variables map
     *                                            at the point this placeholder is being resolved (see
     *                                            CommandScriptExecutionState) - read *before* any assignment happening in
     *                                            the same block, so a set_variable using {var:sameName} in its own value
     *                                            reads the old value (the "accumulate" pattern for a repeat loop)
     */
    public function resolve(string $template, User $user, Collection $optionValues, array $rawPayload, array $variables = []): string
    {
        // {property@placeholder} - resolved first, since "placeholder" here
        // is itself a full placeholder expression (without its own braces),
        // resolved recursively through this same method so it can be any of
        // the fixed/{option:}/{var:} forms handled below.
        $template = preg_replace_callback('/\{(hex|bin|length)@([a-zA-Z0-9_:]+)\}/', function ($matches) use ($user, $optionValues, $rawPayload, $variables) {
            $inner = $this->resolve('{'.$matches[2].'}', $user, $optionValues, $rawPayload, $variables);

            return $this->applyTransform($matches[1], $inner);
        }, $template);

        $discordUser = $rawPayload['member']['user'] ?? $rawPayload['user'] ?? [];

        $replacements = [
            '{user}' => $discordUser['global_name'] ?? $discordUser['username'] ?? $user->name,
            '{mention}' => isset($discordUser['id']) ? "<@{$discordUser['id']}>" : $user->name,
            '{site_user}' => $user->name,
            '{balance}' => (string) $user->money,
        ];

        $resolved = strtr($template, $replacements);

        return preg_replace_callback('/\{(option|var):([a-zA-Z0-9_]+)\}/', function ($matches) use ($optionValues, $variables) {
            return (string) ($matches[1] === 'option' ? $optionValues[$matches[2]] ?? '' : $variables[$matches[2]] ?? '');
        }, $resolved);
    }

    /**
     * hex/bin favor a clean number-base conversion when the resolved value
     * is numeric (e.g. a balance or a var holding a count) - the common
     * case - and fall back to encoding the raw string's bytes otherwise, so
     * a non-numeric value still produces something rather than nothing.
     */
    protected function applyTransform(string $property, string $value): string
    {
        return match ($property) {
            'hex' => is_numeric($value) ? dechex((int) round((float) $value)) : bin2hex($value),
            'bin' => is_numeric($value) ? decbin((int) round((float) $value)) : $this->stringToBinary($value),
            'length' => (string) mb_strlen($value),
            default => $value,
        };
    }

    protected function stringToBinary(string $value): string
    {
        $binary = '';

        foreach (str_split($value) as $byte) {
            $binary .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        return $binary;
    }
}
