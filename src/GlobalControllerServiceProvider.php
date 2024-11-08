<?php

namespace Lumina\LaravelApi;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate;
use Lumina\LaravelApi\Commands\ExportPostmanCommand;
use Lumina\LaravelApi\Commands\GenerateInvitationLink;
use Lumina\LaravelApi\Commands\GenerateCommand;
use Lumina\LaravelApi\Commands\InstallCommand;
use Lumina\LaravelApi\Models\OrganizationInvitation;
use Lumina\LaravelApi\Policies\InvitationPolicy;

class GlobalControllerServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/../config/lumina.php', 'lumina');
    }

    public function boot()
    {
        $this->publishes([
            __DIR__.'/../routes/api.php' => base_path('routes/api.php'),
        ], 'routes');

        $this->publishes([
            __DIR__.'/../config/lumina.php' => config_path('lumina.php'),
        ], 'config');

        // Publish .cursor AI toolkit (rules, skills, subagents)
        $this->publishes([
            __DIR__.'/../.cursor/rules' => base_path('.cursor/rules'),
            __DIR__.'/../.cursor/skills' => base_path('.cursor/skills'),
            __DIR__.'/../.cursor/agents' => base_path('.cursor/agents'),
        ], 'cursor');

        // Register invitation policy
        Gate::policy(OrganizationInvitation::class, InvitationPolicy::class);

        $this->commands([
            InstallCommand::class,
            GenerateCommand::class,
            GenerateInvitationLink::class,
            ExportPostmanCommand::class,
        ]);
    }
}
