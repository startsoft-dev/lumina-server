<?php

namespace Lumina\LaravelApi\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\note;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;
use function Laravel\Prompts\warning;

class InstallCommand extends Command
{
    protected $signature = 'lumina:install';

    protected $description = 'Install and configure Lumina for your Laravel application';

    protected $stubPath;

    public function handle()
    {
        $this->printBanner();

        $this->newLine();
        note('+ Lumina :: Install :: Let\'s build something great +');
        $this->newLine();

        $features = multiselect(
            label: 'Which features would you like to configure?',
            options: [
                'publish' => 'Publish config & routes',
                'multi_tenant' => 'Multi-tenant support (Organizations, Roles)',
                'audit_trail' => 'Audit trail (change logging)',
                'cursor' => 'Cursor AI toolkit (rules, skills, agents)',
            ],
            default: ['publish'],
            required: true,
        );

        $testFramework = select(
            label: 'Which test framework do you use?',
            options: [
                'pest' => 'Pest',
                'phpunit' => 'PHPUnit',
            ],
            default: 'pest',
        );

        $useSubdomain = false;
        $identifierColumn = 'id';
        $roles = ['admin'];

        if (in_array('multi_tenant', $features)) {
            $useSubdomain = select(
                label: 'How should organizations be identified in routes?',
                options: [
                    'subdomain' => 'Subdomain (e.g., org1.example.com)',
                    'prefix' => 'Route prefix (e.g., /api/{org_id}/...)',
                ],
                default: 'subdomain',
            ) === 'subdomain';

            $identifierColumn = text(
                label: 'What column should be used to identify organizations?',
                placeholder: $useSubdomain ? 'slug' : 'id',
                default: $useSubdomain ? 'slug' : 'id',
                hint: 'Common options: id, slug, uuid',
            );

            $rolesInput = text(
                label: 'What roles should your app have?',
                placeholder: 'admin, editor, viewer',
                default: 'admin, editor, viewer',
                hint: 'Comma-separated list of role slugs. "admin" is always included.',
            );

            $roles = array_unique(array_merge(
                ['admin'],
                array_filter(array_map('trim', explode(',', $rolesInput)))
            ));
        }

        $this->newLine();

        if (in_array('publish', $features)) {
            $this->publishConfig($testFramework);
            $this->publishRoutes();
        }

        if (in_array('cursor', $features)) {
            $this->publishCursor();
        }

        if (in_array('multi_tenant', $features)) {
            $this->ensureSanctumInstalled();
            $this->stubPath = __DIR__ . '/../../stubs/multi-tenant';
            $this->installMultiTenant($useSubdomain, $identifierColumn, $roles);
        }

        if (in_array('audit_trail', $features)) {
            $this->installAuditTrail();
        }

        $this->newLine();

        $this->runPostInstallSteps($features, $useSubdomain);

        $this->newLine();
        info('Lumina installed successfully!');
        $this->newLine();

        $this->printNextSteps($features, $useSubdomain);

        return 0;
    }

    protected function printBanner(): void
    {
        $this->newLine();

        $lines = [
            '  ██╗     ██╗   ██╗███╗   ███╗██╗███╗   ██╗ █████╗ ',
            '  ██║     ██║   ██║████╗ ████║██║████╗  ██║██╔══██╗',
            '  ██║     ██║   ██║██╔████╔██║██║██╔██╗ ██║███████║',
            '  ██║     ██║   ██║██║╚██╔╝██║██║██║╚██╗██║██╔══██║',
            '  ███████╗╚██████╔╝██║ ╚═╝ ██║██║██║ ╚████║██║  ██║',
            '  ╚══════╝ ╚═════╝ ╚═╝     ╚═╝╚═╝╚═╝  ╚═══╝╚═╝  ╚═╝',
        ];

        $gradient = [
            [0, 255, 255],
            [0, 230, 200],
            [100, 220, 100],
            [255, 220, 50],
            [255, 170, 30],
            [255, 120, 0],
        ];

        foreach ($lines as $i => $text) {
            [$r, $g, $b] = $gradient[$i];
            $this->output->writeln("\033[38;2;{$r};{$g};{$b}m{$text}\033[0m");
        }
    }

