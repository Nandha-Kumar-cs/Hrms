<?php
/**
 * MagDyn HRMS — Deployment engine
 * ────────────────────────────────
 * Everything the System Update module needs to take a delta ZIP and apply it to
 * THIS installation, safely, in both local and live environments.
 *
 * There is no HTML here — the pages under modules/deployment/ call into this.
 *
 * SECURITY NOTE (read before changing anything below)
 * ---------------------------------------------------
 * This file writes PHP into the application root. That is remote code execution
 * by design, restricted to a Super Admin. The controls here exist to stop
 * ACCIDENTS and malformed/hostile archives; they cannot protect against a
 * compromised Super Admin account. Two rules must never be relaxed:
 *
 *   1. Every destination is canonicalised and proven to sit inside HRMS_ROOT
 *      before anything is written (deploy_safe_target).
 *   2. The protected list (deploy_is_protected) is checked on every entry, and
 *      it includes this module itself so a bad package cannot brick the
 *      deployer that would be used to recover.
 */

// ─── Configuration ────────────────────────────────────────────────────────────

/** Master kill-switch. Define DEPLOYMENT_ENABLED=false in config/app.php to
 *  disable the module entirely (e.g. on live between releases). */
if (!defined('DEPLOYMENT_ENABLED')) define('DEPLOYMENT_ENABLED', true);

/** Largest package accepted, in bytes. */
if (!defined('DEPLOYMENT_MAX_ZIP_BYTES')) define('DEPLOYMENT_MAX_ZIP_BYTES', 64 * 1024 * 1024);

/** Largest single file inside a package. */
if (!defined('DEPLOYMENT_MAX_FILE_BYTES')) define('DEPLOYMENT_MAX_FILE_BYTES', 16 * 1024 * 1024);

/** Most entries a package may contain (zip-bomb guard). */
if (!defined('DEPLOYMENT_MAX_ENTRIES')) define('DEPLOYMENT_MAX_ENTRIES', 3000);

/** A lock older than this is treated as stale and broken. */
if (!defined('DEPLOYMENT_LOCK_TTL')) define('DEPLOYMENT_LOCK_TTL', 900);   // 15 min

/**
 * Extensions a package may install.
 *
 * `.htaccess` is NOT an extension — it is handled separately by
 * deploy_extension_allowed(), because real delta packages legitimately carry
 * per-directory .htaccess files (the QR portal's rewrite rule is one) and a
 * pure extension whitelist would silently skip them and half-install a feature.
 */
function deploy_allowed_extensions(): array
{
    return [
        'php', 'css', 'js', 'html', 'htm', 'json', 'xml', 'sql',
        'md', 'txt', 'csv', 'svg', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'ico',
        'woff', 'woff2', 'ttf', 'eot', 'map', 'pdf',
    ];
}

/** Basenames allowed even though they have no (or a dot-leading) extension. */
function deploy_allowed_basenames(): array
{
    return ['.htaccess', 'web.config', '.gitignore', 'robots.txt'];
}

/**
 * Paths that must never be written by a package, relative to HRMS_ROOT and
 * forward-slashed. A trailing slash means "this directory and everything in it".
 *
 * config/ is protected in full, not just database.php: config/app.php holds
 * BASE_URL, which differs per environment — deploying it would repoint a live
 * install at the developer's machine.
 */
function deploy_protected_paths(): array
{
    return [
        'config/',                      // DB credentials AND per-environment BASE_URL
        '.env',
        '.git/',
        'vendor/',
        'storage/',                     // uploads, backups, packages
        'uploads/',                     // employee photos & documents
        'includes/deployer.php',        // this engine
        'modules/deployment/',          // the module's own pages
        'install/.htaccess',
    ];
}

// ─── Environment & paths ──────────────────────────────────────────────────────

/**
 * The HRMS application root, canonicalised.
 *
 * Derived from BASE_PATH, which config/app.php already defines as
 * dirname(__DIR__) — i.e. the directory holding config/. Nothing is hard-coded,
 * so the same code resolves correctly under XAMPP and on a live host.
 */
function deploy_root(): string
{
    static $root = null;
    if ($root !== null) return $root;
    $r = realpath(BASE_PATH);
    if ($r === false) {
        throw new RuntimeException('Application root could not be resolved.');
    }
    return $root = rtrim(str_replace('\\', '/', $r), '/');
}

