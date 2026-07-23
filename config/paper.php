<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Manifest Cache Store
    |--------------------------------------------------------------------------
    |
    | The cache store that holds the file manifest. Null uses the application's
    | default store. Point this at a store you do not wipe with "cache:clear",
    | and one that supports atomic locks (redis, memcached, database, file) so
    | concurrent rebuilds are serialized instead of stampeding.
    |
    */

    'cache_store' => env('PAPER_CACHE_STORE'),

    /*
    |--------------------------------------------------------------------------
    | File Watcher
    |--------------------------------------------------------------------------
    |
    | Whether a query re-scans the content directory to notice files changed
    | outside the app. "auto" watches in the local environment and trusts the
    | manifest everywhere else. With it off, a warm query is a pure cache read
    | with no per-file stat, and disk edits show up after "paper:refresh".
    |
    */

    'watch' => env('PAPER_WATCH', 'auto'),

    /*
    |--------------------------------------------------------------------------
    | Rebuild Lock
    |--------------------------------------------------------------------------
    |
    | When the manifest is cold, one process rebuilds it while the others wait.
    | "lock_ttl" is how many seconds that process may hold the lock; "lock_wait"
    | is how long the others block for it before building the manifest instead.
    |
    */

    'lock_ttl' => 60,

    'lock_wait' => 10,

];