    // ------------------------------------------------------------------
    // Sanctum
    // ------------------------------------------------------------------

    protected function ensureSanctumInstalled(): void
    {
        if (class_exists(\Laravel\Sanctum\SanctumServiceProvider::class)) {
            return;
        }

        $installSanctum = confirm(
            label: 'Laravel Sanctum is required for API authentication but is not installed. Install it now?',
            default: true,
        );

        if (!$installSanctum) {
            warning('Sanctum is required. Please run: composer require laravel/sanctum');
            return;
        }

        $this->components->task('Installing Laravel Sanctum', function () {
            $process = new \Symfony\Component\Process\Process(
                ['composer', 'require', 'laravel/sanctum'],
                base_path()
            );
            $process->setTimeout(300);
            $process->run();

            if (!$process->isSuccessful()) {
                warning('Failed to install Sanctum automatically. Please run: composer require laravel/sanctum');
                return;
            }
        });

        $this->components->task('Publishing Sanctum migrations', function () {
            $this->callSilently('vendor:publish', [
                '--provider' => 'Laravel\Sanctum\SanctumServiceProvider',
                '--tag' => 'sanctum-migrations',
            ]);
        });
    }

    // ------------------------------------------------------------------
    // Publish
    // ------------------------------------------------------------------

    protected function publishConfig(string $testFramework = 'pest'): void
    {
        $this->components->task('Publishing config', function () use ($testFramework) {
            $this->callSilently('vendor:publish', [
                '--provider' => 'Lumina\LaravelApi\GlobalControllerServiceProvider',
                '--tag' => 'config',
            ]);

            // Set the chosen test framework in config
            $configPath = config_path('lumina.php');
            if (File::exists($configPath)) {
                $content = File::get($configPath);
                $content = str_replace(
                    "'test_framework' => 'pest'",
                    "'test_framework' => '{$testFramework}'",
                    $content
                );
                File::put($configPath, $content);
            }
        });
    }

    protected function publishRoutes(): void
    {
        $this->components->task('Publishing routes', function () {
            $this->callSilently('vendor:publish', [
                '--provider' => 'Lumina\LaravelApi\GlobalControllerServiceProvider',
                '--tag' => 'routes',
            ]);
        });
    }

    protected function publishCursor(): void
    {
        $this->components->task('Publishing Cursor AI toolkit', function () {
            $this->callSilently('vendor:publish', [
                '--provider' => 'Lumina\LaravelApi\GlobalControllerServiceProvider',
                '--tag' => 'cursor',
            ]);
        });
    }

    // ------------------------------------------------------------------
    // Multi-tenant
    // ------------------------------------------------------------------

    protected function installMultiTenant(bool $useSubdomain, string $identifierColumn, array $roles = ['admin']): void
    {
        $this->components->task('Creating migrations', fn () => $this->createMigrations());
        $this->components->task('Creating models', fn () => $this->createModels($roles));
        $this->components->task('Creating factories', fn () => $this->createFactories());
        $this->components->task('Creating policies', fn () => $this->createPolicies());
        $this->components->task('Updating routes', fn () => $this->updateRoutes($useSubdomain));
        $this->components->task('Creating middleware', fn () => $this->createMiddleware($useSubdomain));
        $this->components->task('Updating config', fn () => $this->updateConfig($useSubdomain, $identifierColumn));
        $this->components->task('Updating User model', fn () => $this->updateUserModel());
        $this->components->task('Updating AppServiceProvider', fn () => $this->updateAppServiceProvider());
        $this->components->task('Creating seeders', fn () => $this->createSeeders($roles));
    }

    protected function createMigrations(): void
    {
        $migrationsPath = database_path('migrations');
        $timestamp = now()->format('Y_m_d_His');

        File::ensureDirectoryExists($migrationsPath);

        File::copy(
            $this->stubPath . '/migrations/create_organizations_table.php.stub',
            $migrationsPath . "/{$timestamp}_00_create_organizations_table.php"
        );

        File::copy(
            $this->stubPath . '/migrations/create_roles_table.php.stub',
            $migrationsPath . "/{$timestamp}_01_create_roles_table.php"
        );

        File::copy(
            $this->stubPath . '/migrations/create_user_roles_table.php.stub',
            $migrationsPath . "/{$timestamp}_02_create_user_roles_table.php"
        );
    }

