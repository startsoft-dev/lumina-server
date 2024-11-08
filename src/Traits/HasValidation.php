<?php

namespace Lumina\LaravelApi\Traits;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Lumina\LaravelApi\Contracts\HasRoleBasedValidation;

trait HasValidation
{
    public function validateStore(Request $request): \Illuminate\Validation\Validator
    {
        return Validator::make(
            $request->all(),
            $this->getValidationRulesStore(),
            $this->getValidationRulesMessages()
        );
    }

    public function validateUpdate(Request $request): \Illuminate\Validation\Validator
    {
        return Validator::make(
            $request->all(),
            $this->getValidationRulesUpdate(),
            $this->getValidationRulesMessages()
        );
    }

    private function getValidationRulesStore(): array
    {
        if (! property_exists($this, 'validationRules')
            || ! property_exists($this, 'validationRulesStore')) {
            return [];
        }

        $config = $this->validationRulesStore;

        if ($this->isLegacyRulesFormat($config)) {
            return array_intersect_key($this->validationRules, array_flip($config));
        }

        $roleFields = $this->resolveFieldsForRole($config, 'store');
        if ($roleFields === null || $roleFields === []) {
            return [];
        }

        return $this->mergeRulesWithPresence($roleFields, $this->validationRules);
    }

    private function getValidationRulesUpdate(): array
    {
        if (! property_exists($this, 'validationRules')
            || ! property_exists($this, 'validationRulesUpdate')) {
            return [];
        }

        $config = $this->validationRulesUpdate;

        if ($this->isLegacyRulesFormat($config)) {
            return array_intersect_key($this->validationRules, array_flip($config));
        }

        $roleFields = $this->resolveFieldsForRole($config, 'update');
        if ($roleFields === null || $roleFields === []) {
            return [];
        }

        return $this->mergeRulesWithPresence($roleFields, $this->validationRules);
    }

    /**
     * Legacy format: flat array of field names, e.g. ['title', 'content'].
     * Role-keyed format: associative array keyed by role slug, e.g. ['admin' => [...], '*' => [...]].
     */
    private function isLegacyRulesFormat(array $config): bool
    {
        if ($config === []) {
            return true;
        }

        $first = reset($config);

        return is_string($first);
    }

    /**
     * Resolve the field => presence (or full rule) array for the current user's role.
     * Returns null when not role-keyed or no config; returns empty array when role has no fields.
     */
    private function resolveFieldsForRole(array $roleKeyedConfig, string $action): ?array
    {
        $user = null;
        try {
            $user = auth('sanctum')->user();
        } catch (\InvalidArgumentException $e) {
            $user = auth()->user();
        }

        $organization = request()->get('organization');

        $roleSlug = null;
        if ($user instanceof HasRoleBasedValidation) {
            $roleSlug = $user->getRoleSlugForValidation($organization);
        }

        if ($roleSlug !== null && isset($roleKeyedConfig[$roleSlug])) {
            return $roleKeyedConfig[$roleSlug];
        }

        if (isset($roleKeyedConfig['*'])) {
            return $roleKeyedConfig['*'];
        }

        return [];
    }

    /**
     * Merge role field config (field => 'required'|'nullable'|'sometimes'|full rule) with base format rules.
     * If the modifier contains '|', it is treated as a full rule override; otherwise prepended to base.
     */
    private function mergeRulesWithPresence(array $roleFields, array $baseRules): array
    {
        $merged = [];

        foreach ($roleFields as $field => $modifier) {
            $modifier = (string) $modifier;

            if (str_contains($modifier, '|')) {
                $merged[$field] = $modifier;
                continue;
            }

            $base = $baseRules[$field] ?? '';
            $merged[$field] = $base !== '' ? $modifier.'|'.$base : $modifier;
        }

        return $merged;
    }

    private function getValidationRulesMessages(): array
    {
        return property_exists($this, 'validationRulesMessages') ? $this->validationRulesMessages : [];
    }
}
