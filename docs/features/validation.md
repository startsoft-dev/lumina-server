# Validation

Laravel Global Controller provides automatic request validation through the `HasValidation` trait with support for role-based validation rules.

## Table of Contents

- [Overview](#overview)
- [Basic Validation](#basic-validation)
- [Role-Based Validation](#role-based-validation)
- [Validation Rules](#validation-rules)
- [Custom Messages](#custom-messages)
- [Nested Operations Validation](#nested-operations-validation)
- [Error Responses](#error-responses)
- [Best Practices](#best-practices)

---

## Overview

The `HasValidation` trait automatically validates requests for `store` (create) and `update` operations using rules defined on your model.

**Key Features:**
- Automatic validation on create and update
- Separate rules for store vs update
- Role-based validation (different rules per role)
- Custom error messages
- Integration with Laravel's validator

**Validation happens before:**
- Authorization checks
- Database operations
- Any business logic

---

## Basic Validation

### Setup

Add the `HasValidation` trait to your model:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Lumina\LaravelApi\Traits\HasValidation;

class Post extends Model
{
    use HasValidation;

    protected $fillable = [
        'title',
        'content',
        'user_id',
        'is_published',
    ];

    // Format/type rules (no required/nullable here)
    protected $validationRules = [
        'title' => 'string|max:255',
        'content' => 'string',
        'user_id' => 'exists:users,id',
        'is_published' => 'boolean',
    ];

    // Fields to validate on create (with required/nullable)
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
}
```

### How It Works

**On Create (POST /api/posts):**

1. Request data validated against `$validationRulesStore`
2. Each field combined with its rule from `$validationRules`
3. Final rules: `['title' => 'required|string|max:255', ...]`
4. If validation fails, returns 422 with errors
5. If validation passes, only validated fields are used

**On Update (PUT /api/posts/{id}):**

1. Request data validated against `$validationRulesUpdate`
2. Combined with `$validationRules`
3. `sometimes` allows partial updates
4. Only provided fields are validated and updated

### Example Request/Response

**Valid Request:**
```bash
curl -X POST "http://localhost:8000/api/posts" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer TOKEN" \
  -d '{
    "title": "My Post",
    "content": "Content here",
    "user_id": 1,
    "is_published": true
  }'
```

**Response (201 Created):**
```json
{
  "id": 1,
  "title": "My Post",
  "content": "Content here",
  "user_id": 1,
  "is_published": true,
  "created_at": "2024-01-16T10:00:00.000000Z"
}
```

**Invalid Request:**
```bash
curl -X POST "http://localhost:8000/api/posts" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer TOKEN" \
  -d '{
    "title": "",
    "is_published": true
  }'
```

**Response (422 Unprocessable Entity):**
```json
{
  "message": "The title field is required. (and 2 more errors)",
  "errors": {
    "title": ["The title field is required."],
    "content": ["The content field is required."],
    "user_id": ["The user id field is required."]
  }
}
```

---

## Role-Based Validation

Different roles may submit different fields or have different required/optional rules.

### Setup

**1. Implement interface on User model:**

```php
<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Lumina\LaravelApi\Contracts\HasRoleBasedValidation;

class User extends Authenticatable implements HasRoleBasedValidation
{
    /**
     * Get user's role slug for validation in this organization.
     */
    public function getRoleSlugForValidation($organization): ?string
    {
        if (!$organization) {
            return null;
        }

        $userRole = $this->userRoles()
            ->where('organization_id', $organization->id)
            ->with('role')
            ->first();

        return $userRole?->role->slug;
    }
}
```

**2. Define role-keyed validation rules:**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Lumina\LaravelApi\Traits\HasValidation;

class Post extends Model
{
    use HasValidation;

    // Format/type only (no required/nullable)
    protected $validationRules = [
        'blog_id' => 'exists:blogs,id',
        'title' => 'string|max:255',
        'content' => 'string',
        'is_published' => 'boolean',
        'featured_image' => 'url',
    ];

    // Role-based store rules
    protected $validationRulesStore = [
        'admin' => [
            'blog_id' => 'required',
            'title' => 'required',
            'content' => 'required',
            'is_published' => 'nullable',
            'featured_image' => 'nullable',
        ],
        'editor' => [
            'title' => 'required',
            'content' => 'required',
            'is_published' => 'nullable',
            // blog_id and featured_image not allowed
        ],
        'contributor' => [
            'title' => 'required',
            'content' => 'required',
            // Cannot set is_published or featured_image
        ],
        '*' => [
            // Fallback for unknown roles
            'title' => 'required',
            'content' => 'required',
        ],
    ];

    // Role-based update rules
    protected $validationRulesUpdate = [
        'admin' => [
            'blog_id' => 'sometimes',
            'title' => 'sometimes',
            'content' => 'sometimes',
            'is_published' => 'sometimes',
            'featured_image' => 'sometimes',
        ],
        'editor' => [
            'title' => 'sometimes',
            'content' => 'sometimes',
            'is_published' => 'sometimes',
        ],
        'contributor' => [
            'title' => 'sometimes',
            'content' => 'sometimes',
        ],
        '*' => [
            'title' => 'sometimes',
            'content' => 'sometimes',
        ],
    ];
}
```

### How It Works

**Request Processing:**

1. User's role slug retrieved via `getRoleSlugForValidation()`
2. If role slug exists, use rules from that key
3. If role slug doesn't exist, use `'*'` fallback
4. Only fields listed for that role are validated
5. Other request fields are ignored

**Example - Admin creates post:**

```bash
# Admin can set all fields
POST /api/posts
{
  "blog_id": 1,
  "title": "Post",
  "content": "Content",
  "is_published": true,
  "featured_image": "https://example.com/image.jpg"
}
# ✅ All fields validated and saved
```

**Example - Contributor creates post:**

```bash
# Contributor tries to set is_published
POST /api/posts
{
  "title": "Post",
  "content": "Content",
  "is_published": true  # This field ignored
}
# ✅ Success - is_published ignored, only title/content validated
```

### Fallback Behavior

**User has role "unknown":**
- Uses `'*'` key rules
- If no `'*'` key, validation fails

**User not in organization:**
- Returns null from `getRoleSlugForValidation()`
- Uses `'*'` key rules

**No role interface implemented:**
- Uses `'*'` key if present
- Otherwise uses first role key (not recommended)

---

## Validation Rules

### Combining Rules

Rules from `$validationRules` and `$validationRulesStore`/`$validationRulesUpdate` are combined:

```php
// Model definition
protected $validationRules = [
    'email' => 'email|unique:users,email',
    'age' => 'integer|min:18',
];

protected $validationRulesStore = [
    'email' => 'required',
    'age' => 'required',
];

// Final rules on store:
[
    'email' => 'required|email|unique:users,email',
    'age' => 'required|integer|min:18',
]
```

### Full Rule Strings

If a store/update rule contains `|`, it **replaces** the base rule entirely:

```php
protected $validationRules = [
    'email' => 'email',
];

protected $validationRulesStore = [
    'email' => 'required|email|unique:users,email|max:255',
];

// Final rule: 'required|email|unique:users,email|max:255'
// (base rule ignored)
```

### Common Rules

**String validation:**
```php
'title' => 'string|max:255',
'slug' => 'string|alpha_dash|unique:posts,slug',
```

**Numeric validation:**
```php
'age' => 'integer|min:18|max:120',
'price' => 'numeric|min:0',
'quantity' => 'integer|between:1,100',
```

**Boolean validation:**
```php
'is_published' => 'boolean',
'is_active' => 'boolean',
```

**Date validation:**
```php
'birth_date' => 'date',
'published_at' => 'date|after:today',
'expires_at' => 'date|after:start_date',
```

**Relationships:**
```php
'user_id' => 'exists:users,id',
'category_id' => 'exists:categories,id',
```

**Files:**
```php
'avatar' => 'image|mimes:jpeg,png|max:2048',
'document' => 'file|mimes:pdf,doc,docx|max:10240',
```

**Arrays:**
```php
'tags' => 'array',
'tags.*' => 'string|max:50',
'options' => 'array|min:1',
```

---

## Custom Messages

Define custom validation error messages:

```php
protected $validationRulesMessages = [
    'title.required' => 'Post title is mandatory.',
    'title.max' => 'Post title cannot exceed :max characters.',
    'content.required' => 'Post content cannot be empty.',
    'user_id.exists' => 'The selected author does not exist.',
];
```

**Using custom messages:**

```bash
# Invalid request
POST /api/posts
{"title": ""}

# Response with custom message
{
  "message": "Post title is mandatory.",
  "errors": {
    "title": ["Post title is mandatory."]
  }
}
```

---

## Nested Operations Validation

Each operation in a nested request is validated individually using the model's rules:

```bash
POST /api/nested
{
  "operations": [
    {
      "model": "blogs",
      "action": "create",
      "data": {
        "title": "",  # Invalid
        "description": "Desc"
      }
    },
    {
      "model": "posts",
      "action": "create",
      "data": {
        "title": "Post",
        "content": ""  # Invalid
      }
    }
  ]
}
```

**Response (422):**
```json
{
  "message": "Validation failed for operations",
  "errors": {
    "operations.0.data.title": ["The title field is required."],
    "operations.1.data.content": ["The content field is required."]
  }
}
```

Errors are keyed by operation index: `operations.{index}.data.{field}`

---

## Error Responses

### Validation Failed (422)

```json
{
  "message": "The title field is required. (and 2 more errors)",
  "errors": {
    "title": ["The title field is required."],
    "content": ["The content field is required."],
    "email": ["The email must be a valid email address."]
  }
}
```

### Multiple Errors Per Field

```json
{
  "message": "The email has already been taken. (and 1 more error)",
  "errors": {
    "email": [
      "The email must be a valid email address.",
      "The email has already been taken."
    ]
  }
}
```

---

## Best Practices

### 1. Separate Format from Presence

```php
// ✅ Good - format in base, presence in store/update
protected $validationRules = [
    'email' => 'email|unique:users,email',
];

protected $validationRulesStore = [
    'email' => 'required',
];

// ❌ Bad - mixing required in base rules
protected $validationRules = [
    'email' => 'required|email|unique:users,email',
];
```

### 2. Use 'sometimes' for Updates

```php
// ✅ Good - allows partial updates
protected $validationRulesUpdate = [
    'title' => 'sometimes',
    'content' => 'sometimes',
];

// ❌ Bad - requires all fields on every update
protected $validationRulesUpdate = [
    'title' => 'required',
    'content' => 'required',
];
```

### 3. Validate Relationships

```php
// ✅ Good - ensures FK exists
'user_id' => 'exists:users,id',
'category_id' => 'exists:categories,id',

// ❌ Bad - no FK validation
'user_id' => 'integer',
```

### 4. Set Reasonable Limits

```php
// ✅ Good - prevents abuse
'title' => 'string|max:255',
'content' => 'string|max:10000',
'tags' => 'array|max:10',

// ❌ Bad - no limits
'title' => 'string',
'content' => 'string',
```

### 5. Use Role-Based Validation for Different User Types

```php
// ✅ Good - different rules per role
protected $validationRulesStore = [
    'admin' => ['price' => 'required', 'cost' => 'required'],
    'manager' => ['price' => 'required'],
    'staff' => [],
];

// ❌ Bad - same rules for everyone
protected $validationRulesStore = [
    'price' => 'required',
    'cost' => 'required',
];
```

### 6. Provide Clear Error Messages

```php
// ✅ Good - user-friendly messages
protected $validationRulesMessages = [
    'title.required' => 'Please provide a post title.',
    'email.unique' => 'This email address is already registered.',
];

// ❌ Bad - using default messages only
// (Default messages are OK, but custom is better UX)
```

### 7. Test Validation Rules

```php
public function test_post_requires_title_and_content()
{
    $response = $this->postJson('/api/posts', [
        'user_id' => 1,
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['title', 'content']);
}

public function test_contributor_cannot_set_is_published()
{
    $user = $this->createContributor();

    $response = $this->actingAs($user)
        ->postJson('/api/posts', [
            'title' => 'Post',
            'content' => 'Content',
            'is_published' => true,
        ]);

    $response->assertCreated();

    // is_published should be ignored (defaults to false)
    $this->assertFalse($response->json('is_published'));
}
```

### 8. Document Validation Rules

```php
/**
 * Post Model
 *
 * Validation Rules:
 * - title: Required string, max 255 characters
 * - content: Required string, max 10000 characters
 * - user_id: Required, must exist in users table
 * - is_published: Optional boolean (admin/editor only)
 *
 * Role-Based Access:
 * - Admin: Can set all fields
 * - Editor: Cannot set blog_id
 * - Contributor: Cannot set is_published or blog_id
 */
class Post extends Model
{
    use HasValidation;
    // ...
}
```

---

## Related Documentation

- [API Reference - Validation Errors](../API.md#422-unprocessable-entity)
- [Authorization](./authorization.md) - Role-based permissions
- [Nested Operations](./nested-operations.md) - Multi-model validation
- [Getting Started](../getting-started.md)