    protected function createModels(array $roles = ['admin']): void
    {
        $modelsPath = app_path('Models');

        File::ensureDirectoryExists($modelsPath);

        File::copy(
            $this->stubPath . '/models/Organization.php.stub',
            $modelsPath . '/Organization.php'
        );

        // Role model with dynamic $roles array
        $roleStub = File::get($this->stubPath . '/models/Role.php.stub');
        $rolesPhp = "[\n" . implode("\n", array_map(fn ($r) => "        '{$r}',", $roles)) . "\n    ]";
        $roleContent = str_replace('{{ roles }}', $rolesPhp, $roleStub);
        File::put($modelsPath . '/Role.php', $roleContent);

        File::copy(
            $this->stubPath . '/models/UserRole.php.stub',
            $modelsPath . '/UserRole.php'
        );
    }

    protected function createFactories(): void
    {
        $factoriesPath = database_path('factories');

        File::ensureDirectoryExists($factoriesPath);

        File::copy(
            $this->stubPath . '/factories/OrganizationFactory.php.stub',
            $factoriesPath . '/OrganizationFactory.php'
        );

        File::copy(
            $this->stubPath . '/factories/RoleFactory.php.stub',
            $factoriesPath . '/RoleFactory.php'
        );

        File::copy(
            $this->stubPath . '/factories/UserRoleFactory.php.stub',
            $factoriesPath . '/UserRoleFactory.php'
        );
    }

    protected function createPolicies(): void
    {
        $policiesPath = app_path('Policies');

        File::ensureDirectoryExists($policiesPath);

        File::copy(
            $this->stubPath . '/policies/OrganizationPolicy.php.stub',
            $policiesPath . '/OrganizationPolicy.php'
        );

        File::copy(
            $this->stubPath . '/policies/RolePolicy.php.stub',
            $policiesPath . '/RolePolicy.php'
        );
    }

    protected function updateRoutes(bool $useSubdomain): void
    {
        $apiRoutesPath = base_path('routes/api.php');

        File::ensureDirectoryExists(dirname($apiRoutesPath));

        if ($useSubdomain) {
            File::copy(
                $this->stubPath . '/routes/api-subdomain.php.stub',
                $apiRoutesPath
            );
        } else {
            File::copy(
                $this->stubPath . '/routes/api-route-prefix.php.stub',
                $apiRoutesPath
            );
        }
    }

    protected function createMiddleware(bool $useSubdomain): void
    {
        $middlewarePath = app_path('Http/Middleware');
        $packageMiddlewarePath = __DIR__ . '/../Http/Middleware';

        File::ensureDirectoryExists($middlewarePath);

        if ($useSubdomain) {
            $packageContent = File::get($packageMiddlewarePath . '/ResolveOrganizationFromSubdomain.php');
            $appContent = str_replace(
                'namespace Lumina\LaravelApi\Http\Middleware;',
                'namespace App\Http\Middleware;',
                $packageContent
            );
            File::put($middlewarePath . '/ResolveOrganizationFromSubdomain.php', $appContent);
        } else {
            $packageContent = File::get($packageMiddlewarePath . '/ResolveOrganizationFromRoute.php');
            $appContent = str_replace(
                'namespace Lumina\LaravelApi\Http\Middleware;',
                'namespace App\Http\Middleware;',
                $packageContent
            );
            File::put($middlewarePath . '/ResolveOrganizationFromRoute.php', $appContent);
        }
    }

    protected function updateConfig(bool $useSubdomain, string $identifierColumn): void
    {
        $configPath = config_path('lumina.php');

        if (!File::exists($configPath)) {
            return;
        }

        $config = require $configPath;

        $config['multi_tenant'] = [
            'enabled' => true,
            'use_subdomain' => $useSubdomain,
            'organization_identifier_column' => $identifierColumn,
            'middleware' => $useSubdomain
                ? 'Lumina\LaravelApi\Http\Middleware\ResolveOrganizationFromSubdomain'
                : 'Lumina\LaravelApi\Http\Middleware\ResolveOrganizationFromRoute',
        ];

        $config['models']['organizations'] = \App\Models\Organization::class;
        $config['models']['roles'] = \App\Models\Role::class;

        $configContent = "<?php\n\nreturn " . $this->arrayToShortSyntax($config) . ";\n";
        File::put($configPath, $configContent);
    }