/** 'LIVE' or 'LOCAL', from the app's own APP_ENV — never guessed from the URL. */
function deploy_env(): string
{
    $env = strtolower((string) (defined('APP_ENV') ? APP_ENV : 'production'));
    return in_array($env, ['local', 'development', 'dev', 'testing'], true) ? 'LOCAL' : 'LIVE';
}

function deploy_is_live(): bool { return deploy_env() === 'LIVE'; }

/**
 * Where packages, staging areas and backups live.
 *
 * Prefers a sibling of the application root, so nothing is reachable over HTTP
 * at all. Falls back to storage/deployments/ (already hardened by
 * storage/.htaccess, which disables the PHP engine) when the parent directory
 * is not writable — the common case on shared hosting.
 */
function deploy_storage_dir(): string
{
    static $dir = null;
    if ($dir !== null) return $dir;

    $root   = deploy_root();
    $parent = dirname($root);
    $outside = $parent . '/hrms-deploy-data';

    $chosen = null;
    if (is_dir($outside) || (is_writable($parent) && @mkdir($outside, 0700, true))) {
        if (is_writable($outside)) $chosen = $outside;
    }
    if ($chosen === null) {
        $inside = $root . '/storage/deployments';
        if (!is_dir($inside)) @mkdir($inside, 0700, true);
        $chosen = $inside;
    }

    deploy_harden_dir($chosen);
    return $dir = $chosen;
}

/**
 * Make a storage directory unreachable over HTTP.
 *
 * Being outside HRMS_ROOT is not the same as being outside the DOCUMENT ROOT:
 * on a stock XAMPP layout the parent of the app IS htdocs, so the preferred
 * location would otherwise be downloadable at /hrms-deploy-data/. Package ZIPs,
 * file backups and database dumps all live here, so this is written every time
 * rather than assumed.
 */
function deploy_harden_dir(string $dir): void
{
    if (!is_dir($dir)) return;

    $ht = $dir . '/.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht, implode("\n", [
            '# Deployment storage — packages, file backups and database dumps.',
            '# Never reachable over HTTP, and never executable.',
            '<IfModule mod_authz_core.c>',
            '    Require all denied',
            '</IfModule>',
            '<IfModule !mod_authz_core.c>',
            '    Order allow,deny',
            '    Deny from all',
            '</IfModule>',
            'php_flag engine off',
            'RemoveHandler .php .phtml .php3 .php4 .php5 .php7 .phar',
            'RemoveType .php .phtml .php3 .php4 .php5 .php7 .phar',
            'Options -Indexes',
            '',
        ]));
    }
    // Belt and braces for servers that ignore .htaccess.
    if (!is_file($dir . '/index.html')) @file_put_contents($dir . '/index.html', '');
    if (!is_file($dir . '/web.config')) {
        @file_put_contents($dir . '/web.config',
            "<?xml version=\"1.0\"?>\n<configuration><system.webServer><security>"
          . "<authorization><deny users=\"*\" /></authorization></security>"
          . "</system.webServer></configuration>\n");
    }
}

/* Only the uploaded package and its staging area are stored. No backups/ and no
 * db/ directory — deployment keeps no copy of what it replaces. */
function deploy_packages_dir(): string { return deploy_storage_dir() . '/packages'; }
function deploy_staging_dir(): string  { return deploy_storage_dir() . '/staging'; }

/** True when the storage area is usable; the UI blocks deployment otherwise. */
function deploy_storage_ready(): bool
{
    foreach ([deploy_packages_dir(), deploy_staging_dir()] as $d) {
        if (!is_dir($d) && !@mkdir($d, 0700, true)) return false;
        if (!is_writable($d)) return false;
    }
    return true;
}

// ─── Path safety ──────────────────────────────────────────────────────────────

/**
 * Normalise a ZIP entry name to a safe relative path, or null to reject it.
 *
 * Rejects, in order: absolute POSIX paths, Windows drive paths, UNC paths,
 * stream wrappers, NUL bytes, and any `..` segment. Resolving `.`/`..`
 * textually here is a first line of defence only — deploy_safe_target() does
 * the authoritative filesystem-level check afterwards.
 */
