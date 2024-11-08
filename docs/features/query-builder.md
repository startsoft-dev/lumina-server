# Query Builder

Laravel Global Controller integrates with Spatie Query Builder to provide powerful filtering, sorting, field selection, and relationship loading capabilities.

## Table of Contents

- [Overview](#overview)
- [Setup](#setup)
- [Filtering](#filtering)
- [Sorting](#sorting)
- [Including Relationships](#including-relationships)
- [Field Selection](#field-selection)
- [Search](#search)
- [Combining Features](#combining-features)
- [Include Authorization](#include-authorization)
- [Best Practices](#best-practices)

---

## Overview

The package uses [Spatie Query Builder](https://github.com/spatie/laravel-query-builder) to enable powerful querying capabilities on all `index` endpoints.

**Features:**
- Filter by any field
- Sort by multiple fields (ascending/descending)
- Include relationships (with authorization)
- Select specific fields
- Full-text search across columns
- All features work together seamlessly

**How it works:**
1. Client sends query parameters in URL
2. Query Builder parses and validates parameters
3. Only allowed filters/sorts/includes are applied (configured per model)
4. Results returned as JSON

---

## Setup

Configure allowed operations on your model:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Lumina\LaravelApi\Traits\HasValidation;

class Post extends Model
{
    use HasValidation;

    // Allowed filter fields
    public static $allowedFilters = [
        'title',
        'user_id',
        'is_published',
        'created_at',
    ];

    // Allowed sort fields
    public static $allowedSorts = [
        'created_at',
        'updated_at',
        'title',
    ];

    // Default sort when none specified
    public static $defaultSort = '-created_at'; // Descending

    // Allowed fields for selection
    public static $allowedFields = [
        'id',
        'title',
        'content',
        'user_id',
        'is_published',
        'created_at',
        'updated_at',
    ];

    // Allowed relationships to include
    public static $allowedIncludes = [
        'user',
        'comments',
        'tags',
    ];

    // Searchable columns
    public static $allowedSearch = [
        'title',
        'content',
    ];
}
```

---

## Filtering

Filter results by field values using `filter[field]=value` query parameters.

### Single Filter

**Request:**
```bash
GET /api/posts?filter[is_published]=true
```

**SQL Generated:**
```sql
SELECT * FROM posts WHERE is_published = 1
```

**Example:**
```bash
curl "http://localhost:8000/api/posts?filter[is_published]=true" \
  -H "Accept: application/json"
```

### Multiple Filters (AND)

**Request:**
```bash
GET /api/posts?filter[is_published]=true&filter[user_id]=1
```

**SQL Generated:**
```sql
SELECT * FROM posts WHERE is_published = 1 AND user_id = 1
```

### Multiple Values (OR)

**Request:**
```bash
GET /api/posts?filter[status]=draft,published
```

**SQL Generated:**
```sql
SELECT * FROM posts WHERE status IN ('draft', 'published')
```

### Exact vs Partial Match

By default, filters use exact matching. For partial matching, use wildcard operators:

**Exact match:**
```bash
GET /api/posts?filter[title]=Laravel
# WHERE title = 'Laravel'
```

**Partial match (requires custom filter class):**
```php
// In model
use Spatie\QueryBuilder\AllowedFilter;

public static function getAllowedFilters()
{
    return [
        AllowedFilter::exact('user_id'),
        AllowedFilter::partial('title'),
        AllowedFilter::scope('published'),
    ];
}
```

```bash
GET /api/posts?filter[title]=larav
# WHERE title LIKE '%larav%'
```

### Filter Examples

**By boolean:**
```bash
GET /api/posts?filter[is_published]=true
GET /api/posts?filter[is_published]=false
```

**By integer:**
```bash
GET /api/posts?filter[user_id]=1
```

**By date:**
```bash
GET /api/posts?filter[created_at]=2024-01-15
```

**By multiple values:**
```bash
GET /api/posts?filter[user_id]=1,2,3
# Returns posts by users 1, 2, or 3
```

---

## Sorting

Sort results by one or more fields using the `sort` query parameter.

### Single Sort (Ascending)

**Request:**
```bash
GET /api/posts?sort=title
```

**SQL:**
```sql
SELECT * FROM posts ORDER BY title ASC
```

### Single Sort (Descending)

Prefix field name with `-` for descending order:

**Request:**
```bash
GET /api/posts?sort=-created_at
```

**SQL:**
```sql
SELECT * FROM posts ORDER BY created_at DESC
```

### Multiple Sorts

Separate fields with commas. Order matters:

**Request:**
```bash
GET /api/posts?sort=-is_published,created_at
```

**SQL:**
```sql
SELECT * FROM posts
ORDER BY is_published DESC, created_at ASC
```

This sorts published posts first, then by creation date within each group.

### Default Sort

If no `sort` parameter provided, uses model's `$defaultSort`:

```php
public static $defaultSort = '-created_at';
```

**Request:**
```bash
GET /api/posts
# Automatically sorts by created_at DESC
```

### Sort Examples

**Newest first:**
```bash
GET /api/posts?sort=-created_at
```

**Oldest first:**
```bash
GET /api/posts?sort=created_at
```

**Alphabetical:**
```bash
GET /api/posts?sort=title
```

**Published first, then newest:**
```bash
GET /api/posts?sort=-is_published,-created_at
```

---

## Including Relationships

Load related models using the `include` query parameter.

### Single Include

**Request:**
```bash
GET /api/posts?include=user
```

**Response:**
```json
[
  {
    "id": 1,
    "title": "Post Title",
    "user_id": 1,
    "user": {
      "id": 1,
      "name": "John Doe",
      "email": "john@example.com"
    }
  }
]
```

### Multiple Includes

**Request:**
```bash
GET /api/posts?include=user,comments,tags
```

**Response includes:**
- Post data
- User object (belongsTo)
- Comments array (hasMany)
- Tags array (belongsToMany)

### Nested Includes

Use dot notation for nested relationships:

**Request:**
```bash
GET /api/posts?include=comments.user
```

**Response:**
```json
[
  {
    "id": 1,
    "title": "Post Title",
    "comments": [
      {
        "id": 1,
        "content": "Great post!",
        "user": {
          "id": 2,
          "name": "Jane Smith"
        }
      }
    ]
  }
]
```

### Complex Nested Includes

**Request:**
```bash
GET /api/posts?include=user.profile,comments.user.profile,tags
```

Loads:
- Post author with profile
- Comments with users and their profiles
- All tags

### Define Relationships

Ensure relationships are defined on your model:

```php
class Post extends Model
{
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function comments()
    {
        return $this->hasMany(Comment::class);
    }

    public function tags()
    {
        return $this->belongsToMany(Tag::class);
    }
}
```

---

## Field Selection

Select specific fields to reduce payload size using `fields[model]=field1,field2`.

### Basic Selection

**Request:**
```bash
GET /api/posts?fields[posts]=id,title,created_at
```

**Response:**
```json
[
  {
    "id": 1,
    "title": "Post Title",
    "created_at": "2024-01-15T10:00:00.000000Z"
  }
]
```

Only requested fields are returned. All others excluded.

### With Relationships

**Request:**
```bash
GET /api/posts?include=user&fields[posts]=id,title&fields[users]=id,name
```

**Response:**
```json
[
  {
    "id": 1,
    "title": "Post Title",
    "user": {
      "id": 1,
      "name": "John Doe"
    }
  }
]
```

### Why Use Field Selection

**Performance benefits:**
- Smaller JSON payloads
- Faster serialization
- Reduced bandwidth
- Faster client-side parsing

**Example - Full vs Selected:**

```bash
# Full response: ~5KB per post
GET /api/posts

# Selected fields: ~500 bytes per post
GET /api/posts?fields[posts]=id,title
```

---

## Search

Full-text search across multiple columns using the `search` query parameter.

### Setup

Define searchable columns on your model:

```php
public static $allowedSearch = [
    'title',
    'content',
    'excerpt',
];
```

### Basic Search

**Request:**
```bash
GET /api/posts?search=laravel
```

**SQL Generated:**
```sql
SELECT * FROM posts
WHERE LOWER(title) LIKE '%laravel%'
   OR LOWER(content) LIKE '%laravel%'
   OR LOWER(excerpt) LIKE '%laravel%'
```

### Search with Filters

Combine search with filters:

**Request:**
```bash
GET /api/posts?search=laravel&filter[is_published]=true
```

Only searches within published posts.

### Search Relationship Columns

Use dot notation to search in related tables:

```php
public static $allowedSearch = [
    'title',
    'content',
    'user.name',  // Search in user's name
    'user.email', // Search in user's email
];
```

**Request:**
```bash
GET /api/posts?search=john&include=user
```

Searches for "john" in post title, content, and user name/email.

### Case-Insensitive

Search is automatically case-insensitive:

```bash
GET /api/posts?search=LARAVEL
GET /api/posts?search=Laravel
GET /api/posts?search=laravel
# All return same results
```

---

## Combining Features

All query features work together seamlessly.

### Example 1: Filtered, Sorted, with Includes

**Request:**
```bash
GET /api/posts?filter[is_published]=true&sort=-created_at&include=user,tags&per_page=20
```

Returns:
- Only published posts
- Sorted by newest first
- With author and tags loaded
- Paginated (20 per page)

### Example 2: Search with Selection

**Request:**
```bash
GET /api/posts?search=laravel&fields[posts]=id,title,excerpt&sort=-created_at
```

Returns:
- Posts matching "laravel"
- Only id, title, excerpt fields
- Sorted by newest first

### Example 3: Complex Query

**Request:**
```bash
GET /api/posts?filter[user_id]=1,2&filter[is_published]=true&search=tutorial&include=user.profile,comments.user&sort=-created_at&fields[posts]=id,title,content&per_page=10&page=2
```

Returns:
- Posts by users 1 or 2
- Only published posts
- Matching "tutorial" search
- With author profiles and comment authors
- Sorted newest first
- Only id/title/content fields
- Page 2, 10 items per page

### Example 4: Production-Ready Query

**Frontend list view:**
```bash
GET /api/posts?filter[is_published]=true&include=user&fields[posts]=id,title,excerpt,created_at&fields[users]=id,name&sort=-created_at&per_page=20
```

**Benefits:**
- Only published posts (security)
- Minimal fields (performance)
- Author name included (UX)
- Paginated (scalability)

---

## Include Authorization

Users must have `viewAny` permission on included models.

### How It Works

1. User requests `?include=comments`
2. System checks if user has `comments.index` permission
3. If yes, relationship loaded
4. If no, returns **403 Forbidden**

### Example - Denied Include

**Setup:**
```php
// User has permissions: ['posts.*']
// User does NOT have: ['comments.*']
```

**Request:**
```bash
GET /api/posts/1?include=comments
```

**Response (403 Forbidden):**
```json
{
  "message": "You do not have permission to include comments."
}
```

### Example - Allowed Include

**Setup:**
```php
// User has permissions: ['posts.*', 'comments.*']
```

**Request:**
```bash
GET /api/posts/1?include=comments
```

**Response (200 OK):**
```json
{
  "id": 1,
  "title": "Post",
  "comments": [
    {"id": 1, "content": "Comment 1"},
    {"id": 2, "content": "Comment 2"}
  ]
}
```

### Nested Include Authorization

Each segment checked individually:

**Request:**
```bash
GET /api/posts?include=comments.user.profile
```

**Checks:**
1. User can view comments? (`comments.index`)
2. User can view users? (`users.index`)
3. User can view profiles? (`profiles.index`)

First denied permission returns 403.

---

## Best Practices

### 1. Limit Allowed Filters

```php
// ✅ Good - only safe fields
public static $allowedFilters = [
    'status',
    'category_id',
    'is_published',
];

// ❌ Bad - exposing sensitive fields
public static $allowedFilters = [
    'password',
    'api_token',
    'deleted_at',
];
```

### 2. Set Default Sort

```php
// ✅ Good - consistent ordering
public static $defaultSort = '-created_at';

// ❌ Bad - no default, unpredictable order
// public static $defaultSort = null;
```

### 3. Optimize Field Selection

```php
// ✅ Good - list view with minimal fields
GET /api/posts?fields[posts]=id,title,excerpt

// ❌ Bad - loading all fields when only need few
GET /api/posts
```

### 4. Avoid N+1 with Includes

```php
// ✅ Good - eager load relationships
GET /api/posts?include=user,comments

// ❌ Bad - loading relationships in loop on frontend
// GET /api/posts then GET /api/users/{id} for each post
```

### 5. Use Search for User Input

```php
// ✅ Good - search parameter for text input
public static $allowedSearch = ['title', 'content'];
GET /api/posts?search=user-typed-query

// ❌ Bad - filter with LIKE (security risk if not validated)
// GET /api/posts?filter[title]=%injection%
```

### 6. Combine Features Efficiently

```php
// ✅ Good - single request with all data needed
GET /api/posts?filter[is_published]=true&include=user&fields[posts]=id,title&sort=-created_at&per_page=10

// ❌ Bad - multiple requests
// GET /api/posts
// GET /api/posts?include=user
// GET /api/posts?sort=-created_at
```

### 7. Document Query Capabilities

```php
/**
 * Post Model
 *
 * Query Capabilities:
 * - Filter by: status, user_id, category_id, is_published
 * - Sort by: created_at, updated_at, title
 * - Include: user, comments, tags, category
 * - Search in: title, content, excerpt
 * - Select fields: all except password, api_token
 */
class Post extends Model
{
    // ...
}
```

### 8. Test Query Combinations

```php
public function test_can_filter_sort_and_include()
{
    Post::factory()->count(5)->create(['is_published' => true]);
    Post::factory()->count(3)->create(['is_published' => false]);

    $response = $this->getJson(
        '/api/posts?filter[is_published]=true&sort=-created_at&include=user'
    );

    $response->assertOk()
        ->assertJsonCount(5)
        ->assertJsonStructure([
            '*' => ['id', 'title', 'user' => ['id', 'name']]
        ]);
}
```

---

## Related Documentation

- [API Reference - Query Parameters](../API.md#query-parameters)
- [Pagination](./pagination.md) - Paginated responses
- [Authorization](./authorization.md) - Include authorization
- [Getting Started](../getting-started.md)