    protected function updateUserModel(): void
    {
        $userModelPath = app_path('Models/User.php');

        if (!File::exists($userModelPath)) {
            return;
        }

        $userModelContent = File::get($userModelPath);

        if (strpos($userModelContent, 'function organizations()') !== false) {
            return;
        }

        $relationshipsStub = File::get($this->stubPath . '/user-relationships.php.stub');

        // Step 1: Add traits to the "use" line inside the class BEFORE adding imports
        // (Adding imports first would cause strpos checks to find the class name in the import line)

        // Add HasApiTokens to the use traits line
        if (strpos($userModelContent, 'HasApiTokens') === false) {
            $userModelContent = preg_replace(
                '/(use\s+HasFactory(?:,\s*\w+)*)(;)/',
                '$1, HasApiTokens$2',
                $userModelContent,
                1
            );
        }

        // Add HasPermissions to the use traits line
        if (strpos($userModelContent, 'HasPermissions') === false) {
            $userModelContent = preg_replace(
                '/(use\s+HasFactory(?:,\s*\w+)*)(;)/',
                '$1, HasPermissions$2',
                $userModelContent,
                1
            );
        }

        // Step 2: Add "implements HasRoleBasedValidation" to class declaration
        $userModelContent = preg_replace(
            '/(class User extends Authenticatable)(?!\s+implements)/',
            '$1 implements HasRoleBasedValidation',
            $userModelContent
        );

        // Step 3: Add namespace imports
        if (strpos($userModelContent, 'use App\Models\Organization') === false) {
            $userModelContent = str_replace(
                'namespace App\Models;',
                "namespace App\Models;\n\nuse App\Models\Organization;\nuse App\Models\Role;",
                $userModelContent
            );
        }

        if (strpos($userModelContent, 'Laravel\Sanctum\HasApiTokens') === false) {
            $userModelContent = str_replace(
                'namespace App\Models;',
                "namespace App\Models;\n\nuse Laravel\Sanctum\HasApiTokens;",
                $userModelContent
            );
        }

        if (strpos($userModelContent, 'Lumina\LaravelApi\Traits\HasPermissions') === false) {
            $userModelContent = str_replace(
                'namespace App\Models;',
                "namespace App\Models;\n\nuse Lumina\LaravelApi\Traits\HasPermissions;",
                $userModelContent
            );
        }

        if (strpos($userModelContent, 'Lumina\LaravelApi\Contracts\HasRoleBasedValidation') === false) {
            $userModelContent = str_replace(
                'namespace App\Models;',
                "namespace App\Models;\n\nuse Lumina\LaravelApi\Contracts\HasRoleBasedValidation;",
                $userModelContent
            );
        }

        // Step 4: Append relationship methods
        $userModelContent = preg_replace(
            '/(\n\})$/',
            "\n" . $relationshipsStub . '$1',
            $userModelContent
        );

        File::put($userModelPath, $userModelContent);
    }

    protected function updateAppServiceProvider(): void
    {
        $providerPath = app_path('Providers/AppServiceProvider.php');

        if (!File::exists($providerPath)) {
            return;
        }

        $content = File::get($providerPath);

        // Skip if already configured
        if (strpos($content, 'guessPolicyNamesUsing') !== false) {
            return;
        }

        // Add Gate import if not present
        if (strpos($content, 'use Illuminate\Support\Facades\Gate') === false) {
            $content = str_replace(
                'use Illuminate\Support\ServiceProvider;',
                "use Illuminate\Support\Facades\Gate;\nuse Illuminate\Support\ServiceProvider;",
                $content
            );
        }

        // Add the policy discovery to the boot() method
        $policyDiscovery = "\n        Gate::guessPolicyNamesUsing(function (\$modelClass) {\n"
            . "            return 'App\\\\Policies\\\\' . class_basename(\$modelClass) . 'Policy';\n"
            . "        });\n";

        $content = preg_replace_callback(
            '/(public function boot\(\)(?::\s*void)?\s*\{)/',
            function ($matches) use ($policyDiscovery) {
                return $matches[1] . $policyDiscovery;
            },
            $content
        );

        File::put($providerPath, $content);
    }

