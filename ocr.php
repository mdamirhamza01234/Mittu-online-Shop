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
  'contents' => [['parts' => [['text' => $prompt], ['inline_data' => ['mime_type' => $mime, 'data' => $data]]]]],
  'generationConfig' => ['temperature' => 0, 'responseMimeType' => 'application/json', 'responseSchema' => $schema],
]);

$models = [];
if (is_array($cfg) && !empty($cfg['model'])) $models[] = $cfg['model'];
$models = array_merge($models, ['gemini-2.5-flash', 'gemini-flash-latest']);
$last = 'gemini error';
foreach (array_unique($models) as $m) {
    $ch = curl_init('https://generativelanguage.googleapis.com/v1beta/models/' . $m . ':generateContent');
    curl_setopt_array($ch, [
      CURLOPT_POST => true,
      CURLOPT_POSTFIELDS => $body,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: ' . $key],
      CURLOPT_CONNECTTIMEOUT => 10,
      CURLOPT_TIMEOUT => 28,
    ]);
    $res = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);
    if ($res === false) { $last = 'network: ' . $cerr; continue; }
    $j = json_decode($res, true);
    if ($http === 200 && isset($j['candidates'][0]['content']['parts'][0]['text'])) {
        $parsed = json_decode($j['candidates'][0]['content']['parts'][0]['text'], true);
        if (is_array($parsed)) out(['ok' => true, 'data' => $parsed, 'model' => $m]);
        $last = 'bad ai output';
        continue;
    }
    $last = 'gemini ' . $http . (isset($j['error']['message']) ? ': ' . substr($j['error']['message'], 0, 160) : '');
    if ($http === 400 || $http === 401 || $http === 403 || $http === 429) break; /* key / limit ki dikkat: dusra model bekaar */
}
out(['ok' => false, 'error' => $last], 502);
