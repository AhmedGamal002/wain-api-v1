<?php

/**
 * Wain - hosting trial for Hostinger Business (01-FRD-and-Plan.md section 6, step 1).
 *
 * One standalone file (no Laravel, no composer). It checks everything the plan assumes about the
 * host and prints a PASS / WARN / FAIL report to paste back.
 *
 *   php hosting-check.php                         full check (CLI over SSH)
 *   php hosting-check.php --skip-db               without the database section
 *   php hosting-check.php --skip-images           without the GD memory test (run it last anyway)
 *   php hosting-check.php --heartbeat --duration=55   one cron tick (see README)
 *   php hosting-check.php --cron-report           read the heartbeat log
 *   https://host/hosting-check.php?key=SECRET     same check as the web PHP (delete the file after)
 *
 * Database and key come from ".hosting-check.env" next to this file, or WAIN_DB_HOST,
 * WAIN_DB_PORT, WAIN_DB_DATABASE, WAIN_DB_USERNAME, WAIN_DB_PASSWORD and WAIN_CHECK_KEY.
 * Only tables named wain_trial_* are created, and they are dropped at the end. The password is
 * never printed. Use an EMPTY database, never one with real data.
 *
 * Kept compatible with PHP 7.4 on purpose: it has to run on an unknown PHP long enough to say
 * which version that is.
 */
@set_time_limit(0);
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '0');

define('TRIAL_PREFIX', 'wain_trial_');
define('WAIN_IS_CLI', PHP_SAPI === 'cli');

$GLOBALS['wain_results'] = [];
$GLOBALS['wain_step'] = 'start';

// ---------------------------------------------------------------------------------------------
// Output helpers
// ---------------------------------------------------------------------------------------------

function out($line = '')
{
    echo $line, "\n";

    if (function_exists('ob_get_level') && ob_get_level() > 0) {
        @ob_flush();
    }

    @flush();
}

function section($title)
{
    out('');
    out('== '.$title.' ==');
}

function check($status, $label, $detail = '')
{
    $GLOBALS['wain_results'][] = [$status, $label, $detail];
    out(sprintf('[%-4s] %s%s', $status, $label, $detail !== '' ? ' - '.$detail : ''));
}

function ini_bytes($value)
{
    $value = trim((string) $value);

    if ($value === '') {
        return 0;
    }

    if ($value === '-1') {
        return -1;
    }

    $number = (float) $value;

    switch (strtolower(substr($value, -1))) {
        case 'g':
            $number *= 1024;
            // no break
        case 'm':
            $number *= 1024;
            // no break
        case 'k':
            $number *= 1024;
    }

    return (int) $number;
}

function human_bytes($bytes)
{
    if ($bytes < 0) {
        return 'unlimited';
    }

    return $bytes >= 1048576 ? round($bytes / 1048576, 1).' MB' : round($bytes / 1024).' KB';
}

function function_enabled($name)
{
    if (! function_exists($name)) {
        return false;
    }

    $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

    return ! in_array($name, $disabled, true);
}

/**
 * Runs a shell command. Returns the output, or null when this PHP cannot run commands at all.
 */
function run_command($command, &$exitCode = null)
{
    if (function_enabled('exec')) {
        $lines = [];
        exec($command.' 2>&1', $lines, $exitCode);

        return implode("\n", $lines);
    }

    if (function_enabled('shell_exec')) {
        $output = shell_exec($command.' 2>&1');
        $exitCode = null;

        return $output === null ? '' : $output;
    }

    // Shared hosts often disable exec/shell_exec but leave proc_open, which is what Laravel's
    // scheduler and Symfony Process use anyway.
    if (function_enabled('proc_open')) {
        $process = @proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        if (is_resource($process)) {
            $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            return rtrim($output, "\n");
        }
    }

    return null;
}

/**
 * Peak resident memory of this process in bytes (Linux only). GD from the system libgd allocates
 * outside PHP's allocator, so memory_get_peak_usage() can look tiny while the process is big.
 */
function process_peak_rss()
{
    $status = @file_get_contents('/proc/self/status');

    if ($status !== false && preg_match('/^VmHWM:\s+(\d+)\s+kB/m', $status, $matches)) {
        return (int) $matches[1] * 1024;
    }

    return null;
}

function find_binary($name)
{
    $isWindows = stripos(PHP_OS, 'WIN') === 0;
    $output = run_command($isWindows ? 'where '.$name : 'command -v '.$name, $code);

    if ($output === null || $code !== 0) {
        return null;
    }

    $first = trim(strtok($output, "\n"));

    return $first !== '' ? $first : null;
}

function load_config($dir)
{
    $config = [];
    $file = $dir.DIRECTORY_SEPARATOR.'.hosting-check.env';

    if (is_readable($file)) {
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);

            if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $config[trim($key)] = trim($value, " \t\"'");
        }
    }

    foreach (['DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'CHECK_KEY'] as $key) {
        $env = getenv('WAIN_'.$key);

        if ($env !== false && $env !== '') {
            $config[$key] = $env;
        }
    }

    return $config;
}

// ---------------------------------------------------------------------------------------------
// Sections
// ---------------------------------------------------------------------------------------------

function check_php()
{
    section('PHP');

    $ok = version_compare(PHP_VERSION, '8.3.0', '>=');
    check($ok ? 'PASS' : 'FAIL', 'PHP version', PHP_VERSION.' (Laravel 13 needs >= 8.3)');
    check('INFO', 'SAPI', PHP_SAPI.(WAIN_IS_CLI ? ' (SSH/cron: limits may differ from the web PHP)' : ' (this is what serves the app)'));
    check('INFO', 'Binary', defined('PHP_BINARY') && PHP_BINARY !== '' ? PHP_BINARY : '(unknown)');
    check('INFO', 'php.ini', (string) php_ini_loaded_file() ?: '(none)');
    check('INFO', 'OS / arch', PHP_OS.' / '.(PHP_INT_SIZE * 8).'-bit'.(PHP_ZTS ? ' ZTS' : ''));

    if (isset($_SERVER['SERVER_SOFTWARE'])) {
        check('INFO', 'Web server', (string) $_SERVER['SERVER_SOFTWARE']);
    }
}