    protected function createSeeders(array $roles = ['admin']): void
    {
        $seedersPath = database_path('seeders');

        File::ensureDirectoryExists($seedersPath);

        // Generate RoleSeeder with all roles
        $this->generateRoleSeeder($seedersPath, $roles);

        File::copy(
            $this->stubPath . '/seeders/OrganizationSeeder.php.stub',
            $seedersPath . '/OrganizationSeeder.php'
        );

        File::copy(
            $this->stubPath . '/seeders/UserRoleSeeder.php.stub',
            $seedersPath . '/UserRoleSeeder.php'
        );

        $this->updateDatabaseSeeder();
    }

    protected function generateRoleSeeder(string $seedersPath, array $roles): void
    {
        $seedLines = [];

        foreach ($roles as $role) {
            $name = ucfirst($role);
            $description = match ($role) {
                'admin' => 'Administrator role with full access',
                'editor' => 'Editor role with create, read, and update access',
                'viewer' => 'Viewer role with read-only access',
                'writer' => 'Writer role with create and edit access',
                default => ucfirst($role) . ' role',
            };

            $seedLines[] = "        Role::firstOrCreate(\n"
                . "            ['slug' => '{$role}'],\n"
                . "            [\n"
                . "                'name' => '{$name}',\n"
                . "                'description' => '{$description}',\n"
                . "            ]\n"
                . "        );";
        }

        $content = "<?php\n\nnamespace Database\\Seeders;\n\n"
            . "use App\\Models\\Role;\n"
            . "use Illuminate\\Database\\Seeder;\n\n"
            . "class RoleSeeder extends Seeder\n{\n"
            . "    /**\n     * Run the database seeds.\n     */\n"
            . "    public function run(): void\n    {\n"
            . implode("\n\n", $seedLines) . "\n"
            . "    }\n}\n";

        File::put($seedersPath . '/RoleSeeder.php', $content);
    }

    protected function updateDatabaseSeeder(): void
    {
        $databaseSeederPath = database_path('seeders/DatabaseSeeder.php');

        if (!File::exists($databaseSeederPath)) {
            return;
        }

        $databaseSeederContent = File::get($databaseSeederPath);

        if (
            strpos($databaseSeederContent, 'RoleSeeder') !== false &&
            strpos($databaseSeederContent, 'OrganizationSeeder') !== false &&
            strpos($databaseSeederContent, 'UserRoleSeeder') !== false
        ) {
            return;
        }

        if (preg_match('/(public function run\(\): void\s*\{[^}]*?)(\s*\})/s', $databaseSeederContent, $matches)) {
            $beforeClosingBrace = $matches[1];
            $closingBrace = $matches[2];

            $seedersCall = "\n        \$this->call([\n            RoleSeeder::class,\n            OrganizationSeeder::class,\n            UserRoleSeeder::class,\n        ]);";

            $databaseSeederContent = str_replace(
                $matches[0],
                $beforeClosingBrace . $seedersCall . $closingBrace,
                $databaseSeederContent
            );
        } else {
            $databaseSeederContent = preg_replace(
                '/(public function run\(\): void\s*\{[^}]*)(\})/s',
                '$1' . "\n        \$this->call([\n            RoleSeeder::class,\n            OrganizationSeeder::class,\n            UserRoleSeeder::class,\n        ]);\n" . '$2',
                $databaseSeederContent
            );
        }

        File::put($databaseSeederPath, $databaseSeederContent);
    }

    // ------------------------------------------------------------------
    // Audit trail
    // ------------------------------------------------------------------

    protected function installAuditTrail(): void
    {
        $this->components->task('Creating audit trail migration', function () {
            $stubPath = __DIR__ . '/../../stubs/audit-trail/migrations/create_audit_logs_table.php.stub';
            $migrationsPath = database_path('migrations');
            $timestamp = now()->format('Y_m_d_His');

            $existingMigrations = glob($migrationsPath . '/*_create_audit_logs_table.php');
            if (!empty($existingMigrations)) {
                return;
            }

            File::copy(
                $stubPath,
                $migrationsPath . "/{$timestamp}_create_audit_logs_table.php"
            );
        });
    }

