<?php

namespace Azuriom\Plugin\DiscordIntegration\Controllers\Admin;

use Azuriom\Http\Controllers\Controller;
use Azuriom\Models\Role;
use Azuriom\Plugin\DiscordIntegration\Models\LogEntry;
use Azuriom\Plugin\DiscordIntegration\Models\RoleSync;
use Azuriom\Plugin\DiscordIntegration\Support\DiscordBotClient;
use Azuriom\Plugin\DiscordIntegration\Support\RoleSyncEvaluator;
use Azuriom\Plugin\Shop\Models\Package;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

class RoleSyncController extends Controller
{
    /**
     * Show the plugin's Discord role sync rules.
     */
    public function index()
    {
        $botAvailable = DiscordBotClient::available();
        $roleSyncs = RoleSync::latest()->get();

        return view('discord-integration::admin.roles', [
            'botAvailable' => $botAvailable,
            'guilds' => $botAvailable ? DiscordBotClient::guilds() : null,
            'roleSyncs' => $roleSyncs,
            'siteRoles' => Role::orderByDesc('power')->get(),
            'shopPackages' => class_exists(Package::class) ? Package::enabled()->get(['id', 'name']) : null,
            'permissionErrors' => $this->recentPermissionErrors($roleSyncs),
        ]);
    }

    /**
     * A recent Discord 403 (see LogEntry::recentPermissionError()) for each
     * (guild, role) pair any of the currently listed rules target - one
     * query for every rule rather than N, keyed the same way the view looks
     * entries up so a rule row can show "the bot can't manage this role"
     * directly, instead of an admin having to notice a rule matching
     * everyone yet granting it to no one and go digging through the logs.
     *
     * @param  Collection<int, RoleSync>  $roleSyncs
     * @return \Illuminate\Support\Collection<string, LogEntry>
     */
    protected function recentPermissionErrors(Collection $roleSyncs)
    {
        $roleIds = $roleSyncs->pluck('discord_role_id')->unique()->values();

        if ($roleIds->isEmpty()) {
            return collect();
        }

        return LogEntry::where('status', 403)
            ->whereIn('discord_role_id', $roleIds)
            ->where('created_at', '>=', now()->subHours(6))
            ->latest('created_at')
            ->get()
            ->unique(fn (LogEntry $entry) => $entry->discord_guild_id.'|'.$entry->discord_role_id)
            ->keyBy(fn (LogEntry $entry) => $entry->discord_guild_id.'|'.$entry->discord_role_id);
    }

    /**
     * Create a new role-sync rule and immediately reconcile every linked
     * user against it (rather than waiting for the next scheduled sweep -
     * see SyncDiscordRolesCommand), for prompt feedback in the admin UI.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request, RoleSyncEvaluator $evaluator)
    {
        RoleSync::create($this->validated($request));

        $evaluator->reconcileAll();

        return to_route('discord-integration.admin.roles')->with('success', trans('messages.status.success'));
    }

    /**
     * @throws \Illuminate\Validation\ValidationException
     */
    public function update(Request $request, RoleSync $roleSync, RoleSyncEvaluator $evaluator)
    {
        $roleSync->update($this->validated($request));

        $evaluator->reconcileAll();

        return to_route('discord-integration.admin.roles')->with('success', trans('messages.status.success'));
    }

    /**
     * Deleting a rule doesn't retract the role from anyone right away - the
     * next reconciliation (real-time trigger or scheduled sweep) naturally
     * removes it from anyone no longer matching any remaining rule.
     */
    public function destroy(RoleSync $roleSync)
    {
        $roleSync->delete();

        return to_route('discord-integration.admin.roles')->with('success', trans('messages.status.success'));
    }