function check_extensions()
{
    section('Extensions');

    $required = [
        'ctype', 'curl', 'dom', 'fileinfo', 'filter', 'hash', 'intl', 'json', 'libxml', 'mbstring',
        'openssl', 'pcre', 'pdo', 'pdo_mysql', 'session', 'tokenizer', 'xml', 'xmlwriter', 'zip',
    ];
    $missing = array_values(array_filter($required, function ($name) {
        return ! extension_loaded($name);
    }));

    check($missing === [] ? 'PASS' : 'FAIL', 'Required extensions', $missing === [] ? 'all '.count($required).' loaded' : 'missing: '.implode(', ', $missing));

    foreach (['exif' => 'strips EXIF/location from uploads (SEC-05)', 'gd' => 'image variants', 'bcmath' => 'optional', 'sodium' => 'optional', 'zlib' => 'optional', 'Zend OPcache' => 'speed'] as $name => $why) {
        check(extension_loaded($name) ? 'PASS' : 'WARN', 'ext-'.$name, extension_loaded($name) ? 'loaded' : 'missing - '.$why);
    }

    check('INFO', 'ext-imagick', extension_loaded('imagick') ? 'loaded (intervention can use it)' : 'not loaded (GD will be used)');

    if (extension_loaded('gd')) {
        $gd = gd_info();
        $webp = ! empty($gd['WebP Support']) && function_exists('imagewebp');
        check($webp ? 'PASS' : 'FAIL', 'GD WebP support (imagewebp)', $webp ? 'yes' : 'NO - thumbnails cannot be WebP');
        check(! empty($gd['JPEG Support']) && ! empty($gd['PNG Support']) ? 'PASS' : 'FAIL', 'GD JPEG/PNG', 'JPEG='.(empty($gd['JPEG Support']) ? 'no' : 'yes').' PNG='.(empty($gd['PNG Support']) ? 'no' : 'yes').' GD='.$gd['GD Version']);
    }
}

function check_ini()
{
    section('PHP limits');

    $memory = ini_bytes(ini_get('memory_limit'));
    check($memory === -1 || $memory >= 128 * 1048576 ? 'PASS' : 'WARN', 'memory_limit', (string) ini_get('memory_limit').' (image jobs run in small batches; Filament wants >= 128M)');

    $time = (int) ini_get('max_execution_time');
    if (WAIN_IS_CLI) {
        check('INFO', 'max_execution_time', $time.' (CLI is normally 0 = unlimited; the web value is what matters)');
    } else {
        check($time === 0 || $time >= 30 ? 'PASS' : 'WARN', 'max_execution_time', $time.'s');
    }

    $upload = ini_bytes(ini_get('upload_max_filesize'));
    check($upload >= 8 * 1048576 ? 'PASS' : 'WARN', 'upload_max_filesize', ini_get('upload_max_filesize').' (menu photos are up to 8MB)');
    $post = ini_bytes(ini_get('post_max_size'));
    check($post >= 10 * 1048576 ? 'PASS' : 'WARN', 'post_max_size', ini_get('post_max_size').' (review = up to 5 photos)');
    check((int) ini_get('max_file_uploads') >= 5 ? 'PASS' : 'WARN', 'max_file_uploads', (string) ini_get('max_file_uploads'));

    // proc_open is what Symfony Process / the scheduler / queue:work need: without it nothing
    // runs in the background. The rest only have workarounds.
    $disabledOf = function (array $names) {
        return array_values(array_filter($names, function ($name) {
            return ! function_enabled($name);
        }));
    };

    $critical = $disabledOf(['proc_open', 'proc_get_status', 'putenv']);
    check($critical === [] ? 'PASS' : 'FAIL', 'process functions (proc_open, proc_get_status, putenv)', $critical === [] ? 'enabled: scheduler, queue worker and Symfony Process work' : 'disabled: '.implode(', ', $critical).' - Laravel cannot spawn processes');

    $minor = $disabledOf(['exec', 'shell_exec', 'popen', 'symlink']);
    check($minor === [] ? 'PASS' : 'WARN', 'exec, shell_exec, popen, symlink', $minor === [] ? 'enabled' : 'disabled: '.implode(', ', $minor).($critical === [] ? ' - OK for Laravel; create the storage link from the SSH shell with ln -s' : ''));

    check('INFO', 'open_basedir', (string) ini_get('open_basedir') ?: '(none)');
    check('INFO', 'allow_url_fopen', ini_get('allow_url_fopen') ? 'on' : 'off');
    check('INFO', 'opcache.enable', (string) ini_get('opcache.enable') ?: '(unset)');
}

function check_timezone()
{
    section('Time zone (open_now and Egypt DST)');

    check('INFO', 'tzdata version', function_exists('timezone_version_get') ? timezone_version_get() : '(unknown)');

    try {
        $cairo = new DateTimeZone('Africa/Cairo');
    } catch (Exception $e) {
        check('FAIL', 'Africa/Cairo', 'zone not found: '.$e->getMessage());

        return;
    }

    // Egypt DST: starts last Friday of April 00:00, ends last Thursday of October 24:00.
    $cases = [
        ['2026-04-23 21:59:59', 7200, '2026 DST starts: just before'],
        ['2026-04-23 22:00:00', 10800, '2026 DST starts: just after'],
        ['2026-10-29 20:59:59', 10800, '2026 DST ends: just before (around 30 October 2026)'],
        ['2026-10-29 21:00:00', 7200, '2026 DST ends: just after'],
        ['2027-04-29 21:59:59', 7200, '2027 DST starts: just before'],
        ['2027-04-29 22:00:00', 10800, '2027 DST starts: just after'],
        ['2027-10-28 20:59:59', 10800, '2027 DST ends: just before'],
        ['2027-10-28 21:00:00', 7200, '2027 DST ends: just after'],
    ];

    $bad = [];
    foreach ($cases as [$utc, $expected, $label]) {
        $moment = new DateTime($utc, new DateTimeZone('UTC'));
        $offset = $cairo->getOffset($moment);

        if ($offset !== $expected) {
            $bad[] = $label.' (got '.($offset / 3600).'h, expected '.($expected / 3600).'h)';
        }
    }

    check($bad === [] ? 'PASS' : 'FAIL', 'Africa/Cairo DST 2026 and 2027', $bad === [] ? 'all 8 transitions correct' : implode('; ', $bad));
    check('INFO', 'default timezone', date_default_timezone_get());
}

