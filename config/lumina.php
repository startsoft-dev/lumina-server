<?php

return [
    'models' => [
        // 'users' => \App\Models\User::class,
    ],
    'public' => [
        // 'users'
    ],
    'multi_tenant' => [
        'enabled' => false,
        'use_subdomain' => false,
        'organization_identifier_column' => 'id', // Options: 'id', 'slug', or any other column name
        'middleware' => null,
    ],
    'invitations' => [
        'expires_days' => env('INVITATION_EXPIRES_DAYS', 7),
        'allowed_roles' => null, // null means all roles can invite, or specify array of role slugs
    ],
    'nested' => [
        'path' => 'nested',
        'max_operations' => 50,
        'allowed_models' => null, // null = all registered models; or e.g. ['blogs', 'posts']
    ],
    'test_framework' => 'pest', // Options: 'pest', 'phpunit'
    'postman' => [
        'role_class' => 'App\Models\Role',
        'user_role_class' => 'App\Models\UserRole',
        'user_class' => 'App\Models\User',
    ],
];
