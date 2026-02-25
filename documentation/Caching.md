# Caching strategy

## Final setup

1. Redis is configured as `cache.app` (shared cache backend).
2. Public read API providers use application-level cache keys in Redis.
3. All these keys are namespaced by dataset version (`dataset.<version>.*`).
4. Public read APIs have HTTP cache headers (`s-maxage`, `stale-while-revalidate`).
5. Authenticated `/api/user/profile/*` responses are `private, no-store`.
6. Doctrine cache in `prod` is enabled as secondary cache:
   - `doctrine.system_cache_pool` -> `cache.system`
   - `doctrine.result_cache_pool` -> `cache.app` with `default_lifetime: 86400`

## Operational flow after MySQL dump import

Run after each manual dump import:

```bash
php bin/console app:cache:refresh-after-import --env=prod --warmup
```

Optional full refresh:

```bash
php bin/console app:cache:refresh-after-import --env=prod --clear-cache-app --warmup
```

## Notes

- Main performance gain comes from endpoint-level cache and HTTP cache.
- Doctrine cache is kept as secondary optimization only.
- Dataset version bump provides safe invalidation without global key scanning.