function check_filesystem($dir)
{
    section('Filesystem');

    check(is_writable($dir) ? 'PASS' : 'WARN', 'script directory writable', $dir);

    $tmp = sys_get_temp_dir();
    $file = @tempnam($tmp, 'wain');
    check($file !== false ? 'PASS' : 'FAIL', 'temp directory writable', $tmp);

    if ($file !== false) {
        $handle = fopen($file, 'c+');
        $locked = $handle && flock($handle, LOCK_EX | LOCK_NB);
        check($locked ? 'PASS' : 'WARN', 'flock() (file cache locks)', $locked ? 'works' : 'failed');

        if ($handle) {
            fclose($handle);
        }

        $link = $file.'.lnk';
        $linked = function_enabled('symlink') && @symlink($file, $link);
        check($linked ? 'PASS' : 'WARN', 'symlink() (php artisan storage:link)', $linked ? 'works' : 'not available in PHP: run ln -sfn ../storage/app/public public/storage once from the SSH shell instead');

        @unlink($link);
        @unlink($file);
    }

    $free = @disk_free_space($dir);
    check('INFO', 'free disk space here', $free === false ? '(unknown)' : human_bytes((int) $free));
}

function check_shell()
{
    section('Shell tools (SSH)');

    if (! function_enabled('exec') && ! function_enabled('shell_exec') && ! function_enabled('proc_open')) {
        check('WARN', 'running commands', 'exec, shell_exec and proc_open are all disabled in this PHP: PHP cannot run mariadb-dump or any command (a cron job in hPanel still can)');

        return;
    }

    $php = run_command('php -v');
    check('INFO', '`php` on PATH', $php === null ? '(cannot run)' : trim(strtok($php, "\n")));

    foreach (['composer' => 'FAIL', 'mariadb-dump' => 'WARN', 'mysqldump' => 'WARN', 'git' => 'INFO'] as $name => $level) {
        $path = find_binary($name);
        $status = $path !== null ? 'PASS' : $level;

        if ($name === 'mariadb-dump' || $name === 'mysqldump') {
            // one of the two is enough
            $other = $name === 'mariadb-dump' ? 'mysqldump' : 'mariadb-dump';
            if ($path === null && find_binary($other) !== null) {
                $status = 'INFO';
            }
        }

        check($status, $name, $path !== null ? $path : 'not found');
    }

    $limits = stripos(PHP_OS, 'WIN') === 0 ? null : run_command('ulimit -u; ulimit -v');
    if ($limits !== null) {
        $parts = array_map('trim', explode("\n", trim($limits)));
        check('INFO', 'ulimit processes / virtual memory', implode(' / ', $parts));
    }
}

function probe_url($url)
{
    if (! function_exists('curl_init')) {
        return [0, 0, 'ext-curl missing'];
    }

    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_USERAGENT => 'wain-hosting-check',
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    curl_exec($handle);
    $code = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    $ms = (int) round(curl_getinfo($handle, CURLINFO_TOTAL_TIME) * 1000);
    $error = curl_error($handle);
    curl_close($handle);

    return [$code, $ms, $error];
}

function check_network()
{
    section('Outbound HTTPS (the API calls these)');

    $targets = [
        'https://exp.host' => 'Expo Push (F11)',
        'https://api.cloudflare.com' => 'Cloudflare (R2 / API)',
        'https://appleid.apple.com/auth/keys' => 'Sign in with Apple keys (F2)',
        'https://www.googleapis.com/oauth2/v3/certs' => 'Google ID-token certs (F2)',
        'https://sentry.io' => 'Sentry (ANL-01)',
    ];

    foreach ($targets as $url => $why) {
        [$code, $ms, $error] = probe_url($url);

        if ($code > 0) {
            check('PASS', $why, 'HTTP '.$code.' in '.$ms.' ms');

            continue;
        }

        $hint = stripos((string) $error, 'certificate') !== false
            ? ' (TLS verification failed: this PHP has no usable CA bundle - set curl.cainfo / openssl.cafile, or Laravel HTTP calls will fail too)'
            : '';
        check('FAIL', $why, 'no response: '.($error ?: 'unknown').$hint);
    }
}

function lat_lng_sql($lat, $lng)
{
    return "ST_GeomFromText('POINT(".number_format($lng, 7, '.', '').' '.number_format($lat, 7, '.', '').")')";
}