function deploy_normalize_entry(string $name): ?string
{
    $p = str_replace('\\', '/', trim($name));

    if ($p === '' || strpos($p, "\0") !== false)   return null;   // NUL injection
    if (preg_match('#^[a-zA-Z]:#', $p))            return null;   // C:\Windows\...
    if (strncmp($p, '//', 2) === 0)                return null;   // UNC \\server\share
    if ($p[0] === '/')                             return null;   // /etc/passwd
    if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*://#', $p)) return null;  // php://, http://

    $out = [];
    foreach (explode('/', $p) as $seg) {
        if ($seg === '' || $seg === '.') continue;
        if ($seg === '..') return null;                            // ../../config.php
        $out[] = $seg;
    }
    if (!$out) return null;

    $rel = implode('/', $out);
    return strlen($rel) > 500 ? null : $rel;
}

/**
 * Absolute destination for a relative path, proven to be inside HRMS_ROOT.
 * Returns null if it would land anywhere else.
 *
 * The check canonicalises the nearest EXISTING ancestor (the file itself may not
 * exist yet) and then requires the result to be the root or a descendant of it.
 * That is what actually defeats symlinked directories; a string comparison on
 * the un-resolved path does not.
 */
function deploy_safe_target(string $rel): ?string
{
    $rel = deploy_normalize_entry($rel);
    if ($rel === null) return null;

    $root   = deploy_root();
    $target = $root . '/' . $rel;

    $probe = $target;
    while (!file_exists($probe)) {
        $up = dirname($probe);
        if ($up === $probe) return null;
        $probe = $up;
    }
    $real = realpath($probe);
    if ($real === false) return null;
    $real = rtrim(str_replace('\\', '/', $real), '/');

    // Windows paths are case-insensitive; POSIX ones are not.
    $ci  = DIRECTORY_SEPARATOR === '\\';
    $a   = $ci ? strtolower($real) : $real;
    $b   = $ci ? strtolower($root) : $root;
    if ($a !== $b && strncmp($a, $b . '/', strlen($b) + 1) !== 0) return null;

    return $target;
}

/** True if the package must not touch this path. */
function deploy_is_protected(string $rel): bool
{
    $rel = ltrim(str_replace('\\', '/', $rel), '/');
    $cmp = DIRECTORY_SEPARATOR === '\\' ? strtolower($rel) : $rel;

    foreach (deploy_protected_paths() as $p) {
        $p = DIRECTORY_SEPARATOR === '\\' ? strtolower($p) : $p;
        if (substr($p, -1) === '/') {
            if (strncmp($cmp, $p, strlen($p)) === 0) return true;      // inside dir
        } elseif ($cmp === $p) {
            return true;                                              // exact file
        }
    }
    return false;
}

/** True if the file's extension (or basename) is on the whitelist. */
function deploy_extension_allowed(string $rel): bool
{
    $base = basename($rel);
    if (in_array($base, deploy_allowed_basenames(), true)) return true;
    if (strpos($base, '.') === false) return false;                   // no extension

    $ext = strtolower((string) pathinfo($base, PATHINFO_EXTENSION));
    if ($ext === '') return false;

    // Never accept anything that could be executed by a mis-configured server
    // even if it somehow reached the whitelist.
    if (in_array($ext, ['phtml', 'phar', 'phps', 'pht', 'cgi', 'pl', 'py', 'sh', 'exe', 'bat', 'com'], true)) {
        return false;
    }
    return in_array($ext, deploy_allowed_extensions(), true);
}

// ─── Deployment id & lock ─────────────────────────────────────────────────────

function deploy_new_id(): string
{
    return 'DEP-' . date('Ymd-His');
}

/**
 * Acquire the global deployment lock. Returns a handle to pass to
 * deploy_unlock(), or null when another deployment holds it.
 *
 * Uses flock(), so the lock is released by the OS even if PHP dies mid-request.
 * A lock file whose mtime is older than DEPLOYMENT_LOCK_TTL is treated as stale
 * (a crashed run) and reclaimed.
 */
