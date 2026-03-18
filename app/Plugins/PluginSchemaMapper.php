<?php

namespace App\Plugins;

use App\Models\ExtensionPlugin;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class PluginSchemaMapper
{
    public function settingsComponents(?ExtensionPlugin $plugin): array
    {
        if (! $plugin) {
            return [];
        }

        return $this->componentsForFields($plugin->settings_schema ?? [], 'settings.');
    }

    public function actionComponents(ExtensionPlugin $plugin, string $actionId): array
    {
        $action = $plugin->getActionDefinition($actionId);

        return $this->componentsForFields($action['fields'] ?? []);
    }

    public function settingsRules(?ExtensionPlugin $plugin): array
    {
        if (! $plugin) {
            return [];
        }

        return $this->rulesForFields($plugin->settings_schema ?? [], 'settings.');
    }

    public function actionRules(ExtensionPlugin $plugin, string $actionId): array
    {
        $action = $plugin->getActionDefinition($actionId);

        return $this->rulesForFields($action['fields'] ?? []);
    }

    public function defaultsForFields(array $fields, array $existing = []): array
    {
        $defaults = [];

        foreach ($fields as $field) {
            $fieldId = $field['id'] ?? null;
            if (! $fieldId) {
                continue;
            }

            $defaults[$fieldId] = Arr::get($existing, $fieldId, $field['default'] ?? null);
        }

        return $defaults;
    }

    private function componentsForFields(array $fields, string $prefix = ''): array
    {
        return collect($fields)
            ->filter(fn (array $field): bool => filled($field['id'] ?? null))
            ->map(fn (array $field) => $this->componentForField($field, $prefix))
            ->all();
    }

    private function componentForField(array $field, string $prefix = '')
    {
        $name = $prefix.($field['id'] ?? '');
        $type = $field['type'] ?? 'text';
        $label = $field['label'] ?? Str::headline((string) ($field['id'] ?? 'value'));
        $helperText = $field['helper_text'] ?? null;
        $required = (bool) ($field['required'] ?? false);

        $component = match ($type) {
            'boolean' => Toggle::make($name),
            'number' => TextInput::make($name)->numeric(),
            'textarea' => Textarea::make($name)->rows(4),
            'select' => Select::make($name)->options($field['options'] ?? [])->searchable(),
            'model_select' => $this->modelSelectComponent($name, $field),
            'text' => TextInput::make($name),
            default => throw new InvalidArgumentException("Unsupported plugin field type [{$type}]"),
        };

        return $component
            ->label($label)
            ->default($field['default'] ?? null)
            ->helperText($helperText)
            ->required($required);
    }

    private function modelSelectComponent(string $name, array $field): Select
    {
        $modelClass = $field['model'] ?? null;
        $labelAttribute = $field['label_attribute'] ?? 'name';

        if (! is_string($modelClass) || ! class_exists($modelClass) || ! is_subclass_of($modelClass, Model::class)) {
            throw new InvalidArgumentException("Invalid model_select model for [{$name}]");
        }

        return Select::make($name)
            ->searchable()
            ->preload()
            ->options(function () use ($field, $modelClass, $labelAttribute) {
                $query = $modelClass::query();

                if (($field['scope'] ?? null) === 'owned' && auth()->check() && $query->getModel()->isFillable('user_id')) {
                    $query->where('user_id', auth()->id());
                }

                return $query
                    ->orderBy($labelAttribute)
                    ->limit(200)
                    ->pluck($labelAttribute, 'id')
                    ->toArray();
            });
    }

    private function rulesForFields(array $fields, string $prefix = ''): array
    {
        $rules = [];

        foreach ($fields as $field) {
            $fieldId = $field['id'] ?? null;
            if (! $fieldId) {
                continue;
            }

            $name = $prefix.$fieldId;
            $fieldRules = [];
            $required = (bool) ($field['required'] ?? false);

            $fieldRules[] = $required ? 'required' : 'nullable';

            $fieldRules = [
                ...$fieldRules,
                ...match ($field['type'] ?? 'text') {
                    'boolean' => ['boolean'],
                    'number' => ['numeric'],
                    'textarea', 'text' => ['string'],
                    'select' => ['string', Rule::in(array_keys($field['options'] ?? []))],
                    'model_select' => ['integer', 'exists:'.app($field['model'])->getTable().',id'],
                    default => ['string'],
                },
            ];

            $rules[$name] = $fieldRules;
        }

        return $rules;
    }
}
