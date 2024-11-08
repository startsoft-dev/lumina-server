# Invitations

User invitation system for adding members to organizations with email-based workflow and secure token verification.

## Table of Contents

- [Overview](#overview)
- [Database Setup](#database-setup)
- [Email Configuration](#email-configuration)
- [Invitation Workflow](#invitation-workflow)
- [Creating Invitations](#creating-invitations)
- [Accepting Invitations](#accepting-invitations)
- [Managing Invitations](#managing-invitations)
- [Permissions](#permissions)
- [Best Practices](#best-practices)

---

## Overview

The invitation system allows existing users to invite new members to join their organization via email.

**Key Features:**
- Email-based invitations with secure tokens
- Configurable expiration (default 7 days)
- Role assignment on invitation
- Status tracking (pending, accepted, expired, cancelled)
- Resend functionality
- Organization-scoped permissions

**Workflow:**
1. Admin creates invitation with email and role
2. System generates secure token and sends email
3. Invitee clicks link, creates account with token
4. Account automatically linked to organization with assigned role
5. Invitee can log in immediately

**Statuses:**
- `pending` - Invitation sent, awaiting acceptance
- `accepted` - User accepted and created account
- `expired` - Invitation past expiration date
- `cancelled` - Invitation manually cancelled by admin

---

## Database Setup

### Invitations Table

Create the invitations migration:

```bash
php artisan make:migration create_invitations_table
```

**Migration:**
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->string('email');
            $table->foreignId('role_id')->constrained();
            $table->string('token')->unique();
            $table->enum('status', ['pending', 'accepted', 'expired', 'cancelled'])
                ->default('pending');
            $table->foreignId('invited_by_id')->constrained('users');
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            // Prevent duplicate invitations for same email in organization
            $table->unique(['organization_id', 'email', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }
};
```

**Key Fields:**
- `organization_id` - Which organization the user is invited to
- `email` - Invitee's email address
- `role_id` - Role to assign when accepted
- `token` - Unique secure token for verification
- `status` - Current invitation state
- `invited_by_id` - User who created the invitation
- `expires_at` - Expiration timestamp (typically +7 days)
- `accepted_at` - When invitation was accepted (null until accepted)

### Invitation Model

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Invitation extends Model
{
    protected $fillable = [
        'organization_id',
        'email',
        'role_id',
        'token',
        'status',
        'invited_by_id',
        'expires_at',
        'accepted_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'accepted_at' => 'datetime',
    ];

    // Relationships

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function invitedBy()
    {
        return $this->belongsTo(User::class, 'invited_by_id');
    }

    // Helpers

    /**
     * Generate a secure token.
     */
    public static function generateToken(): string
    {
        return Str::random(64);
    }

    /**
     * Check if invitation is expired.
     */
    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * Check if invitation is still valid.
     */
    public function isValid(): bool
    {
        return $this->status === 'pending' && !$this->isExpired();
    }

    /**
     * Mark invitation as accepted.
     */
    public function markAsAccepted(): void
    {
        $this->update([
            'status' => 'accepted',
            'accepted_at' => now(),
        ]);
    }

    /**
     * Mark invitation as cancelled.
     */
    public function cancel(): void
    {
        $this->update(['status' => 'cancelled']);
    }

    // Scopes

    public function scopePending($query)
    {
        return $query->where('status', 'pending')
            ->where('expires_at', '>', now());
    }

    public function scopeExpired($query)
    {
        return $query->where('status', 'pending')
            ->where('expires_at', '<=', now());
    }

    public function scopeForOrganization($query, $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }
}
```

---

## Email Configuration

### Create Invitation Email Mailable

```bash
php artisan make:mail InvitationEmail
```

**Implementation:**

```php
<?php

namespace App\Mail;

use App\Models\Invitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class InvitationEmail extends Mailable
{
    use Queueable, SerializesModels;

    public $invitation;
    public $acceptUrl;

    public function __construct(Invitation $invitation)
    {
        $this->invitation = $invitation;

        // Build accept URL
        // Frontend URL with token parameter
        $this->acceptUrl = config('app.frontend_url')
            . '/invitations/accept?token=' . $invitation->token;
    }

    public function build()
    {
        return $this->subject("You've been invited to join " . $this->invitation->organization->name)
            ->markdown('emails.invitation');
    }
}
```

### Email Template

Create `resources/views/emails/invitation.blade.php`:

```blade
@component('mail::message')
# You've been invited!

{{ $invitation->invitedBy->name }} has invited you to join **{{ $invitation->organization->name }}** as a **{{ $invitation->role->name }}**.

@component('mail::button', ['url' => $acceptUrl])
Accept Invitation
@endcomponent

This invitation will expire on {{ $invitation->expires_at->format('F j, Y') }}.

If you did not expect this invitation, you can safely ignore this email.

Thanks,<br>
{{ config('app.name') }}
@endcomponent
```

### Configure Frontend URL

In `.env`:

```
FRONTEND_URL=http://localhost:3000
```

In `config/app.php`:

```php
return [
    // ...
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000'),
];
```

### Queue Configuration (Recommended)

Emails should be queued for better performance:

```php
use Illuminate\Support\Facades\Mail;
use App\Mail\InvitationEmail;

// Queue email (recommended)
Mail::to($invitation->email)->queue(new InvitationEmail($invitation));

// Send immediately (not recommended for production)
Mail::to($invitation->email)->send(new InvitationEmail($invitation));
```

Configure queue in `.env`:

```
QUEUE_CONNECTION=database
# or redis, sqs, etc.
```

Run queue worker:

```bash
php artisan queue:work
```

---

## Invitation Workflow

### Complete Flow Diagram

```
1. Admin creates invitation
   ↓
2. System generates token, saves to database
   ↓
3. Email sent to invitee with accept link
   ↓
4. Invitee clicks link → Frontend accept page
   ↓
5. User enters name and password
   ↓
6. Frontend posts to /api/invitations/accept with token
   ↓
7. Backend validates token, creates user account
   ↓
8. User linked to organization with assigned role
   ↓
9. Invitation marked as accepted
   ↓
10. User receives auth token, can log in
```

### Example Implementation

**Controller:**

```php
<?php

namespace App\Http\Controllers;

use App\Models\Invitation;
use App\Models\User;
use App\Models\UserRole;
use App\Mail\InvitationEmail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Hash;

class InvitationController extends Controller
{
    /**
     * Create a new invitation.
     */
    public function store(Request $request)
    {
        $organization = $request->get('organization');

        $request->validate([
            'email' => 'required|email',
            'role_id' => 'required|exists:roles,id',
        ]);

        // Check if user already exists in organization
        $existingUser = User::where('email', $request->email)->first();
        if ($existingUser) {
            $belongsToOrg = $existingUser->organizations()
                ->where('organizations.id', $organization->id)
                ->exists();

            if ($belongsToOrg) {
                return response()->json([
                    'message' => 'User is already a member of this organization'
                ], 422);
            }
        }

        // Check for existing pending invitation
        $existingInvitation = Invitation::where('organization_id', $organization->id)
            ->where('email', $request->email)
            ->where('status', 'pending')
            ->first();

        if ($existingInvitation) {
            if ($existingInvitation->isExpired()) {
                // Update expired invitation
                $existingInvitation->update([
                    'role_id' => $request->role_id,
                    'token' => Invitation::generateToken(),
                    'invited_by_id' => auth()->id(),
                    'expires_at' => now()->addDays(7),
                ]);

                $invitation = $existingInvitation;
            } else {
                return response()->json([
                    'message' => 'An invitation is already pending for this email'
                ], 422);
            }
        } else {
            // Create new invitation
            $invitation = Invitation::create([
                'organization_id' => $organization->id,
                'email' => $request->email,
                'role_id' => $request->role_id,
                'token' => Invitation::generateToken(),
                'invited_by_id' => auth()->id(),
                'expires_at' => now()->addDays(7),
                'status' => 'pending',
            ]);
        }

        // Send invitation email
        Mail::to($invitation->email)->queue(new InvitationEmail($invitation));

        return response()->json($invitation->load(['role', 'invitedBy']), 201);
    }

    /**
     * Accept an invitation.
     */
    public function accept(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
            'name' => 'required|string|max:255',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $invitation = Invitation::where('token', $request->token)->first();

        if (!$invitation) {
            return response()->json([
                'message' => 'Invalid invitation token'
            ], 404);
        }

        if (!$invitation->isValid()) {
            return response()->json([
                'message' => 'Invitation has expired or is no longer valid'
            ], 422);
        }

        // Check if user already exists
        $existingUser = User::where('email', $invitation->email)->first();

        if ($existingUser) {
            return response()->json([
                'message' => 'An account with this email already exists. Please log in.'
            ], 422);
        }

        DB::beginTransaction();
        try {
            // Create user account
            $user = User::create([
                'name' => $request->name,
                'email' => $invitation->email,
                'password' => Hash::make($request->password),
                'email_verified_at' => now(), // Auto-verify via invitation
            ]);

            // Link user to organization with assigned role
            UserRole::create([
                'user_id' => $user->id,
                'role_id' => $invitation->role_id,
                'organization_id' => $invitation->organization_id,
                'permissions' => [], // Permissions from role defaults
            ]);

            // Mark invitation as accepted
            $invitation->markAsAccepted();

            // Generate auth token
            $token = $user->createToken('auth-token')->plainTextToken;

            DB::commit();

            return response()->json([
                'message' => 'Invitation accepted successfully',
                'user' => $user,
                'token' => $token,
                'organization' => $invitation->organization,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * List invitations for organization.
     */
    public function index(Request $request)
    {
        $organization = $request->get('organization');

        $query = Invitation::where('organization_id', $organization->id)
            ->with(['role', 'invitedBy']);

        // Filter by status
        if ($request->has('filter.status')) {
            $query->where('status', $request->input('filter.status'));
        }

        // Sort
        $sort = $request->input('sort', '-created_at');
        $sortField = ltrim($sort, '-');
        $sortDirection = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $query->orderBy($sortField, $sortDirection);

        // Paginate or get all
        if ($request->has('per_page')) {
            $invitations = $query->paginate($request->input('per_page', 15));
            return response()->json($invitations->items());
        }

        return response()->json($query->get());
    }

    /**
     * Resend invitation email.
     */
    public function resend(Request $request, $id)
    {
        $organization = $request->get('organization');

        $invitation = Invitation::where('id', $id)
            ->where('organization_id', $organization->id)
            ->firstOrFail();

        if (!$invitation->isValid()) {
            return response()->json([
                'message' => 'Cannot resend invalid or expired invitation'
            ], 422);
        }

        // Send email
        Mail::to($invitation->email)->queue(new InvitationEmail($invitation));

        return response()->json([
            'message' => 'Invitation resent successfully'
        ]);
    }

    /**
     * Cancel invitation.
     */
    public function cancel(Request $request, $id)
    {
        $organization = $request->get('organization');

        $invitation = Invitation::where('id', $id)
            ->where('organization_id', $organization->id)
            ->firstOrFail();

        if ($invitation->status !== 'pending') {
            return response()->json([
                'message' => 'Only pending invitations can be cancelled'
            ], 422);
        }

        $invitation->cancel();

        return response()->json([
            'message' => 'Invitation cancelled successfully'
        ]);
    }
}
```

---

## Creating Invitations

### API Endpoint

`POST /api/{organization}/invitations`

### Request

```bash
curl -X POST "http://localhost:8000/api/acme-corp/invitations" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d '{
    "email": "newuser@example.com",
    "role_id": 2
  }'
```

### Response (201 Created)

```json
{
  "id": 1,
  "organization_id": 1,
  "email": "newuser@example.com",
  "role_id": 2,
  "status": "pending",
  "invited_by_id": 1,
  "token": "abc123def456...",
  "expires_at": "2024-01-23T10:00:00.000000Z",
  "created_at": "2024-01-16T10:00:00.000000Z",
  "role": {
    "id": 2,
    "name": "Member",
    "slug": "member"
  },
  "invited_by": {
    "id": 1,
    "name": "Admin User",
    "email": "admin@acme-corp.com"
  }
}
```

### Validation

**Required fields:**
- `email` - Valid email address
- `role_id` - Must exist in roles table

**Business rules:**
- User cannot already be member of organization
- Email cannot have pending invitation in same organization
- User must have `invitations.store` permission

### Frontend Example (React)

```jsx
function InviteUserForm({ organizationSlug }) {
  const [email, setEmail] = useState('');
  const [roleId, setRoleId] = useState('');

  const handleSubmit = async (e) => {
    e.preventDefault();

    try {
      const response = await api.post(
        `/api/${organizationSlug}/invitations`,
        { email, role_id: roleId }
      );

      alert('Invitation sent successfully!');
      setEmail('');
      setRoleId('');
    } catch (error) {
      if (error.response?.status === 422) {
        alert(error.response.data.message);
      } else {
        alert('Failed to send invitation');
      }
    }
  };

  return (
    <form onSubmit={handleSubmit}>
      <input
        type="email"
        value={email}
        onChange={(e) => setEmail(e.target.value)}
        placeholder="Email address"
        required
      />

      <select
        value={roleId}
        onChange={(e) => setRoleId(e.target.value)}
        required
      >
        <option value="">Select role...</option>
        <option value="2">Member</option>
        <option value="3">Admin</option>
      </select>

      <button type="submit">Send Invitation</button>
    </form>
  );
}
```

---

## Accepting Invitations

### API Endpoint

`POST /api/invitations/accept`

**Note:** This endpoint is public (no organization prefix) because the user doesn't have an account yet.

### Request

```bash
curl -X POST "http://localhost:8000/api/invitations/accept" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "token": "invitation-token-from-email",
    "name": "John Doe",
    "password": "securepassword123",
    "password_confirmation": "securepassword123"
  }'
```

### Response (200 OK)

```json
{
  "message": "Invitation accepted successfully",
  "user": {
    "id": 5,
    "name": "John Doe",
    "email": "newuser@example.com",
    "email_verified_at": "2024-01-16T11:00:00.000000Z",
    "created_at": "2024-01-16T11:00:00.000000Z"
  },
  "token": "5|abcdef123456...",
  "organization": {
    "id": 1,
    "slug": "acme-corp",
    "name": "Acme Corporation"
  }
}
```

### Validation

**Required fields:**
- `token` - Valid invitation token
- `name` - User's full name (max 255 chars)
- `password` - Min 8 characters
- `password_confirmation` - Must match password

**Business rules:**
- Token must exist and be valid (not expired/cancelled/accepted)
- Email from invitation cannot already have an account
- Creates user account
- Links user to organization with assigned role
- Marks invitation as accepted
- Returns auth token for immediate login

### Frontend Example (React)

```jsx
function AcceptInvitationPage() {
  const [searchParams] = useSearchParams();
  const token = searchParams.get('token');

  const [name, setName] = useState('');
  const [password, setPassword] = useState('');
  const [passwordConfirmation, setPasswordConfirmation] = useState('');

  const handleSubmit = async (e) => {
    e.preventDefault();

    try {
      const response = await api.post('/api/invitations/accept', {
        token,
        name,
        password,
        password_confirmation: passwordConfirmation
      });

      // Store token
      localStorage.setItem('token', response.data.token);
      localStorage.setItem('organization_slug', response.data.organization.slug);

      // Redirect to dashboard
      navigate(`/${response.data.organization.slug}/dashboard`);
    } catch (error) {
      if (error.response?.status === 422) {
        alert(error.response.data.message);
      } else if (error.response?.status === 404) {
        alert('Invalid invitation link');
      } else {
        alert('Failed to accept invitation');
      }
    }
  };

  return (
    <div>
      <h1>Accept Invitation</h1>
      <form onSubmit={handleSubmit}>
        <input
          type="text"
          value={name}
          onChange={(e) => setName(e.target.value)}
          placeholder="Full name"
          required
        />

        <input
          type="password"
          value={password}
          onChange={(e) => setPassword(e.target.value)}
          placeholder="Password"
          required
          minLength={8}
        />

        <input
          type="password"
          value={passwordConfirmation}
          onChange={(e) => setPasswordConfirmation(e.target.value)}
          placeholder="Confirm password"
          required
        />

        <button type="submit">Create Account</button>
      </form>
    </div>
  );
}
```

---

## Managing Invitations

### List Invitations

`GET /api/{organization}/invitations`

**With filters:**
```bash
GET /api/acme-corp/invitations?filter[status]=pending&sort=-created_at
```

**Response:**
```json
[
  {
    "id": 1,
    "email": "user1@example.com",
    "role_id": 2,
    "status": "pending",
    "invited_by_id": 1,
    "expires_at": "2024-01-23T00:00:00.000000Z",
    "created_at": "2024-01-16T10:00:00.000000Z",
    "role": {
      "id": 2,
      "name": "Member"
    },
    "invited_by": {
      "id": 1,
      "name": "Admin User"
    }
  }
]
```

### Resend Invitation

`POST /api/{organization}/invitations/{id}/resend`

```bash
curl -X POST "http://localhost:8000/api/acme-corp/invitations/1/resend" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

**Response:**
```json
{
  "message": "Invitation resent successfully"
}
```

**When to use:**
- Invitee didn't receive email
- Email was accidentally deleted
- Invitee needs reminder

**Limitations:**
- Only valid for pending, non-expired invitations
- Sends same token (doesn't regenerate)

### Cancel Invitation

`DELETE /api/{organization}/invitations/{id}`

```bash
curl -X DELETE "http://localhost:8000/api/acme-corp/invitations/1" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

**Response:**
```json
{
  "message": "Invitation cancelled successfully"
}
```

**When to use:**
- Invitation sent to wrong email
- Role assignment was incorrect
- No longer want to invite user

**Effects:**
- Sets status to 'cancelled'
- Invitation cannot be accepted (token no longer valid)
- Can create new invitation for same email

---

## Permissions

### Required Permissions

| Action | Permission | Description |
|--------|------------|-------------|
| Create invitation | `invitations.store` | Send invitations to new users |
| List invitations | `invitations.index` | View all organization invitations |
| Resend invitation | `invitations.resend` | Resend invitation email |
| Cancel invitation | `invitations.destroy` | Cancel pending invitations |
| Accept invitation | *public* | No authentication required |

### Setup Permissions

**Admin role:**
```php
UserRole::create([
    'user_id' => $admin->id,
    'role_id' => $adminRole->id,
    'organization_id' => $org->id,
    'permissions' => ['*'], // Includes all invitation permissions
]);
```

**Manager role (can invite but not cancel):**
```php
UserRole::create([
    'user_id' => $manager->id,
    'role_id' => $managerRole->id,
    'organization_id' => $org->id,
    'permissions' => [
        'invitations.index',
        'invitations.store',
        'invitations.resend',
        // No invitations.destroy
    ],
]);
```

**Member role (cannot invite):**
```php
UserRole::create([
    'user_id' => $member->id,
    'role_id' => $memberRole->id,
    'organization_id' => $org->id,
    'permissions' => [
        // No invitation permissions
    ],
]);
```

---

## Best Practices

### 1. Set Reasonable Expiration

```php
// ✅ Good - 7 days is standard
'expires_at' => now()->addDays(7),

// ❌ Bad - too short
'expires_at' => now()->addHours(24),

// ❌ Bad - too long
'expires_at' => now()->addMonths(6),
```

### 2. Validate Email Before Sending

```php
// ✅ Good - check for existing users/invitations
public function store(Request $request)
{
    $request->validate([
        'email' => [
            'required',
            'email',
            Rule::unique('users', 'email'), // No existing user
            Rule::unique('invitations')
                ->where('organization_id', $organization->id)
                ->where('status', 'pending'), // No pending invitation
        ],
    ]);

    // Create invitation
}

// ❌ Bad - no validation
public function store(Request $request)
{
    Invitation::create($request->all());
}
```

### 3. Use Queues for Emails

```php
// ✅ Good - queue email
Mail::to($invitation->email)->queue(new InvitationEmail($invitation));

// ❌ Bad - synchronous email (blocks request)
Mail::to($invitation->email)->send(new InvitationEmail($invitation));
```

### 4. Clean Up Expired Invitations

```php
// ✅ Good - scheduled cleanup
// In app/Console/Kernel.php
protected function schedule(Schedule $schedule)
{
    // Mark expired invitations
    $schedule->call(function () {
        Invitation::where('status', 'pending')
            ->where('expires_at', '<', now())
            ->update(['status' => 'expired']);
    })->daily();

    // Delete old expired/cancelled invitations
    $schedule->call(function () {
        Invitation::whereIn('status', ['expired', 'cancelled'])
            ->where('created_at', '<', now()->subMonths(3))
            ->delete();
    })->weekly();
}

// ❌ Bad - invitations never cleaned up
```

### 5. Prevent Duplicate Invitations

```php
// ✅ Good - check before creating
$existing = Invitation::where('organization_id', $org->id)
    ->where('email', $request->email)
    ->where('status', 'pending')
    ->first();

if ($existing && !$existing->isExpired()) {
    return response()->json([
        'message' => 'Invitation already pending for this email'
    ], 422);
}

// ❌ Bad - allow duplicates
Invitation::create($request->all());
```

### 6. Log Invitation Activity

```php
// ✅ Good - log actions
use Illuminate\Support\Facades\Log;

public function store(Request $request)
{
    // ... create invitation

    Log::info('Invitation created', [
        'invitation_id' => $invitation->id,
        'email' => $invitation->email,
        'role_id' => $invitation->role_id,
        'invited_by' => auth()->id(),
        'organization_id' => $organization->id,
    ]);

    return response()->json($invitation, 201);
}

// ❌ Bad - no logging
```

### 7. Test Email Delivery

```php
public function test_invitation_email_sent()
{
    Mail::fake();

    $this->actingAs($admin)
        ->postJson("/api/acme-corp/invitations", [
            'email' => 'test@example.com',
            'role_id' => 2,
        ]);

    Mail::assertQueued(InvitationEmail::class, function ($mail) {
        return $mail->invitation->email === 'test@example.com';
    });
}
```

### 8. Secure Token Generation

```php
// ✅ Good - cryptographically secure random string
use Illuminate\Support\Str;

$token = Str::random(64); // Uses random_bytes()

// ❌ Bad - predictable token
$token = md5($email . time());
```

### 9. Provide Clear Email Copy

```blade
{{-- ✅ Good - clear, actionable --}}
@component('mail::message')
# You've been invited!

{{ $invitation->invitedBy->name }} has invited you to join **{{ $invitation->organization->name }}**.

Click below to accept and create your account:

@component('mail::button', ['url' => $acceptUrl])
Accept Invitation
@endcomponent

This invitation expires on {{ $invitation->expires_at->format('F j, Y') }}.

Thanks,<br>
{{ config('app.name') }}
@endcomponent

{{-- ❌ Bad - vague --}}
You have an invitation. Click here.
```

### 10. Handle Edge Cases

```php
// ✅ Good - handle all cases
public function accept(Request $request)
{
    $invitation = Invitation::where('token', $request->token)->first();

    if (!$invitation) {
        return response()->json(['message' => 'Invalid token'], 404);
    }

    if ($invitation->status === 'accepted') {
        return response()->json([
            'message' => 'This invitation has already been accepted'
        ], 422);
    }

    if ($invitation->status === 'cancelled') {
        return response()->json([
            'message' => 'This invitation has been cancelled'
        ], 422);
    }

    if ($invitation->isExpired()) {
        return response()->json([
            'message' => 'This invitation has expired'
        ], 422);
    }

    // Existing user check
    if (User::where('email', $invitation->email)->exists()) {
        return response()->json([
            'message' => 'An account with this email already exists'
        ], 422);
    }

    // Continue with account creation
}

// ❌ Bad - minimal validation
public function accept(Request $request)
{
    $invitation = Invitation::where('token', $request->token)->firstOrFail();
    User::create($request->all());
}
```

---

## Related Documentation

- [Authentication](./authentication.md) - Registration with invitations
- [Authorization](./authorization.md) - Invitation permissions
- [Multi-Tenancy](./multi-tenancy.md) - Organization context
- [API Reference - Invitations](../API.md#invitation-endpoints)
- [Getting Started](../getting-started.md)
