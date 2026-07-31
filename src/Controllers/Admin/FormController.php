<?php

namespace Azuriom\Plugin\DiscordIntegration\Controllers\Admin;

use Azuriom\Http\Controllers\Controller;
use Azuriom\Plugin\DiscordIntegration\Models\Form;
use Azuriom\Plugin\DiscordIntegration\Support\Concerns\BuildsScriptEditorContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Admin CRUD for Models\Form - unlike CustomCommand, a form is never
 * registered with Discord's API (it only ever appears as the response to a
 * command's "show_modal" script action), so this has no equivalent of
 * CommandController's syncWithDiscord()/buildDiscordPayload(), and no
 * guildRoles()/guildChannels() of its own - the form script editor points at
 * CommandController's existing routes for those two pickers, since they're
 * generic Discord API proxies, not command-specific. The forms list itself
 * is rendered by CommandController::index() (the combined "Interactions"
 * page) rather than a route here, see that method's docblock.
 */
class FormController extends Controller
{
    use BuildsScriptEditorContext;

    /**
     * Show the dedicated drag-and-drop script editor for one form.
     */
    public function editScript(Form $form)
    {
        return view('discord-integration::admin.form-script', array_merge($this->builderContext(), [
            'form' => $form,
        ]));
    }

    /**
     * @throws \Illuminate\Validation\ValidationException
     */
    public function updateScript(Request $request, Form $form)
    {
        $data = $this->validate($request, [
            'script' => ['nullable', 'string'],
        ]);

        $form->update(['script' => $this->decodeJsonField($data['script'] ?? null)]);

        return to_route('discord-integration.admin.forms.script', $form)
            ->with('success', trans('messages.status.success'));
    }

    /**
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request)
    {
        Form::create($this->validated($request));

        return to_route('discord-integration.admin.commands')->with('success', trans('messages.status.success'));
    }

    /**
     * @throws \Illuminate\Validation\ValidationException
     */
    public function update(Request $request, Form $form)
    {
        $form->update($this->validated($request));

        return to_route('discord-integration.admin.commands')->with('success', trans('messages.status.success'));
    }

    public function destroy(Form $form)
    {
        $form->delete();

        return to_route('discord-integration.admin.commands')->with('success', trans('messages.status.success'));
    }

    /**
     * @throws \Illuminate\Validation\ValidationException
     */
    protected function validated(Request $request): array
    {
        $request->merge([
            'fields' => $this->decodeJsonField($request->input('fields')),
        ]);

        $validator = Validator::make($request->all(), [
            'name' => [
                'required', 'string', 'max:64', 'alpha_dash',
                Rule::unique('discord_integration_forms', 'name')->ignore($request->route('form')),
            ],
            'title' => ['required', 'string', 'max:45'],
            'fallback_message' => ['nullable', 'string', 'max:2000'],
            'fields' => ['nullable', 'array', 'max:5'],
            'fields.*.field_key' => ['required', 'string', 'max:100', 'regex:/^[a-zA-Z0-9_-]+$/', 'distinct'],
            'fields.*.label' => ['required', 'string', 'max:45'],
            'fields.*.style' => ['required', 'in:short,paragraph'],
            'fields.*.required' => ['nullable', 'boolean'],
            'fields.*.min_length' => ['nullable', 'integer', 'min:0', 'max:4000'],
            'fields.*.max_length' => ['nullable', 'integer', 'min:1', 'max:4000'],
            'fields.*.placeholder' => ['nullable', 'string', 'max:100'],
            'fields.*.value' => ['nullable', 'string', 'max:4000'],
        ]);

        $validator->after(fn ($validator) => $this->validateFieldLengthBounds($validator, $request));

        $data = $validator->validate();

        $data['enabled'] = $request->boolean('enabled');
        $data['fields'] = $request->input('fields') ?? [];

        return $data;
    }

    /**
     * Discord rejects a modal outright if a field's min_length exceeds its
     * own max_length - a mismatch here would otherwise only surface as a
     * confusing failure when the command actually runs, not when the form
     * is saved.
     */
    protected function validateFieldLengthBounds($validator, Request $request): void
    {
        foreach ($request->input('fields', []) as $index => $field) {
            $min = $field['min_length'] ?? null;
            $max = $field['max_length'] ?? null;

            if ($min !== null && $max !== null && $min > $max) {
                $validator->errors()->add("fields.{$index}.min_length", trans('discord-integration::admin.forms.fields.min_greater_than_max'));
            }
        }
    }

    /**
     * @see CommandController::decodeJsonField()
     */
    protected function decodeJsonField(?string $value): array
    {
        if (! $value) {
            return [];
        }

        return json_decode($value, true) ?? [];
    }
}
