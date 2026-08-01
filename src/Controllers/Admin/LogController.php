<?php

namespace Azuriom\Plugin\DiscordIntegration\Controllers\Admin;

use Azuriom\Http\Controllers\Controller;
use Azuriom\Plugin\DiscordIntegration\Models\LogEntry;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The "Journal" admin page: a chronological, filterable read of everything
 * Support\PluginLog has written - Discord API failures (the same 403s a
 * misconfigured bot role produces, see LogEntry::recentPermissionError()
 * and RoleSyncController's use of it), script action errors, and other
 * plugin-side failures that would otherwise only ever show up by tailing
 * the server's own log files.
 */
class LogController extends Controller
{
    public function index(Request $request)
    {
        $this->validate($request, [
            'level' => ['nullable', Rule::in(['info', 'warning', 'error'])],
        ]);

        $level = $request->query('level');

        return view('discord-integration::admin.logs', [
            'entries' => LogEntry::query()
                ->when($level, fn ($query) => $query->where('level', $level))
                ->latest('created_at')
                ->paginate(50)
                ->withQueryString(),
            'level' => $level,
        ]);
    }

    /**
     * Wipes the journal - a manual reset for an admin who just fixed
     * something and wants a clean slate, not something routinely needed
     * (entries already self-prune after 30 days, see LogEntry::prunable()).
     */
    public function clear()
    {
        LogEntry::query()->delete();

        return to_route('discord-integration.admin.logs')->with('success', trans('messages.status.success'));
    }
}