function check_database(array $config)
{
    section('Database');

    foreach (['DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'] as $key) {
        if (! isset($config[$key]) || $config[$key] === '') {
            check('WARN', 'database config', 'missing '.$key.' - create .hosting-check.env (see README) or use --skip-db');

            return;
        }
    }

    $host = isset($config['DB_HOST']) ? $config['DB_HOST'] : '127.0.0.1';
    $port = isset($config['DB_PORT']) ? $config['DB_PORT'] : '3306';

    if (! extension_loaded('pdo_mysql')) {
        check('FAIL', 'pdo_mysql', 'extension missing');

        return;
    }

    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 8];

    if (defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
        $options[PDO::MYSQL_ATTR_USE_BUFFERED_QUERY] = true;
    }

    try {
        $pdo = new PDO(
            'mysql:host='.$host.';port='.$port.';dbname='.$config['DB_DATABASE'].';charset=utf8mb4',
            $config['DB_USERNAME'],
            $config['DB_PASSWORD'],
            $options,
        );
    } catch (PDOException $e) {
        check('FAIL', 'connect', preg_replace('/password[^,;]*/i', 'password=***', $e->getMessage()));

        return;
    }

    check('PASS', 'connect', $host.':'.$port.' / '.$config['DB_DATABASE']);

    $tables = ['places', 'claims', 'json', 'idx'];

    try {
        database_checks($pdo, $config, $host, $port);
    } catch (Exception $e) {
        check('FAIL', 'database checks aborted', $e->getMessage());
    }

    foreach ($tables as $table) {
        try {
            $pdo->exec('DROP TABLE IF EXISTS '.TRIAL_PREFIX.$table);
        } catch (Exception $e) {
            check('WARN', 'cleanup '.TRIAL_PREFIX.$table, $e->getMessage());
        }
    }

    check('INFO', 'cleanup', 'dropped wain_trial_* tables');
}

