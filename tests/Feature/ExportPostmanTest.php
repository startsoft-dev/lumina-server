<?php

namespace Lumina\LaravelApi\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Lumina\LaravelApi\Tests\TestCase;
use Lumina\LaravelApi\Traits\HasValidation;
use Lumina\LaravelApi\Traits\HidableColumns;

class ExportPostModel extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'posts';

    protected $fillable = ['title', 'status'];

    protected $validationRules = [
        'title' => 'string|max:255',
        'status' => 'boolean',
    ];

    protected $validationRulesStore = [
        'admin' => ['title' => 'required', 'status' => 'nullable'],
        '*' => ['title' => 'required'],
    ];

    protected $validationRulesUpdate = [
        'admin' => ['title' => 'sometimes', 'status' => 'nullable'],
        '*' => ['title' => 'sometimes'],
    ];

    public static $allowedFilters = ['title', 'status'];
    public static $allowedSorts = ['title', 'created_at'];
    public static $allowedFields = ['id', 'title', 'status'];
    public static $allowedIncludes = ['blog'];
    public static $allowedSearch = ['title'];
    public static $defaultSort = 'created_at';
}

class ExportPostModelWithSoftDeletes extends Model
{
    use HasValidation, HidableColumns, SoftDeletes;

    protected $table = 'posts';

    protected $fillable = ['title'];

    protected $validationRules = ['title' => 'string'];
    protected $validationRulesStore = ['*' => ['title' => 'required']];
    protected $validationRulesUpdate = ['*' => ['title' => 'sometimes']];

    public static $allowedSorts = ['deleted_at'];
}

class ExportPostModelWithExcept extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'posts';
    protected $fillable = ['title'];
    protected $validationRules = ['title' => 'string'];
    protected $validationRulesStore = ['*' => ['title' => 'required']];
    protected $validationRulesUpdate = ['*' => ['title' => 'sometimes']];

    public static array $exceptActions = ['destroy', 'update'];
}

class ExportPostmanTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('lumina.models', [
            'exportPosts' => ExportPostModel::class,
            'exportPostsSoft' => ExportPostModelWithSoftDeletes::class,
            'exportPostsExcept' => ExportPostModelWithExcept::class,
        ]);
        $app['config']->set('lumina.public', ['exportPostsExcept']);
        $app['config']->set('lumina.multi_tenant.enabled', false);
        $app['config']->set('lumina.postman.role_class', 'App\Models\Role');
        $app['config']->set('lumina.postman.user_role_class', 'App\Models\UserRole');
        $app['config']->set('lumina.postman.user_class', 'App\Models\User');
    }

    public function test_collection_json_is_valid_and_has_correct_structure(): void
    {
        $path = sys_get_temp_dir() . '/postman_export_test_' . uniqid() . '.json';
        $this->assertSame(0, Artisan::call('lumina:export-postman', [
            '--output' => $path,
            '--project-name' => 'Test API',
        ]));

        $this->assertFileExists($path);
        $json = json_decode(File::get($path), true);
        $this->assertNotNull($json);
        $this->assertArrayHasKey('info', $json);
        $this->assertSame('Test API', $json['info']['name']);
        $this->assertSame('https://schema.getpostman.com/json/collection/v2.1.0/collection.json', $json['info']['schema']);
        $this->assertArrayHasKey('variable', $json);
        $this->assertArrayHasKey('item', $json);
        $this->assertIsArray($json['item']);

        @unlink($path);
    }

    public function test_authentication_folder_is_first(): void
    {
        $path = sys_get_temp_dir() . '/postman_export_test_' . uniqid() . '.json';
        Artisan::call('lumina:export-postman', ['--output' => $path]);

        $json = json_decode(File::get($path), true);
        $first = $json['item'][0] ?? null;
        $this->assertNotNull($first);
        $this->assertSame('Authentication', $first['name']);
        $authNames = array_column($first['item'], 'name');
        $this->assertContains('Login', $authNames);
        $this->assertContains('Logout', $authNames);
        $this->assertContains('Password recover', $authNames);
        $this->assertContains('Password reset', $authNames);
        $this->assertContains('Register (with invitation)', $authNames);
        $this->assertContains('Accept invitation', $authNames);

        @unlink($path);
    }

    public function test_models_from_config_appear_as_top_level_folders_with_config_slug_name(): void
    {
        $path = sys_get_temp_dir() . '/postman_export_test_' . uniqid() . '.json';
        Artisan::call('lumina:export-postman', ['--output' => $path]);

        $json = json_decode(File::get($path), true);
        $names = array_column($json['item'], 'name');
        $this->assertContains('Authentication', $names);
        $this->assertContains('exportPosts', $names);
        $this->assertContains('exportPostsSoft', $names);
        $this->assertContains('exportPostsExcept', $names);

        @unlink($path);
    }

    public function test_model_folder_has_action_folders_directly(): void
    {
        $path = sys_get_temp_dir() . '/postman_export_test_' . uniqid() . '.json';
        Artisan::call('lumina:export-postman', ['--output' => $path]);

        $json = json_decode(File::get($path), true);
        $exportPostsFolder = collect($json['item'])->firstWhere('name', 'exportPosts');
        $this->assertNotNull($exportPostsFolder);
        $actionNames = array_column($exportPostsFolder['item'], 'name');
        $this->assertContains('Index', $actionNames);
        $this->assertContains('Show', $actionNames);
        $this->assertContains('Store', $actionNames);

        @unlink($path);
    }

    public function test_index_has_query_builder_examples(): void
    {
        $path = sys_get_temp_dir() . '/postman_export_test_' . uniqid() . '.json';
        Artisan::call('lumina:export-postman', ['--output' => $path]);

        $json = json_decode(File::get($path), true);
        $exportPostsFolder = collect($json['item'])->firstWhere('name', 'exportPosts');
        $this->assertNotNull($exportPostsFolder);
        $indexFolder = collect($exportPostsFolder['item'])->firstWhere('name', 'Index');
        $this->assertNotNull($indexFolder);
        $requestNames = array_column($indexFolder['item'], 'name');
        $this->assertContains('List all', $requestNames);
        $this->assertContains('Filter by title', $requestNames);
        $this->assertContains('Sort by title (asc)', $requestNames);
        $this->assertContains('Include blog', $requestNames);
        $this->assertContains('Select fields', $requestNames);
        $this->assertContains('Search', $requestNames);
        $this->assertContains('Paginate', $requestNames);
        $this->assertContains('Combined', $requestNames);

        @unlink($path);
    }

    public function test_soft_delete_actions_appear_only_for_soft_deletes_models(): void
    {
        $path = sys_get_temp_dir() . '/postman_export_test_' . uniqid() . '.json';
        Artisan::call('lumina:export-postman', ['--output' => $path]);

        $json = json_decode(File::get($path), true);
        $regularFolder = collect($json['item'])->firstWhere('name', 'exportPosts');
        $softFolder = collect($json['item'])->firstWhere('name', 'exportPostsSoft');

        $regularActionNames = array_column($regularFolder['item'] ?? [], 'name');
        $this->assertNotContains('Trashed', $regularActionNames);
        $this->assertNotContains('Restore', $regularActionNames);
        $this->assertNotContains('Force Delete', $regularActionNames);

        $softActionNames = array_column($softFolder['item'] ?? [], 'name');
        $this->assertContains('Trashed', $softActionNames);
        $this->assertContains('Restore', $softActionNames);
        $this->assertContains('Force Delete', $softActionNames);

        @unlink($path);
    }

    public function test_except_actions_are_excluded(): void
    {
        $path = sys_get_temp_dir() . '/postman_export_test_' . uniqid() . '.json';
        Artisan::call('lumina:export-postman', ['--output' => $path]);

        $json = json_decode(File::get($path), true);
        $exceptFolder = collect($json['item'])->firstWhere('name', 'exportPostsExcept');
        $this->assertNotNull($exceptFolder);
        $actionNames = array_column($exceptFolder['item'] ?? [], 'name');
        $this->assertNotContains('Destroy', $actionNames);
        $this->assertNotContains('Update', $actionNames);
        $this->assertContains('Index', $actionNames);
        $this->assertContains('Show', $actionNames);
        $this->assertContains('Store', $actionNames);

        @unlink($path);
    }

    public function test_all_requests_have_bearer_token_header(): void
    {
        $path = sys_get_temp_dir() . '/postman_export_test_' . uniqid() . '.json';
        Artisan::call('lumina:export-postman', ['--output' => $path]);

        $json = json_decode(File::get($path), true);
        $exportPostsFolder = collect($json['item'])->firstWhere('name', 'exportPosts');
        $indexFolder = collect($exportPostsFolder['item'])->firstWhere('name', 'Index');
        $listAllRequest = $indexFolder['item'][0]['request'] ?? null;
        $this->assertNotNull($listAllRequest);
        $headers = $listAllRequest['header'] ?? [];
        $authHeader = collect($headers)->firstWhere('key', 'Authorization');
        $this->assertNotNull($authHeader);
        $this->assertSame('Bearer {{token}}', $authHeader['value']);

        @unlink($path);
    }

    public function test_non_multi_tenant_urls_omit_organization_prefix(): void
    {
        $path = sys_get_temp_dir() . '/postman_export_test_' . uniqid() . '.json';
        Artisan::call('lumina:export-postman', [
            '--output' => $path,
            '--base-url' => 'http://localhost:8000/api',
        ]);

        $json = json_decode(File::get($path), true);
        $vars = collect($json['variable'])->pluck('key')->toArray();
        $this->assertNotContains('organization', $vars);

        $exportPostsFolder = collect($json['item'])->firstWhere('name', 'exportPosts');
        $indexFolder = collect($exportPostsFolder['item'])->firstWhere('name', 'Index');
        $listAll = $indexFolder['item'][0];
        $rawUrl = $listAll['request']['url']['raw'] ?? '';
        $this->assertStringNotContainsString('organization', $rawUrl);
        $this->assertStringContainsString('/exportPosts', $rawUrl);

        @unlink($path);
    }

    public function test_store_request_has_body_from_validation_rules(): void
    {
        $path = sys_get_temp_dir() . '/postman_export_test_' . uniqid() . '.json';
        Artisan::call('lumina:export-postman', ['--output' => $path]);

        $json = json_decode(File::get($path), true);
        $exportPostsFolder = collect($json['item'])->firstWhere('name', 'exportPosts');
        $this->assertNotNull($exportPostsFolder);
        $storeFolder = collect($exportPostsFolder['item'])->firstWhere('name', 'Store');
        $this->assertNotNull($storeFolder);
        $createRequest = $storeFolder['item'][0]['request'] ?? null;
        $this->assertNotNull($createRequest);
        $this->assertArrayHasKey('body', $createRequest);
        $this->assertSame('raw', $createRequest['body']['mode']);
        $body = json_decode($createRequest['body']['raw'], true);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('title', $body);

        @unlink($path);
    }

    public function test_collection_variables_include_base_url_and_model_id(): void
    {
        $path = sys_get_temp_dir() . '/postman_export_test_' . uniqid() . '.json';
        Artisan::call('lumina:export-postman', [
            '--output' => $path,
            '--base-url' => 'https://api.example.com/v1',
        ]);

        $json = json_decode(File::get($path), true);
        $vars = collect($json['variable'])->keyBy('key');
        $this->assertSame('https://api.example.com/v1', $vars['baseUrl']['value']);
        $this->assertArrayHasKey('modelId', $vars->toArray());

        @unlink($path);
    }
}