function deploy_lock()
{
    $path = deploy_storage_dir() . '/deploy.lock';

    if (is_file($path) && (time() - (int) @filemtime($path)) > DEPLOYMENT_LOCK_TTL) {
        @unlink($path);                                   // stale — reclaim
    }

    $fh = @fopen($path, 'c+');
    if (!$fh) return null;

    if (!flock($fh, LOCK_EX | LOCK_NB)) {                 // someone else is deploying
        fclose($fh);
        return null;
    }

    /* Who holds the lock goes in a SEPARATE file.
     * On Windows flock() is a MANDATORY lock, so while the lock is held the
     * lock file itself cannot be read at all ("Permission denied") — which
     * would leave the "deployment in progress" banner unable to name anyone.
     * POSIX flock is advisory and would read fine, but the sidecar keeps both
     * platforms behaving identically. */
    @file_put_contents($path . '.info', json_encode([
        'pid'  => function_exists('getmypid') ? getmypid() : null,
        'user' => current_user()['name'] ?? 'unknown',
        'at'   => date('c'),
    ]));

    @touch($path);
    return $fh;
}

/**
 * Release the lock. Safe to call more than once, and safe to call with a handle
 * that has already been closed — the callers register this as a shutdown
 * function AND may call it directly.
 */
function deploy_unlock($fh): void
{
    static $released = false;
    if ($released) return;
    $released = true;

    if (is_resource($fh)) {
        @flock($fh, LOCK_UN);
        @fclose($fh);
    }
    $path = deploy_storage_dir() . '/deploy.lock';
    @unlink($path);
    @unlink($path . '.info');
}

/**
 * Release the lock (and clear maintenance mode) no matter how the script ends.
 *
 * A `finally` block is NOT enough here: redirect() calls exit(), and exit()
 * bypasses finally in PHP, which would leave the lock file behind and block
 * every later deployment until the TTL expired. Shutdown functions DO run on
 * exit(), so the cleanup is registered the moment the lock is taken.
 */
function deploy_register_cleanup($fh, bool &$maintenanceOn): void
{
    register_shutdown_function(function () use ($fh, &$maintenanceOn) {
        if ($maintenanceOn) deploy_maintenance_disable();
        deploy_unlock($fh);
    });
}

/**
 * Details of the current lock holder, for the "already in progress" message.
 * Returns null when no deployment is running.
 *
 * Reads the sidecar rather than the lock file itself — see deploy_lock() for
 * why the lock file is unreadable while held on Windows.
 */
function deploy_lock_info(): ?array
{
    $path = deploy_storage_dir() . '/deploy.lock';
    if (!is_file($path)) return null;

    // A lock past its TTL is stale (a crashed run) — report it as not running,
    // matching what deploy_lock() will do on the next attempt.
    if ((time() - (int) @filemtime($path)) > DEPLOYMENT_LOCK_TTL) return null;

    $j = json_decode((string) @file_get_contents($path . '.info'), true);
    return is_array($j) ? $j : [];
}

// ─── Maintenance mode ─────────────────────────────────────────────────────────
// A flag file rather than a DB row, so it still works if a deployment breaks the
// database layer. bootstrap.php checks it; Super Admins are never locked out.

function deploy_maintenance_file(): string { return deploy_root() . '/storage/maintenance.flag'; }
function deploy_maintenance_on(): bool     { return is_file(deploy_maintenance_file()); }

function deploy_maintenance_enable(string $by): void
{
    @file_put_contents(deploy_maintenance_file(), json_encode(['by' => $by, 'at' => date('c')]));
}
function deploy_maintenance_disable(): void { @unlink(deploy_maintenance_file()); }

// ─── Analysis ─────────────────────────────────────────────────────────────────

/**
 * Open and validate a ZIP, returning what a deployment WOULD do. Nothing is
 * written to the application root by this function.
 *
 * @return array{ok:bool,error:?string,entries:array,summary:array,has_sql:bool}
 *   entries[] = [rel, action, reason, size, sha256, size_before, sha256_before]
 *   action ∈ ADD | UPDATE | SKIP | PROTECTED | SQL
 */
