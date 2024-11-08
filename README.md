# Lumina Laravel API

> Automatic REST API generation for Laravel Eloquent models with built-in security, validation, and advanced querying.

[![PHP Version](https://img.shields.io/badge/php-8.0%2B-blue)](https://www.php.net/)
[![Laravel Version](https://img.shields.io/badge/laravel-10%2B-red)](https://laravel.com/)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

## ✨ Features

- 🚀 **Automatic CRUD API** - Register a model, get full REST endpoints instantly
- 🔐 **Built-in Authentication** - Login, logout, password recovery out of the box
- 🛡️ **Authorization** - Laravel Policy integration with permission-based access control
- ✅ **Validation** - Role-based validation rules with automatic request validation
- 🔍 **Advanced Querying** - Filtering, sorting, search, pagination, field selection
- 🗑️ **Soft Deletes** - Automatic trash, restore, and force-delete endpoints
- 🔄 **Nested Operations** - Multi-model atomic transactions in single request
- 📝 **Audit Trail** - Automatic change logging for compliance
- 🏢 **Multi-Tenancy** - Organization-based data isolation built-in
- 📧 **Invitations** - User invitation system with email workflow
- ⚡ **High Performance** - Header-based pagination, query optimization
- 🎯 **Type-Safe** - Model-driven API with explicit route registration

## 📦 Installation

```bash
composer require startsoft/lumina dev-main
php artisan lumina:install
```

The interactive installer will guide you through configuring:

- **Config & routes** - Publishes `config/lumina.php` and route files
- **Multi-tenant support** - Organizations, roles, middleware, and seeders
- **Audit trail** - Change logging migration
- **Cursor AI toolkit** - Rules, skills, and agents for AI-assisted development

```
  ██╗     ██╗   ██╗███╗   ███╗██╗███╗   ██╗ █████╗
  ██║     ██║   ██║████╗ ████║██║████╗  ██║██╔══██╗
  ██║     ██║   ██║██╔████╔██║██║██╔██╗ ██║███████║
  ██║     ██║   ██║██║╚██╔╝██║██║██║╚██╗██║██╔══██║
  ███████╗╚██████╔╝██║ ╚═╝ ██║██║██║ ╚████║██║  ██║
  ╚══════╝ ╚═════╝ ╚═╝     ╚═╝╚═╝╚═╝  ╚═══╝╚═╝  ╚═╝

 + Lumina :: Install :: Let's build something great +

 ┌ Which features would you like to configure? ───────┐
 │ ◼ Publish config & routes                          │
 │ ◻ Multi-tenant support (Organizations, Roles)      │
 │ ◻ Audit trail (change logging)                     │
 │ ◻ Cursor AI toolkit (rules, skills, agents)        │
 └────────────────────────────────────────────────────┘
```

## 🚀 Quick Start

### 1. Register Your Model

Edit `config/lumina.php`:

```php
return [
    'models' => [
        'posts' => \App\Models\Post::class,
    ],
];
```

### 2. Add Validation Trait

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Lumina\LaravelApi\Traits\HasValidation;

class Post extends Model
{
    use HasValidation;

    protected $fillable = ['title', 'content', 'user_id'];

    // Validation rules
    protected $validationRules = [
        'title' => 'string|max:255',
        'content' => 'string',
        'user_id' => 'exists:users,id',
    ];

    protected $validationRulesStore = [
        'title' => 'required',
        'content' => 'required',
        'user_id' => 'required',
    ];

    protected $validationRulesUpdate = [
        'title' => 'sometimes',
        'content' => 'sometimes',
    ];

    // Query Builder configuration
    public static $allowedFilters = ['title', 'user_id'];
    public static $allowedSorts = ['created_at', 'title'];
    public static $defaultSort = '-created_at';
    public static $allowedIncludes = ['user'];
}
```

### 3. Create Policy

```bash
php artisan make:policy PostPolicy --model=Post
```

```php
<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Post;
use Lumina\LaravelApi\Policies\ResourcePolicy;

class PostPolicy extends ResourcePolicy
{
    public function viewAny(?User $user) { return true; }
    public function view(?User $user, Post $post) { return true; }
    public function create(?User $user) { return $user !== null; }
    public function update(?User $user, Post $post) {
        return $user && $user->id === $post->user_id;
    }
    public function delete(?User $user, Post $post) {
        return $user && $user->id === $post->user_id;
    }
}
```

### 4. Done! 🎉

Your API endpoints are now available:

```bash
GET    /api/posts              # List posts
POST   /api/posts              # Create post
GET    /api/posts/{id}         # Show post
PUT    /api/posts/{id}         # Update post
DELETE /api/posts/{id}         # Delete post
```

## 📋 Requirements

- **PHP:** 8.0+
- **Laravel:** 10+
- **Spatie Query Builder:** ^6.2

## 📚 Documentation

### Getting Started

- **[Installation & Setup](./docs/getting-started.md)** - Complete installation guide and first steps
- **[API Reference](./docs/API.md)** - Full endpoint documentation with examples

### Core Features

| Feature | Documentation |
|---------|---------------|
| 🔐 **Authentication** | [Authentication Guide](./docs/features/authentication.md) - Login, logout, password recovery |
| 🛡️ **Authorization** | [Authorization Guide](./docs/features/authorization.md) - Permissions and policies |
| ✅ **Validation** | [Validation Guide](./docs/features/validation.md) - Role-based validation |
| 🔍 **Query Builder** | [Query Builder Guide](./docs/features/query-builder.md) - Filtering, sorting, includes |
| 📄 **Pagination** | [Pagination Guide](./docs/features/pagination.md) - Header-based pagination |
| 🗑️ **Soft Deletes** | [Soft Deletes Guide](./docs/features/soft-deletes.md) - Trash and restore |
| 📝 **Audit Trail** | [Audit Trail Guide](./docs/features/audit-trail.md) - Change tracking |
| 🔄 **Nested Operations** | [Nested Operations Guide](./docs/features/nested-operations.md) - Multi-model transactions |
| 🏢 **Multi-Tenancy** | [Multi-Tenancy Guide](./docs/features/multi-tenancy.md) - Organization isolation |
| 📧 **Invitations** | [Invitations Guide](./docs/features/invitations.md) - User invitation system |

## 🎯 Key Concepts

### Automatic CRUD Generation

Register a model and get full REST API endpoints with zero controller code:

```php
// config/lumina.php
'models' => [
    'posts' => \App\Models\Post::class,
],
```

All routes are explicitly registered and visible via `php artisan route:list`.

### Permission-Based Authorization

Use Laravel policies with convention-based permissions stored in JSON:

```php
// Permission format: {slug}.{action}
'permissions' => [
    'posts.index',   // Can list posts
    'posts.store',   // Can create posts
    'posts.*',       // All post actions
    '*',             // All permissions
]
```

### Advanced Query Builder

Built on Spatie Query Builder with include authorization:

```bash
# Complex query in single request
GET /api/posts?filter[status]=published&include=user,comments&sort=-created_at&per_page=20

# Include authorization - returns 403 if user cannot view comments
GET /api/posts?include=comments
```

### Role-Based Validation

Different validation rules per user role:

```php
protected $validationRulesStore = [
    'admin' => [
        'title' => 'required',
        'content' => 'required',
        'is_published' => 'nullable',  // Admins can publish
    ],
    'contributor' => [
        'title' => 'required',
        'content' => 'required',
        // Contributors cannot set is_published
    ],
];
```

### Multi-Model Transactions

Execute multiple operations atomically:

```bash
POST /api/nested
{
  "operations": [
    {
      "model": "blogs",
      "action": "create",
      "data": {"title": "My Blog"}
    },
    {
      "model": "posts",
      "action": "create",
      "data": {"blog_id": 1, "title": "First Post"}
    }
  ]
}
```

### Audit Trail

Automatic change tracking:

```php
use Lumina\LaravelApi\Traits\HasAuditTrail;

class Post extends Model
{
    use HasAuditTrail;
}

// Automatically logs:
// - Created, updated, deleted, restored events
// - Old and new values
// - User, organization, IP, user agent
```

## 🔧 Configuration

### Model Configuration

```php
class Post extends Model
{
    use HasValidation;

    // Query Builder
    public static $allowedFilters = ['title', 'status', 'user_id'];
    public static $allowedSorts = ['created_at', 'updated_at', 'title'];
    public static $defaultSort = '-created_at';
    public static $allowedFields = ['id', 'title', 'content'];
    public static $allowedIncludes = ['user', 'comments'];
    public static $allowedSearch = ['title', 'content'];

    // Pagination
    public static bool $paginationEnabled = true;
    protected $perPage = 25;

    // Middleware
    public static array $middleware = ['throttle:60,1'];
    public static array $middlewareActions = [
        'store' => ['verified'],
        'update' => ['verified'],
    ];

    // Exclude actions
    public static array $exceptActions = ['destroy'];
}
```

### Global Configuration

Edit `config/lumina.php`:

```php
return [
    // Model registration
    'models' => [
        'posts' => \App\Models\Post::class,
        'comments' => \App\Models\Comment::class,
    ],

    // Public endpoints (no auth required)
    'public' => ['posts'],

    // Nested operations
    'nested_operations' => [
        'enabled' => true,
        'max_operations' => 10,
        'allowed_models' => ['blogs', 'posts', 'comments'],
    ],

    // Authentication
    'auth' => [
        'login_route' => 'api/auth/login',
        'logout_route' => 'api/auth/logout',
        'password_recovery_enabled' => true,
        'registration_enabled' => true,
    ],
];
```

## 📖 Examples

### Filtering

```bash
# Single filter
GET /api/posts?filter[is_published]=true

# Multiple filters (AND)
GET /api/posts?filter[is_published]=true&filter[user_id]=1

# Multiple values (OR)
GET /api/posts?filter[status]=draft,published
```

### Sorting

```bash
# Ascending
GET /api/posts?sort=title

# Descending
GET /api/posts?sort=-created_at

# Multiple sorts
GET /api/posts?sort=-is_published,created_at
```

### Includes (Eager Loading)

```bash
# Single relationship
GET /api/posts?include=user

# Multiple relationships
GET /api/posts?include=user,comments,tags

# Nested relationships
GET /api/posts?include=comments.user
```

### Pagination

```bash
# On-demand pagination
GET /api/posts?per_page=20&page=2

# With other query features
GET /api/posts?filter[status]=published&sort=-created_at&include=user&per_page=20
```

**Pagination metadata in headers:**
```
X-Current-Page: 2
X-Last-Page: 10
X-Per-Page: 20
X-Total: 195
```

### Field Selection

```bash
# Select specific fields
GET /api/posts?fields[posts]=id,title,created_at

# With relationships
GET /api/posts?include=user&fields[posts]=id,title&fields[users]=id,name
```

### Search

```bash
# Full-text search
GET /api/posts?search=laravel

# Search with filters
GET /api/posts?search=tutorial&filter[is_published]=true
```

## 🏗️ Architecture

### Request Flow

```
1. Request → Middleware (auth, organization context)
   ↓
2. GlobalController resolves model
   ↓
3. Policy authorization check
   ↓
4. Query Builder applies filters/sorts/includes
   ↓
5. Include authorization (per relationship)
   ↓
6. Model validation (create/update)
   ↓
7. Database operation
   ↓
8. Audit logging (if enabled)
   ↓
9. Response with data + pagination headers
```

### Built With

- **[Laravel](https://laravel.com/)** - PHP framework
- **[Spatie Query Builder](https://github.com/spatie/laravel-query-builder)** - Advanced querying
- **[Laravel Sanctum](https://laravel.com/docs/sanctum)** - API authentication
- **Laravel Policies** - Authorization
- **Eloquent ORM** - Database operations

## 🛡️ Security

### Authorization

All endpoints are protected by Laravel policies:

```php
// Automatically checks:
// - viewAny() for index
// - view() for show
// - create() for store
// - update() for update
// - delete() for delete/destroy
```

### Validation

All create/update requests validated:

```php
// Prevents invalid data from reaching database
// Role-based rules for different user types
// Custom error messages
```

### Query Scopes

Add automatic filtering:

```php
protected static function booted()
{
    static::addGlobalScope(new OrganizationScope);
}
```

### Rate Limiting

Per-model rate limiting:

```php
public static array $middleware = ['throttle:60,1'];
```

## 🧪 Testing

### Example Tests

```php
use Tests\TestCase;

class PostApiTest extends TestCase
{
    public function test_can_list_posts()
    {
        Post::factory()->count(5)->create();

        $response = $this->getJson('/api/posts');

        $response->assertOk()
            ->assertJsonCount(5);
    }

    public function test_can_filter_posts()
    {
        Post::factory()->create(['is_published' => true]);
        Post::factory()->create(['is_published' => false]);

        $response = $this->getJson('/api/posts?filter[is_published]=true');

        $response->assertOk()
            ->assertJsonCount(1);
    }

    public function test_cannot_create_post_without_auth()
    {
        $response = $this->postJson('/api/posts', [
            'title' => 'Test',
            'content' => 'Content',
        ]);

        $response->assertStatus(401);
    }

    public function test_can_include_relationships()
    {
        $post = Post::factory()->create();

        $response = $this->getJson('/api/posts?include=user');

        $response->assertOk()
            ->assertJsonStructure([
                '*' => ['id', 'title', 'user' => ['id', 'name']]
            ]);
    }
}
```

## 📊 Performance

### Optimizations

- **Lazy Loading Prevention** - Use `$allowedIncludes` for explicit relationships
- **Query Optimization** - Spatie Query Builder optimizes SQL queries
- **Pagination** - Limits result sets with configurable defaults
- **Field Selection** - Reduces payload size by selecting specific fields
- **Header-Based Pagination** - Keeps response body clean and consistent

### Caching

Add caching to expensive queries:

```php
public function index(Request $request)
{
    $cacheKey = 'posts_' . md5($request->fullUrl());

    return Cache::remember($cacheKey, 60, function () use ($request) {
        return Post::query()
            ->allowedFilters(['title', 'status'])
            ->paginate(20);
    });
}
```

## ⚙️ Artisan Commands

| Command | Description |
|---------|-------------|
| `lumina:install` | Interactive installer — publish config, enable multi-tenancy, audit trail, and Cursor AI toolkit |
| `lumina:generate` (`lumina:g`) | Interactive generator — scaffold Models (with migration, factory, config registration), Policies, and Scopes |
| `lumina:export-postman` | Generate a Postman Collection v2.1 for all registered models |
| `invitation:link` | Generate an invitation link for testing |

## 🤝 Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

### Development Setup

```bash
# Clone repository
git clone https://github.com/startsoft-dev/lumina-server.git

# Install dependencies
composer install

# Run tests
php artisan test
```

## 📄 License

This package is open-sourced software licensed under the [MIT license](LICENSE).

## 🔗 Links

- **[Documentation](./docs/)** - Complete documentation
- **[API Reference](./docs/API.md)** - Endpoint reference
- **[Changelog](./CHANGELOG.md)** - Version history
- **[Issues](https://github.com/startsoft-dev/lumina-server/issues)** - Report bugs
- **[Discussions](https://github.com/startsoft-dev/lumina-server/discussions)** - Ask questions

## 🙏 Credits

- Built with [Laravel](https://laravel.com/)
- Query building powered by [Spatie Query Builder](https://github.com/spatie/laravel-query-builder)
- Inspired by best practices from the Laravel community

---

Made with ❤️ by [Startsoft](https://github.com/startsoft-dev)
