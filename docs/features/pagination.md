# Pagination

Laravel Global Controller provides flexible pagination with metadata in response headers, keeping the response body clean and consistent.

## Table of Contents

- [Overview](#overview)
- [On-Demand Pagination](#on-demand-pagination)
- [Always-On Pagination](#always-on-pagination)
- [Response Format](#response-format)
- [Pagination Headers](#pagination-headers)
- [Client-Side Implementation](#client-side-implementation)
- [Safety Limits](#safety-limits)
- [Best Practices](#best-practices)

---

## Overview

**Two pagination modes:**

| Mode | Trigger | Use Case |
|------|---------|----------|
| **On-Demand** | `?per_page=N` query parameter | User controls pagination |
| **Always-On** | Model property `$paginationEnabled = true` | Large datasets, always paginated |

**Key Features:**
- Metadata in headers (not body)
- Consistent JSON array response
- Configurable per-page limits
- Safety limits (1-100 items)
- Works with all query features

**Response body is always a flat array:**
```json
[
  {"id": 1, "title": "Post 1"},
  {"id": 2, "title": "Post 2"}
]
```

**Pagination metadata in headers:**
```
X-Current-Page: 1
X-Last-Page: 5
X-Per-Page: 20
X-Total: 95
```

---

## On-Demand Pagination

Pagination activated only when `per_page` parameter is present.

### Basic Usage

**Request:**
```bash
GET /api/posts?per_page=10
```

**Response Body:**
```json
[
  {"id": 1, "title": "Post 1"},
  {"id": 2, "title": "Post 2"},
  ...
  {"id": 10, "title": "Post 10"}
]
```

**Response Headers:**
```
X-Current-Page: 1
X-Last-Page: 10
X-Per-Page: 10
X-Total: 95
```

### Specific Page

**Request:**
```bash
GET /api/posts?per_page=10&page=2
```

Returns items 11-20 with header `X-Current-Page: 2`.

### Without Pagination

**Request:**
```bash
GET /api/posts
# No per_page parameter
```

**Response:**
All records returned in single array. No pagination headers.

### With Query Features

**Request:**
```bash
GET /api/posts?filter[is_published]=true&sort=-created_at&per_page=20&page=2
```

Returns:
- Page 2 of published posts
- 20 items per page
- Sorted by newest first

---

## Always-On Pagination

Enable pagination by default for models with large datasets.

### Setup

Set `$paginationEnabled` on your model:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Post extends Model
{
    // Enable pagination by default
    public static bool $paginationEnabled = true;

    // Default items per page (Laravel default: 15)
    protected $perPage = 25;
}
```

### Behavior

**Request without per_page:**
```bash
GET /api/posts
# Automatically paginated
```

**Response:**
- Returns first 25 items (from `$perPage`)
- Includes pagination headers
- Same format as on-demand pagination

**Request with per_page:**
```bash
GET /api/posts?per_page=50
# Overrides $perPage
```

Returns 50 items per page instead of model's default.

### Use Cases

**When to use always-on:**
- Models with thousands of records
- Performance-critical endpoints
- Mobile apps (smaller payloads)
- Public APIs (prevent abuse)

**Examples:**
```php
// Large models - always paginate
class Product extends Model
{
    public static bool $paginationEnabled = true;
    protected $perPage = 50;
}

class Order extends Model
{
    public static bool $paginationEnabled = true;
    protected $perPage = 30;
}

// Small models - on-demand only
class Category extends Model
{
    public static bool $paginationEnabled = false;
    // No default perPage needed
}
```

---

## Response Format

### Response Body

**Always a flat JSON array** - identical whether paginated or not:

```json
[
  {
    "id": 1,
    "title": "Post Title",
    "content": "Content here",
    "created_at": "2024-01-15T10:00:00.000000Z"
  },
  {
    "id": 2,
    "title": "Another Post",
    "content": "More content",
    "created_at": "2024-01-14T08:00:00.000000Z"
  }
]
```

**Benefits:**
- Consistent parsing logic on frontend
- No need to check if `data` wrapper exists
- Simpler client code

### Why Headers?

**Traditional pagination (body-based):**
```json
{
  "data": [...],
  "meta": {
    "current_page": 1,
    "last_page": 10
  }
}
```

**Header-based pagination (this package):**
```json
[...] // Just the data
```

**Advantages:**
- Cleaner response body
- Same format for all endpoints
- Reduces payload size
- Standard HTTP practice

---

## Pagination Headers

### Available Headers

| Header | Type | Description | Example |
|--------|------|-------------|---------|
| `X-Current-Page` | Integer | Current page number | `2` |
| `X-Last-Page` | Integer | Total number of pages | `10` |
| `X-Per-Page` | Integer | Items per page | `20` |
| `X-Total` | Integer | Total number of items | `195` |

### Calculating Ranges

**Items on current page:**
```javascript
const startItem = (currentPage - 1) * perPage + 1;
const endItem = Math.min(currentPage * perPage, total);

// Page 2, 20 per page, 95 total
// Start: (2-1) * 20 + 1 = 21
// End: min(2 * 20, 95) = 40
// Showing items 21-40
```

**Has next page:**
```javascript
const hasNextPage = currentPage < lastPage;
```

**Has previous page:**
```javascript
const hasPrevPage = currentPage > 1;
```

---

## Client-Side Implementation

### JavaScript/Fetch

```javascript
async function fetchPosts(page = 1, perPage = 20) {
  const response = await fetch(
    `https://api.example.com/api/posts?page=${page}&per_page=${perPage}`
  );

  const posts = await response.json();

  // Extract pagination from headers
  const pagination = {
    currentPage: parseInt(response.headers.get('X-Current-Page')),
    lastPage: parseInt(response.headers.get('X-Last-Page')),
    perPage: parseInt(response.headers.get('X-Per-Page')),
    total: parseInt(response.headers.get('X-Total'))
  };

  return { posts, pagination };
}

// Usage
const { posts, pagination } = await fetchPosts(1, 20);
console.log(`Showing ${posts.length} of ${pagination.total} posts`);
```

### Axios

```javascript
import axios from 'axios';

async function fetchPosts(page = 1, perPage = 20) {
  const response = await axios.get('/api/posts', {
    params: { page, per_page: perPage }
  });

  // Extract from headers
  const pagination = {
    currentPage: parseInt(response.headers['x-current-page']),
    lastPage: parseInt(response.headers['x-last-page']),
    perPage: parseInt(response.headers['x-per-page']),
    total: parseInt(response.headers['x-total'])
  };

  return {
    posts: response.data,
    pagination
  };
}
```

### React Component

```jsx
import { useState, useEffect } from 'react';

function PostsList() {
  const [posts, setPosts] = useState([]);
  const [pagination, setPagination] = useState(null);
  const [page, setPage] = useState(1);
  const perPage = 20;

  useEffect(() => {
    fetchPosts();
  }, [page]);

  async function fetchPosts() {
    const response = await fetch(
      `/api/posts?page=${page}&per_page=${perPage}`
    );

    const data = await response.json();

    setPosts(data);
    setPagination({
      currentPage: parseInt(response.headers.get('X-Current-Page')),
      lastPage: parseInt(response.headers.get('X-Last-Page')),
      perPage: parseInt(response.headers.get('X-Per-Page')),
      total: parseInt(response.headers.get('X-Total'))
    });
  }

  return (
    <div>
      <h1>Posts</h1>

      {posts.map(post => (
        <article key={post.id}>
          <h2>{post.title}</h2>
          <p>{post.content}</p>
        </article>
      ))}

      {pagination && (
        <div className="pagination">
          <button
            onClick={() => setPage(page - 1)}
            disabled={page === 1}
          >
            Previous
          </button>

          <span>
            Page {pagination.currentPage} of {pagination.lastPage}
            ({pagination.total} total)
          </span>

          <button
            onClick={() => setPage(page + 1)}
            disabled={page >= pagination.lastPage}
          >
            Next
          </button>
        </div>
      )}
    </div>
  );
}
```

### Vue Component

```vue
<template>
  <div>
    <h1>Posts</h1>

    <article v-for="post in posts" :key="post.id">
      <h2>{{ post.title }}</h2>
      <p>{{ post.content }}</p>
    </article>

    <div v-if="pagination" class="pagination">
      <button
        @click="prevPage"
        :disabled="pagination.currentPage === 1"
      >
        Previous
      </button>

      <span>
        Page {{ pagination.currentPage }} of {{ pagination.lastPage }}
        ({{ pagination.total }} total)
      </span>

      <button
        @click="nextPage"
        :disabled="pagination.currentPage >= pagination.lastPage"
      >
        Next
      </button>
    </div>
  </div>
</template>

<script>
export default {
  data() {
    return {
      posts: [],
      pagination: null,
      page: 1,
      perPage: 20
    };
  },

  mounted() {
    this.fetchPosts();
  },

  methods: {
    async fetchPosts() {
      const response = await fetch(
        `/api/posts?page=${this.page}&per_page=${this.perPage}`
      );

      this.posts = await response.json();

      this.pagination = {
        currentPage: parseInt(response.headers.get('X-Current-Page')),
        lastPage: parseInt(response.headers.get('X-Last-Page')),
        perPage: parseInt(response.headers.get('X-Per-Page')),
        total: parseInt(response.headers.get('X-Total'))
      };
    },

    nextPage() {
      this.page++;
      this.fetchPosts();
    },

    prevPage() {
      this.page--;
      this.fetchPosts();
    }
  }
};
</script>
```

---

## Safety Limits

### Per-Page Limits

`per_page` is automatically clamped between **1** and **100**:

```bash
# Requested 0 → clamped to 1
GET /api/posts?per_page=0

# Requested 500 → clamped to 100
GET /api/posts?per_page=500

# Valid range
GET /api/posts?per_page=50  # ✅ OK
```

### Why Limits?

**Without limits:**
- Users could request `?per_page=999999`
- Server loads entire table into memory
- Response size exceeds reasonable limits
- API becomes slow/crashes

**With limits:**
- Maximum 100 items per request
- Predictable performance
- Reasonable payload sizes
- Prevents abuse

### Configuring Limits

Currently hardcoded to 1-100. To change, modify `GlobalController`:

```php
// In GlobalController.php
$perPage = (int) $request->input('per_page', 15);
$perPage = max(1, min($perPage, 100)); // Clamp between 1-100

// Change to custom range:
$perPage = max(1, min($perPage, 50)); // Max 50 items
```

---

## Best Practices

### 1. Always Handle Pagination on Frontend

```javascript
// ✅ Good - checks for pagination headers
const pagination = response.headers.get('X-Total')
  ? extractPagination(response)
  : null;

// ❌ Bad - assumes pagination always present
const total = response.headers.get('X-Total'); // Could be null
```

### 2. Use Reasonable Per-Page Values

```bash
# ✅ Good - reasonable sizes
GET /api/posts?per_page=10   # Mobile
GET /api/posts?per_page=20   # Desktop
GET /api/posts?per_page=50   # Data tables

# ❌ Bad - excessive
GET /api/posts?per_page=1000
```

### 3. Paginate Large Datasets

```php
// ✅ Good - large models always paginated
class Product extends Model
{
    public static bool $paginationEnabled = true;
    protected $perPage = 50;
}

// ❌ Bad - returning 10,000 products without pagination
class Product extends Model
{
    // No pagination
}
```

### 4. Show Pagination Info to Users

```jsx
// ✅ Good - user knows their position
<p>Showing {startItem}-{endItem} of {total} results</p>

// ❌ Bad - no context
<div>{posts.map(...)}</div>
```

### 5. Combine with Filters

```bash
# ✅ Good - paginate filtered results
GET /api/posts?filter[is_published]=true&sort=-created_at&per_page=20

# ❌ Bad - loading all then filtering client-side
GET /api/posts
# Then filter 10,000 posts in JavaScript
```

### 6. Cache Pagination Results

```javascript
// ✅ Good - cache pages
const cache = new Map();

async function fetchPage(page) {
  if (cache.has(page)) {
    return cache.get(page);
  }

  const result = await fetch(`/api/posts?page=${page}`);
  cache.set(page, result);
  return result;
}

// ❌ Bad - refetch same page repeatedly
async function fetchPage(page) {
  return await fetch(`/api/posts?page=${page}`);
}
```

### 7. Prefetch Next Page

```javascript
// ✅ Good - prefetch while user reads
useEffect(() => {
  if (pagination && pagination.currentPage < pagination.lastPage) {
    // Prefetch next page in background
    fetch(`/api/posts?page=${pagination.currentPage + 1}&per_page=${perPage}`);
  }
}, [pagination]);
```

### 8. Handle Empty Results

```jsx
// ✅ Good - show empty state
{posts.length === 0 && <p>No posts found</p>}

// ❌ Bad - shows nothing when empty
{posts.map(post => <Post key={post.id} {...post} />)}
```

---

## Related Documentation

- [API Reference - Pagination](../API.md#pagination)
- [Query Builder](./query-builder.md) - Filtering and sorting
- [Getting Started](../getting-started.md)