function deploy_analyze(string $zipPath): array
{
    $out = ['ok' => false, 'error' => null, 'entries' => [],
            'summary' => ['total' => 0, 'add' => 0, 'update' => 0, 'skip' => 0, 'protected' => 0, 'sql' => 0],
            'has_sql' => false];

    if (!class_exists('ZipArchive')) {
        $out['error'] = 'The PHP zip extension is not available on this server.';
        return $out;
    }

    $zip = new ZipArchive();
    $rc  = $zip->open($zipPath, ZipArchive::CHECKCONS);   // integrity check
    if ($rc !== true) {
        $out['error'] = 'The package is not a readable ZIP archive (code ' . $rc . ').';
        return $out;
    }
    if ($zip->numFiles > DEPLOYMENT_MAX_ENTRIES) {
        $zip->close();
        $out['error'] = 'The package contains too many entries (' . $zip->numFiles . ').';
        return $out;
    }

    $root = deploy_root();

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $st = $zip->statIndex($i);
        if ($st === false) continue;

        $raw = (string) $st['name'];
        if (substr($raw, -1) === '/') continue;            // directory entry

        $entry = ['raw' => $raw, 'rel' => null, 'action' => 'SKIP', 'reason' => '',
                  'size' => (int) $st['size'], 'sha256' => null,
                  'size_before' => null, 'sha256_before' => null, 'is_sql' => false];

        $rel = deploy_normalize_entry($raw);
        if ($rel === null) {
            $entry['reason'] = 'Unsafe path — rejected';
            $out['entries'][] = $entry; $out['summary']['skip']++; $out['summary']['total']++;
            continue;
        }
        $entry['rel'] = $rel;

        // Reject before extracting anything.
        if (deploy_safe_target($rel) === null) {
            $entry['action'] = 'SKIP'; $entry['reason'] = 'Resolves outside the application root';
            $out['entries'][] = $entry; $out['summary']['skip']++; $out['summary']['total']++;
            continue;
        }
        if (deploy_is_protected($rel)) {
            $entry['action'] = 'PROTECTED'; $entry['reason'] = 'Protected path';
            $out['entries'][] = $entry; $out['summary']['protected']++; $out['summary']['total']++;
            continue;
        }
        if (!deploy_extension_allowed($rel)) {
            $entry['action'] = 'SKIP'; $entry['reason'] = 'File type not permitted';
            $out['entries'][] = $entry; $out['summary']['skip']++; $out['summary']['total']++;
            continue;
        }
        if ($entry['size'] > DEPLOYMENT_MAX_FILE_BYTES) {
            $entry['action'] = 'SKIP'; $entry['reason'] = 'File exceeds the size limit';
            $out['entries'][] = $entry; $out['summary']['skip']++; $out['summary']['total']++;
            continue;
        }

        $data = $zip->getFromIndex($i);
        if ($data === false) {
            $entry['action'] = 'SKIP'; $entry['reason'] = 'Entry could not be read';
            $out['entries'][] = $entry; $out['summary']['skip']++; $out['summary']['total']++;
            continue;
        }
        $entry['sha256'] = hash('sha256', $data);
        $entry['size']   = strlen($data);

        /* A .sql entry is BOTH a file and a database change:
         *   • it is installed like any other file, so migrations land in
         *     install/ permanently and are backed up / rolled back normally;
         *   • and it is offered for execution behind a separate confirmation.
         * Treating it as "database only" meant packaged migrations were run but
         * never filed, so the next developer had no record of them. */
        if (strtolower((string) pathinfo($rel, PATHINFO_EXTENSION)) === 'sql') {
            $entry['is_sql'] = true;
            $entry['reason'] = 'Database change — installed as a file; run requires separate confirmation';
            $out['has_sql']  = true;
            $out['summary']['sql']++;
        }

        $abs = $root . '/' . $rel;
        if (is_file($abs)) {
            $entry['size_before']   = (int) filesize($abs);
            $entry['sha256_before'] = hash_file('sha256', $abs) ?: null;
            if ($entry['sha256_before'] === $entry['sha256']) {
                $entry['action'] = 'SKIP';
                $entry['reason'] = 'Identical to the installed file';
                $out['summary']['skip']++;
            } else {
                $entry['action'] = 'UPDATE';
                $out['summary']['update']++;
            }
        } else {
            $entry['action'] = 'ADD';
            $out['summary']['add']++;
        }
        $out['summary']['total']++;
        $out['entries'][] = $entry;
    }

    $zip->close();

    usort($out['entries'], function ($a, $b) {
        $order = ['UPDATE' => 0, 'ADD' => 1, 'SQL' => 2, 'PROTECTED' => 3, 'SKIP' => 4];
        $c = ($order[$a['action']] ?? 9) <=> ($order[$b['action']] ?? 9);
        return $c !== 0 ? $c : strcmp((string) $a['rel'], (string) $b['rel']);
    });

    // Does applying this package actually change anything on disk?
    $out['has_file_changes'] = ($out['summary']['add'] + $out['summary']['update']) > 0;

    $out['ok'] = true;
    return $out;
}

