# Nested Operations

Execute multiple CRUD operations in a single atomic transaction, allowing complex multi-model workflows to succeed or fail together.

## Table of Contents

- [Overview](#overview)
- [Basic Usage](#basic-usage)
- [Operation Types](#operation-types)
- [Transaction Behavior](#transaction-behavior)
- [Validation](#validation)
- [Authorization](#authorization)
- [Configuration](#configuration)
- [Real-World Examples](#real-world-examples)
- [Error Handling](#error-handling)
- [Best Practices](#best-practices)

---

## Overview

Nested operations allow you to execute multiple creates and updates across different models in a single API request, wrapped in a database transaction.

**Use Cases:**
- Creating related records together (blog + posts)
- Multi-step workflows (order + items + payment)
- Complex data entry forms
- Batch operations with dependencies
- Atomic updates across multiple models

**Benefits:**
- **Atomicity** - All operations succeed or all fail
- **Performance** - Single HTTP request instead of multiple
- **Consistency** - Ensures related data is always in sync
- **Simplicity** - No need to manage dependencies client-side

**Endpoint:**
```
POST /api/nested
POST /api/{organization}/nested
```

---

## Basic Usage

### Simple Create Operations

**Request:**
```bash
curl -X POST "http://localhost:8000/api/nested" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer TOKEN" \
  -d '{
    "operations": [
      {
        "model": "blogs",
        "action": "create",
        "data": {
          "title": "My Tech Blog",
          "description": "A blog about technology"
        }
      },
      {
        "model": "posts",
        "action": "create",
        "data": {
          "blog_id": 1,
          "title": "First Post",
          "content": "Welcome to my blog!"
        }
      }
    ]
  }'
```

**Response (200 OK):**
```json
{
  "results": [
    {
      "model": "blogs",
      "action": "create",
      "id": 1,
      "data": {
        "id": 1,
        "title": "My Tech Blog",
        "description": "A blog about technology",
        "created_at": "2024-01-16T10:00:00.000000Z",
        "updated_at": "2024-01-16T10:00:00.000000Z"
      }
    },
    {
      "model": "posts",
      "action": "create",
      "id": 5,
      "data": {
        "id": 5,
        "blog_id": 1,
        "title": "First Post",
        "content": "Welcome to my blog!",
        "created_at": "2024-01-16T10:00:00.000000Z",
        "updated_at": "2024-01-16T10:00:00.000000Z"
      }
    }
  ]
}
```

### Mixed Create and Update

**Request:**
```bash
curl -X POST "http://localhost:8000/api/nested" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer TOKEN" \
  -d '{
    "operations": [
      {
        "model": "posts",
        "action": "update",
        "id": 5,
        "data": {
          "title": "Updated Title"
        }
      },
      {
        "model": "comments",
        "action": "create",
        "data": {
          "post_id": 5,
          "content": "Great update!"
        }
      }
    ]
  }'
```

**Response:**
```json
{
  "results": [
    {
      "model": "posts",
      "action": "update",
      "id": 5,
      "data": {
        "id": 5,
        "title": "Updated Title",
        "updated_at": "2024-01-16T11:00:00.000000Z"
      }
    },
    {
      "model": "comments",
      "action": "create",
      "id": 12,
      "data": {
        "id": 12,
        "post_id": 5,
        "content": "Great update!",
        "created_at": "2024-01-16T11:00:00.000000Z"
      }
    }
  ]
}
```

---

## Operation Types

### Create Operation

Creates a new model instance.

**Structure:**
```json
{
  "model": "posts",
  "action": "create",
  "data": {
    "title": "New Post",
    "content": "Post content"
  }
}
```

**Required Fields:**
- `model` - Model name (plural, e.g., "posts", "users")
- `action` - Must be "create"
- `data` - Object with fields to create

**Validation:**
- Uses model's `$validationRulesStore`
- All required fields must be present
- Role-based validation applied

**Authorization:**
- Checks `store()` policy method
- Requires `{model}.store` permission

### Update Operation

Updates an existing model instance.

**Structure:**
```json
{
  "model": "posts",
  "action": "update",
  "id": 5,
  "data": {
    "title": "Updated Title"
  }
}
```

**Required Fields:**
- `model` - Model name
- `action` - Must be "update"
- `id` - ID of record to update
- `data` - Object with fields to update

**Validation:**
- Uses model's `$validationRulesUpdate`
- Only provided fields validated
- Role-based validation applied

**Authorization:**
- Checks `update()` policy method
- Requires `{model}.update` permission

---

## Transaction Behavior

### Atomic Operations

All operations are wrapped in a database transaction:

```php
DB::beginTransaction();
try {
    // Execute all operations
    foreach ($operations as $operation) {
        $result = executeOperation($operation);
        $results[] = $result;
    }
    DB::commit();
    return $results;
} catch (Exception $e) {
    DB::rollBack();
    throw $e;
}
```

**What this means:**
- If **any** operation fails, **all** operations are rolled back
- Database remains in consistent state
- No partial success possible
- Safe to use for critical workflows

### Example - All or Nothing

**Request:**
```json
{
  "operations": [
    {
      "model": "blogs",
      "action": "create",
      "data": {"title": "Blog"}
    },
    {
      "model": "posts",
      "action": "create",
      "data": {
        "blog_id": 999,
        "title": "Post"
      }
    }
  ]
}
```

**Result:**
- Blog creation succeeds initially
- Post creation fails (blog_id 999 doesn't exist)
- **Transaction rolls back**
- Blog is **not** created
- Database unchanged

**Response (422):**
```json
{
  "message": "Validation failed for operation 1",
  "errors": {
    "blog_id": ["The selected blog id is invalid."]
  }
}
```

### Order of Execution

Operations execute in the order provided:

```json
{
  "operations": [
    {"model": "blogs", "action": "create", ...},    // Runs first
    {"model": "posts", "action": "create", ...},    // Runs second
    {"model": "comments", "action": "create", ...}  // Runs third
  ]
}
```

**Important:**
- Cannot reference IDs from later operations
- Must structure operations in dependency order
- First operation cannot depend on second

---

## Validation

### Per-Operation Validation

Each operation is validated individually using its model's validation rules:

```php
// For create operations
$model::validateStore($data);

// For update operations
$model::validateUpdate($data);
```

### Validation Example

**Model setup:**
```php
class Post extends Model
{
    protected $validationRules = [
        'title' => 'string|max:255',
        'content' => 'string',
        'blog_id' => 'exists:blogs,id',
    ];

    protected $validationRulesStore = [
        'title' => 'required',
        'content' => 'required',
        'blog_id' => 'required',
    ];
}
```

**Request with validation errors:**
```json
{
  "operations": [
    {
      "model": "posts",
      "action": "create",
      "data": {
        "title": ""
      }
    }
  ]
}
```

**Response (422):**
```json
{
  "message": "Validation failed for operation 0",
  "errors": {
    "title": ["The title field is required."],
    "content": ["The content field is required."],
    "blog_id": ["The blog id field is required."]
  }
}
```

### Role-Based Validation

Role-based validation applies to nested operations:

```php
protected $validationRulesStore = [
    'admin' => [
        'title' => 'required',
        'content' => 'required',
        'is_published' => 'nullable',
    ],
    'contributor' => [
        'title' => 'required',
        'content' => 'required',
        // Cannot set is_published
    ],
];
```

**Admin request:**
```json
{
  "operations": [
    {
      "model": "posts",
      "action": "create",
      "data": {
        "title": "Post",
        "content": "Content",
        "is_published": true
      }
    }
  ]
}
```
✅ Succeeds - admin can set is_published

**Contributor request:**
```json
{
  "operations": [
    {
      "model": "posts",
      "action": "create",
      "data": {
        "title": "Post",
        "content": "Content",
        "is_published": true
      }
    }
  ]
}
```
✅ Succeeds - is_published ignored per role rules

---

## Authorization

### Per-Operation Authorization

Each operation checks permissions individually:

**Create operation:**
- Policy method: `store()`
- Required permission: `{model}.store`

**Update operation:**
- Policy method: `update()`
- Required permission: `{model}.update`

### Authorization Flow

```
1. Parse operations array
2. For each operation:
   a. Check user has permission for action
   b. If unauthorized, return 403
3. If all authorized, execute in transaction
```

### Example - Mixed Permissions

**User permissions:**
```php
['posts.store', 'posts.update']
// Can create and update posts
// Cannot create blogs
```

**Request:**
```json
{
  "operations": [
    {
      "model": "blogs",
      "action": "create",
      "data": {"title": "Blog"}
    },
    {
      "model": "posts",
      "action": "create",
      "data": {"title": "Post"}
    }
  ]
}
```

**Response (403 Forbidden):**
```json
{
  "message": "You do not have permission to perform operation 0 (create blogs)"
}
```

**Important:**
- Authorization checked **before** transaction starts
- No database changes if any operation unauthorized
- Clear error indicates which operation failed

### Custom Authorization Logic

Override policy methods for custom logic:

```php
class PostPolicy extends ResourcePolicy
{
    public function store(?Authenticatable $user): bool
    {
        if (!parent::store($user)) {
            return false;
        }

        // Custom logic: limit posts per day
        if ($user) {
            $todayPosts = Post::where('user_id', $user->id)
                ->whereDate('created_at', today())
                ->count();

            return $todayPosts < 10;
        }

        return false;
    }
}
```

This applies to nested operations too.

---

## Configuration

Configure nested operations in `config/lumina.php`:

```php
return [
    'nested_operations' => [
        // Maximum number of operations per request
        'max_operations' => 10,

        // Models allowed in nested operations
        'allowed_models' => [
            'blogs',
            'posts',
            'comments',
            'tags',
            'categories',
        ],

        // Enable/disable feature
        'enabled' => true,
    ],
];
```

### Max Operations

Limit operations per request to prevent abuse:

```php
'max_operations' => 5,
```

**Request with 6 operations:**
```json
{
  "operations": [
    {...}, {...}, {...}, {...}, {...}, {...}
  ]
}
```

**Response (422):**
```json
{
  "message": "Maximum 5 operations allowed per request"
}
```

### Allowed Models

Restrict which models can be used:

```php
'allowed_models' => ['posts', 'comments'],
```

**Request with disallowed model:**
```json
{
  "operations": [
    {
      "model": "users",
      "action": "create",
      "data": {...}
    }
  ]
}
```

**Response (422):**
```json
{
  "message": "Model 'users' is not allowed in nested operations"
}
```

### Disable Feature

Completely disable nested operations:

```php
'nested_operations' => [
    'enabled' => false,
],
```

**Any nested request:**
```
POST /api/nested
```

**Response (404 Not Found):**
Route not registered when disabled.

---

## Real-World Examples

### Example 1: Blog with Posts

Create a blog and its first posts together:

```json
{
  "operations": [
    {
      "model": "blogs",
      "action": "create",
      "data": {
        "title": "Tech Blog",
        "description": "All about web development",
        "user_id": 1
      }
    },
    {
      "model": "posts",
      "action": "create",
      "data": {
        "blog_id": 1,
        "title": "Getting Started with Laravel",
        "content": "Laravel is a powerful PHP framework..."
      }
    },
    {
      "model": "posts",
      "action": "create",
      "data": {
        "blog_id": 1,
        "title": "Understanding MVC",
        "content": "MVC stands for Model-View-Controller..."
      }
    }
  ]
}
```

### Example 2: Order with Items

Create order and line items atomically:

```json
{
  "operations": [
    {
      "model": "orders",
      "action": "create",
      "data": {
        "user_id": 1,
        "total": 150.00,
        "status": "pending"
      }
    },
    {
      "model": "order_items",
      "action": "create",
      "data": {
        "order_id": 1,
        "product_id": 10,
        "quantity": 2,
        "price": 50.00
      }
    },
    {
      "model": "order_items",
      "action": "create",
      "data": {
        "order_id": 1,
        "product_id": 15,
        "quantity": 1,
        "price": 50.00
      }
    }
  ]
}
```

### Example 3: Publish Post with Updates

Update post and create notification:

```json
{
  "operations": [
    {
      "model": "posts",
      "action": "update",
      "id": 5,
      "data": {
        "is_published": true,
        "published_at": "2024-01-16T10:00:00Z"
      }
    },
    {
      "model": "notifications",
      "action": "create",
      "data": {
        "user_id": 1,
        "type": "post_published",
        "data": {
          "post_id": 5,
          "message": "Your post has been published"
        }
      }
    }
  ]
}
```

### Example 4: Bulk Create

Create multiple records of same model:

```json
{
  "operations": [
    {
      "model": "tags",
      "action": "create",
      "data": {"name": "Laravel"}
    },
    {
      "model": "tags",
      "action": "create",
      "data": {"name": "PHP"}
    },
    {
      "model": "tags",
      "action": "create",
      "data": {"name": "Web Development"}
    },
    {
      "model": "tags",
      "action": "create",
      "data": {"name": "Tutorial"}
    }
  ]
}
```

### Example 5: Complex Workflow

Multi-step user onboarding:

```json
{
  "operations": [
    {
      "model": "user_profiles",
      "action": "update",
      "id": 10,
      "data": {
        "onboarding_completed": true,
        "completed_at": "2024-01-16T10:00:00Z"
      }
    },
    {
      "model": "user_settings",
      "action": "create",
      "data": {
        "user_id": 10,
        "notifications_enabled": true,
        "theme": "dark"
      }
    },
    {
      "model": "activity_logs",
      "action": "create",
      "data": {
        "user_id": 10,
        "action": "onboarding_completed",
        "ip_address": "192.168.1.1"
      }
    }
  ]
}
```

---

## Error Handling

### Validation Errors

**Request:**
```json
{
  "operations": [
    {
      "model": "posts",
      "action": "create",
      "data": {"title": ""}
    }
  ]
}
```

**Response (422):**
```json
{
  "message": "Validation failed for operation 0",
  "errors": {
    "title": ["The title field is required."],
    "content": ["The content field is required."]
  }
}
```

**Error structure:**
- `message` - Indicates which operation failed (0-indexed)
- `errors` - Validation errors for that operation

### Authorization Errors

**Response (403):**
```json
{
  "message": "You do not have permission to perform operation 1 (update posts)"
}
```

### Not Found Errors

**Request:**
```json
{
  "operations": [
    {
      "model": "posts",
      "action": "update",
      "id": 999,
      "data": {"title": "Updated"}
    }
  ]
}
```

**Response (404):**
```json
{
  "message": "Resource not found for operation 0"
}
```

### Structure Errors

**Missing required fields:**
```json
{
  "operations": [
    {
      "model": "posts",
      "data": {"title": "Post"}
    }
  ]
}
```

**Response (422):**
```json
{
  "message": "Operation 0 is missing required field: action"
}
```

### Max Operations Exceeded

**Response (422):**
```json
{
  "message": "Maximum 10 operations allowed per request"
}
```

### Disallowed Model

**Response (422):**
```json
{
  "message": "Model 'users' is not allowed in nested operations"
}
```

---

## Best Practices

### 1. Keep Operations Focused

```json
// ✅ Good - related operations
{
  "operations": [
    {"model": "blogs", "action": "create", ...},
    {"model": "posts", "action": "create", ...}
  ]
}

// ❌ Bad - unrelated operations
{
  "operations": [
    {"model": "blogs", "action": "create", ...},
    {"model": "users", "action": "update", ...},
    {"model": "settings", "action": "update", ...}
  ]
}
```

### 2. Order Dependencies Correctly

```json
// ✅ Good - parent before children
{
  "operations": [
    {"model": "blogs", "action": "create", ...},
    {"model": "posts", "action": "create", "data": {"blog_id": 1}}
  ]
}

// ❌ Bad - child before parent
{
  "operations": [
    {"model": "posts", "action": "create", "data": {"blog_id": 1}},
    {"model": "blogs", "action": "create", ...}
  ]
}
```

### 3. Limit Operation Count

```javascript
// ✅ Good - reasonable limit
const operations = posts.slice(0, 5).map(post => ({
  model: 'posts',
  action: 'create',
  data: post
}));

// ❌ Bad - too many operations
const operations = allPosts.map(post => ({
  model: 'posts',
  action: 'create',
  data: post
})); // Could be hundreds
```

### 4. Handle Errors Gracefully

```javascript
// ✅ Good - proper error handling
try {
  const response = await api.post('/api/nested', { operations });
  console.log('All operations succeeded:', response.data);
} catch (error) {
  if (error.response?.status === 422) {
    console.error('Validation failed:', error.response.data.errors);
  } else if (error.response?.status === 403) {
    console.error('Permission denied:', error.response.data.message);
  } else {
    console.error('Transaction failed:', error.response?.data);
  }
}

// ❌ Bad - generic error handling
try {
  await api.post('/api/nested', { operations });
} catch (error) {
  console.error('Error');
}
```

### 5. Use for Atomic Workflows

```javascript
// ✅ Good - operations must succeed together
await api.post('/api/nested', {
  operations: [
    { model: 'orders', action: 'create', ... },
    { model: 'order_items', action: 'create', ... },
    { model: 'inventory', action: 'update', ... }
  ]
});

// ❌ Bad - independent operations
await api.post('/api/nested', {
  operations: [
    { model: 'posts', action: 'update', id: 1, ... },
    { model: 'posts', action: 'update', id: 2, ... },
    { model: 'posts', action: 'update', id: 3, ... }
  ]
});
// Better to do individually or use bulk update endpoint
```

### 6. Validate Before Sending

```javascript
// ✅ Good - validate structure client-side
function validateOperations(operations) {
  if (!Array.isArray(operations)) {
    throw new Error('Operations must be an array');
  }

  if (operations.length === 0) {
    throw new Error('At least one operation required');
  }

  if (operations.length > 10) {
    throw new Error('Maximum 10 operations allowed');
  }

  operations.forEach((op, index) => {
    if (!op.model || !op.action) {
      throw new Error(`Operation ${index} missing required fields`);
    }
    if (op.action === 'update' && !op.id) {
      throw new Error(`Operation ${index} update requires id`);
    }
    if (!op.data) {
      throw new Error(`Operation ${index} missing data`);
    }
  });

  return true;
}

// Validate before sending
validateOperations(operations);
await api.post('/api/nested', { operations });
```

### 7. Log Failed Transactions

```php
// ✅ Good - log for debugging
try {
    $results = $this->executeNestedOperations($operations);
} catch (Exception $e) {
    Log::error('Nested operations failed', [
        'operations' => $operations,
        'error' => $e->getMessage(),
        'user_id' => auth()->id(),
        'trace' => $e->getTraceAsString()
    ]);
    throw $e;
}

// ❌ Bad - silent failures
try {
    $results = $this->executeNestedOperations($operations);
} catch (Exception $e) {
    throw $e;
}
```

### 8. Test Transaction Rollback

```php
public function test_nested_operations_rollback_on_failure()
{
    $this->actingAs($user);

    $response = $this->postJson('/api/nested', [
        'operations' => [
            [
                'model' => 'blogs',
                'action' => 'create',
                'data' => ['title' => 'Blog'],
            ],
            [
                'model' => 'posts',
                'action' => 'create',
                'data' => ['blog_id' => 999, 'title' => 'Post'], // Invalid blog_id
            ],
        ],
    ]);

    $response->assertStatus(422);

    // Verify rollback - blog should not exist
    $this->assertDatabaseMissing('blogs', ['title' => 'Blog']);
}
```

### 9. Document Operation Order

```php
/**
 * Create blog with initial posts
 *
 * Operations:
 * 1. Create blog (must be first - other operations depend on it)
 * 2. Create first post (uses blog.id from operation 0)
 * 3. Create second post (uses blog.id from operation 0)
 * 4. Update blog post_count (updates blog from operation 0)
 */
public function createBlogWithPosts($data)
{
    return api.post('/api/nested', {
        operations: [
            { model: 'blogs', action: 'create', data: $data.blog },
            { model: 'posts', action: 'create', data: $data.post1 },
            { model: 'posts', action: 'create', data: $data.post2 },
            { model: 'blogs', action: 'update', id: 1, data: { post_count: 2 } }
        ]
    });
}
```

### 10. Use Descriptive Operation Data

```json
// ✅ Good - clear intent
{
  "operations": [
    {
      "model": "orders",
      "action": "create",
      "data": {
        "user_id": 1,
        "total": 150.00,
        "status": "pending",
        "notes": "Created via nested operation"
      }
    }
  ]
}

// ❌ Bad - minimal context
{
  "operations": [
    {
      "model": "orders",
      "action": "create",
      "data": {
        "user_id": 1,
        "total": 150.00
      }
    }
  ]
}
```

---

## Related Documentation

- [API Reference - Nested Operations](../API.md#nested-operations)
- [Validation](./validation.md) - How validation works per operation
- [Authorization](./authorization.md) - Permission checking
- [Getting Started](../getting-started.md)