function database_checks(PDO $pdo, array $config, $host, $port)
{
    // ---- server identity --------------------------------------------------------------
    $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
    $isMaria = stripos($version, 'mariadb') !== false;

    if ($isMaria && preg_match('/^11\.8\./', $version)) {
        check('PASS', 'SELECT VERSION()', $version);
    } elseif ($isMaria) {
        check('WARN', 'SELECT VERSION()', $version.' - MariaDB but not 11.8: re-check the spatial results below and update the local/CI image');
    } else {
        check('FAIL', 'SELECT VERSION()', $version.' - this is MySQL, not MariaDB: ST_GeomFromText axis order and SRID differ, the plan must change');
    }

    $vars = [];
    foreach ($pdo->query("SHOW VARIABLES WHERE Variable_name IN ('character_set_server','collation_server','sql_mode','max_connections','max_user_connections','wait_timeout','max_allowed_packet','default_storage_engine','time_zone','system_time_zone')") as $row) {
        $vars[$row['Variable_name']] = $row['Value'];
    }

    foreach ($vars as $name => $value) {
        check('INFO', '@@'.$name, $value === '' ? '(empty)' : (string) $value);
    }

    $engine = isset($vars['default_storage_engine']) ? $vars['default_storage_engine'] : '';
    check(strcasecmp($engine, 'InnoDB') === 0 ? 'PASS' : 'WARN', 'default storage engine', $engine);

    // Idle connections are dropped after wait_timeout seconds. Laravel reconnects on its own, but
    // not inside a transaction, so queue jobs must not sit idle (no queries) for that long.
    $waitTimeout = isset($vars['wait_timeout']) ? (int) $vars['wait_timeout'] : 0;
    check(
        $waitTimeout >= 60 || $waitTimeout === 0 ? 'PASS' : 'WARN',
        'wait_timeout',
        $waitTimeout.'s'.($waitTimeout > 0 && $waitTimeout < 60 ? ' - a job or request idle on the DB longer than this loses its connection: keep jobs short, no long transactions' : ''),
    );

    $maxUser = isset($vars['max_user_connections']) ? (int) $vars['max_user_connections'] : 0;
    check($maxUser === 0 || $maxUser >= 25 ? 'PASS' : 'WARN', 'max_user_connections', $maxUser === 0 ? 'unlimited' : $maxUser.' concurrent connections for this user (web requests + the queue worker + cron share them)');

    $sqlMode = isset($vars['sql_mode']) ? $vars['sql_mode'] : '';
    check('INFO', 'server sql_mode strict?', stripos($sqlMode, 'STRICT_') !== false ? 'yes' : 'no - Laravel sets strict mode per connection (config strict=true), so the app is unaffected; direct SQL / phpMyAdmin imports are not');

    // useCurrent() columns (place_events.created_at) use the DB clock, the app stores UTC.
    $row = $pdo->query('SELECT TIMESTAMPDIFF(MINUTE, UTC_TIMESTAMP(), NOW()) AS offset_minutes')->fetch(PDO::FETCH_ASSOC);
    $offset = (int) $row['offset_minutes'];
    check(
        $offset === 0 ? 'PASS' : 'WARN',
        'DB clock vs UTC',
        $offset === 0 ? 'NOW() is UTC' : 'NOW() is '.($offset / 60).'h from UTC: CURRENT_TIMESTAMP defaults (place_events.created_at) would not be UTC - set "timezone" => "+00:00" in config/database.php',
    );

    // ---- charset round trip -----------------------------------------------------------
    $stmt = $pdo->prepare('SELECT CHAR_LENGTH(?) AS chars, ? AS echoed');
    $stmt->execute(['كافيهات', 'كافيهات']);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    check((int) $row['chars'] === 7 && $row['echoed'] === 'كافيهات' ? 'PASS' : 'FAIL', 'Arabic utf8mb4 round trip', 'CHAR_LENGTH='.$row['chars']);

    foreach (['places', 'claims', 'json', 'idx'] as $table) {
        $pdo->exec('DROP TABLE IF EXISTS '.TRIAL_PREFIX.$table);
    }

    // ---- spatial: POINT + SPATIAL INDEX + axis order ----------------------------------
    $places = TRIAL_PREFIX.'places';
    try {
        $pdo->exec('CREATE TABLE '.$places." (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(50) NOT NULL,
            status ENUM('active','draft') NOT NULL DEFAULT 'active',
            latitude DECIMAL(10,7) NOT NULL,
            longitude DECIMAL(10,7) NOT NULL,
            location POINT NOT NULL,
            SPATIAL INDEX wain_trial_location (location)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        check('PASS', 'CREATE TABLE with POINT NOT NULL + SPATIAL INDEX', 'ok');
    } catch (PDOException $e) {
        check('FAIL', 'CREATE TABLE with POINT NOT NULL + SPATIAL INDEX', $e->getMessage());

        return;
    }

    $fixed = [['cairo', 30.0444, 31.2357], ['alexandria', 31.2001, 29.9187], ['aswan', 24.0889, 32.8998]];
    $rows = [];
    foreach ($fixed as [$name, $lat, $lng]) {
        $rows[] = "('".$name."','active',".$lat.','.$lng.','.lat_lng_sql($lat, $lng).')';
    }
    mt_srand(42);
    for ($i = 0; $i < 5000; $i++) {
        $lat = 29.8 + mt_rand(0, 6000) / 10000;
        $lng = 31.0 + mt_rand(0, 8000) / 10000;
        $rows[] = "('p".$i."','".($i % 5 === 0 ? 'draft' : 'active')."',".$lat.','.$lng.','.lat_lng_sql($lat, $lng).')';
    }
    $started = microtime(true);
    foreach (array_chunk($rows, 500) as $chunk) {
        $pdo->exec('INSERT INTO '.$places.' (name, status, latitude, longitude, location) VALUES '.implode(',', $chunk));
    }
    check('INFO', 'insert 5003 points', round((microtime(true) - $started) * 1000).' ms');
    // ANALYZE returns a result set: it must be read before the next statement
    $pdo->query('ANALYZE TABLE '.$places)->fetchAll();

    $row = $pdo->query('SELECT ST_X(location) AS x, ST_Y(location) AS y FROM '.$places." WHERE name = 'cairo'")->fetch(PDO::FETCH_ASSOC);
    $axisOk = abs((float) $row['x'] - 31.2357) < 0.0001 && abs((float) $row['y'] - 30.0444) < 0.0001;
    check($axisOk ? 'PASS' : 'FAIL', 'POINT(lng lat): ST_X = longitude, ST_Y = latitude', 'X='.$row['x'].' Y='.$row['y']);

    $km = function ($fromName, $toName) use ($pdo, $places) {
        $sql = 'SELECT ST_Distance_Sphere(a.location, b.location) / 1000 FROM '.$places.' a, '.$places.' b WHERE a.name = ? AND b.name = ?';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$fromName, $toName]);

        return (float) $stmt->fetchColumn();
    };

    try {
        $alex = $km('cairo', 'alexandria');
        $aswan = $km('cairo', 'aswan');
        // swapped axes would give 183.5 / 590.8, so the tolerances are tight on purpose
        check(abs($alex - 180.0) <= 1.5 ? 'PASS' : 'FAIL', 'ST_Distance_Sphere Cairo-Alexandria', round($alex, 1).' km (expected 180.0, swapped axes give 183.5)');
        check(abs($aswan - 682.4) <= 3.0 ? 'PASS' : 'FAIL', 'ST_Distance_Sphere Cairo-Aswan', round($aswan, 1).' km (expected 682.4, swapped axes give 590.8)');
    } catch (PDOException $e) {
        check('FAIL', 'ST_Distance_Sphere', $e->getMessage());
    }

    $polygon = "ST_GeomFromText('POLYGON((31.20 30.00,31.30 30.00,31.30 30.10,31.20 30.10,31.20 30.00))')";
    $where = "status = 'active' AND MBRContains(".$polygon.', location)';

    try {
        $explain = $pdo->query('EXPLAIN SELECT id FROM '.$places.' WHERE '.$where)->fetch(PDO::FETCH_ASSOC);
        $used = isset($explain['key']) ? (string) $explain['key'] : '';
        check(
            $used === 'wain_trial_location' ? 'PASS' : 'WARN',
            'EXPLAIN: MBRContains uses the SPATIAL INDEX',
            $used === 'wain_trial_location'
                ? 'key='.$used.' type='.$explain['type'].' rows='.$explain['rows']
                : 'key='.($used ?: 'NULL').' - fall back to the (status, latitude, longitude) B-tree from 01 section 6',
        );

        $started = microtime(true);
        for ($i = 0; $i < 20; $i++) {
            $pdo->query('SELECT id FROM '.$places.' WHERE '.$where.' AND ST_Distance_Sphere(location, '.lat_lng_sql(30.05, 31.25).') <= 5000')->fetchAll();
        }
        $avg = (microtime(true) - $started) * 1000 / 20;
        check($avg < 100 ? 'PASS' : 'WARN', 'nearby-style query on 5000 places', round($avg, 1).' ms average of 20 (PRF-A target: p95 < 300 ms for the whole request)');
    } catch (PDOException $e) {
        check('FAIL', 'MBRContains / EXPLAIN', $e->getMessage());
    }

    // ---- generated column + UNIQUE (one open claim per place) -------------------------
    $claims = TRIAL_PREFIX.'claims';
    $syntax = null;
    $last = '';

    foreach (['STORED', 'PERSISTENT'] as $kind) {
        try {
            $pdo->exec('DROP TABLE IF EXISTS '.$claims);
            $pdo->exec('CREATE TABLE '.$claims." (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                place_id BIGINT UNSIGNED NOT NULL,
                status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
                open_place_id BIGINT UNSIGNED AS (IF(status = 'pending', place_id, NULL)) ".$kind.',
                UNIQUE KEY wain_trial_open_claim (open_place_id)
            ) ENGINE=InnoDB');
            $syntax = $kind;
            break;
        } catch (PDOException $e) {
            $last = $e->getMessage();
        }
    }

    if ($syntax === null) {
        check('FAIL', 'generated column + UNIQUE (place_claims)', $last);
    } else {
        $dupes = 0;
        $insert = function ($placeId, $status) use ($pdo, $claims, &$dupes) {
            try {
                $pdo->prepare('INSERT INTO '.$claims.' (place_id, status) VALUES (?, ?)')->execute([$placeId, $status]);

                return true;
            } catch (PDOException $e) {
                if (isset($e->errorInfo[1]) && (int) $e->errorInfo[1] === 1062) {
                    $dupes++;

                    return false;
                }

                throw $e;
            }
        };

        $first = $insert(1, 'pending');
        $second = $insert(1, 'pending');
        $approvedA = $insert(1, 'approved');
        $approvedB = $insert(1, 'approved');
        $works = $first && ! $second && $approvedA && $approvedB;

        check($works ? 'PASS' : 'FAIL', 'generated column + UNIQUE: one open claim per place', 'syntax '.$syntax.'; second pending rejected='.($second ? 'no' : 'yes').', approved duplicates allowed='.($approvedA && $approvedB ? 'yes' : 'no'));
    }

    // ---- JSON column ------------------------------------------------------------------
    $json = TRIAL_PREFIX.'json';
    try {
        $pdo->exec('CREATE TABLE '.$json.' (id INT AUTO_INCREMENT PRIMARY KEY, payload JSON NULL) ENGINE=InnoDB');
        $pdo->prepare('INSERT INTO '.$json.' (payload) VALUES (?)')->execute(['{"hours":{"sat":["09:00","23:30"]},"ar":"كافيه"}']);
        $row = $pdo->query('SELECT JSON_VALID(payload) AS valid, JSON_UNQUOTE(JSON_EXTRACT(payload, "$.ar")) AS ar FROM '.$json)->fetch(PDO::FETCH_ASSOC);
        $type = $pdo->query('SHOW COLUMNS FROM '.$json." WHERE Field = 'payload'")->fetch(PDO::FETCH_ASSOC);

        $rejects = false;
        try {
            $pdo->prepare('INSERT INTO '.$json.' (payload) VALUES (?)')->execute(['not json']);
        } catch (PDOException $e) {
            $rejects = true;
        }

        check(
            (int) $row['valid'] === 1 && $row['ar'] === 'كافيه' ? 'PASS' : 'FAIL',
            'JSON column',
            'stored as '.$type['Type'].', invalid JSON '.($rejects ? 'rejected' : 'accepted').' (MariaDB stores JSON as text: do not filter on its content)',
        );
    } catch (PDOException $e) {
        check('FAIL', 'JSON column', $e->getMessage());
    }

    // ---- index lengths used by the migrations -----------------------------------------
    try {
        $pdo->exec('CREATE TABLE '.TRIAL_PREFIX.'idx (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            city_id BIGINT UNSIGNED NOT NULL,
            slug VARCHAR(255) NOT NULL,
            name_ar VARCHAR(255) NOT NULL,
            UNIQUE KEY wain_trial_slug (slug),
            UNIQUE KEY wain_trial_city_name (city_id, name_ar)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        check('PASS', 'utf8mb4 unique indexes on VARCHAR(255)', 'ok (areas UNIQUE(city_id, name_ar))');
    } catch (PDOException $e) {
        check('FAIL', 'utf8mb4 unique indexes on VARCHAR(255)', $e->getMessage());
    }

    // ---- backup tool against this database --------------------------------------------
    $dump = find_binary('mariadb-dump') ?: find_binary('mysqldump');
    if ($dump === null || ! function_enabled('proc_open')) {
        check('WARN', 'mariadb-dump / mysqldump run', $dump === null ? 'no dump tool found on PATH' : 'proc_open is disabled');

        return;
    }

    $command = escapeshellarg($dump).' -h '.escapeshellarg($host).' -P '.escapeshellarg($port).' -u '.escapeshellarg($config['DB_USERNAME']).' --single-transaction --skip-lock-tables '.escapeshellarg($config['DB_DATABASE']).' '.escapeshellarg($places).' '.escapeshellarg($json);
    $environment = array_merge(getenv(), ['MYSQL_PWD' => $config['DB_PASSWORD']]);
    $process = @proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);

    if (! is_resource($process)) {
        check('WARN', 'mariadb-dump / mysqldump run', 'could not start '.$dump);

        return;
    }

    $bytes = 0;
    while (! feof($pipes[1])) {
        $chunk = fread($pipes[1], 65536);
        $bytes += strlen((string) $chunk);
    }
    $stderr = trim((string) stream_get_contents($pipes[2]));
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);

    check($code === 0 && $bytes > 0 ? 'PASS' : 'FAIL', basename($dump).' dump (needed for the daily R2 backup)', $code === 0 ? round($bytes / 1024).' KB dumped' : 'exit '.$code.' '.substr($stderr, 0, 160));
}

