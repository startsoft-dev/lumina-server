# Audit Trail

The `HasAuditTrail` trait automatically logs all creates, updates, deletes, and restores for complete change tracking.

## Table of Contents

- [Overview](#overview)
- [Installation](#installation)
- [Setup](#setup)
- [What Gets Logged](#what-gets-logged)
- [Querying Audit Logs](#querying-audit-logs)
- [Excluding Sensitive Columns](#excluding-sensitive-columns)
- [Custom Audit Logic](#custom-audit-logic)
- [Best Practices](#best-practices)

---

## Overview

The audit trail system provides automatic change tracking for any model with zero configuration required.

**Features:**
- Automatic logging of creates, updates, deletes, restores
- Captures old and new values
- Records user, organization, IP, and user agent
- Polymorphic relationship to any model
- No built-in API endpoint (use in your own controllers)

**Use Cases:**
- Compliance and regulatory requirements
- Debugging data issues
- User activity tracking
- Security auditing
- Rollback/undo functionality

---

## Installation

### 1. Install Migration

```bash
php artisan lumina:audit-trail
```

This creates the migration file:
```
database/migrations/xxxx_create_audit_logs_table.php
```

### 2. Run Migration

```bash
php artisan migrate
```

Creates the `audit_logs` table with columns:
- `id` - Primary key
- `action` - Action performed (created, updated, deleted, etc.)
- `user_id` - User who performed action (nullable)
- `organization_id` - Organization context (nullable)
- `model_type` - Model class name
- `model_id` - Model ID
- `old_values` - JSON of old values
- `new_values` - JSON of new values
- `ip_address` - Request IP
- `user_agent` - Request user agent
- `timestamps` - Created at

---

## Setup

Add the `HasAuditTrail` trait to any model you want to track:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Lumina\LaravelApi\Traits\HasAuditTrail;

class Post extends Model
{
    use HasAuditTrail;

    protected $fillable = [
        'title',
        'content',
        'user_id',
        'is_published',
    ];
}
```

That's it! All changes are now logged automatically.

---

## What Gets Logged

### Created Event

**Trigger:** New model created

**Action:** `created`

**Old Values:** `null`

**New Values:** All model attributes

**Example:**
```json
{
  "action": "created",
  "user_id": 1,
  "model_type": "App\\Models\\Post",
  "model_id": 5,
  "old_values": null,
  "new_values": {
    "id": 5,
    "title": "New Post",
    "content": "Content here",
    "user_id": 1,
    "is_published": false,
    "created_at": "2024-01-16T10:00:00.000000Z",
    "updated_at": "2024-01-16T10:00:00.000000Z"
  },
  "ip_address": "192.168.1.1",
  "user_agent": "Mozilla/5.0...",
  "created_at": "2024-01-16T10:00:00.000000Z"
}
```

### Updated Event

**Trigger:** Model updated

**Action:** `updated`

**Old Values:** Only changed fields (original values)

**New Values:** Only changed fields (new values)

**Example:**
```json
{
  "action": "updated",
  "user_id": 1,
  "model_type": "App\\Models\\Post",
  "model_id": 5,
  "old_values": {
    "title": "Old Title",
    "is_published": false
  },
  "new_values": {
    "title": "Updated Title",
    "is_published": true
  },
  "ip_address": "192.168.1.1",
  "created_at": "2024-01-16T11:00:00.000000Z"
}
```

Only `title` and `is_published` changed, so only those fields are logged.

### Deleted Event (Soft Delete)

**Trigger:** Soft delete performed

**Action:** `deleted`

**Old Values:** All attributes before deletion

**New Values:** `null`

**Example:**
```json
{
  "action": "deleted",
  "user_id": 1,
  "model_type": "App\\Models\\Post",
  "model_id": 5,
  "old_values": {
    "id": 5,
    "title": "Post Title",
    "content": "Content",
    "deleted_at": null
  },
  "new_values": null,
  "created_at": "2024-01-16T12:00:00.000000Z"
}
```

### Restored Event

**Trigger:** Soft-deleted model restored

**Action:** `restored`

**Old Values:** `null`

**New Values:** All attributes after restore

**Example:**
```json
{
  "action": "restored",
  "user_id": 1,
  "model_type": "App\\Models\\Post",
  "model_id": 5,
  "old_values": null,
  "new_values": {
    "id": 5,
    "title": "Post Title",
    "deleted_at": null
  },
  "created_at": "2024-01-16T13:00:00.000000Z"
}
```

### Force Deleted Event

**Trigger:** Permanent deletion

**Action:** `force_deleted`

**Old Values:** All attributes before deletion

**New Values:** `null`

**Example:**
```json
{
  "action": "force_deleted",
  "user_id": 1,
  "model_type": "App\\Models\\Post",
  "model_id": 5,
  "old_values": {
    "id": 5,
    "title": "Post Title",
    "content": "Content"
  },
  "new_values": null,
  "created_at": "2024-01-16T14:00:00.000000Z"
}
```

---

## Querying Audit Logs

### Access via Relationship

```php
// Get all audit logs for a post
$post = Post::find(1);
$logs = $post->auditLogs;

foreach ($logs as $log) {
    echo "{$log->action} by user {$log->user_id} at {$log->created_at}";
}
```

### Filter by Action

```php
// Get all updates
$updates = $post->auditLogs()
    ->where('action', 'updated')
    ->get();

// Get all deletes
$deletes = $post->auditLogs()
    ->where('action', 'deleted')
    ->get();
```

### Get User Activity

```php
use Lumina\LaravelApi\Models\AuditLog;

// All actions by a specific user
$userActivity = AuditLog::where('user_id', 1)
    ->latest('id')
    ->get();

// User activity in specific organization
$orgActivity = AuditLog::where('user_id', 1)
    ->where('organization_id', $org->id)
    ->latest('id')
    ->get();
```

### Get Recent Changes

```php
// Last 50 changes across all models
$recentChanges = AuditLog::latest('id')
    ->limit(50)
    ->get();

// Recent changes to posts
$recentPostChanges = AuditLog::where('model_type', Post::class)
    ->latest('id')
    ->limit(20)
    ->get();
```

### Date Range Queries

```php
// Changes in last 24 hours
$recent = AuditLog::where('created_at', '>=', now()->subDay())
    ->latest('id')
    ->get();

// Changes in specific date range
$logs = AuditLog::whereBetween('created_at', [
    '2024-01-01 00:00:00',
    '2024-01-31 23:59:59'
])->get();
```

### Custom Controller Endpoint

```php
<?php

namespace App\Http\Controllers;

use App\Models\Post;
use Illuminate\Http\Request;

class PostAuditController extends Controller
{
    public function show(Post $post)
    {
        $logs = $post->auditLogs()
            ->with('user') // Eager load user
            ->latest('id')
            ->paginate(50);

        return response()->json($logs);
    }
}
```

**Route:**
```php
Route::get('/posts/{post}/audit', [PostAuditController::class, 'show'])
    ->middleware('auth:sanctum');
```

---

## Excluding Sensitive Columns

By default, `password` and `remember_token` are excluded. Add more exclusions:

```php
<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Lumina\LaravelApi\Traits\HasAuditTrail;

class User extends Authenticatable
{
    use HasAuditTrail;

    /**
     * Columns to exclude from audit logs.
     */
    public static array $auditExclude = [
        'password',
        'remember_token',
        'api_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];
}
```

**What gets logged:**

```php
// Before update
$user->update([
    'name' => 'John Doe',
    'password' => 'newpassword',
    'api_token' => 'secret123',
]);

// Audit log only shows
{
  "old_values": {"name": "Old Name"},
  "new_values": {"name": "John Doe"}
}
// password and api_token excluded
```

---

## Custom Audit Logic

### Add Custom Data

Use model events to add custom audit data:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Lumina\LaravelApi\Traits\HasAuditTrail;

class Post extends Model
{
    use HasAuditTrail;

    protected static function booted()
    {
        static::updated(function ($post) {
            // Custom audit logic after standard logging
            if ($post->isDirty('is_published') && $post->is_published) {
                // Log publication event separately
                activity()
                    ->performedOn($post)
                    ->log('Post published');
            }
        });
    }
}
```

### Query Specific Changes

```php
// Find who published a post
$publishLog = $post->auditLogs()
    ->where('action', 'updated')
    ->whereJsonContains('new_values->is_published', true)
    ->first();

echo "Published by user {$publishLog->user_id}";
```

### Detect Field Changes

```php
// Find all title changes
$titleChanges = $post->auditLogs()
    ->where('action', 'updated')
    ->whereNotNull('old_values->title')
    ->get();

foreach ($titleChanges as $log) {
    echo "Title changed from '{$log->old_values['title']}' to '{$log->new_values['title']}'";
}
```

---

## Best Practices

### 1. Exclude Sensitive Data

```php
// ✅ Good - exclude passwords, tokens, secrets
public static array $auditExclude = [
    'password',
    'api_token',
    'secret_key',
];

// ❌ Bad - logging sensitive data
// (No exclusions)
```

### 2. Limit Audit Log Retention

```php
// ✅ Good - clean up old logs
// app/Console/Kernel.php
protected function schedule(Schedule $schedule)
{
    // Delete audit logs older than 1 year
    $schedule->call(function () {
        AuditLog::where('created_at', '<', now()->subYear())
            ->delete();
    })->daily();
}

// ❌ Bad - audit logs grow forever
```

### 3. Index Audit Queries

```php
// ✅ Good - add indexes for common queries
Schema::table('audit_logs', function (Blueprint $table) {
    $table->index(['model_type', 'model_id']);
    $table->index('user_id');
    $table->index('created_at');
});

// ❌ Bad - slow queries on large audit tables
```

### 4. Protect Audit Endpoints

```php
// ✅ Good - admin-only access
Route::get('/audit', [AuditController::class, 'index'])
    ->middleware(['auth:sanctum', 'role:admin']);

// ❌ Bad - public audit logs
Route::get('/audit', [AuditController::class, 'index']);
```

### 5. Display Human-Readable Changes

```php
// ✅ Good - format for users
public function getFormattedChanges()
{
    $changes = [];

    foreach ($this->new_values as $field => $newValue) {
        $oldValue = $this->old_values[$field] ?? null;

        $changes[] = [
            'field' => ucwords(str_replace('_', ' ', $field)),
            'from' => $this->formatValue($oldValue),
            'to' => $this->formatValue($newValue),
        ];
    }

    return $changes;
}

// ❌ Bad - raw JSON to users
echo json_encode($log->new_values);
```

### 6. Aggregate Audit Data

```php
// ✅ Good - useful insights
public function getUserActivityStats($userId)
{
    return AuditLog::where('user_id', $userId)
        ->selectRaw('action, COUNT(*) as count')
        ->groupBy('action')
        ->get();
}

// Result:
// [
//   {action: 'created', count: 50},
//   {action: 'updated', count: 120},
//   {action: 'deleted', count: 10}
// ]
```

### 7. Export for Compliance

```php
// ✅ Good - CSV export for audits
public function exportAuditLog($startDate, $endDate)
{
    $logs = AuditLog::whereBetween('created_at', [$startDate, $endDate])
        ->get();

    $csv = \League\Csv\Writer::createFromString();
    $csv->insertOne(['Date', 'User', 'Action', 'Model', 'Changes']);

    foreach ($logs as $log) {
        $csv->insertOne([
            $log->created_at,
            $log->user_id,
            $log->action,
            $log->model_type,
            json_encode($log->new_values)
        ]);
    }

    return $csv->toString();
}
```

### 8. Monitor Suspicious Activity

```php
// ✅ Good - detect bulk deletions
protected function schedule(Schedule $schedule)
{
    $schedule->call(function () {
        $recentDeletes = AuditLog::where('action', 'deleted')
            ->where('created_at', '>=', now()->subHour())
            ->count();

        if ($recentDeletes > 100) {
            // Alert admins
            \Log::alert("Suspicious activity: {$recentDeletes} deletions in last hour");
        }
    })->hourly();
}
```

### 9. Test Audit Logging

```php
public function test_audit_logs_model_creation()
{
    $post = Post::factory()->create([
        'title' => 'Test Post',
    ]);

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'created',
        'model_type' => Post::class,
        'model_id' => $post->id,
    ]);

    $log = AuditLog::where('model_id', $post->id)
        ->where('action', 'created')
        ->first();

    $this->assertEquals('Test Post', $log->new_values['title']);
}

public function test_audit_logs_only_changed_fields()
{
    $post = Post::factory()->create([
        'title' => 'Original',
        'content' => 'Content',
    ]);

    $post->update(['title' => 'Updated']);

    $log = AuditLog::where('model_id', $post->id)
        ->where('action', 'updated')
        ->first();

    $this->assertArrayHasKey('title', $log->old_values);
    $this->assertArrayNotHasKey('content', $log->old_values);
}
```

### 10. Document Audit Usage

```php
/**
 * Post Model
 *
 * Audit Trail:
 * - Logs all creates, updates, deletes, restores
 * - Excludes: internal_notes, draft_content
 * - Retention: 1 year
 * - Access: Admin only via /api/posts/{id}/audit
 */
class Post extends Model
{
    use HasAuditTrail;

    public static array $auditExclude = [
        'internal_notes',
        'draft_content',
    ];
}
```

---

## Related Documentation

- [API Reference](../API.md) - API endpoint reference
- [Soft Deletes](./soft-deletes.md) - Audit deleted records
- [Authorization](./authorization.md) - Protect audit endpoints
- [Getting Started](../getting-started.md)