    /**
     * Download every rule as JSON, for backup or copying to another site.
     * Site roles and the shop package are exported by name rather than id,
     * since those ids are meaningless on a different site (and can drift
     * over time on the same one) - see import() for the reverse lookup.
     */
    public function export()
    {
        $siteRoleNames = Role::pluck('name', 'id');
        $shopPackageNames = class_exists(Package::class) ? Package::pluck('name', 'id') : collect();

        $data = RoleSync::all()->map(fn (RoleSync $rule) => [
            'discord_guild_id' => $rule->discord_guild_id,
            'discord_role_id' => $rule->discord_role_id,
            'overwrite' => $rule->overwrite,
            'site_roles' => collect($rule->site_role_ids ?? [])
                ->map(fn ($id) => $siteRoleNames[$id] ?? null)
                ->filter()
                ->values(),
            'shop_package' => $rule->shop_package_id !== null ? ($shopPackageNames[$rule->shop_package_id] ?? null) : null,
            'balance_min' => $rule->balance_min,
            'balance_max' => $rule->balance_max,
        ]);

        return response()->json($data->values(), 200, [
            'Content-Disposition' => 'attachment; filename="discord-role-sync-'.now()->format('Y-m-d').'.json"',
        ], JSON_PRETTY_PRINT);
    }

    /**
     * Create rules from a previously exported JSON file. Site roles and the
     * shop package are matched back by name against this site's current
     * ones (see export()) - a name with no match is silently dropped from
     * that rule's conditions rather than failing the whole import, since
     * renamed/removed roles or packages are an expected possibility when
     * moving rules to another site.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function import(Request $request, RoleSyncEvaluator $evaluator)
    {
        $this->validate($request, [
            'file' => ['required', 'file', 'mimes:json,txt', 'max:2048'],
        ]);

        $entries = json_decode($request->file('file')->get(), true);

        abort_if(! is_array($entries), 422, trans('discord-integration::admin.role_sync.import_invalid'));

        $siteRoleIds = Role::pluck('id', 'name');
        $shopPackageIds = class_exists(Package::class) ? Package::pluck('id', 'name') : collect();
        $imported = 0;

        foreach ($entries as $entry) {
            if (! is_array($entry) || ! isset($entry['discord_guild_id'], $entry['discord_role_id'])) {
                continue;
            }

            $siteRoleIdsForRule = collect($entry['site_roles'] ?? [])
                ->map(fn ($name) => $siteRoleIds[$name] ?? null)
                ->filter()
                ->values();

            RoleSync::create([
                'discord_guild_id' => $entry['discord_guild_id'],
                'discord_role_id' => $entry['discord_role_id'],
                'overwrite' => $entry['overwrite'] ?? false,
                'site_role_ids' => $siteRoleIdsForRule->isNotEmpty() ? $siteRoleIdsForRule->all() : null,
                'shop_package_id' => isset($entry['shop_package']) ? ($shopPackageIds[$entry['shop_package']] ?? null) : null,
                'balance_min' => $entry['balance_min'] ?? null,
                'balance_max' => $entry['balance_max'] ?? null,
            ]);

            $imported++;
        }

        $evaluator->reconcileAll();

        return to_route('discord-integration.admin.roles')
            ->with('success', trans('discord-integration::admin.role_sync.import_done', ['count' => $imported]));
    }

    /**
     * Roles of the given guild, for the role picker modal (see
     * resources/views/admin/roles.blade.php). Excludes @everyone (its id
     * always equals the guild id, and it can't be assigned/removed like a
     * normal role anyway) and managed roles (bot/booster/integration roles,
     * which the API always refuses to assign manually).
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
     * @throws \Illuminate\Validation\ValidationException
     */
    protected function validated(Request $request): array
    {
        $data = $this->validate($request, [
            'discord_guild_id' => ['required', 'string'],
            'discord_role_id' => ['required', 'string'],
            'site_role_ids' => ['nullable', 'array'],
            'site_role_ids.*' => ['integer', 'exists:roles,id'],
            'shop_package_id' => ['nullable', 'integer'],
            'balance_min' => ['nullable', 'numeric', 'min:0'],
            'balance_max' => ['nullable', 'numeric', 'gte:balance_min'],
        ]);

        $data['overwrite'] = $request->boolean('overwrite');

        return $data;
    }
}
