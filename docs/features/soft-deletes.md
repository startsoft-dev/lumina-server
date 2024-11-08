# Soft Deletes

Laravel Global Controller automatically provides soft delete endpoints for models using Laravel's `SoftDeletes` trait.

## Table of Contents

- [Overview](#overview)
- [Setup](#setup)
- [Soft Delete (Trash)](#soft-delete-trash)
- [List Trashed Items](#list-trashed-items)
- [Restore](#restore)
- [Force Delete](#force-delete)
- [Authorization](#authorization)
- [Excluding Actions](#excluding-actions)
- [Best Practices](#best-practices)

---

## Overview

Soft deletes allow you to "delete" records without permanently removing them from the database. Deleted records can be viewed, restored, or permanently deleted later.

**Available Operations:**
- **Delete** - Soft delete (sets `deleted_at`)
- **List Trashed** - View soft-deleted records
- **Restore** - Recover a soft-deleted record
- **Force Delete** - Permanently remove from database

**Automatic Detection:**
If your model uses `SoftDeletes` trait, three additional endpoints are automatically registered:
```
GET    /api/{model}/trashed
POST   /api/{model}/{id}/restore
DELETE /api/{model}/{id}/force-delete
```

---

## Setup

### 1. Database Migration

Add `deleted_at` column to your table:

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
            $table->foreignId('user_id')->constrained();
            $table->timestamps();
            $table->softDeletes(); // Add this
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posts');
    }
};
```

### 2. Add SoftDeletes Trait

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Lumina\LaravelApi\Traits\HasValidation;

class Post extends Model
{
    use SoftDeletes, HasValidation; // Add SoftDeletes

    protected $fillable = [
        'title',
        'content',
        'user_id',
    ];
}
```

### 3. That's It!

Soft delete endpoints are now available automatically. No additional configuration needed.

---

## Soft Delete (Trash)

The standard `DELETE` endpoint performs a soft delete when the model uses `SoftDeletes`.

### Endpoint

`DELETE /api/{model}/{id}`

### Request

```bash
curl -X DELETE "http://localhost:8000/api/posts/1" \
  -H "Authorization: Bearer TOKEN" \
  -H "Accept: application/json"
```

### Response (200 OK)

```json
{
  "message": "Resource deleted successfully"
}
```

### What Happens

1. Record's `deleted_at` field is set to current timestamp
2. Record no longer appears in normal queries (`GET /api/posts`)
3. Record appears in trashed endpoint (`GET /api/posts/trashed`)
4. Record can be restored or permanently deleted

### Database State

**Before delete:**
```sql
SELECT * FROM posts WHERE id = 1;
-- id | title | deleted_at
-- 1  | Post  | NULL
```

**After soft delete:**
```sql
SELECT * FROM posts WHERE id = 1;
-- Returns nothing (excluded by default scope)

SELECT * FROM posts WHERE id = 1 WITH_TRASHED;
-- id | title | deleted_at
-- 1  | Post  | 2024-01-16 10:00:00
```

---

## List Trashed Items

View all soft-deleted records.

### Endpoint

`GET /api/{model}/trashed`

### Request

```bash
curl -X GET "http://localhost:8000/api/posts/trashed" \
  -H "Authorization: Bearer TOKEN" \
  -H "Accept: application/json"
```

### Response (200 OK)

```json
[
  {
    "id": 1,
    "title": "Deleted Post",
    "content": "Content here",
    "user_id": 1,
    "created_at": "2024-01-10T08:00:00.000000Z",
    "updated_at": "2024-01-15T12:00:00.000000Z",
    "deleted_at": "2024-01-16T10:00:00.000000Z"
  }
]
```

### With Query Features

All query features work on trashed endpoint:

**Sort by deletion date:**
```bash
GET /api/posts/trashed?sort=-deleted_at
```

**Filter trashed items:**
```bash
GET /api/posts/trashed?filter[user_id]=1
```

**Include relationships:**
```bash
GET /api/posts/trashed?include=user
```

**Paginate:**
```bash
GET /api/posts/trashed?per_page=20&page=1
```

---

## Restore

Recover a soft-deleted record.

### Endpoint

`POST /api/{model}/{id}/restore`

### Request

```bash
curl -X POST "http://localhost:8000/api/posts/1/restore" \
  -H "Authorization: Bearer TOKEN" \
  -H "Accept: application/json"
```

### Response (200 OK)

```json
{
  "id": 1,
  "title": "Deleted Post",
  "content": "Content here",
  "user_id": 1,
  "created_at": "2024-01-10T08:00:00.000000Z",
  "updated_at": "2024-01-16T11:00:00.000000Z",
  "deleted_at": null
}
```

### What Happens

1. `deleted_at` field is set to `null`
2. Record appears in normal queries again
3. Record no longer in trashed endpoint
4. `updated_at` timestamp is updated

### Error Response

**404 Not Found** - If record doesn't exist or isn't soft-deleted:

```json
{
  "message": "Resource not found"
}
```

---

## Force Delete

Permanently remove a record from the database. **Cannot be undone.**

### Endpoint

`DELETE /api/{model}/{id}/force-delete`

### Request

```bash
curl -X DELETE "http://localhost:8000/api/posts/1/force-delete" \
  -H "Authorization: Bearer TOKEN" \
  -H "Accept: application/json"
```

### Response (200 OK)

```json
{
  "message": "Resource permanently deleted"
}
```

### What Happens

1. Record is permanently removed from database
2. All related records may be affected (depends on foreign key constraints)
3. **Cannot be restored**
4. Removed from all queries (normal and trashed)

### Warning

Force delete is irreversible. Always:
1. Confirm with user before force deleting
2. Check for related records
3. Consider backup before batch force deletes
4. Log force delete operations for audit trail

---

## Authorization

Each soft delete action has its own policy method.

### Policy Methods

| Endpoint | Policy Method | Permission Checked |
|----------|--------------|-------------------|
| `DELETE /{id}` | `delete()` | `{slug}.destroy` |
| `GET /trashed` | `viewTrashed()` | `{slug}.trashed` |
| `POST /{id}/restore` | `restore()` | `{slug}.restore` |
| `DELETE /{id}/force-delete` | `forceDelete()` | `{slug}.forceDelete` |

### Default Behavior

`ResourcePolicy` provides default implementations:

```php
<?php

namespace App\Policies;

use Lumina\LaravelApi\Policies\ResourcePolicy;

class PostPolicy extends ResourcePolicy
{
    // Inherits:
    // - delete() checks {slug}.destroy permission
    // - viewTrashed() checks {slug}.trashed permission
    // - restore() checks {slug}.restore permission
    // - forceDelete() checks {slug}.forceDelete permission
}
```

### Custom Authorization

Override methods for custom logic:

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
     * Only post owner can restore.
     */
    public function restore(?Authenticatable $user, Post $post): bool
    {
        if (!parent::restore($user, $post)) {
            return false;
        }

        return $user && $user->id === $post->user_id;
    }

    /**
     * Only admins can force delete.
     */
    public function forceDelete(?Authenticatable $user, Post $post): bool
    {
        if (!parent::forceDelete($user, $post)) {
            return false;
        }

        return $user && $user->hasRole('admin');
    }

    /**
     * Only admins can view trash.
     */
    public function viewTrashed(?Authenticatable $user): bool
    {
        return $user && $user->hasRole('admin');
    }
}
```

### Permission Examples

**Admin - full access:**
```php
UserRole::create([
    'user_id' => $admin->id,
    'role_id' => $adminRole->id,
    'organization_id' => $org->id,
    'permissions' => ['*'], // Includes all soft delete actions
]);
```

**Editor - can restore but not force delete:**
```php
UserRole::create([
    'user_id' => $editor->id,
    'role_id' => $editorRole->id,
    'organization_id' => $org->id,
    'permissions' => [
        'posts.destroy',  // Can soft delete
        'posts.trashed',  // Can view trash
        'posts.restore',  // Can restore
        // No posts.forceDelete
    ],
]);
```

**User - can only soft delete own posts:**
```php
// Permission granted via policy
UserRole::create([
    'user_id' => $user->id,
    'role_id' => $userRole->id,
    'organization_id' => $org->id,
    'permissions' => ['posts.destroy'],
]);

// Policy checks ownership
public function delete(?Authenticatable $user, Post $post): bool
{
    return parent::delete($user, $post) && $user->id === $post->user_id;
}
```

---

## Excluding Actions

Disable specific soft delete actions using `$exceptActions`.

### Example

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Post extends Model
{
    use SoftDeletes;

    // Disable force delete - only allow restore
    public static array $exceptActions = ['forceDelete'];
}
```

### Available Action Names

- `trashed` - List trashed items
- `restore` - Restore soft-deleted item
- `forceDelete` - Permanently delete

### Use Cases

**Prevent permanent deletion:**
```php
public static array $exceptActions = ['forceDelete'];
```

**Hide trash from API (use admin panel only):**
```php
public static array $exceptActions = ['trashed', 'restore', 'forceDelete'];
```

**Allow view but not restore:**
```php
public static array $exceptActions = ['restore', 'forceDelete'];
```

---

## Best Practices

### 1. Always Confirm Force Delete

```javascript
// ✅ Good - double confirmation
if (confirm('Permanently delete this post? This cannot be undone!')) {
  if (confirm('Are you absolutely sure?')) {
    await forceDelete(postId);
  }
}

// ❌ Bad - no confirmation
await forceDelete(postId);
```

### 2. Show Deletion Date

```jsx
// ✅ Good - user knows when deleted
<p>Deleted: {post.deleted_at}</p>

// ❌ Bad - no context
<p>This post was deleted</p>
```

### 3. Separate Trash UI

```jsx
// ✅ Good - dedicated trash view
<Route path="/posts/trash" element={<TrashPage />} />

// ❌ Bad - mixing deleted and active
<PostsList items={[...activePosts, ...trashedPosts]} />
```

### 4. Auto-Cleanup Old Trash

```php
// ✅ Good - schedule cleanup
// app/Console/Kernel.php
protected function schedule(Schedule $schedule)
{
    // Force delete posts trashed over 30 days ago
    $schedule->call(function () {
        Post::onlyTrashed()
            ->where('deleted_at', '<', now()->subDays(30))
            ->forceDelete();
    })->daily();
}

// ❌ Bad - trash grows forever
```

### 5. Check Related Records

```php
// ✅ Good - check before force delete
public function forceDelete(?Authenticatable $user, Post $post): bool
{
    if (!parent::forceDelete($user, $post)) {
        return false;
    }

    // Check for related records
    if ($post->comments()->exists()) {
        return false; // Cannot delete post with comments
    }

    return true;
}

// ❌ Bad - force delete without checking
// Orphans related records or causes foreign key errors
```

### 6. Use Cascade Delete

```php
// ✅ Good - database handles relations
Schema::table('comments', function (Blueprint $table) {
    $table->foreignId('post_id')
        ->constrained()
        ->onDelete('cascade'); // Delete comments when post deleted
});

// ❌ Bad - orphaned records
Schema::table('comments', function (Blueprint $table) {
    $table->foreignId('post_id')->constrained();
    // No onDelete specified
});
```

### 7. Soft Delete Related Models

```php
// ✅ Good - soft delete cascade
class Post extends Model
{
    use SoftDeletes;

    protected static function booted()
    {
        static::deleting(function ($post) {
            // Soft delete related records
            $post->comments()->delete();
        });

        static::restoring(function ($post) {
            // Restore related records
            $post->comments()->withTrashed()->restore();
        });
    }
}

// ❌ Bad - related records not handled
```

### 8. Test Soft Delete Operations

```php
public function test_soft_delete_sets_deleted_at()
{
    $post = Post::factory()->create();

    $this->deleteJson("/api/posts/{$post->id}");

    $this->assertSoftDeleted('posts', ['id' => $post->id]);
}

public function test_restore_clears_deleted_at()
{
    $post = Post::factory()->create();
    $post->delete();

    $this->postJson("/api/posts/{$post->id}/restore");

    $this->assertDatabaseHas('posts', [
        'id' => $post->id,
        'deleted_at' => null,
    ]);
}

public function test_force_delete_removes_from_database()
{
    $post = Post::factory()->create();
    $post->delete();

    $this->deleteJson("/api/posts/{$post->id}/force-delete");

    $this->assertDatabaseMissing('posts', ['id' => $post->id]);
}
```

### 9. Log Force Deletes

```php
// ✅ Good - audit force deletes
public function forceDelete(Post $post)
{
    Log::info('Post force deleted', [
        'post_id' => $post->id,
        'title' => $post->title,
        'user_id' => auth()->id(),
    ]);

    $post->forceDelete();
}

// ❌ Bad - no audit trail
$post->forceDelete();
```

### 10. Visual Distinction

```css
/* ✅ Good - clearly mark trashed items */
.trashed-item {
  opacity: 0.6;
  background-color: #f5f5f5;
  border-left: 3px solid #999;
}

.trashed-item::before {
  content: '🗑️ ';
}
```

---

## Related Documentation

- [API Reference - Soft Deletes](../API.md#soft-delete-endpoints)
- [Authorization](./authorization.md) - Permissions and policies
- [Audit Trail](./audit-trail.md) - Track deletions
- [Getting Started](../getting-started.md)
