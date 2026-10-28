<?php

namespace App\Modules\Ticket\Services;

use App\Modules\Ticket\Support\TicketRuleStableJson;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/** Unknown definition values stay server-side; read-only placeholders round-trip at their original positions. */
final class TicketRuleApiDefinition
{
    private const ROOT = ['schema_version', 'trigger', 'trigger_filters', 'conditions', 'then_actions', 'else_actions', 'flow', 'order'];

    public function project(array $definition): array
    {
        $catalog = app(TicketRuleBuilderCatalog::class)->get();

        return $this->projection($definition, $catalog);
    }

    public function restore(array $candidate, ?array $source, ?string $expectedSourceChecksum = null): array
    {
        $catalog = app(TicketRuleBuilderCatalog::class)->get();
        if ($source === null) {
            if (TicketRuleStableJson::checksum($this->projection($candidate, $catalog)) !== TicketRuleStableJson::checksum($candidate)) {
                $this->reject();
            }

            return $candidate;
        }
        $safeSource = $this->projection($source, $catalog);
        $containsUnsupported = TicketRuleStableJson::checksum($safeSource) !== TicketRuleStableJson::checksum($source);
        if ($containsUnsupported && (! is_string($expectedSourceChecksum) || ! hash_equals(TicketRuleStableJson::checksum($source), $expectedSourceChecksum))) {
            $this->reject();
        }
        $result = $candidate;
        foreach (['then_actions', 'else_actions'] as $branch) {
            foreach ((array) ($safeSource[$branch] ?? []) as $index => $node) {
                if ($node === ['unsupported' => true]) {
                    if (($candidate[$branch][$index] ?? null) !== $node) {
                        $this->reject();
                    }
                    $result[$branch][$index] = $source[$branch][$index];
                }
            }
        }
        foreach ((array) data_get($safeSource, 'conditions.groups', []) as $g => $group) {
            $groupPath = 'conditions.groups.'.$g;
            if ($group === ['unsupported' => true]) {
                if (data_get($candidate, $groupPath) !== $group) {
                    $this->reject();
                }
                Arr::set($result, $groupPath, data_get($source, $groupPath));

                continue;
            }
            foreach ((array) ($group['conditions'] ?? []) as $c => $node) {
                if ($node === ['unsupported' => true]) {
                    $path = $groupPath.'.conditions.'.$c;
                    if (data_get($candidate, $path) !== $node) {
                        $this->reject();
                    }
                    Arr::set($result, $path, data_get($source, $path));
                }
            }
        }
        if ($safeSource['_preserved_unsupported_root'] ?? false) {
            if (($candidate['_preserved_unsupported_root'] ?? null) !== true) {
                $this->reject();
            }
            $result += Arr::except($source, self::ROOT);
        }
        if ($safeSource['_preserved_unsupported_filters'] ?? false) {
            if (($candidate['_preserved_unsupported_filters'] ?? null) !== true || ($candidate['trigger'] ?? null) !== ($source['trigger'] ?? null)) {
                $this->reject();
            }
            $knownKeys = array_keys((array) data_get(collect($catalog['triggers'])->firstWhere('key', $source['trigger'] ?? null), 'filter_schema.properties', []));
            $unknown = Arr::except((array) ($source['trigger_filters'] ?? []), $knownKeys);
            $result['trigger_filters'] = (array) ($result['trigger_filters'] ?? []) + $unknown;
        }
        $sections = ['flow' => ['stop_processing'], 'order' => ['weight'], 'conditions' => ['mode', 'match', 'groups']];
        foreach ((array) ($safeSource['_preserved_unsupported_sections'] ?? []) as $section) {
            if (! in_array($section, (array) ($candidate['_preserved_unsupported_sections'] ?? []), true)) {
                $this->reject();
            }
            $result[$section] = (array) ($result[$section] ?? []) + Arr::except((array) ($source[$section] ?? []), $sections[$section]);
        }
        unset($result['_preserved_unsupported_root'], $result['_preserved_unsupported_filters'], $result['_preserved_unsupported_sections']);
        // Only exact source-owned placeholders can survive. New unknown nodes or hidden key names are rejected.
        if (TicketRuleStableJson::checksum($this->projection($result, $catalog)) !== TicketRuleStableJson::checksum($candidate)) {
            $this->reject();
        }

        return $result;
    }

