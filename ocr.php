<?php
/* Mittu Wholesaler - bill scan proxy (Gemini).
   API key yahan nahi hai: GitHub Actions deploy ke waqt gemini-config.php mein likh di jaati hai. */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Cache-Control: no-store');

function out($a, $code = 200) {
    http_response_code($code);
    echo json_encode($a, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(['ok' => false, 'error' => 'POST only'], 405);

$cfg = @include __DIR__ . '/gemini-config.php';
$key = (is_array($cfg) && isset($cfg['key'])) ? trim($cfg['key']) : '';
if ($key === '') out(['ok' => false, 'error' => 'server key missing'], 503);

$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) > 7000000) out(['ok' => false, 'error' => 'file too big'], 413);
$in = json_decode($raw, true);
if (!is_array($in)) out(['ok' => false, 'error' => 'bad request'], 400);

$mime = isset($in['mime']) ? $in['mime'] : '';
$data = isset($in['data']) ? $in['data'] : '';
$items = (isset($in['items']) && is_array($in['items'])) ? $in['items'] : [];
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'], true)) out(['ok' => false, 'error' => 'bad type'], 400);
if (!is_string($data) || $data === '' || !preg_match('/^[A-Za-z0-9+\/=\r\n]+$/', $data)) out(['ok' => false, 'error' => 'bad data'], 400);

/* simple rate limit: ek IP se 30 scan / ghanta (key ka galat istemal rokne ke liye) */
$ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'x';
$rl = sys_get_temp_dir() . '/mittu_rl_' . md5($ip);
$now = time();
$hits = [];
if (is_file($rl)) { $j = json_decode(@file_get_contents($rl), true); if (is_array($j)) $hits = $j; }
$hits = array_values(array_filter($hits, function ($t) use ($now) { return $t > $now - 3600; }));
if (count($hits) >= 30) out(['ok' => false, 'error' => 'too many scans, thodi der baad try karein'], 429);
$hits[] = $now;
@file_put_contents($rl, json_encode($hits));

/* stock list (id | naam | hindi | units) */
$lines = [];
foreach (array_slice($items, 0, 1500) as $it) {
    if (!is_array($it) || !isset($it['id'], $it['name'])) continue;
    $u = (isset($it['units']) && is_array($it['units'])) ? implode(' / ', array_map('strval', $it['units'])) : '';
    $h = isset($it['hindi']) ? $it['hindi'] : '';
    $lines[] = str_replace(["\n", '|'], ' ', $it['id'] . ' | ' . $it['name'] . ' | ' . $h . ' | ' . $u);
}

$prompt = "You read a customer's shopping list or bill (a photo or PDF; may be HANDWRITTEN; language may be Hindi in Devanagari, Hinglish or English) for an Indian grocery and general wholesale shop in Bihar.\n"
  . "Extract: customerName, mobile (10-digit Indian number, digits only), address (empty string if absent), and every item the customer wants.\n"
  . "For each item line choose the best matching stock item from the STOCK list below. Match by meaning, not spelling: Hindi, Hinglish and English names are the same item (e.g. आलू = Aalu = Potato, चीनी = Cheeni = Sugar, सरसों तेल = Sarson Tel = Mustard Oil).\n"
  . "Rules: itemId must be EXACTLY an id from STOCK, or null if nothing fits. unit must be EXACTLY one of that item's listed units. If the customer wrote another unit (gm, ग्राम, किलो, लीटर...) convert qty into one of the listed units (500 gm with unit kg => qty 0.5). If no unit is written use the first listed unit. qty is a number greater than 0 (default 1). line is the item text as written. Ignore prices, rates, totals and dates. Never invent items that are not written.\n\n"
  . "STOCK (id | name | hindi | units):\n" . implode("\n", $lines);

$schema = [
  'type' => 'OBJECT',
  'properties' => [
    'customerName' => ['type' => 'STRING'],
    'mobile' => ['type' => 'STRING'],
    'address' => ['type' => 'STRING'],
    'items' => ['type' => 'ARRAY', 'items' => [
      'type' => 'OBJECT',
      'properties' => [
        'line' => ['type' => 'STRING'],
        'itemId' => ['type' => 'STRING', 'nullable' => true],
        'qty' => ['type' => 'NUMBER'],
        'unit' => ['type' => 'STRING'],
      ],
      'required' => ['line', 'qty'],
    ]],
  ],
  'required' => ['items'],
];

$body = json_encode([
  'contents' => [['role' => 'user', 'parts' => [['text' => $prompt], ['inline_data' => ['mime_type' => $mime, 'data' => $data]]]]],
  'generationConfig' => ['temperature' => 0, 'responseMimeType' => 'application/json', 'responseSchema' => $schema],
]);

/* ---- model chain: yaad rakha hua -> config -> Google ki list ke naye flash models -> backup ---- */
$dir = is_writable(__DIR__) ? __DIR__ : sys_get_temp_dir();
$memFile = $dir . '/.gemini_model';
$listFile = $dir . '/.gemini_models.json';
$base = (is_array($cfg) && !empty($cfg['base'])) ? $cfg['base'] : 'https://generativelanguage.googleapis.com/v1beta/';

function g_call($url, $key, $body, $timeout) {
    $ch = curl_init($url);
    $opt = [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: ' . $key],
      CURLOPT_CONNECTTIMEOUT => 8,
      CURLOPT_TIMEOUT => max(3, (int)$timeout),
    ];
    if ($body !== null) { $opt[CURLOPT_POST] = true; $opt[CURLOPT_POSTFIELDS] = $body; }
    curl_setopt_array($ch, $opt);
    $res = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return [$http, $res === false ? null : json_decode($res, true), $err];
}

