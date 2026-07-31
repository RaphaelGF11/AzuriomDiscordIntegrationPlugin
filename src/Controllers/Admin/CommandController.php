<?php

namespace Azuriom\Plugin\DiscordIntegration\Controllers\Admin;

use Azuriom\Http\Controllers\Controller;
use Azuriom\Models\Setting;
use Azuriom\Plugin\DiscordIntegration\Models\CustomCommand;
use Azuriom\Plugin\DiscordIntegration\Models\Form;
use Azuriom\Plugin\DiscordIntegration\Support\Concerns\BuildsScriptEditorContext;
use Azuriom\Plugin\DiscordIntegration\Support\DiscordBotClient;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CommandController extends Controller
{
    use BuildsScriptEditorContext;

    /**
     * Discord's numeric option type for each of this plugin's simplified
     * option "type" values (see CustomCommand's docblock for the JSON shape).
     */
    protected const OPTION_TYPE_MAP = [
        'text' => 3,
        'integer' => 4,
        'boolean' => 5,
        'user' => 6,
        'channel' => 7,
        'role' => 8,
        'mentionable' => 9,
        'number' => 10,
        'attachment' => 11,
    ];

    /**
     * Option types Discord allows min/max value bounds on.
     */
    protected const OPTION_TYPES_WITH_VALUE_BOUNDS = ['integer', 'number'];

    /**
     * Show the plugin's custom Discord slash commands and forms - one flat
     * admin nav item ("Interactions") covers both, see
     * DiscordIntegrationServiceProvider::adminNavigation().
     */
    public function index()
    {
        return view('discord-integration::admin.commands', array_merge($this->builderContext(), [
            'commands' => CustomCommand::latest()->get(),
            'forms' => Form::latest()->get(),
            'interactionsUrl' => route('discord-integration.interactions'),
            'interactionsPublicKey' => setting('discord-integration.interactions_public_key'),
        ]));
    }

    /**
     * Show the dedicated drag-and-drop script editor for one command.
     */
    public function editScript(CustomCommand $command)
    {
        return view('discord-integration::admin.command-script', array_merge($this->builderContext(), [
            'command' => $command,
            // Only a command's script can show a form (the "show_modal"
            // block) - a form's own script editor doesn't need this list at
            // all, see FormController::editScript().
            'forms' => Form::where('enabled', true)->get(['id', 'name']),
        ]));
    }

    /**
     * @throws \Illuminate\Validation\ValidationException
     */
    public function updateScript(Request $request, CustomCommand $command)
    {
        $data = $this->validate($request, [
            'script' => ['nullable', 'string'],
        ]);

        $command->update(['script' => $this->decodeJsonField($data['script'] ?? null)]);

        return to_route('discord-integration.admin.commands.script', $command)
            ->with('success', trans('messages.status.success'));
    }

    /**
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request)
    {
        $command = CustomCommand::create($this->validated($request));

        $this->syncWithDiscord($command);

        return to_route('discord-integration.admin.commands')->with('success', trans('messages.status.success'));
    }

    /**
     * @throws \Illuminate\Validation\ValidationException
     */
    public function update(Request $request, CustomCommand $command)
    {
        $previousScope = $command->scope;
        $previousGuildId = $command->discord_guild_id;
        $previousCommandId = $command->discord_command_id;

        $command->update($this->validated($request));

        // A command re-scoped between global and a specific server (or moved
        // to a different server) isn't automatically migrated by Discord -
        // the old registration would stay behind as an orphan, so it's
        // explicitly deregistered before the new one is created.
        if ($previousCommandId !== null && ($previousScope !== $command->scope || $previousGuildId !== $command->discord_guild_id)) {
            DiscordBotClient::deleteCommand($previousGuildId, $previousCommandId);
            $command->discord_command_id = null;
        }

        $this->syncWithDiscord($command);

        return to_route('discord-integration.admin.commands')->with('success', trans('messages.status.success'));
    }

    public function destroy(CustomCommand $command)
    {
        if ($command->discord_command_id !== null) {
            DiscordBotClient::deleteCommand($command->discord_guild_id, $command->discord_command_id);
        }

        $command->delete();

        return to_route('discord-integration.admin.commands')->with('success', trans('messages.status.success'));
    }

    /**
     * Save the Interactions Endpoint public key - lives on this page (rather
     * than Configuration) since it must be set here first: Discord signs a
     * PING to the endpoint URL the moment it's saved on Discord's own side,
     * and rejects saving that URL at all if the endpoint doesn't already
     * verify against a known public key.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function savePublicKey(Request $request)
    {
        $data = $this->validate($request, [
            'interactions_public_key' => ['nullable', 'string'],
        ]);

        Setting::updateSettings([
            'discord-integration.interactions_public_key' => $data['interactions_public_key'],
        ]);

        return to_route('discord-integration.admin.commands')->with('success', trans('messages.status.success'));
    }

    /**
     * Roles of the given guild, for the role picker modal - identical to
     * RoleSyncController::guildRoles(), used by every "Discord role" picker
     * in the script editor (condition blocks, assign/remove-role actions).
     */
    public function guildRoles(Request $request)
    {
        $guildId = $request->query('guild_id', '');

        abort_if(! DiscordBotClient::available() || $guildId === '', 404);

        $roles = DiscordBotClient::guildRoles($guildId);

        abort_if($roles === null, 404);

        return response()->json(
            collect($roles)
                ->reject(fn ($role) => $role['id'] === $guildId || ($role['managed'] ?? false))
                ->sortByDesc('position')
                ->values()
                ->map(fn ($role) => ['id' => $role['id'], 'name' => $role['name']])
        );
    }

    /**
     * Channels of the given guild that a message can actually be posted to
     * (text/announcement/forum, not voice/category/stage), for the "send a
     * channel message" script action's channel picker.
     */
    public function guildChannels(Request $request)
    {
        $guildId = $request->query('guild_id', '');

        abort_if(! DiscordBotClient::available() || $guildId === '', 404);

        $channels = DiscordBotClient::guildChannels($guildId);

        abort_if($channels === null, 404);

        // Discord channel types: 0 = text, 5 = announcement, 15 = forum.
        return response()->json(
            collect($channels)
                ->whereIn('type', [0, 5, 15])
                ->sortBy('position')
                ->values()
                ->map(fn ($channel) => ['id' => $channel['id'], 'name' => $channel['name']])
        );
    }

    /**
     * Register (or update) the command with Discord's API and persist the
     * id it assigns - a no-op if no bot token is configured.
     */
    protected function syncWithDiscord(CustomCommand $command): void
    {
        if (! DiscordBotClient::available()) {
            return;
        }

        $result = DiscordBotClient::registerCommand($command->discord_guild_id, $this->buildDiscordPayload($command));

        if ($result !== null && isset($result['id'])) {
            $command->updateQuietly(['discord_command_id' => $result['id']]);
        }
    }

    protected function buildDiscordPayload(CustomCommand $command): array
    {
        return [
            'name' => $command->name,
            'description' => $command->description,
            'options' => collect($command->options ?? [])->map(fn ($option) => array_filter([
                'name' => $option['name'],
                'description' => $option['description'],
                'type' => self::OPTION_TYPE_MAP[$option['type']] ?? 3,
                'required' => (bool) ($option['required'] ?? false),
                'choices' => ! empty($option['choices']) ? $option['choices'] : null,
                'min_length' => $option['type'] === 'text' ? ($option['min_length'] ?? null) : null,
                'max_length' => $option['type'] === 'text' ? ($option['max_length'] ?? null) : null,
                'min_value' => in_array($option['type'], self::OPTION_TYPES_WITH_VALUE_BOUNDS, true) ? ($option['min_value'] ?? null) : null,
                'max_value' => in_array($option['type'], self::OPTION_TYPES_WITH_VALUE_BOUNDS, true) ? ($option['max_value'] ?? null) : null,
                'channel_types' => $option['type'] === 'channel' && ! empty($option['channel_types']) ? $option['channel_types'] : null,
            ], fn ($value) => $value !== null))->values()->all(),
        ];
    }

    /**
     * @throws \Illuminate\Validation\ValidationException
     */
    protected function validated(Request $request): array
    {
        $request->merge([
            'options' => $this->decodeJsonField($request->input('options')),
        ]);

        $data = $this->validate($request, [
            'name' => [
                'required', 'string', 'regex:/^[a-z0-9_-]{1,32}$/',
                Rule::unique('discord_integration_commands', 'name')
                    ->where(fn ($query) => $query->where(
                        'discord_guild_id',
                        $request->input('scope') === 'guild' ? $request->input('discord_guild_id') : null
                    ))
                    ->ignore($request->route('command')),
            ],
            'description' => ['required', 'string', 'max:100'],
            'scope' => ['required', 'in:global,guild'],
            'discord_guild_id' => ['nullable', 'required_if:scope,guild', 'string'],
            'fallback_message' => ['nullable', 'string', 'max:2000'],
            'options' => ['nullable', 'array', 'max:25'],
            'options.*.name' => ['required', 'string', 'regex:/^[a-z0-9_-]{1,32}$/'],
            'options.*.description' => ['required', 'string', 'max:100'],
            'options.*.type' => ['required', Rule::in(array_keys(self::OPTION_TYPE_MAP))],
            'options.*.min_length' => ['nullable', 'integer', 'min:0', 'max:6000'],
            'options.*.max_length' => ['nullable', 'integer', 'min:1', 'max:6000'],
            'options.*.min_value' => ['nullable', 'numeric'],
            'options.*.max_value' => ['nullable', 'numeric'],
            'options.*.channel_types' => ['nullable', 'array'],
            'options.*.channel_types.*' => ['integer'],
        ]);

        $data['enabled'] = $request->boolean('enabled');
        $data['options'] = $request->input('options') ?? [];

        if ($data['scope'] !== 'guild') {
            $data['discord_guild_id'] = null;
        }

        return $data;
    }

    /**
     * The options/script builders each serialize their in-memory state into
     * a hidden field as a JSON string - decoded back into an array here
     * before validation, an empty/missing field becoming an empty array
     * rather than a validation error.
     */
    protected function decodeJsonField(?string $value): array
    {
        if (! $value) {
            return [];
        }

        return json_decode($value, true) ?? [];
    }
}