    private function projection(array $definition, array $catalog): array
    {
        $result = Arr::only($definition, self::ROOT);
        if (Arr::except($definition, self::ROOT) !== []) {
            $result['_preserved_unsupported_root'] = true;
        }
        $filterKeys = array_keys((array) data_get(collect($catalog['triggers'])->firstWhere('key', $definition['trigger'] ?? null), 'filter_schema.properties', []));
        if (isset($definition['trigger_filters'])) {
            $result['trigger_filters'] = Arr::only((array) $definition['trigger_filters'], $filterKeys);
            if (Arr::except((array) $definition['trigger_filters'], $filterKeys) !== []) {
                $result['_preserved_unsupported_filters'] = true;
            }
        }
        foreach ((array) data_get($definition, 'conditions.groups', []) as $g => $group) {
            if (! is_array($group) || array_diff(array_keys($group), ['match', 'conditions']) !== [] || ! in_array($group['match'] ?? null, ['ALL', 'ANY'], true) || ! is_array($group['conditions'] ?? null)) {
                Arr::set($result, 'conditions.groups.'.$g, ['unsupported' => true]);

                continue;
            }
            foreach ($group['conditions'] as $c => $condition) {
                $field = is_array($condition) ? ($condition['field'] ?? null) : null;
                $known = collect($catalog['facts'])->firstWhere('key', $field);
                if (is_string($field) && str_starts_with($field, 'custom_field.')) {
                    $cf = collect($catalog['custom_fields'])->first(fn ($row) => ($row['target'] ?? null) === ($condition['target'] ?? null));
                    $known = collect((array) ($cf['facts'] ?? []))->firstWhere('key', $field);
                }
                $allowed = is_string($field) && str_starts_with($field, 'custom_field.') ? ['field', 'target', 'operator', 'value'] : ['field', 'operator', 'value'];
                if (! $known || array_diff(array_keys((array) $condition), $allowed) !== [] || ! in_array($condition['operator'] ?? null, $known['condition_operators'] ?? [], true)) {
                    Arr::set($result, 'conditions.groups.'.$g.'.conditions.'.$c, ['unsupported' => true]);
                }
            }
        }
        foreach (['flow' => ['stop_processing'], 'order' => ['weight'], 'conditions' => ['mode', 'match', 'groups']] as $section => $keys) {
            if (is_array($result[$section] ?? null)) {
                if (Arr::except($result[$section], $keys) !== []) {
                    $result['_preserved_unsupported_sections'][] = $section;
                }
                $result[$section] = Arr::only($result[$section], $keys);
            }
        }
        foreach (['then_actions', 'else_actions'] as $branch) {
            foreach ((array) ($definition[$branch] ?? []) as $index => $action) {
                $known = collect($catalog['actions'])->firstWhere('key', is_array($action) ? ($action['type'] ?? null) : null);
                $legacy = (int) ($definition['schema_version'] ?? 0) === 1 && is_array($action) && in_array($action['type'] ?? null, ['set_ticket_type', 'set_queue', 'set_priority', 'set_sla', 'set_category', 'add_tag', 'emit_signal'], true)
                    && array_diff(array_keys($action), ['type', 'value', 'signal_type', 'severity', 'confidence', 'summary', 'payload_note']) === [];
                $fieldActionKnown = true;
                if (($action['type'] ?? null) === 'set_ticket_fields') {
                    $fields = (array) data_get($action, 'input.fields', []);
                    $fieldActionKnown = count($fields) === 1 && collect($catalog['action_fields'])->contains('key', array_key_first($fields));
                }
                if (! $legacy && (! $known || ! $fieldActionKnown || array_diff(array_keys((array) $action), ['type', 'input']) !== [] || ! is_array($action['input'] ?? null)
                    || array_diff(array_keys($action['input']), array_keys((array) data_get($known, 'input_schema.properties', []))) !== [])) {
                    $result[$branch][$index] = ['unsupported' => true];
                }
            }
        }

        return $result;
    }

    private function reject(): never
    {
        throw ValidationException::withMessages(['definition' => 'Unsupported definition fields are read-only. Reload and preserve every source placeholder and checksum.']);
    }
}