function check_images()
{
    section('Image processing (GD, memory and time)');

    if (! extension_loaded('gd') || ! function_exists('imagewebp')) {
        check('FAIL', 'GD + WebP', 'not available, skipping');

        return;
    }

    check('INFO', 'memory_limit for this run', (string) ini_get('memory_limit').', max_execution_time '.ini_get('max_execution_time'));
    out('       each step prints before it runs: if the script dies the last line shows which size failed');

    $sizes = [[1200, 800], [3000, 2000], [4000, 3000], [6000, 4000]];

    register_shutdown_function(function () {
        $error = error_get_last();

        if ($error && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            out('[FAIL] fatal error during: '.$GLOBALS['wain_step']);
            out('       '.$error['message']);
        }
    });

    foreach ($sizes as [$width, $height]) {
        $label = $width.'x'.$height.' ('.round($width * $height / 1000000, 1).' MP)';
        $GLOBALS['wain_step'] = 'image '.$label;
        out('       trying '.$label.' ...');

        $started = microtime(true);
        $source = tempnam(sys_get_temp_dir(), 'wainimg');
        $image = imagecreatetruecolor($width, $height);

        if ($image === false) {
            check('FAIL', 'image '.$label, 'imagecreatetruecolor failed');
            @unlink($source);

            continue;
        }

        $colour = imagecolorallocate($image, 200, 140, 90);
        imagefilledrectangle($image, 0, 0, $width, $height, $colour);
        for ($i = 0; $i < 40; $i++) {
            imageline($image, mt_rand(0, $width), mt_rand(0, $height), mt_rand(0, $width), mt_rand(0, $height), imagecolorallocate($image, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)));
        }
        imagejpeg($image, $source, 85);
        imagedestroy($image);

        // what the queue job does: decode the original, then three WebP variants
        $decoded = imagecreatefromjpeg($source);
        $ok = $decoded !== false;
        $variants = 0;

        if ($ok) {
            foreach ([240, 800, 1600] as $target) {
                $scaled = imagescale($decoded, $target);
                $webp = tempnam(sys_get_temp_dir(), 'wainwebp');

                if ($scaled !== false && imagewebp($scaled, $webp, 80) && filesize($webp) > 0) {
                    $variants++;
                }

                if ($scaled !== false) {
                    imagedestroy($scaled);
                }

                @unlink($webp);
            }

            imagedestroy($decoded);
        }

        @unlink($source);
        $seconds = round(microtime(true) - $started, 2);
        $peak = 'PHP '.human_bytes(memory_get_peak_usage(true));
        $rss = process_peak_rss();

        if ($rss !== null) {
            $peak .= ', process RSS '.human_bytes($rss);
        }

        check($ok && $variants === 3 ? 'PASS' : 'FAIL', 'image '.$label, $variants.'/3 WebP variants, '.$seconds.' s, peak memory '.$peak);
    }

    $GLOBALS['wain_step'] = 'done';

    check_imagick();
}

