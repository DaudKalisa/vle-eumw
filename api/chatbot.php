<?php
/**
 * api/chatbot.php
 * AI Chatbot endpoint - Multi-provider with automatic fallback.
 * Provider order: OpenAI (ChatGPT) -> Groq (Llama 3.3) -> Meta Llama -> DeepSeek -> Gemini
 * Accepts POST JSON: { message: string, history: [{user,bot},...] }
 * Returns JSON: { reply: string, provider: string } | { error: string }
 */
// Prevent PHP notices/warnings from corrupting JSON output
ob_start();
ini_set('display_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);

require_once '../includes/auth.php';
requireLogin();

// Clear any stray output (BOM, notices, etc.) before sending JSON
ob_end_clean();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request body']);
    exit;
}

$message = trim($input['message'] ?? '');
$history = is_array($input['history'] ?? null) ? $input['history'] : [];

if ($message === '') {
    echo json_encode(['error' => 'Empty message']);
    exit;
}

// Sanitise - limit length to prevent token abuse
$message = mb_substr($message, 0, 2000);

if (!function_exists('curl_init')) {
    echo json_encode(['error' => 'cURL is not available on this server.']);
    exit;
}

// -- API Keys ------------------------------------------------------------------
$openai_key   = defined('OPENAI_API_KEY')   ? OPENAI_API_KEY   : (getenv('OPENAI_API_KEY')   ?: '');
$groq_key     = defined('GROQ_API_KEY')     ? GROQ_API_KEY     : (getenv('GROQ_API_KEY')     ?: '');
$meta_key     = defined('META_API_KEY')     ? META_API_KEY     : (getenv('META_API_KEY')     ?: '');
$deepseek_key = defined('DEEPSEEK_API_KEY') ? DEEPSEEK_API_KEY : (getenv('DEEPSEEK_API_KEY') ?: '');
$gemini_key   = defined('GEMINI_API_KEY')   ? GEMINI_API_KEY   : (getenv('GEMINI_API_KEY')   ?: '');

if (empty($openai_key) && empty($groq_key) && empty($meta_key) && empty($deepseek_key) && empty($gemini_key)) {
    echo json_encode(['error' => 'AI assistant not configured. Please ask the administrator to set up an API key.']);
    exit;
}

// -- Build system prompt -------------------------------------------------------
$role      = $_SESSION['vle_role'] ?? 'student';
$user_name = '';
if (!empty($_SESSION['vle_full_name'])) {
    $user_name = $_SESSION['vle_full_name'];
} elseif (function_exists('getCurrentUser')) {
    $_cw_u = getCurrentUser();
    $user_name = $_cw_u['display_name'] ?? '';
}
if (empty($user_name)) {
    $user_name = $_SESSION['vle_username'] ?? 'there';
}

$university = 'EUMW (European University of Modern World)';

$system_prompt = <<<PROMPT
You are EU Assistant, an intelligent academic support chatbot for the $university Virtual Learning Environment (VLE). You help {$role}s with any question they have.

Your capabilities:
- Answer academic questions across all subjects and disciplines
- Provide study tips, essay structure, research methodology, and citation guidance (APA, Harvard, MLA)
- Explain university policies, procedures, deadlines, and processes
- Guide students through dissertation and research project phases
- Help with exam preparation, revision strategies, and time management
- Assist lecturers with course delivery, assessment design, and student engagement
- Answer questions about the VLE system features and navigation
- Provide motivational support and academic wellbeing guidance
- Explain complex topics in simple terms with examples

Guidelines:
- Be warm, encouraging, and professional
- Give structured, clear answers with bullet points or numbered steps where helpful
- If asked about live personal data (grades, balances, enrollment status), explain you don't have real-time access and guide them to the correct VLE section
- For dissertation students: be especially thorough on research methodology, literature review, and academic writing
- Keep responses focused and practical - avoid unnecessary padding
- If a question is unclear, ask for clarification
- Current user role: {$role} | Name: {$user_name}
PROMPT;

// -- Limit history depth -------------------------------------------------------
$history = array_slice($history, -10);

// -- Helper: OpenAI-compatible call (OpenAI, Groq, Meta, DeepSeek) -------------
function _cw_call_openai($url, $api_key, $model, $system_prompt, $message, $history) {
    $messages = [['role' => 'system', 'content' => $system_prompt]];
    foreach ($history as $h) {
        if (!empty($h['user'])) $messages[] = ['role' => 'user',      'content' => (string)$h['user']];
        if (!empty($h['bot']))  $messages[] = ['role' => 'assistant',  'content' => (string)$h['bot']];
    }
    $messages[] = ['role' => 'user', 'content' => $message];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['model' => $model, 'messages' => $messages, 'temperature' => 0.75, 'max_tokens' => 1024], JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $api_key],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);

    if ($response === false || $curl_err) return ['ok' => false, 'error' => "cURL: $curl_err"];
    $data  = json_decode($response, true);
    if ($http_code !== 200) return ['ok' => false, 'error' => $data['error']['message'] ?? "HTTP $http_code"];
    $reply = $data['choices'][0]['message']['content'] ?? '';
    if (trim($reply) === '') return ['ok' => false, 'error' => 'empty response'];
    return ['ok' => true, 'reply' => $reply];
}

