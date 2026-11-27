# REST Cache Invalidation

A small, well-engineered WordPress plugin that caches public REST API GET
responses and **invalidates them correctly when content changes**.

> This is a portfolio/demonstration project. It is production-capable but has
> documented limitations. No benchmark numbers are claimed; see
> [Limitations](#limitations) for an honest assessment.

---

## Table of Contents

1. [The Problem](#the-problem)
2. [How It Works](#how-it-works)
3. [Design Decisions & Trade-offs](#design-decisions--trade-offs)
4. [Limitations](#limitations)
5. [Requirements](#requirements)
6. [Local Development](#local-development)
7. [Running Tests](#running-tests)
8. [Configuration](#configuration)
9. [WP-CLI Usage](#wp-cli-usage)
10. [What I Would Do Next](#what-i-would-do-next)

---

## The Problem

The WordPress REST API is fast — but every request still hits PHP, the
database, and serialisation code. For public, unauthenticated GET requests
(e.g. a headless front-end fetching `/wp/v2/posts`) the response is identical
for every anonymous visitor until something actually changes. Caching those
responses is straightforward; **invalidating them correctly when content
changes is the hard part.**

A naïve approach (store key → delete key on change) requires knowing every
cache key that might be affected by a given edit. Posts, terms, users, and
comments can all appear in many different REST responses with different query
parameters, so a complete key-tracking index is complex, slow to update, and
fragile.

This plugin uses a different strategy: **version-bump invalidation**.

---

## How It Works

```
Request arrives
     │
     ▼
rest_pre_dispatch filter
     │
     ├─ Not cacheable? (authenticated, non-GET, wrong namespace, skip filter)
     │       └─ pass through to endpoint handler unchanged
     │
     ├─ Cache HIT?
     │       └─ return stored WP_REST_Response immediately
     │          add X-Cache: HIT, X-Cache-TTL: <remaining seconds>
     │
     └─ Cache MISS
             └─ pass through to endpoint handler
                      │
                      ▼
              rest_post_dispatch filter
                      │
                      ├─ Status ≠ 200? → discard, do not cache
                      │
                      └─ Status = 200?
                              └─ store response body + headers under key
                                 add X-Cache: MISS
```

### Cache Key

```
rci/{group_version}/{namespace_hash}/{route_hash}/{params_hash}
```

| Segment | Value |
|---------|-------|
| `group_version` | Integer fetched from the store; bumped on content change |
| `namespace_hash` | MD5 of the REST namespace (`wp/v2`) |
| `route_hash` | MD5 of the matched route (`/wp/v2/posts/42`) |
| `params_hash` | MD5 of alphabetically-sorted, URL-encoded query params |

Sorting query params ensures `?b=2&a=1` and `?a=1&b=2` produce the same key.

### Invalidation Groups

| Group | Triggered by |
|-------|-------------|
| `posts` | `save_post`, `deleted_post`, `transition_post_status`, `attachment_updated` |
| `terms` | `edited_term`, `delete_term`, `set_object_terms`, `created_term` |
| `users` | `profile_update`, `user_register`, `deleted_user` |
| `comments` | `comment_post`, `edit_comment`, `delete_comment`, `wp_set_comment_status`, `trash_comment` |

### Storage Back-ends

The plugin detects which back-end is available at boot time:

| Condition | Back-end used |
|-----------|--------------|
| `wp_using_ext_object_cache() === true` | `Object_Cache_Store` (wp_cache_*) |
| Otherwise | `Transient_Store` (wp_options table) |

Both implement the same `Cache_Store` interface, so the rest of the code is
back-end-agnostic.

---

## Design Decisions & Trade-offs

### Version-bump invalidation (the core idea)

**How it works:** Each group has a small integer counter stored separately
(in the object cache or as a wp_option). Every cache key embeds the current
counter value. When a post is saved, we increment the counter by 1. All
existing keys whose counter value no longer matches are silently unreachable —
they will never be served again, even though the data is still in the store.

**Trade-off — stale storage:** Old entries remain physically in the store until
their TTL elapses. With a 5-minute TTL this waste is small, but on a very high
write-frequency site (thousands of post saves per hour) the stale data
accumulates faster than it expires. Options: lower the TTL, use a persistent
cache with LRU eviction (Redis), or add an async sweep job. This is documented
in [What I Would Do Next](#what-i-would-do-next).

**Why not delete-on-change?** Deleting the right keys requires knowing all the
query-parameter combinations that might have been cached for a given post. That
secondary index is itself cache-coherence problem. Version-bumping is O(1) per
invalidation regardless of how many keys exist.

### Conservative auth detection

We skip caching if **any** of these signals are present:
- `is_user_logged_in()` (cookie auth)
- `Authorization` request header (tokens, JWT, Application Passwords)
- `_wpnonce` or `nonce` query param
- `X-WP-Nonce` request header

This is intentionally strict. A single leaked authenticated response into the
public cache would be a security bug. The cost is that some edge cases (e.g. a
logged-in admin previewing a page) won't benefit from the cache — that is the
correct behaviour.

### No settings UI

Configuration is done via PHP constants (in `wp-config.php`) and WordPress
filters (in a mu-plugin or theme's `functions.php`). This is deliberate:
- No database table or options-page overhead.
- Configuration is code-reviewable, version-controllable, and environment-aware.
- No risk of a cache setting being accidentally toggled through the admin UI.

### Transient fallback for group versions stored as options

The group version counters are stored as `wp_options` entries (not transients),
even when the `Transient_Store` back-end is active. This prevents a scenario
where flushing all transients (e.g. via a third-party plugin) resets the
counters to 1, creating a collision with pre-existing stale cache entries that
happen to share the same key structure.

---

## Limitations

1. **Single-site only.** There is no multisite / network-wide invalidation.
   Each subsite's cache is independent, which may cause issues if the same
   object cache is shared across subsites.

2. **Object-cache group flush requires WP 6.1+.** On older WordPress or on
   object-cache backends that do not implement `wp_cache_flush_group()`, the
   flush command falls back to flushing the *entire* object cache.

3. **Transient back-end has database overhead.** Every cache read is a DB
   query (`SELECT` from `wp_options`). This is fine for low-to-medium traffic
   but should be replaced with a persistent object cache (Redis/Memcached) in
   production.

4. **No cache warming.** The first request after an invalidation is always a
   MISS. If you need zero-MISS SLAs, add a background job that re-primes the
   cache after each invalidation.

5. **wp/v2 namespace only.** Custom REST namespaces are not cached by default.
   This can be changed via the `rci_cache_key` / `rci_cache_skip` filters.

6. **No benchmark numbers are included.** The performance impact is entirely
   dependent on your server, database, and object-cache setup. If you need
   numbers, run the ab / wrk / k6 tool against your environment before and
   after enabling the plugin.

---

## Requirements

- WordPress 6.0+
- PHP 8.1+
- Composer (for development dependencies)
- Node.js 18+ and Docker (for `@wordpress/env`)

---

## Local Development

```bash
# Clone the repo into your wp-content/plugins directory, or anywhere:
git clone https://github.com/your-username/rest-cache-invalidation
cd rest-cache-invalidation

# Install PHP development dependencies
composer install

# Install wp-env globally (once)
npm install -g @wordpress/env

# Start the local WordPress environment (pulls Docker images on first run)
wp-env start

# The site is now available at:
#   http://localhost:8888  (WordPress)
#   http://localhost:8889  (WordPress test runner)

# Stop the environment
wp-env stop

# Destroy everything (containers + data)
wp-env destroy
```

---

## Running Tests

**Unit tests** (no Docker needed, uses Brain\Monkey stubs):
```bash
# From your host machine, requires Composer deps
./vendor/bin/phpunit --testsuite unit
```

**Integration tests** (requires wp-env running):
```bash
wp-env run tests-cli ./vendor/bin/phpunit --testsuite integration
```

**All tests**:
```bash
wp-env run tests-cli ./vendor/bin/phpunit
```

**PHPCS lint**:
```bash
composer lint        # check
composer lint-fix    # auto-fix where possible
```

---

## Configuration

All configuration is via PHP constants and WordPress filters.

### Constants (in `wp-config.php`)

| Constant | Type | Default | Description |
|----------|------|---------|-------------|
| `RCI_ENABLED` | bool | `true` | Set to `false` to disable the plugin entirely without deactivating it |
| `RCI_TTL` | int | `300` | Cache time-to-live in seconds |

### Filters

#### `rci_cache_skip`

Return `true` to bypass caching for a specific request.

```php
// Don't cache the /wp/v2/users endpoint at all.
add_filter( 'rci_cache_skip', function ( bool $skip, WP_REST_Request $request ): bool {
    if ( str_starts_with( $request->get_route(), '/wp/v2/users' ) ) {
        return true;
    }
    return $skip;
}, 10, 2 );
```

#### `rci_cache_key`

Modify the computed cache key. Useful for adding locale or A/B segments.

```php
// Add the current locale to separate caches per language.
add_filter( 'rci_cache_key', function ( string $key, WP_REST_Request $request ): string {
    return $key . '/' . get_locale();
}, 10, 2 );
```

#### `rci_cache_ttl`

Override the TTL per-request.

```php
// Cache post-list responses for 10 minutes, everything else uses the default.
add_filter( 'rci_cache_ttl', function ( int $ttl, WP_REST_Request $request ): int {
    if ( '/wp/v2/posts' === $request->get_route() ) {
        return 600;
    }
    return $ttl;
}, 10, 2 );
```

---

## WP-CLI Usage

```bash
# Flush all cached REST responses
wp rest-cache flush

# Show hit/miss statistics and current group versions
wp rest-cache stats

# Stats in JSON format
wp rest-cache stats --format=json
```

Example `stats` output:

```
Cache counters:
+--------+-------+
| Metric | Value |
+--------+-------+
| Hits   | 1423  |
| Misses | 87    |
+--------+-------+

Group versions (bump = invalidation):
+----------+---------+
| Group    | Version |
+----------+---------+
| posts    | 5       |
| terms    | 2       |
| users    | 1       |
| comments | 3       |
+----------+---------+
```

The version number tells you how many times that group has been invalidated
since the counter was initialised. It is not a wall-clock timestamp.

---

## What I Would Do Next

1. **Async stale-entry sweep.** Schedule a WP-Cron job to delete cache entries
   with an expired version segment, reclaiming storage without blocking
   request threads.

2. **Multisite support.** Namespace the cache group by blog ID so each subsite
   has independent invalidation.

3. **Custom namespace support.** Extend the routing table and configuration so
   plugins registering custom REST namespaces (e.g. `myplugin/v1`) can opt in.

4. **Vary-header awareness.** Respect `Vary: Accept-Language` or custom Vary
   headers from endpoint handlers and fold them into the cache key.

5. **Conditional GET / ETags.** Add `ETag` / `Last-Modified` support so clients
   can validate their cached copy without a full response body transfer.

6. **Redis-specific optimisations.** When the object cache backend is Redis,
   use `SCAN` + `DEL` to actually purge stale keys on flush rather than relying
   on TTL expiry.

7. **Measured benchmark.** Add a `bin/benchmark.sh` script using `ab` or `k6`
   that compares response times with and without the cache, so real numbers can
   be reported honestly.

8. **REST API schema.** Expose a `/wp/v2/rest-cache/stats` endpoint (admin-only)
   for dashboards and monitoring integrations.