    // ------------------------------------------------------------------
    // Post-install steps
    // ------------------------------------------------------------------

    protected function runPostInstallSteps(array $features, bool $useSubdomain): void
    {
        $hasMigrations = in_array('multi_tenant', $features) || in_array('audit_trail', $features);

        if ($hasMigrations) {
            $runMigrate = confirm(
                label: 'Would you like to run migrations now?',
                default: true,
            );

            if ($runMigrate) {
                $this->components->task('Running migrations', function () {
                    $this->callSilently('migrate');
                });
            }
        }

        if (in_array('multi_tenant', $features)) {
            $runSeed = confirm(
                label: 'Would you like to seed the database? (Roles, Organizations, UserRoles)',
                default: true,
            );

            if ($runSeed) {
                $this->components->task('Seeding database', function () {
                    $this->callSilently('db:seed');
                });
            }

            $configureBootstrap = confirm(
                label: 'Would you like to configure bootstrap/app.php? (API routes, middleware, exception handlers)',
                default: true,
            );

            if ($configureBootstrap) {
                $this->components->task('Configuring bootstrap/app.php', function () use ($useSubdomain) {
                    $this->overwriteBootstrapApp($useSubdomain);
                });
            }
        }
    }

    protected function overwriteBootstrapApp(bool $useSubdomain): void
    {
        $bootstrapPath = base_path('bootstrap/app.php');

        $middlewareClass = $useSubdomain
            ? '\\Lumina\\LaravelApi\\Http\\Middleware\\ResolveOrganizationFromSubdomain'
            : '\\Lumina\\LaravelApi\\Http\\Middleware\\ResolveOrganizationFromRoute';

        $stubPath = $this->stubPath ?? __DIR__ . '/../../stubs/multi-tenant';
        $stub = File::get($stubPath . '/bootstrap/app.php.stub');
        $content = str_replace('{{ middlewareClass }}', $middlewareClass, $stub);

        File::put($bootstrapPath, $content);
    }

    // ------------------------------------------------------------------
    // Next steps (remaining manual steps)
    // ------------------------------------------------------------------

    protected function printNextSteps(array $features, bool $useSubdomain): void
    {
        $hasManualSteps = false;

        if (in_array('audit_trail', $features)) {
            $hasManualSteps = true;
        }

        if (!$hasManualSteps) {
            return;
        }

        $this->components->info('Remaining steps:');
        $this->newLine();

        $step = 1;

        if (in_array('audit_trail', $features)) {
            $this->line("  <fg=yellow>{$step}.</> Add <fg=white>HasAuditTrail</> trait to your models:");
            $this->line('     <fg=gray>use Lumina\LaravelApi\Traits\HasAuditTrail;</>');
            $step++;
        }

        $this->newLine();
    }

    protected function arrayToShortSyntax(array $array, int $depth = 1): string
    {
        $indent = str_repeat('    ', $depth);
        $closingIndent = str_repeat('    ', $depth - 1);
        $lines = [];

        $isAssoc = array_keys($array) !== range(0, count($array) - 1);

        foreach ($array as $key => $value) {
            $exportedValue = match (true) {
                is_array($value) => $this->arrayToShortSyntax($value, $depth + 1),
                is_bool($value) => $value ? 'true' : 'false',
                is_null($value) => 'null',
                is_int($value) || is_float($value) => (string) $value,
                is_string($value) && preg_match('/^[A-Z][A-Za-z0-9]*(\\\\[A-Z][A-Za-z0-9]*)+$/', $value) => '\\' . $value . '::class',
                default => "'" . addslashes((string) $value) . "'",
            };

            if ($isAssoc) {
                $exportedKey = is_int($key) ? $key : "'" . addslashes($key) . "'";
                $lines[] = "{$indent}{$exportedKey} => {$exportedValue},";
            } else {
                $lines[] = "{$indent}{$exportedValue},";
            }
        }

        if (empty($lines)) {
            return '[]';
        }

        return "[\n" . implode("\n", $lines) . "\n{$closingIndent}]";
    }
}