// ─── Execution ────────────────────────────────────────────────────────────────

/** Recursively delete a directory (used for staging cleanup only). */
function deploy_rmdir(string $dir): void
{
    if (!is_dir($dir)) return;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
    @rmdir($dir);
}

/** Write $data to $target atomically (temp file + rename on the same volume). */
function deploy_write_atomic(string $target, string $data): bool
{
    $dir = dirname($target);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return false;

    $tmp = $target . '.deploy-' . bin2hex(random_bytes(4)) . '.tmp';
    if (@file_put_contents($tmp, $data) === false) { @unlink($tmp); return false; }
    if (!@rename($tmp, $target)) {                       // Windows: rename over an
        @unlink($target);                                // existing file can fail
        if (!@rename($tmp, $target)) { @unlink($tmp); return false; }
    }
    return true;
}

/**
 * Apply a package.
 *
 * Order is: write → verify each write by SHA-256 → on ANY failure, undo what
 * this run added and report.
 *
 * NO BACKUPS ARE TAKEN. Deployment is one-way: a file that is overwritten is
 * gone, and the only recovery is to deploy a package containing the previous
 * version. Automatic recovery from a mid-write failure is therefore PARTIAL —
 * newly added files are deleted, but files that were overwritten before the
 * failure stay overwritten, because their previous contents no longer exist.
 * deploy_restore_files() reports that honestly rather than claiming success.
 *
 * @return array{ok:bool,deployment_id:string,error:?string,files:array,rolled_back:bool}
 */
function deploy_execute(string $zipPath, string $packageName, array $analysis, string $deploymentId): array
{
    $result = ['ok' => false, 'deployment_id' => $deploymentId, 'error' => null,
               'files' => [], 'rolled_back' => false];

    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CHECKCONS) !== true) {
        $result['error'] = 'The package could not be reopened for deployment.';
        return $result;
    }

    $applied = [];           // [rel, action] in write order
    $failure = null;

    foreach ($analysis['entries'] as $e) {
        if (!in_array($e['action'], ['ADD', 'UPDATE'], true)) continue;

        $rel = $e['rel'];

        // Re-validate at write time; never trust the analysis alone.
        $target = deploy_safe_target($rel);
        if ($target === null || deploy_is_protected($rel) || !deploy_extension_allowed($rel)) {
            $failure = ['rel' => $rel, 'msg' => 'Failed a safety check at write time'];
            break;
        }

        $data = $zip->getFromName($e['raw']);
        if ($data === false) {
            $failure = ['rel' => $rel, 'msg' => 'Could not read the file from the package'];
            break;
        }
        if (hash('sha256', $data) !== $e['sha256']) {
            $failure = ['rel' => $rel, 'msg' => 'Package contents changed during deployment'];
            break;
        }

        // 1. Write. An UPDATE overwrites the previous version irrecoverably.
        if (!deploy_write_atomic($target, $data)) {
            $failure = ['rel' => $rel, 'msg' => 'File could not be written'];
            break;
        }

        // 2. Verify: exists, readable, size, SHA-256.
        clearstatcache(true, $target);
        if (!is_file($target) || !is_readable($target)) {
            $failure = ['rel' => $rel, 'msg' => 'File is missing or unreadable after writing'];
            break;
        }
        if ((int) filesize($target) !== strlen($data)) {
            $failure = ['rel' => $rel, 'msg' => 'Size mismatch after writing'];
            break;
        }
        $after = hash_file('sha256', $target);
        if ($after !== $e['sha256']) {
            $failure = ['rel' => $rel, 'msg' => 'Checksum mismatch after writing'];
            break;
        }

        $applied[] = ['rel' => $rel, 'action' => $e['action']];
        $result['files'][] = ['rel' => $rel, 'action' => $e['action'], 'status' => 'OK',
                              'size_before' => $e['size_before'], 'size_after' => strlen($data),
                              'sha256_before' => $e['sha256_before'], 'sha256_after' => $after];
    }

    $zip->close();

    if ($failure !== null) {
        $restored = deploy_restore_files($applied);
        $result['error']       = 'Failed on ' . $failure['rel'] . ': ' . $failure['msg'];
        $result['failed_file'] = $failure['rel'];
        $result['rolled_back'] = $restored;
        return $result;
    }

    $result['ok'] = true;
    return $result;
}