/**
 * The plan says intervention/image uses Imagick when it is available and GD otherwise. Measure it
 * the same way: decode a large JPEG, make the three WebP variants.
 */
function check_imagick()
{
    if (! extension_loaded('imagick') || ! class_exists('Imagick')) {
        check('INFO', 'Imagick', 'not loaded: GD will be used');

        return;
    }

    try {
        $webp = count(Imagick::queryFormats('WEBP')) > 0;
        $jpeg = count(Imagick::queryFormats('JPEG')) > 0;
        $version = Imagick::getVersion();
        check($webp && $jpeg ? 'PASS' : 'FAIL', 'Imagick WebP + JPEG delegates', 'WEBP='.($webp ? 'yes' : 'NO').' JPEG='.($jpeg ? 'yes' : 'NO').' - '.(isset($version['versionString']) ? $version['versionString'] : ''));

        if (! $webp || ! $jpeg || ! function_exists('imagecreatetruecolor')) {
            return;
        }

        foreach ([[3000, 2000], [6000, 4000]] as [$width, $height]) {
            $label = $width.'x'.$height.' ('.round($width * $height / 1000000, 1).' MP)';
            $GLOBALS['wain_step'] = 'imagick '.$label;
            out('       trying Imagick '.$label.' ...');

            $source = tempnam(sys_get_temp_dir(), 'wainimk');
            $canvas = imagecreatetruecolor($width, $height);
            imagefilledrectangle($canvas, 0, 0, $width, $height, imagecolorallocate($canvas, 90, 140, 200));
            for ($i = 0; $i < 40; $i++) {
                imageline($canvas, mt_rand(0, $width), mt_rand(0, $height), mt_rand(0, $width), mt_rand(0, $height), imagecolorallocate($canvas, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)));
            }
            imagejpeg($canvas, $source, 85);
            imagedestroy($canvas);

            $started = microtime(true);
            $variants = 0;
            $image = new Imagick($source);

            foreach ([240, 800, 1600] as $target) {
                $copy = clone $image;
                $copy->thumbnailImage($target, 0);
                $copy->setImageFormat('webp');
                $copy->setImageCompressionQuality(80);
                $copy->stripImage();

                if (strlen($copy->getImageBlob()) > 0) {
                    $variants++;
                }

                $copy->clear();
                $copy->destroy();
            }

            $image->clear();
            $image->destroy();
            @unlink($source);

            $detail = $variants.'/3 WebP variants, '.round(microtime(true) - $started, 2).' s';
            $rss = process_peak_rss();
            check($variants === 3 ? 'PASS' : 'FAIL', 'Imagick '.$label, $detail.($rss !== null ? ', process RSS peak so far '.human_bytes($rss) : ''));
        }
    } catch (Throwable $e) {
        check('FAIL', 'Imagick test', get_class($e).': '.substr($e->getMessage(), 0, 200));
    }

    $GLOBALS['wain_step'] = 'done';
}

// ---------------------------------------------------------------------------------------------
// Cron heartbeat: proves the host runs a job every minute, with which PHP, and whether a 55 s
// run (the queue worker) is allowed to finish.
// ---------------------------------------------------------------------------------------------

function heartbeat_file($dir)
{
    return $dir.DIRECTORY_SEPARATOR.'.hosting-check-heartbeat.log';
}

function run_heartbeat($dir, $duration)
{
    $write = function ($event) use ($dir) {
        $line = implode("\t", [gmdate('c'), $event, PHP_VERSION, defined('PHP_BINARY') ? PHP_BINARY : '', PHP_SAPI, (string) getmypid()]);
        file_put_contents(heartbeat_file($dir), $line."\n", FILE_APPEND | LOCK_EX);
    };

    $write('start');
    $end = time() + max(0, (int) $duration);

    while (time() < $end) {
        sleep(1);
    }

    $write('end');
}

