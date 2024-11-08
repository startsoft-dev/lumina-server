# Authentication

Laravel Global Controller provides built-in authentication endpoints using Laravel Sanctum for API token-based authentication.

## Table of Contents

- [Overview](#overview)
- [Setup](#setup)
- [Login](#login)
- [Logout](#logout)
- [Password Recovery](#password-recovery)
- [Registration](#registration)
- [Token Management](#token-management)
- [Protecting Routes](#protecting-routes)
- [Best Practices](#best-practices)

---

## Overview

The package includes a complete authentication system with:

- **Login** - Email/password authentication with token generation
- **Logout** - Token revocation
- **Password Recovery** - Email-based password reset
- **Password Reset** - Token-based password update
- **Registration** - User registration with invitation tokens

All authentication uses **Laravel Sanctum** for API token management.

---

## Setup

### 1. Install Laravel Sanctum

```bash
php artisan install:api
```

This creates the `personal_access_tokens` table migration.

### 2. Run Migrations

```bash
php artisan migrate
```

### 3. Update User Model

Add the `HasApiTokens` trait to your User model:

```php
<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, Notifiable;

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

### 4. Configure Sanctum Middleware

In `config/sanctum.php`, ensure your API routes are configured:

```php
'middleware' => [
    'encrypt_cookies' => App\Http\Middleware\EncryptCookies::class,
    'verify_csrf_token' => App\Http\Middleware\VerifyCsrfToken::class,
],
```

---

## Login

### Endpoint

`POST /api/auth/login`

### Request

```bash
curl -X POST "http://localhost:8000/api/auth/login" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "email": "user@example.com",
    "password": "password"
  }'
```

### Response (200 OK)

```json
{
  "user": {
    "id": 1,
    "name": "John Doe",
    "email": "user@example.com",
    "email_verified_at": "2024-01-01T00:00:00.000000Z",
    "created_at": "2024-01-01T00:00:00.000000Z",
    "updated_at": "2024-01-15T10:30:00.000000Z"
  },
  "token": "1|abcdefghijklmnopqrstuvwxyz123456",
  "organization_slug": "acme-corp"
}
```

### Error Response (401 Unauthorized)

```json
{
  "message": "Invalid credentials"
}
```

### Using the Token

Include the token in the `Authorization` header for subsequent requests:

```bash
curl -X GET "http://localhost:8000/api/posts" \
  -H "Authorization: Bearer 1|abcdefghijklmnopqrstuvwxyz123456" \
  -H "Accept: application/json"
```

---

## Logout

### Endpoint

`POST /api/auth/logout`

### Request

```bash
curl -X POST "http://localhost:8000/api/auth/logout" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json"
```

### Response (200 OK)

```json
{
  "message": "Logged out successfully"
}
```

### What Happens

- Current access token is revoked
- User must log in again to get a new token
- Other active tokens (on other devices) remain valid

---

## Password Recovery

### Step 1: Request Reset Link

**Endpoint:** `POST /api/auth/password/recover`

**Request:**
```bash
curl -X POST "http://localhost:8000/api/auth/password/recover" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "email": "user@example.com"
  }'
```

**Response (200 OK):**
```json
{
  "message": "Password reset link sent to your email"
}
```

**What Happens:**
- Laravel generates a password reset token
- Email sent to user with reset link
- Token expires after configured time (default: 60 minutes)

### Step 2: Reset Password

**Endpoint:** `POST /api/auth/password/reset`

**Request:**
```bash
curl -X POST "http://localhost:8000/api/auth/password/reset" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "email": "user@example.com",
    "token": "reset-token-from-email",
    "password": "newpassword",
    "password_confirmation": "newpassword"
  }'
```

**Response (200 OK):**
```json
{
  "message": "Password reset successfully"
}
```

**Error Response (422 Unprocessable Entity):**
```json
{
  "message": "Invalid or expired token",
  "errors": {
    "email": ["We can't find a user with that email address."]
  }
}
```

### Email Configuration

Configure your mail settings in `.env`:

```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.mailtrap.io
MAIL_PORT=2525
MAIL_USERNAME=your-username
MAIL_PASSWORD=your-password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS="noreply@yourapp.com"
MAIL_FROM_NAME="${APP_NAME}"
```

For development, use [Mailtrap](https://mailtrap.io) or `log` driver:

```env
MAIL_MAILER=log
```

---

## Registration

Registration requires an invitation token. See [Invitations Guide](./invitations.md) for details.

### Endpoint

`POST /api/auth/register`

### Request

```bash
curl -X POST "http://localhost:8000/api/auth/register" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "invitation_token": "abc123def456",
    "name": "New User",
    "password": "password",
    "password_confirmation": "password"
  }'
```

### Response (201 Created)

```json
{
  "user": {
    "id": 2,
    "name": "New User",
    "email": "newuser@example.com",
    "created_at": "2024-01-16T15:00:00.000000Z"
  },
  "token": "2|xyz789abc123...",
  "organization_slug": "acme-corp"
}
```

### What Happens

- User account created
- Invitation marked as accepted
- User added to organization with specified role
- Access token generated
- User is logged in

---

## Token Management

### Token Abilities

Sanctum supports token abilities (permissions). Example:

```php
// Generate token with specific abilities
$token = $user->createToken('api-token', ['posts:create', 'posts:update'])->plainTextToken;

// Check abilities in middleware or controllers
if ($user->tokenCan('posts:create')) {
    // User can create posts
}
```

### Token Expiration

Configure token lifetime in `config/sanctum.php`:

```php
'expiration' => 60 * 24, // 24 hours (in minutes)
```

Set to `null` for non-expiring tokens:

```php
'expiration' => null,
```

### Revoking All Tokens

Revoke all user tokens (logout from all devices):

```php
// In a controller
$user->tokens()->delete();
```

### Multiple Tokens

Users can have multiple active tokens (e.g., web, mobile, tablet):

```php
// Generate named tokens
$webToken = $user->createToken('web-app')->plainTextToken;
$mobileToken = $user->createToken('mobile-app')->plainTextToken;

// Revoke specific token
$user->tokens()->where('name', 'web-app')->delete();
```

---

## Protecting Routes

### Using Sanctum Middleware

Protect routes in `routes/api.php`:

```php
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    Route::get('/profile', [ProfileController::class, 'show']);
});
```

### Public vs Protected Routes

Configure in `config/lumina.php`:

```php
return [
    'models' => [
        'users' => \App\Models\User::class,
        'posts' => \App\Models\Post::class,
        'comments' => \App\Models\Comment::class,
    ],

    // Public models - no authentication required
    'public' => [
        'posts',  // Anyone can access GET /api/posts
    ],

    // All other models require authentication by default
];
```

### Per-Model Middleware

Apply additional middleware to specific models:

```php
class Post extends Model
{
    // Apply to all CRUD actions
    public static array $middleware = [
        'throttle:60,1', // Rate limiting
    ];

    // Apply to specific actions
    public static array $middlewareActions = [
        'store' => ['verified'], // Only verified users can create
        'update' => ['verified'],
        'destroy' => ['verified'],
    ];
}
```

---

## Best Practices

### 1. Use HTTPS in Production

Always use HTTPS for API authentication:

```env
# .env
APP_URL=https://yourapp.com
SANCTUM_STATEFUL_DOMAINS=yourapp.com,www.yourapp.com
```

### 2. Implement Rate Limiting

Protect login endpoint from brute force:

```php
Route::middleware('throttle:5,1')->group(function () {
    Route::post('/auth/login', [AuthController::class, 'login']);
});
```

### 3. Hash Passwords Properly

Laravel automatically hashes passwords. Never store plain text:

```php
// ✅ Good - Laravel hashes automatically
User::create([
    'name' => 'John',
    'email' => 'john@example.com',
    'password' => 'password', // Hashed via $casts
]);

// ❌ Bad - Manual hashing unnecessary
User::create([
    'password' => Hash::make('password'), // Don't do this with $casts
]);
```

### 4. Validate Email Format

Always validate email addresses:

```php
$request->validate([
    'email' => 'required|email|exists:users,email',
]);
```

### 5. Use Token Names

Name tokens for better management:

```php
$token = $user->createToken('mobile-app-v2')->plainTextToken;
```

### 6. Implement 2FA (Optional)

For enhanced security, consider two-factor authentication:

```bash
composer require laravel/fortify
```

### 7. Monitor Failed Login Attempts

Log failed attempts for security auditing:

```php
// In AuthController
if (!Auth::attempt($credentials)) {
    Log::warning('Failed login attempt', [
        'email' => $request->email,
        'ip' => $request->ip(),
    ]);

    return response()->json(['message' => 'Invalid credentials'], 401);
}
```

### 8. Clear Expired Tokens

Schedule token cleanup:

```php
// app/Console/Kernel.php
protected function schedule(Schedule $schedule)
{
    $schedule->command('sanctum:prune-expired --hours=24')->daily();
}
```

### 9. Handle Token Errors Gracefully

Return clear error messages:

```php
// In exception handler
if ($exception instanceof AuthenticationException) {
    return response()->json([
        'message' => 'Unauthenticated. Please log in.'
    ], 401);
}
```

### 10. Test Authentication Flow

Write tests for auth endpoints:

```php
public function test_user_can_login()
{
    $user = User::factory()->create([
        'password' => 'password',
    ]);

    $response = $this->postJson('/api/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertOk()
        ->assertJsonStructure(['user', 'token']);
}
```

---

## Related Documentation

- [API Reference - Authentication](../API.md#authentication-endpoints)
- [Authorization](./authorization.md) - Permissions and policies
- [Invitations](./invitations.md) - User invitation system
- [Getting Started](../getting-started.md)
