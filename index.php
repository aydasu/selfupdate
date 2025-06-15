<?php
// githubhook.aydasu.me.uk

// .env dosyasını oku
function loadEnv($filePath) {
    if (!file_exists($filePath)) {
        return [];
    }
    
    $env = [];
    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    
    foreach ($lines as $line) {        
        list($key, $value) = explode('=', $line, 2);
        $env[trim($key)] = trim($value);
    }
    
    return $env;
}

$env = loadEnv(__DIR__ . '/.env');

if (!isset($env['WEBHOOK_SECRET'])) {
    die('Secret not found');
}

$secret = $env['WEBHOOK_SECRET'];
$logFile = $env['LOG_FILE'] ?? '/var/log/github-webhook.log';
$sitesBasePath = $env['SITES_BASE_PATH'] ?? '/var/www';

function writeLog($message) {
  global $logFile;
  $timestamp = date('Y-m-d H:i:s');
  $newEntry = "[$timestamp] $message\n";
  
  if (file_exists($logFile)) {
    $lines = file($logFile, FILE_IGNORE_NEW_LINES);
    if (count($lines) >= 200) {
      $lines = array_slice($lines, -199);
    }
      $lines[] = rtrim($newEntry);
      file_put_contents($logFile, implode("\n", $lines) . "\n", LOCK_EX);
    } else {
    file_put_contents($logFile, $newEntry, LOCK_EX);
    }
}

$payload = file_get_contents('php://input');
$headers = getallheaders();

if (isset($headers['X-Hub-Signature-256'])) {
    $signature = hash_hmac('sha256', $payload, $secret);
    $expectedSignature = 'sha256=' . $signature;
    
    if (!hash_equals($expectedSignature, $headers['X-Hub-Signature-256'])) {
        writeLog('Unauthorized request - Invalid signature');
        http_response_code(401);
        die('Unauthorized');
    }
} else {
    writeLog('Unauthorized request - No signature');
    http_response_code(401);
    die('Unauthorized');
}

$data = json_decode($payload, true);

if ($data === null) {
    writeLog('Invalid JSON payload');
    http_response_code(400);
    die('Invalid JSON');
}

if (isset($data['ref']) && $data['ref'] === 'refs/heads/main') {
    
    $repoName = $data['repository']['name'] ?? 'unknown';
    $pusher = $data['pusher']['name'] ?? 'unknown';
    
    writeLog("Push received for repo: $repoName by $pusher");
    
    // Site dizinine git ve pull yap
    $siteDir = $sitesBasePath . '/' . $repoName;
    
    // Dizin var mı kontrol et
    if (!is_dir($siteDir)) {
        writeLog("Directory not found: $siteDir");
        http_response_code(404);
        die("Site directory not found: $siteDir");
    }
    
    // Git pull komutunu çalıştır
    $command = "cd $siteDir && git pull origin main 2>&1";
    $output = shell_exec($command);
    
    writeLog("Git pull executed for $repoName");
    writeLog("Output: " . trim($output));
    
    // Başarılı yanıt
    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'message' => "Site updated successfully: $repoName",
        'timestamp' => date('Y-m-d H:i:s'),
        'output' => trim($output)
    ]);
    
} else {
    // Main branch değilse
    $branch = $data['ref'] ?? 'unknown';
    
    http_response_code(200);
    echo json_encode([
        'status' => 'ignored',
        'message' => 'Only main branch pushes are processed',
        'branch' => $branch
    ]);
}
