<?php
// Self-contained chatbot diagnostics — no VLE includes
ini_set('display_errors', 1);
error_reporting(E_ALL);
header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex');
echo "=== EU Chatbot Diagnostics ===\n";
echo "Time   : " . date('Y-m-d H:i:s') . "\n";
echo "PHP    : " . PHP_VERSION . "\n";
echo "Host   : " . ($_SERVER['HTTP_HOST'] ?? 'n/a') . "\n\n";

// cURL
echo "cURL available : " . (function_exists('curl_init') ? 'YES' : 'NO -- FATAL') . "\n";
if (function_exists('curl_init')) {
    $v = curl_version();
    echo "cURL version   : " . $v['version'] . "\n";
    echo "cURL SSL       : " . $v['ssl_version'] . "\n";
}

// Config
echo "\n--- Config constants ---\n";
$cfg = dirname(__DIR__) . '/includes/config.php';
echo "config.php exists : " . (file_exists($cfg) ? 'YES' : 'NO') . "\n";
if (file_exists($cfg)) {
    // Only define a guard to prevent config from running its session redirect
    define('LOGIN_PAGE', true);
    require_once $cfg;
}
$keys = [
    'OPENAI_API_KEY'   => defined('OPENAI_API_KEY')   ? OPENAI_API_KEY   : '',
    'GROQ_API_KEY'     => defined('GROQ_API_KEY')     ? GROQ_API_KEY     : '',
    'DEEPSEEK_API_KEY' => defined('DEEPSEEK_API_KEY') ? DEEPSEEK_API_KEY : '',
    'GEMINI_API_KEY'   => defined('GEMINI_API_KEY')   ? GEMINI_API_KEY   : '',
];
foreach ($keys as $n => $v) {
    $status = empty($v) ? 'NOT SET' : 'SET (' . substr($v,0,6) . '...' . substr($v,-4) . ')';
    echo "$n : $status\n";
}

// Outbound tests
echo "\n--- Outbound HTTP ---\n";
if (!function_exists('curl_init')) {
    echo "Skipped\n";
} else {
    foreach ([
        'OpenAI'   => 'https://api.openai.com',
        'Groq'     => 'https://api.groq.com',
        'DeepSeek' => 'https://api.deepseek.com',
        'Gemini'   => 'https://generativelanguage.googleapis.com',
    ] as $label => $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>8,CURLOPT_NOBODY=>true,CURLOPT_SSL_VERIFYPEER=>true]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        echo "$label : " . ($err ? "BLOCKED/FAIL ($err)" : "REACHABLE (HTTP $code)") . "\n";
    }
}

// Live Groq test
echo "\n--- Live Groq call ---\n";
$groq_key = defined('GROQ_API_KEY') ? GROQ_API_KEY : '';
if (empty($groq_key) || !function_exists('curl_init')) {
    echo "Skipped\n";
} else {
    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['model'=>'llama-3.3-70b-versatile','messages'=>[['role'=>'user','content'=>'Reply: OK']],'max_tokens'=>5]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json','Authorization: Bearer '.$groq_key],
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($err)       echo "FAIL cURL: $err\n";
    elseif ($code === 200) {
        $d = json_decode($resp, true);
        echo "SUCCESS HTTP 200 - reply: " . ($d['choices'][0]['message']['content'] ?? '(empty)') . "\n";
    } else {
        $d = json_decode($resp, true);
        echo "FAIL HTTP $code - " . ($d['error']['message'] ?? substr($resp,0,200)) . "\n";
    }
}

echo "\n=== Done ===\n";