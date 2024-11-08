# API Reference

Complete reference for all automatically generated API endpoints in Laravel Global Controller.

## Table of Contents

- [Model CRUD Endpoints](#model-crud-endpoints)
- [Soft Delete Endpoints](#soft-delete-endpoints)
- [Authentication Endpoints](#authentication-endpoints)
- [Invitation Endpoints](#invitation-endpoints)
- [Nested Operations](#nested-operations)
- [Query Parameters](#query-parameters)
- [Response Format](#response-format)
- [Error Responses](#error-responses)

---

## Model CRUD Endpoints

For each registered model in `config/lumina.php`, the following endpoints are automatically created.

### List Resources (Index)

**Endpoint:** `GET /api/{model}`

**Description:** Retrieve a paginated or complete list of resources with filtering, sorting, and includes.

**Authorization:** Checked via `PolicyClass::viewAny(?User $user)`

**Query Parameters:**
- `filter[field]` - Filter by field value
- `sort` - Sort by field (prefix with `-` for descending)
- `include` - Eager load relationships
- `fields[model]` - Select specific fields
- `search` - Full-text search (if `$allowedSearch` is defined)
- `page` - Page number (if paginated)
- `per_page` - Items per page

**Example Request:**
```bash
curl -X GET "http://localhost:8000/api/posts?filter[is_published]=true&sort=-created_at&include=user&per_page=20" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

**Example Response (200 OK):**
```json
[
  {
    "id": 1,
    "title": "Laravel Best Practices",
    "content": "Content here...",
    "user_id": 1,
    "is_published": true,
    "created_at": "2024-01-15T10:30:00.000000Z",
    "updated_at": "2024-01-15T10:30:00.000000Z",
    "user": {
      "id": 1,
      "name": "John Doe",
      "email": "john@example.com"
    }
  }
]
```

**Response Headers (when paginated):**
```
X-Current-Page: 1
X-Last-Page: 5
X-Per-Page: 20
X-Total: 95
```

---

### Show Resource

**Endpoint:** `GET /api/{model}/{id}`

**Description:** Retrieve a single resource by ID.

**Authorization:** Checked via `PolicyClass::view(?User $user, Model $model)`

**Query Parameters:**
- `include` - Eager load relationships
- `fields[model]` - Select specific fields

**Example Request:**
```bash
curl -X GET "http://localhost:8000/api/posts/1?include=user,comments" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

**Example Response (200 OK):**
```json
{
  "id": 1,
  "title": "Laravel Best Practices",
  "content": "Full content here...",
  "user_id": 1,
  "is_published": true,
  "created_at": "2024-01-15T10:30:00.000000Z",
  "updated_at": "2024-01-15T10:30:00.000000Z",
  "user": {
    "id": 1,
    "name": "John Doe",
    "email": "john@example.com"
  },
  "comments": [
    {
      "id": 1,
      "content": "Great post!",
      "user_id": 2
    }
  ]
}
```

**Error Responses:**
- `404 Not Found` - Resource doesn't exist
- `403 Forbidden` - User doesn't have permission to view

---

### Create Resource (Store)

**Endpoint:** `POST /api/{model}`

**Description:** Create a new resource.

**Authorization:** Checked via `PolicyClass::create(?User $user)`

**Request Body:** JSON object with model fields (validated by model's `$validationRulesStore`)

**Example Request:**
```bash
curl -X POST "http://localhost:8000/api/posts" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d '{
    "title": "New Post",
    "content": "Post content here",
    "user_id": 1,
    "is_published": false
  }'
```

**Example Response (201 Created):**
```json
{
  "id": 2,
  "title": "New Post",
  "content": "Post content here",
  "user_id": 1,
  "is_published": false,
  "created_at": "2024-01-16T14:20:00.000000Z",
  "updated_at": "2024-01-16T14:20:00.000000Z"
}
```

**Error Responses:**
- `422 Unprocessable Entity` - Validation failed
- `403 Forbidden` - User doesn't have permission to create

**Validation Error Example (422):**
```json
{
  "message": "The title field is required. (and 1 more error)",
  "errors": {
    "title": ["The title field is required."],
    "content": ["The content field is required."]
  }
}
```

---

### Update Resource

**Endpoint:** `PUT /api/{model}/{id}` or `PATCH /api/{model}/{id}`

**Description:** Update an existing resource.

**Authorization:** Checked via `PolicyClass::update(?User $user, Model $model)`

**Request Body:** JSON object with fields to update (validated by model's `$validationRulesUpdate`)

**Example Request:**
```bash
curl -X PUT "http://localhost:8000/api/posts/1" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d '{
    "title": "Updated Title",
    "is_published": true
  }'
```

**Example Response (200 OK):**
```json
{
  "id": 1,
  "title": "Updated Title",
  "content": "Original content",
  "user_id": 1,
  "is_published": true,
  "created_at": "2024-01-15T10:30:00.000000Z",
  "updated_at": "2024-01-16T15:10:00.000000Z"
}
```

**Error Responses:**
- `404 Not Found` - Resource doesn't exist
- `422 Unprocessable Entity` - Validation failed
- `403 Forbidden` - User doesn't have permission to update

---

### Delete Resource

**Endpoint:** `DELETE /api/{model}/{id}`

**Description:** Delete a resource. If the model uses `SoftDeletes`, this performs a soft delete (sets `deleted_at`). Otherwise, it permanently deletes.

**Authorization:** Checked via `PolicyClass::delete(?User $user, Model $model)`

**Example Request:**
```bash
curl -X DELETE "http://localhost:8000/api/posts/1" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

**Example Response (200 OK):**
```json
{
  "message": "Resource deleted successfully"
}
```

**Error Responses:**
- `404 Not Found` - Resource doesn't exist
- `403 Forbidden` - User doesn't have permission to delete

---

## Soft Delete Endpoints

These endpoints are **only available** for models using Laravel's `SoftDeletes` trait.

### List Trashed Resources

**Endpoint:** `GET /api/{model}/trashed`

**Description:** Retrieve soft-deleted resources.

**Authorization:** Checked via `PolicyClass::viewTrashed(?User $user)` or permission `{model}.trashed`

**Query Parameters:** Same as index endpoint (filtering, sorting, pagination)

**Example Request:**
```bash
curl -X GET "http://localhost:8000/api/posts/trashed?sort=-deleted_at" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

**Example Response (200 OK):**
```json
[
  {
    "id": 3,
    "title": "Deleted Post",
    "content": "Content",
    "user_id": 1,
    "is_published": false,
    "created_at": "2024-01-10T08:00:00.000000Z",
    "updated_at": "2024-01-15T12:00:00.000000Z",
    "deleted_at": "2024-01-16T10:00:00.000000Z"
  }
]
```

---

### Restore Resource

**Endpoint:** `POST /api/{model}/{id}/restore`

**Description:** Restore a soft-deleted resource (clears `deleted_at`).

**Authorization:** Checked via `PolicyClass::restore(?User $user, Model $model)` or permission `{model}.restore`

**Example Request:**
```bash
curl -X POST "http://localhost:8000/api/posts/3/restore" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

**Example Response (200 OK):**
```json
{
  "id": 3,
  "title": "Deleted Post",
  "content": "Content",
  "user_id": 1,
  "is_published": false,
  "created_at": "2024-01-10T08:00:00.000000Z",
  "updated_at": "2024-01-16T11:00:00.000000Z",
  "deleted_at": null
}
```

**Error Responses:**
- `404 Not Found` - Resource doesn't exist or isn't soft-deleted
- `403 Forbidden` - User doesn't have permission to restore

---

### Force Delete Resource

**Endpoint:** `DELETE /api/{model}/{id}/force-delete`

**Description:** Permanently delete a resource from the database. **Cannot be undone.**

**Authorization:** Checked via `PolicyClass::forceDelete(?User $user, Model $model)` or permission `{model}.forceDelete`

**Example Request:**
```bash
curl -X DELETE "http://localhost:8000/api/posts/3/force-delete" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

**Example Response (200 OK):**
```json
{
  "message": "Resource permanently deleted"
}
```

**Error Responses:**
- `404 Not Found` - Resource doesn't exist
- `403 Forbidden` - User doesn't have permission to force delete

---

## Authentication Endpoints

Built-in authentication using Laravel Sanctum.

### Login

**Endpoint:** `POST /api/auth/login`

**Description:** Authenticate user and receive API token.

**Authorization:** Public (no authentication required)

**Request Body:**
```json
{
  "email": "user@example.com",
  "password": "password"
}
```

**Example Request:**
```bash
curl -X POST "http://localhost:8000/api/auth/login" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "email": "user@example.com",
    "password": "password"
  }'
```

**Success Response (200 OK):**
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

**Error Response (401 Unauthorized):**
```json
{
  "message": "Invalid credentials"
}
```

---

### Logout

**Endpoint:** `POST /api/auth/logout`

**Description:** Revoke current API token.

**Authorization:** Requires authentication (`Bearer` token)

**Example Request:**
```bash
curl -X POST "http://localhost:8000/api/auth/logout" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

**Success Response (200 OK):**
```json
{
  "message": "Logged out successfully"
}
```

---

### Password Recovery Request

**Endpoint:** `POST /api/auth/password/recover`

**Description:** Request a password reset token via email.

**Authorization:** Public

**Request Body:**
```json
{
  "email": "user@example.com"
}
```

**Example Request:**
```bash
curl -X POST "http://localhost:8000/api/auth/password/recover" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "email": "user@example.com"
  }'
```

**Success Response (200 OK):**
```json
{
  "message": "Password reset link sent to your email"
}
```

---

### Password Reset

**Endpoint:** `POST /api/auth/password/reset`

**Description:** Reset password using token from email.

**Authorization:** Public

**Request Body:**
```json
{
  "email": "user@example.com",
  "token": "reset-token-from-email",
  "password": "newpassword",
  "password_confirmation": "newpassword"
}
```

**Example Request:**
```bash
curl -X POST "http://localhost:8000/api/auth/password/reset" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "email": "user@example.com",
    "token": "abc123...",
    "password": "newpassword",
    "password_confirmation": "newpassword"
  }'
```

**Success Response (200 OK):**
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

---

### Register

**Endpoint:** `POST /api/auth/register`

**Description:** Register a new user with an invitation token.

**Authorization:** Public (requires valid invitation token)

**Request Body:**
```json
{
  "invitation_token": "invitation-token",
  "name": "New User",
  "password": "password",
  "password_confirmation": "password"
}
```

**Success Response (201 Created):**
```json
{
  "user": {
    "id": 2,
    "name": "New User",
    "email": "newuser@example.com",
    "created_at": "2024-01-16T15:00:00.000000Z"
  },
  "token": "2|xyz789...",
  "organization_slug": "acme-corp"
}
```

---

## Invitation Endpoints

User invitation system for organization access.

### Accept Invitation

**Endpoint:** `POST /api/invitations/accept`

**Description:** Accept an invitation and create user account.

**Authorization:** Public (requires valid invitation token)

**Request Body:**
```json
{
  "token": "invitation-token",
  "name": "User Name",
  "password": "password",
  "password_confirmation": "password"
}
```

**Success Response (200 OK):**
```json
{
  "message": "Invitation accepted",
  "user": {
    "id": 3,
    "name": "User Name",
    "email": "invited@example.com"
  },
  "token": "3|token123...",
  "organization": {
    "id": 1,
    "name": "Acme Corp",
    "slug": "acme-corp"
  }
}
```

---

### List Invitations

**Endpoint:** `GET /api/{organization}/invitations`

**Description:** List all invitations for an organization.

**Authorization:** Requires authentication and permission `invitations.index`

**Query Parameters:**
- `filter[status]` - Filter by status (pending, accepted, expired, cancelled)
- `sort` - Sort field
- `page`, `per_page` - Pagination

**Example Request:**
```bash
curl -X GET "http://localhost:8000/api/acme-corp/invitations?filter[status]=pending" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

**Success Response (200 OK):**
```json
[
  {
    "id": 1,
    "email": "newuser@example.com",
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

---

### Create Invitation

**Endpoint:** `POST /api/{organization}/invitations`

**Description:** Invite a user to join the organization.

**Authorization:** Requires authentication and permission `invitations.store`

**Request Body:**
```json
{
  "email": "newuser@example.com",
  "role_id": 2
}
```

**Example Request:**
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

**Success Response (201 Created):**
```json
{
  "id": 2,
  "email": "newuser@example.com",
  "role_id": 2,
  "status": "pending",
  "invited_by_id": 1,
  "token": "abc123def456...",
  "expires_at": "2024-01-23T00:00:00.000000Z",
  "created_at": "2024-01-16T15:30:00.000000Z"
}
```

---

### Resend Invitation

**Endpoint:** `POST /api/{organization}/invitations/{id}/resend`

**Description:** Resend invitation email to user.

**Authorization:** Requires authentication and permission `invitations.resend`

**Example Request:**
```bash
curl -X POST "http://localhost:8000/api/acme-corp/invitations/1/resend" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

**Success Response (200 OK):**
```json
{
  "message": "Invitation resent successfully"
}
```

---

### Cancel Invitation

**Endpoint:** `DELETE /api/{organization}/invitations/{id}`

**Description:** Cancel a pending invitation.

**Authorization:** Requires authentication and permission `invitations.destroy`

**Example Request:**
```bash
curl -X DELETE "http://localhost:8000/api/acme-corp/invitations/1" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

**Success Response (200 OK):**
```json
{
  "message": "Invitation cancelled successfully"
}
```

---

## Nested Operations

Execute multiple CRUD operations in a single atomic transaction.

**Endpoint:** `POST /api/nested` or `POST /api/{organization}/nested`

**Description:** Perform multiple create/update operations atomically.

**Authorization:** Each operation is authorized individually via its model's policy

**Request Body:**
```json
{
  "operations": [
    {
      "model": "blogs",
      "action": "create",
      "data": {
        "title": "My Blog",
        "description": "Blog description"
      }
    },
    {
      "model": "posts",
      "action": "create",
      "data": {
        "blog_id": 1,
        "title": "First Post",
        "content": "Post content"
      }
    }
  ]
}
```

**Example Request:**
```bash
curl -X POST "http://localhost:8000/api/nested" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d '{
    "operations": [
      {
        "model": "blogs",
        "action": "create",
        "data": {"title": "Blog", "description": "Desc"}
      },
      {
        "model": "posts",
        "action": "create",
        "data": {"blog_id": 1, "title": "Post", "content": "Content"}
      }
    ]
  }'
```

**Success Response (200 OK):**
```json
{
  "results": [
    {
      "model": "blogs",
      "action": "create",
      "id": 1,
      "data": {
        "id": 1,
        "title": "Blog",
        "description": "Desc",
        "created_at": "2024-01-16T16:00:00.000000Z"
      }
    },
    {
      "model": "posts",
      "action": "create",
      "id": 5,
      "data": {
        "id": 5,
        "blog_id": 1,
        "title": "Post",
        "content": "Content",
        "created_at": "2024-01-16T16:00:00.000000Z"
      }
    }
  ]
}
```

**Error Responses:**
- `422 Unprocessable Entity` - Validation failed, invalid structure, or max operations exceeded
- `403 Forbidden` - One or more operations failed authorization
- `404 Not Found` - Update operation: resource not found

---

## Query Parameters

### Filtering

**Format:** `filter[field]=value`

**Example:**
```
GET /api/posts?filter[is_published]=true&filter[user_id]=1
```

**Multiple values (OR):**
```
GET /api/posts?filter[status]=draft,published
```

---

### Sorting

**Format:** `sort=field` or `sort=-field` (descending)

**Example:**
```
GET /api/posts?sort=-created_at
```

**Multiple fields:**
```
GET /api/posts?sort=-created_at,title
```

---

### Including Relationships

**Format:** `include=relationship1,relationship2`

**Example:**
```
GET /api/posts?include=user,comments
```

**Nested relationships:**
```
GET /api/posts?include=user.profile,comments.user
```

**Authorization:** User must have `viewAny` permission on included models.

---

### Field Selection

**Format:** `fields[model]=field1,field2`

**Example:**
```
GET /api/posts?fields[posts]=id,title,created_at
```

---

### Search

**Format:** `search=term`

**Example:**
```
GET /api/posts?search=laravel
```

**Requirements:** Model must define `$allowedSearch` property.

---

### Pagination

**Format:** `page=N&per_page=N`

**Example:**
```
GET /api/posts?page=2&per_page=20
```

**Response Headers:**
```
X-Current-Page: 2
X-Last-Page: 10
X-Per-Page: 20
X-Total: 195
```

---

## Response Format

### Success Responses

**Single Resource:**
```json
{
  "id": 1,
  "field": "value",
  "created_at": "2024-01-16T00:00:00.000000Z"
}
```

**Multiple Resources:**
```json
[
  {"id": 1, "field": "value"},
  {"id": 2, "field": "value"}
]
```

**Operation Confirmation:**
```json
{
  "message": "Operation successful"
}
```

---

## Error Responses

### 400 Bad Request

**Description:** Malformed request syntax.

```json
{
  "message": "Invalid JSON payload"
}
```

---

### 401 Unauthorized

**Description:** Missing or invalid authentication token.

```json
{
  "message": "Unauthenticated."
}
```

---

### 403 Forbidden

**Description:** User doesn't have permission for this action.

```json
{
  "message": "This action is unauthorized."
}
```

**Include authorization failed:**
```json
{
  "message": "You do not have permission to include comments."
}
```

---

### 404 Not Found

**Description:** Resource doesn't exist.

```json
{
  "message": "Resource not found"
}
```

---

### 422 Unprocessable Entity

**Description:** Validation failed.

```json
{
  "message": "The title field is required. (and 2 more errors)",
  "errors": {
    "title": ["The title field is required."],
    "content": ["The content field is required."],
    "user_id": ["The user id field must be a number."]
  }
}
```

---

### 429 Too Many Requests

**Description:** Rate limit exceeded.

```json
{
  "message": "Too Many Attempts."
}
```

**Headers:**
```
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 0
Retry-After: 58
```

---

### 500 Internal Server Error

**Description:** Server error.

```json
{
  "message": "Server Error"
}
```

---

## Related Documentation

- [Getting Started](./getting-started.md) - Installation and setup
- [Query Builder](./features/query-builder.md) - Advanced querying
- [Authentication](./features/authentication.md) - Auth system details
- [Authorization](./features/authorization.md) - Permissions and policies
- [Soft Deletes](./features/soft-deletes.md) - Trash management
- [Nested Operations](./features/nested-operations.md) - Multi-model transactions
