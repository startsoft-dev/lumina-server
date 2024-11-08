<?php

namespace Lumina\LaravelApi\Traits;

use Illuminate\Support\Facades\Gate;
use Lumina\LaravelApi\Contracts\HasHiddenColumns;

trait HidableColumns
{
    /**
     * Cache for dynamically resolved hidden columns per model class and user.
     * Prevents N+1 queries when serializing collections.
     *
     * @var array<string, array<string>>
     */
    private static array $hiddenColumnsCache = [];

    protected $baseHiddenColumns = [
        'password',
        'remember_token',
        'has_temporary_password',
        'updated_at',
        'created_at',
        'deleted_at',
        'email_verified_at',
    ];

    public function initializeHidableColumns()
    {
        $this->hidden = array_merge($this->hidden, $this->baseHiddenColumns);

        if (property_exists($this, 'additionalHiddenColumns')) {
            $this->hidden = array_merge($this->hidden, $this->additionalHiddenColumns);
        }
    }

    /**
     * Get the hidden attributes for the model.
     *
     * Extends Laravel's getHidden() to support dynamic column hiding based on the
     * authenticated user. Checks the model's policy for a hiddenColumns() method
     * (via the HasHiddenColumns contract or ResourcePolicy base class).
     *
     * Priority:
     *   1. Base hidden columns ($baseHiddenColumns) — always applied
     *   2. Static additional columns ($additionalHiddenColumns) — always applied
     *   3. Policy hiddenColumns() — contextual, based on authenticated user
     *
     * Results are cached per model class + user to avoid N+1 queries on collections.
     *
     * @return array<string>
     */
    public function getHidden()
    {
        $hidden = $this->hidden;

        try {
            $user = auth('sanctum')->user();
        } catch (\InvalidArgumentException $e) {
            // Sanctum guard not defined — fall back to default guard
            $user = auth()->user();
        }
        $cacheKey = static::class . ':' . ($user?->id ?? 'guest');

        if (!isset(static::$hiddenColumnsCache[$cacheKey])) {
            static::$hiddenColumnsCache[$cacheKey] = $this->resolveHiddenColumnsFromPolicy($user);
        }

        return array_unique(array_merge($hidden, static::$hiddenColumnsCache[$cacheKey]));
    }

    /**
     * Resolve additional hidden columns from the model's policy.
     *
     * @param  mixed  $user
     * @return array<string>
     */
    protected function resolveHiddenColumnsFromPolicy($user): array
    {
        try {
            $policy = Gate::getPolicyFor($this);

            if ($policy instanceof HasHiddenColumns) {
                return $policy->hiddenColumns($user);
            }
        } catch (\Exception $e) {
            // If policy resolution fails, fall back to no additional columns
        }

        return [];
    }

    public function hideAdditionalColumns(array $columns)
    {
        $this->hidden = array_merge($this->hidden, $columns);
        return $this;
    }

    protected function getColumns(): array
    {
        return $this->getConnection()
            ->getSchemaBuilder()
            ->getColumnListing($this->getTable());
    }

    /**
     * Clear the hidden columns cache.
     * Useful for testing or when user context changes mid-request.
     */
    public static function clearHiddenColumnsCache(): void
    {
        static::$hiddenColumnsCache = [];
    }
}