/**
 * Undo what a failed deployment added, newest first.
 *
 * This is the AUTOMATIC recovery used by deploy_execute() when a deployment
 * fails part-way through writing. It is not user-facing.
 *
 * Recovery is PARTIAL by design now that no backups are taken:
 *   • ADD    — the file did not exist before, so deleting it fully undoes it.
 *   • UPDATE — the previous contents were overwritten and no copy was kept.
 *              Nothing can be restored, so this reports false.
 *
 * Returning false makes deploy.php show "Automatic recovery: INCOMPLETE",
 * which is the truth: some files are left at the new version even though the
 * deployment failed. Reporting true here would be a lie that hides a
 * half-applied install.
 */
function deploy_restore_files(array $applied): bool
{
    $allOk = true;
    foreach (array_reverse($applied) as $a) {
        $target = deploy_safe_target($a['rel']);
        if ($target === null) { $allOk = false; continue; }

        if ($a['action'] === 'ADD') {
            if (is_file($target) && !@unlink($target)) $allOk = false;
            continue;
        }
        $allOk = false;     // UPDATE — previous version was not kept
    }
    return $allOk;
}

/* Deploying is one-way and final.
 *
 * There is no rollback, no file backup, no manifest and no database dump. A
 * file this module overwrites is gone; the only way back is to deploy a package
 * containing the previous version. Keep that in mind before relaxing any of the
 * checks in deploy_analyze() — the preview is now the last line of defence,
 * because nothing downstream can undo a mistake. */

// ─── Packaged SQL ─────────────────────────────────────────────────────────────

/* No database backup is taken before a migration runs. Nothing is dumped, so
 * there is no snapshot to restore from if a packaged .sql does the wrong thing.
 * The explicit tick-box in the preview is the ONLY safeguard on a schema change,
 * which is why deploy.php never runs SQL unless it was asked to. */

/**
 * Split a .sql file into statements.
 *
 * Splitting on ";" alone is WRONG and silently corrupts real migrations: a
 * semicolon inside a comment or a string literal cuts a statement in half.
 * install/add_shift_system.sql hit exactly this —
 *
 *     lunch_start TIME NULL,   -- default lunch window; NULL = no lunch
 *
 * — which truncated the CREATE TABLE and failed with a syntax error, so the
 * whole shift system silently failed to install.
 *
 * This walks the text and treats ";" as a separator only when it is outside
 * single quotes, double quotes, backticks and comments. Comments are dropped,
 * EXCEPT MySQL version-conditional ones, which are executable and passed
 * through untouched.
 *
 * @return string[] trimmed statements, empties removed
 */
