# Getting Started with Laravel Global Controller

This guide will walk you through installing and setting up Laravel Global Controller to automatically generate CRUD API endpoints for your Eloquent models.

## Prerequisites

Before you begin, ensure you have:

- **PHP 8.0+**
- **Laravel 10+**
- **Composer** installed
- Basic knowledge of Laravel, Eloquent, and Policies

## Installation

### Option 1: Local Package (Development)

For development or when contributing to the package:

**1. Add to your `composer.json`:**

```json
{
    "require": {
        "lumina/laravel-api": "dev-main"
    },
    "repositories": [
        {
            "type": "path",
            "url": "./packages/Startsoft/laravel-global-controller"
        }
    ]
}
```

**2. Create the directory and add the package:**

```bash
mkdir -p packages/Startsoft/laravel-global-controller
# Clone or copy the package files into this directory
```

**3. Install dependencies:**

```bash
composer update
```

**4. Publish configuration and routes:**

```bash
php artisan lumina:publish
```

This publishes:
- `config/lumina.php` - Package configuration
- `routes/global-routes.php` - Auto-generated routes file
- `.cursor/` - AI toolkit (optional)

### Option 2: From Repository (Production)

```bash
composer require lumina/laravel-api
php artisan lumina:publish
```

## Quick Start

Let's create a complete CRUD API for a `Post` model in 4 steps:

### Step 1: Create Migration and Model

```bash
php artisan make:model Post -m
```

**Database migration** (`database/migrations/xxxx_create_posts_table.php`):

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('content');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->boolean('is_published')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posts');
    }
};
```

Run the migration:

```bash
php artisan migrate
```

### Step 2: Configure Your Model

**app/Models/Post.php:**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Lumina\LaravelApi\Traits\HasValidation;
use Lumina\LaravelApi\Traits\HidableColumns;

class Post extends Model
{
    use SoftDeletes, HasValidation, HidableColumns;

    protected $fillable = [
        'title',
        'content',
        'user_id',
        'is_published',
    ];

    // Validation rules (format/type only)
    protected $validationRules = [
        'title' => 'string|max:255',
        'content' => 'string',
        'user_id' => 'exists:users,id',
        'is_published' => 'boolean',
    ];

    // Fields to validate on create (required/optional)
    protected $validationRulesStore = [
        'title' => 'required',
        'content' => 'required',
        'user_id' => 'required',
        'is_published' => 'nullable',
    ];

    // Fields to validate on update
    protected $validationRulesUpdate = [
        'title' => 'sometimes',
        'content' => 'sometimes',
        'is_published' => 'sometimes',
    ];

    // Query Builder configuration
    public static $allowedFilters = ['title', 'user_id', 'is_published'];
    public static $allowedSorts = ['created_at', 'title', 'updated_at'];
    public static $defaultSort = '-created_at';
    public static $allowedFields = ['id', 'title', 'content', 'user_id', 'is_published', 'created_at'];
    public static $allowedIncludes = ['user'];
    public static $allowedSearch = ['title', 'content'];

    // Enable pagination by default
    public static bool $paginationEnabled = true;
    protected $perPage = 20;

    // Relationships
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
```

### Step 3: Create a Policy

```bash
php artisan make:policy PostPolicy --model=Post
```

**app/Policies/PostPolicy.php:**

```php
<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Post;
use Lumina\LaravelApi\Policies\ResourcePolicy;
use Illuminate\Contracts\Auth\Authenticatable;

class PostPolicy extends ResourcePolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(?Authenticatable $user): bool
    {
        return true; // Public endpoint
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(?Authenticatable $user, Post $post): bool
    {
        // Anyone can view published posts, only authors can view drafts
        if ($post->is_published) {
            return true;
        }

        return $user && $user->id === $post->user_id;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(?Authenticatable $user): bool
    {
        return $user !== null; // Must be authenticated
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(?Authenticatable $user, Post $post): bool
    {
        // Only post author can update
        return $user && $user->id === $post->user_id;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(?Authenticatable $user, Post $post): bool
    {
        // Only post author can delete
        return $user && $user->id === $post->user_id;
    }

    /**
     * Define columns to hide based on user role.
     */
    public function hiddenColumns(?Authenticatable $user): array
    {
        // Hide user_id for non-authenticated users
        if (!$user) {
            return ['user_id'];
        }

        return [];
    }
}
```

**Register the policy** in `app/Providers/AuthServiceProvider.php`:

```php
<?php

namespace App\Providers;

use App\Models\Post;
use App\Policies\PostPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [
        Post::class => PostPolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();
    }
}
```

