<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Manifest Cache Store
    |--------------------------------------------------------------------------
    |
    | The cache store that holds the file manifest. Null uses the default store.
    | Pick one that "cache:clear" does not wipe and that supports locks (redis,
    | database, file). The manifest grows about 0.35 MB per 1,000 files, so
    | avoid memcached (1 MB per value) and dynamodb (400 KB).
    |
    */

    'cache_store' => env('PAPER_CACHE_STORE'),

    /*
    |--------------------------------------------------------------------------
    | File Watcher
    |--------------------------------------------------------------------------
    |
    | Whether a query lists the content directory to pick up files changed
    | outside the app. "auto" watches locally and trusts the manifest elsewhere.
    | With it off, a warm query is a pure cache read and disk edits show up
    | after "paper:refresh".
    |
    */

    'watch' => env('PAPER_WATCH', 'auto'),

    /*
    |--------------------------------------------------------------------------
    | Concurrency
    |--------------------------------------------------------------------------
    |
    | What a write does when the file changed after the record was loaded.
    | "strict" refuses storage that cannot check and write in one step,
    | "best_effort" checks anyway and accepts the gap, and "off" skips the
    | check so the last write wins.
    |
    */

    'concurrency' => env('PAPER_CONCURRENCY', 'best_effort'),

    /*
    |--------------------------------------------------------------------------
    | Rebuild Lock
    |--------------------------------------------------------------------------
    |
    | When the manifest is cold, one process rebuilds it while the others wait.
    | "lock_ttl" is how long that process may hold the lock, and "lock_wait"
    | how long the others wait before building it themselves. Both in seconds.
    |
    */

    'lock_ttl' => env('PAPER_LOCK_TTL', 60),

    'lock_wait' => env('PAPER_LOCK_WAIT', 10),

];
