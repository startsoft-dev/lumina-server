<?php

namespace Lumina\LaravelApi\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

interface HasHiddenColumns
{
    /**
     * Define additional columns to hide based on the authenticated user.
     *
     * Return an array of column names that should be hidden from the response
     * for the given user. These columns are merged with the base hidden columns
     * and any static $additionalHiddenColumns defined on the model.
     *
     * @param  \Illuminate\Contracts\Auth\Authenticatable|null  $user
     * @return array<string>
     */
    public function hiddenColumns(?Authenticatable $user): array;
}