### Step 4: Register the Model

Edit `config/lumina.php`:

```php
<?php

return [
    'models' => [
        'users' => \App\Models\User::class,
        'posts' => \App\Models\Post::class,  // Add your model here
    ],

    'public' => [
        // 'posts', // Uncomment to make posts public (no auth required)
    ],

    // ... other config
];
```

### That's It!

Your API endpoints are now available:

**Standard CRUD:**
```
GET    /api/posts              - List all posts
POST   /api/posts              - Create a post
GET    /api/posts/{id}         - Get a specific post
PUT    /api/posts/{id}         - Update a post
DELETE /api/posts/{id}         - Soft delete a post
```

**Soft Delete Endpoints** (because Post uses `SoftDeletes`):
```
GET    /api/posts/trashed      - List soft-deleted posts
POST   /api/posts/{id}/restore - Restore a post
DELETE /api/posts/{id}/force-delete - Permanently delete
```

## Testing Your API

### Using cURL

**List all posts:**
```bash
curl http://localhost:8000/api/posts
```

**Filter and sort:**
```bash
curl "http://localhost:8000/api/posts?filter[is_published]=true&sort=-created_at"
```

**Create a post:**
```bash
curl -X POST http://localhost:8000/api/posts \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d '{
    "title": "My First Post",
    "content": "This is the content",
    "user_id": 1,
    "is_published": true
  }'
```

**Update a post:**
```bash
curl -X PUT http://localhost:8000/api/posts/1 \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d '{
    "title": "Updated Title"
  }'
```

**Delete a post (soft delete):**
```bash
curl -X DELETE http://localhost:8000/api/posts/1 \
  -H "Authorization: Bearer YOUR_TOKEN"
```

### Generate Postman Collection

Generate a complete Postman collection with all endpoints:

```bash
php artisan lumina:export-postman \
  --output=postman_collection.json \
  --base-url=http://localhost:8000/api \
  --project-name="My API"
```

Import the generated JSON file into Postman and test all endpoints interactively.

## Authentication Setup

The package includes built-in authentication endpoints. To use them:

### 1. Configure Laravel Sanctum

```bash
php artisan install:api
```

This creates the `personal_access_tokens` table migration.

### 2. Update User Model

**app/Models/User.php:**

```php
<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens;

    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];
}
```

### 3. Authentication Endpoints

**Login:**
```bash
curl -X POST http://localhost:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "user@example.com",
    "password": "password"
  }'
```

**Response:**
```json
{
  "user": {
    "id": 1,
    "name": "John Doe",
    "email": "user@example.com"
  },
  "token": "1|abcdefg..."
}
```

**Use the token in subsequent requests:**
```bash
curl http://localhost:8000/api/posts \
  -H "Authorization: Bearer 1|abcdefg..."
```

## Next Steps

Now that you have a basic setup, explore these guides:

- **[API Reference](./API.md)** - Complete endpoint documentation
- **[Query Builder](./features/query-builder.md)** - Filtering, sorting, includes
- **[Authentication](./features/authentication.md)** - Login, logout, password recovery
- **[Authorization](./features/authorization.md)** - Permissions and policies
- **[Validation](./features/validation.md)** - Request validation and rules
- **[Soft Deletes](./features/soft-deletes.md)** - Trash and restore
- **[Pagination](./features/pagination.md)** - Paginated responses
- **[Multi-Tenancy](./features/multi-tenancy.md)** - Organization-based data isolation

## Troubleshooting

### Routes Not Found

Make sure `routes/global-routes.php` is included in your `routes/api.php`:

```php
// routes/api.php

// Your custom routes here (optional)

// Auto-generated routes
require base_path('routes/global-routes.php');
```

### Policy Not Applied

Ensure your policy is registered in `AuthServiceProvider`:

```php
protected $policies = [
    Post::class => PostPolicy::class,
];
```

### Validation Errors

Check that your model has both `$validationRules` and `$validationRulesStore`/`$validationRulesUpdate` defined.

### CORS Issues

Configure CORS in `config/cors.php`:

```php
'paths' => ['api/*'],
'allowed_origins' => ['*'], // Or specific frontend URL
'allowed_methods' => ['*'],
'allowed_headers' => ['*'],
'supports_credentials' => true,
```

## Support

- **Documentation**: [Full Docs](../README.md)
- **Issues**: Report bugs in your project repository
- **Package Structure**: See [Package Structure](../README.md#-package-structure)