function auto_models($base, $key, $listFile) {
    if (is_file($listFile) && filemtime($listFile) > time() - 86400) {
        $c = json_decode(@file_get_contents($listFile), true);
        if (is_array($c) && count($c)) return $c;
    }
    list($http, $j) = g_call($base . 'models?pageSize=200', $key, null, 10);
    $found = [];
    if ($http === 200 && isset($j['models']) && is_array($j['models'])) {
        foreach ($j['models'] as $m) {
            if (empty($m['name']) || empty($m['supportedGenerationMethods']) || !in_array('generateContent', $m['supportedGenerationMethods'], true)) continue;
            $n = preg_replace('#^models/#', '', $m['name']);
            if (!preg_match('#^gemini-(\d+(?:\.\d+)?)-flash(-lite)?(-preview[\w.-]*)?$#', $n, $mm)) continue;
            $score = ((float)$mm[1]) * 100 - (empty($mm[2]) ? 0 : 20) - (empty($mm[3]) ? 0 : 8);
            $found[$n] = $score;
        }
    }
    arsort($found);
    $names = array_keys($found);
    if (count($names)) @file_put_contents($listFile, json_encode($names));
    return $names;
}

/* Key do tarah ki hoti hai: AI Studio ("AIza...") aur Vertex AI express ("AQ...").
   Pehle key ke shuruaat se andaza lagate hain, na chale to doosri API bhi try karte hain. */
$mem = ''; $memMode = '';
if (is_file($memFile)) {
    $p = explode('|', trim((string)@file_get_contents($memFile)), 2);
    if (count($p) === 2) { $memMode = $p[0]; $mem = $p[1]; }
}
$vbase = (is_array($cfg) && !empty($cfg['vbase'])) ? $cfg['vbase'] : 'https://aiplatform.googleapis.com/v1/';
$cfgModel = (is_array($cfg) && !empty($cfg['model'])) ? $cfg['model'] : '';

function candidates($mode, $mem, $memMode, $cfgModel, $base, $key, $listFile) {
    $c = [];
    if ($mem !== '' && $memMode === $mode) $c[] = $mem;
    if ($cfgModel !== '') $c[] = $cfgModel;
    if ($mode === 'aistudio') {
        foreach (auto_models($base, $key, $listFile) as $n) $c[] = $n;
        foreach (['gemini-flash-latest', 'gemini-2.5-flash', 'gemini-2.0-flash'] as $n) $c[] = $n;
    } else {
        foreach (['gemini-2.5-flash', 'gemini-2.0-flash', 'gemini-2.5-flash-lite', 'gemini-2.5-pro'] as $n) $c[] = $n;
    }
    return array_slice(array_values(array_unique($c)), 0, 6);
}

/* ek API par models ki chain. return: [jawab ya null, key_galat?] */
function run_chain($mode, $base, $vbase, $key, $body, $cands, $memFile, $mem, $memMode, $t0, &$tried, &$last) {
    foreach ($cands as $m) {
        $left = 27 - (microtime(true) - $t0);
        if ($left < 4) return [null, false];
        $url = ($mode === 'vertex') ? $vbase . 'publishers/google/models/' . $m . ':generateContent' : $base . 'models/' . $m . ':generateContent';
        list($http, $j, $cerr) = g_call($url, $key, $body, min(20, $left));
        $isMem = ($m === $mem && $mode === $memMode);
        if ($j === null) { $last = $mode . '/' . $m . ' network ' . $http . ': ' . $cerr; $tried[] = [$mode, $m, $http, substr($cerr, 0, 100)]; if ($isMem) @unlink($memFile); continue; }
        if ($http === 200 && isset($j['candidates'][0]['content']['parts'][0]['text'])) {
            $parsed = json_decode($j['candidates'][0]['content']['parts'][0]['text'], true);
            if (is_array($parsed)) {
                if (!$isMem) @file_put_contents($memFile, $mode . '|' . $m);
                return [['ok' => true, 'data' => $parsed, 'model' => $m, 'api' => $mode], false];
            }
            $msg = 'bad ai output';
        } else {
            $msg = isset($j['error']['message']) ? substr($j['error']['message'], 0, 140) : 'no answer';
        }
        $last = $mode . '/' . $m . ' ' . $http . ': ' . $msg;
        $tried[] = [$mode, $m, $http, $msg];
        if ($isMem) @unlink($memFile);                    /* yaad rakha model ab nahi chala */
        if ($http === 401 || ($http === 400 && preg_match('/api key|API_KEY/i', $msg))) return [null, true];   /* is API ke liye key galat */
    }
    return [null, false];
}

$modes = ['aistudio', 'vertex'];   /* AQ. aur AIza dono AI Studio (Gemini API) ki keys hain; Vertex sirf backup */
$t0 = microtime(true);
$tried = [];
$last = 'gemini error';
foreach ($modes as $mode) {
    $cands = candidates($mode, $mem, $memMode, $cfgModel, $base, $key, $listFile);
    list($res, $keyBad) = run_chain($mode, $base, $vbase, $key, $body, $cands, $memFile, $mem, $memMode, $t0, $tried, $last);
    if ($res) out($res);
    if (!$keyBad) break;      /* key theek hai par model nahi chale: doosri API se kuch nahi badlega */
}
out(['ok' => false, 'error' => $last, 'tried' => $tried], 502);