// ============================================================================
// PROVIDER 1: OpenAI - ChatGPT 4o-mini (PRIMARY)
// Get your key at: https://platform.openai.com/api-keys
// ============================================================================
if (!empty($openai_key)) {
    $result = _cw_call_openai(
        'https://api.openai.com/v1/chat/completions',
        $openai_key,
        'gpt-4o-mini',
        $system_prompt, $message, $history
    );
    if ($result['ok']) {
        echo json_encode(['reply' => $result['reply'], 'provider' => 'openai']);
        exit;
    }
    error_log('[chatbot.php] OpenAI failed: ' . ($result['error'] ?? 'unknown') . ' - trying Groq');
}

// ============================================================================
// PROVIDER 2: Groq - Llama 3.3 70B (free tier, ultra-fast)
// Get your free key at: https://console.groq.com
// ============================================================================
if (!empty($groq_key)) {
    $result = _cw_call_openai(
        'https://api.groq.com/openai/v1/chat/completions',
        $groq_key,
        'llama-3.3-70b-versatile',
        $system_prompt, $message, $history
    );
    if ($result['ok']) {
        echo json_encode(['reply' => $result['reply'], 'provider' => 'groq']);
        exit;
    }
    error_log('[chatbot.php] Groq failed: ' . ($result['error'] ?? 'unknown') . ' - trying Meta');
}

// ============================================================================
// PROVIDER 3: Meta Llama API - Llama-3.3-70B-Instruct
// Get your key at: https://llama.developer.meta.com
// ============================================================================
if (!empty($meta_key)) {
    $result = _cw_call_openai(
        'https://api.llama.com/v1/chat/completions',
        $meta_key,
        'Llama-3.3-70B-Instruct',
        $system_prompt, $message, $history
    );
    if ($result['ok']) {
        echo json_encode(['reply' => $result['reply'], 'provider' => 'meta']);
        exit;
    }
    error_log('[chatbot.php] Meta failed: ' . ($result['error'] ?? 'unknown') . ' - trying DeepSeek');
}

// ============================================================================
// PROVIDER 4: DeepSeek - deepseek-chat (OpenAI-compatible)
// Get your key at: https://platform.deepseek.com
// ============================================================================
if (!empty($deepseek_key)) {
    $result = _cw_call_openai(
        'https://api.deepseek.com/v1/chat/completions',
        $deepseek_key,
        'deepseek-chat',
        $system_prompt, $message, $history
    );
    if ($result['ok']) {
        echo json_encode(['reply' => $result['reply'], 'provider' => 'deepseek']);
        exit;
    }
    error_log('[chatbot.php] DeepSeek failed: ' . ($result['error'] ?? 'unknown') . ' - trying Gemini');
}

// ============================================================================
// PROVIDER 5: Google Gemini 2.0 Flash (last resort)
// Get your free key at: https://aistudio.google.com/apikey
// ============================================================================
if (!empty($gemini_key)) {
    $contents = [];
    foreach ($history as $h) {
        $u = trim($h['user'] ?? '');
        $b = trim($h['bot']  ?? '');
        if ($u !== '') $contents[] = ['role' => 'user',  'parts' => [['text' => $u]]];
        if ($b !== '') $contents[] = ['role' => 'model', 'parts' => [['text' => $b]]];
    }
    $contents[] = ['role' => 'user', 'parts' => [['text' => $message]]];

    $payload = [
        'system_instruction' => ['parts' => [['text' => $system_prompt]]],
        'contents'           => $contents,
        'generationConfig'   => ['temperature' => 0.75, 'maxOutputTokens' => 1024, 'topP' => 0.9, 'topK' => 40],
        'safetySettings'     => [
            ['category' => 'HARM_CATEGORY_HARASSMENT',        'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'],
            ['category' => 'HARM_CATEGORY_HATE_SPEECH',       'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'],
            ['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'],
            ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'],
        ],
    ];

    $ch = curl_init('https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=' . urlencode($gemini_key));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);

    if ($response !== false && !$curl_err && $http_code === 200) {
        $data          = json_decode($response, true);
        $finish_reason = $data['candidates'][0]['finishReason'] ?? '';
        if ($finish_reason === 'SAFETY') {
            echo json_encode(['error' => 'That question was blocked by content safety filters. Please rephrase.']);
            exit;
        }
        $reply = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
        if (trim($reply) !== '') {
            echo json_encode(['reply' => $reply, 'provider' => 'gemini']);
            exit;
        }
    }
    error_log("[chatbot.php] Gemini failed (HTTP $http_code, err: $curl_err)");
}

// -- All providers exhausted --------------------------------------------------
echo json_encode(['error' => 'AI service is temporarily unavailable. Please try again in a moment.']);