function cron_report($dir)
{
    section('Cron heartbeat report');

    $file = heartbeat_file($dir);

    if (! is_readable($file)) {
        check('FAIL', 'heartbeat log', 'not found: the cron job never ran (or wrote somewhere else)');

        return;
    }

    $starts = [];
    $open = [];
    $versions = [];
    $binaries = [];

    // Lines are in chronological order. A start opens a run for its pid and the matching end
    // closes it, so a reused pid cannot hide a run that never finished.
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $parts = explode("\t", $line);

        if (count($parts) < 6) {
            continue;
        }

        [$time, $event, $version, $binary, , $pid] = $parts;
        $versions[$version] = true;
        $binaries[$binary] = true;

        if ($event === 'start') {
            $starts[] = strtotime($time);
            $open[$pid][] = strtotime($time);
        } elseif (! empty($open[$pid])) {
            array_pop($open[$pid]);
        }
    }

    $open = array_merge([], ...array_values($open));

    $times = array_values($starts);
    sort($times);
    $count = count($times);
    check($count >= 3 ? 'PASS' : 'FAIL', 'cron runs recorded', (string) $count.' (need >= 3 over a few minutes)');

    $gaps = [];
    for ($i = 1; $i < $count; $i++) {
        $gaps[] = $times[$i] - $times[$i - 1];
    }

    if ($gaps !== []) {
        sort($gaps);
        $median = $gaps[(int) floor(count($gaps) / 2)];
        check($median >= 50 && $median <= 70 ? 'PASS' : 'FAIL', 'interval between runs', 'median '.$median.' s, min '.min($gaps).' s, max '.max($gaps).' s (expected about 60)');
        check(max($gaps) <= 130 ? 'PASS' : 'WARN', 'missed ticks', 'longest gap '.max($gaps).' s');
    }

    // A run that started a moment ago is still running, not killed. Anything older than the
    // longest job we ask for (a 55 s worker) plus a margin that has no end line was killed.
    $cutoff = time() - 100;
    $killed = 0;
    $running = 0;

    foreach ($open as $startedAt) {
        if ($startedAt > $cutoff) {
            $running++;
        } else {
            $killed++;
        }
    }

    check(
        $killed === 0 ? 'PASS' : 'FAIL',
        'runs that finished',
        $killed === 0
            ? 'every finished-by-now run reached its end line (a 55 s job is not killed)'.($running > 0 ? '; '.$running.' run(s) still in progress, not counted' : '')
            : $killed.' run(s) started more than 100 s ago and never finished: the host kills long processes',
    );
    check(count($versions) === 1 && version_compare((string) array_keys($versions)[0], '8.3.0', '>=') ? 'PASS' : 'FAIL', 'PHP used by cron', implode(', ', array_keys($versions)).' via '.implode(', ', array_keys($binaries)).' (must be 8.3 - use the full path in the cron command)');
}

// ---------------------------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------------------------

$dir = __DIR__;
$config = load_config($dir);
$mode = 'check';
$flags = [];
$duration = 0;

if (WAIN_IS_CLI) {
    foreach (array_slice($_SERVER['argv'], 1) as $argument) {
        if ($argument === '--heartbeat') {
            $mode = 'heartbeat';
        } elseif ($argument === '--cron-report') {
            $mode = 'cron-report';
        } elseif (strpos($argument, '--duration=') === 0) {
            $duration = (int) substr($argument, 11);
        } elseif (in_array($argument, ['--skip-db', '--skip-images', '--skip-network'], true)) {
            $flags[$argument] = true;
        }
    }
} else {
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');

    $key = isset($config['CHECK_KEY']) ? (string) $config['CHECK_KEY'] : '';
    $given = isset($_GET['key']) ? (string) $_GET['key'] : '';

    if (strlen($key) < 16 || ! hash_equals($key, $given)) {
        http_response_code(403);
        echo "Forbidden. Set CHECK_KEY (16+ characters) in .hosting-check.env and open ?key=...\n";
        exit;
    }

    if (isset($_GET['mode']) && $_GET['mode'] === 'cron-report') {
        $mode = 'cron-report';
    }

    foreach (['skip-images', 'skip-network'] as $name) {
        if (isset($_GET[$name])) {
            $flags['--'.$name] = true;
        }
    }

    // The web copy sits in a public directory, where dotfiles are usually served: never touch the
    // database from here (run the database section over SSH), so no password has to live there.
    $flags['--skip-db'] = true;
    $webPasswordWarning = ! empty($config['DB_PASSWORD']);
}

if ($mode === 'heartbeat') {
    run_heartbeat($dir, $duration);
    exit(0);
}

out('Wain hosting check - '.gmdate('Y-m-d H:i:s').' UTC - '.(WAIN_IS_CLI ? 'CLI' : 'web').' - mode '.$mode);

if (! WAIN_IS_CLI) {
    out('(web mode: the database section is skipped on purpose, run it over SSH)');

    if (! empty($webPasswordWarning)) {
        check('WARN', 'DB_PASSWORD in the web directory', 'remove DB_PASSWORD from .hosting-check.env here: dotfiles are often publicly readable');
    }
}

if ($mode === 'cron-report') {
    cron_report($dir);
} else {
    check_php();
    check_extensions();
    check_ini();
    check_timezone();
    check_filesystem($dir);
    check_shell();

    if (empty($flags['--skip-network'])) {
        check_network();
    }

    if (empty($flags['--skip-db'])) {
        check_database($config);
    }

    if (empty($flags['--skip-images'])) {
        check_images();
    }
}

section('Summary');

$counts = ['PASS' => 0, 'WARN' => 0, 'FAIL' => 0, 'INFO' => 0];
foreach ($GLOBALS['wain_results'] as [$status]) {
    $counts[$status]++;
}

out(sprintf('PASS %d   WARN %d   FAIL %d   (INFO %d)', $counts['PASS'], $counts['WARN'], $counts['FAIL'], $counts['INFO']));

foreach (['FAIL', 'WARN'] as $level) {
    foreach ($GLOBALS['wain_results'] as [$status, $label, $detail]) {
        if ($status === $level) {
            out('  '.$level.': '.$label.($detail !== '' ? ' - '.$detail : ''));
        }
    }
}

if (! WAIN_IS_CLI) {
    out('');
    out('Delete this file and .hosting-check.env from the web directory now.');
}

exit($counts['FAIL'] > 0 ? 1 : 0);