function deploy_split_sql(string $sql): array
{
    $out = [];
    $buf = '';
    $len = strlen($sql);
    $i   = 0;
    $inSingle = $inDouble = $inTick = false;

    while ($i < $len) {
        $c    = $sql[$i];
        $next = $i + 1 < $len ? $sql[$i + 1] : '';

        // ── inside a quoted run: copy verbatim, honouring backslash escapes ──
        if ($inSingle || $inDouble || $inTick) {
            $buf .= $c;
            if (($inSingle || $inDouble) && $c === '\\' && $next !== '') {
                $buf .= $next; $i += 2; continue;          // \' or \" is not a terminator
            }
            if ($inSingle && $c === "'")     $inSingle = false;
            elseif ($inDouble && $c === '"') $inDouble = false;
            elseif ($inTick   && $c === '`') $inTick   = false;
            $i++; continue;
        }

        // ── comments ────────────────────────────────────────────────────────
        // "--" only opens a comment when followed by whitespace (MySQL rule),
        // so an expression like `a--b` is left alone.
        if ($c === '-' && $next === '-') {
            $third = $i + 2 < $len ? $sql[$i + 2] : "\n";
            if ($third === ' ' || $third === "\t" || $third === "\n" || $third === "\r") {
                while ($i < $len && $sql[$i] !== "\n") $i++;
                continue;
            }
        }
        if ($c === '#') {
            while ($i < $len && $sql[$i] !== "\n") $i++;
            continue;
        }
        if ($c === '/' && $next === '*') {
            $end  = strpos($sql, '*/', $i + 2);
            $stop = $end === false ? $len : $end + 2;
            if (($i + 2 < $len) && $sql[$i + 2] === '!') {   // version-conditional: executable
                $buf .= substr($sql, $i, $stop - $i);
            }
            $i = $stop; continue;
        }

        // ── quote openers ───────────────────────────────────────────────────
        if ($c === "'") { $inSingle = true; $buf .= $c; $i++; continue; }
        if ($c === '"') { $inDouble = true; $buf .= $c; $i++; continue; }
        if ($c === '`') { $inTick   = true; $buf .= $c; $i++; continue; }

        // ── the only place a ";" actually separates statements ──────────────
        if ($c === ';') {
            $t = trim($buf);
            if ($t !== '') $out[] = $t;
            $buf = '';
            $i++; continue;
        }

        $buf .= $c;
        $i++;
    }

    $t = trim($buf);
    if ($t !== '') $out[] = $t;      // trailing statement with no final ";"
    return $out;
}

/**
 * Run one packaged .sql file. Only ever called after an explicit, separate
 * confirmation.
 *
 * Statements run one at a time and are NOT atomic: MySQL commits DDL
 * immediately, so statements that succeeded before a failure stay applied.
 * The error therefore reports WHICH statement failed and HOW MANY already
 * landed — with no backup to restore, that count is the only way an admin can
 * work out what state the database is in.
 */
function deploy_run_sql(string $sqlText): array
{
    $out = ['ok' => false, 'statements' => 0, 'error' => null];

    $db = db();
    $n  = 0;                                  // 1-based index of the statement being run
    try {
        foreach (deploy_split_sql($sqlText) as $stmt) {
            $n++;
            $db->exec($stmt);
            $out['statements']++;
        }
        $out['ok'] = true;
    } catch (Throwable $e) {
        error_log('deploy_run_sql failed on statement ' . $n . ': ' . $e->getMessage());

        /* The real driver message is surfaced rather than a generic one. This
         * page is Super Admin only, and without it there is no way to tell what
         * went wrong short of reading the server log. */
        $msg = 'Statement ' . $n . ' failed: ' . $e->getMessage() . ' — '
             . $out['statements'] . ' earlier statement(s) already applied and cannot be undone.';

        // deployment_files.note is VARCHAR(255); truncate rather than be cut off
        // by the database (or rejected outright in strict mode).
        $out['error'] = mb_strlen($msg) > 255 ? mb_substr($msg, 0, 252) . '...' : $msg;
    }
    return $out;
}

/**
 * How many packaged .sql files a deployment actually EXECUTED.
 *
 * Rollback restores files only — it never reverts a schema change — so a
 * deployment can be marked ROLLED_BACK while its database changes are still
 * live. This is what lets the UI say so instead of showing a bare "rolled back"
 * that reads as "left no trace".
 *
 * rollback.php deliberately flips only status='OK' rows, so SQL_APPLIED
 * survives the rollback and remains the record that the DB was touched.
 */
function deploy_sql_applied_count(string $deploymentId): int
{
    try {
        $q = db()->prepare(
            'SELECT COUNT(*) FROM deployment_files WHERE deployment_id = ? AND status = ?'
        );
        $q->execute([$deploymentId, 'SQL_APPLIED']);
        return (int) $q->fetchColumn();
    } catch (Throwable $e) {
        return 0;                       // never let a warning block the page
    }
}

// ─── Audit ────────────────────────────────────────────────────────────────────

/**
 * Record a deployment action in the existing activity log.
 * Never pass secrets in $extra — this is written to the database verbatim.
 */
function deploy_audit(string $action, string $deploymentId, string $summary, array $extra = []): void
{
    $desc = $deploymentId . ' — ' . $summary;
    if ($extra) $desc .= ' [' . http_build_query($extra, '', ', ') . ']';
    activity_log($action, 'Deployment', $desc);
}
