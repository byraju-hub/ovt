<?php
// Adds the 생명의 삶 months newly posted on the Duranno notice board to qt-2026.json.
// Called by the "QT본문 추가" button on the OVT page; sits behind the /ovt/ login.
//
// Only months after the last one already in the calendar are added, and only
// when a notice's passage list fits the "*YYMM 본문 1 몬 1:1~14 2 ..." layout
// exactly (consecutive days, one entry per day of the month, known books).
// Otherwise nothing is written and the reason is reported back.
//   body (optional JSON): {"dry_run": true} reports without writing,
//                         {"after": "2026-08"} overrides "the last month already there".
if (($_SERVER['REMOTE_USER'] ?? '') !== 'byraju') { http_response_code(403); exit('Forbidden'); }
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function qt_out($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// POST plus a custom header: a page on another site can't send that header
// without a CORS preflight, which this file never answers, so only the OVT
// page itself can trigger an update.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_SERVER['HTTP_X_OVT_REQUEST'] ?? '') !== '1') {
    qt_out(['ok' => false, 'message' => 'POST only'], 405);
}
@set_time_limit(90);

const QT_BASE = 'https://www.duranno.com/qt/view/';
$in = json_decode((string) file_get_contents('php://input'), true);
$in = is_array($in) ? $in : [];
$dry = !empty($in['dry_run']);
$after = (isset($in['after']) && preg_match('/^\d{4}-\d{2}$/', (string) $in['after'])) ? $in['after'] : null;

function qt_fetch($url) {
    if (!function_exists('curl_init')) return null;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_USERAGENT => 'Mozilla/5.0',
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $code !== 200) return null;
    // the board is served as EUC-KR
    $utf = function_exists('iconv') ? @iconv('CP949', 'UTF-8//IGNORE', $body) : false;
    if (($utf === false || $utf === '') && function_exists('mb_convert_encoding')) $utf = mb_convert_encoding($body, 'UTF-8', 'CP949');
    return $utf ?: null;
}

function qt_text($html) {
    $html = preg_replace('#<(script|style)\b.*?</\1>#is', ' ', $html);
    $t = html_entity_decode(preg_replace('/<[^>]*>/', ' ', $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = str_replace("\xC2\xA0", ' ', $t);
    return trim(preg_replace('/\s+/u', ' ', $t));
}

// "*2612 본문 1 사 1:1~9 2 ..." -> [day => [book, range]], or null with $err set
function qt_parse_notice($text, $ym, $names, &$err) {
    if (!preg_match('/\*(\d{2})(\d{2}) 본문 /u', $text, $m, PREG_OFFSET_CAPTURE)) { $err = "'*YYMM 본문' 표시를 찾지 못했어요"; return null; }
    if ('20' . $m[1][0] . '-' . $m[2][0] !== $ym) { $err = "공지 제목의 달($ym)과 본문 표시(*{$m[1][0]}{$m[2][0]})가 달라요"; return null; }
    $pos = $m[0][1] + strlen($m[0][0]);
    $re = '/\G(\d+) ([^\s\d]+) (\d+:\d+(?:~(?:\d+:)?\d+)?|\d+(?:~\d+)?장)(?: |$)/u';
    $days = []; $prev = 0;
    while (preg_match($re, $text, $x, PREG_OFFSET_CAPTURE, $pos)) {
        $d = (int) $x[1][0];
        if ($d !== $prev + 1) { $err = "날짜 번호가 이어지지 않아요 ($prev 다음 $d)"; return null; }
        if (!isset($names[$x[2][0]])) { $err = "알 수 없는 책 약칭 '{$x[2][0]}' ({$d}일)"; return null; }
        $days[$d] = [$names[$x[2][0]], $x[3][0]];
        $prev = $d;
        $pos = $x[0][1] + strlen($x[0][0]);
    }
    if (preg_match('/^\d/u', substr($text, $pos))) { $err = '본문 목록 뒤에 이해하지 못한 내용이 이어져요'; return null; }
    $last = (int) date('t', strtotime("$ym-01"));
    if (count($days) !== $last) { $err = "$ym 은 {$last}일까지인데 본문은 " . count($days) . '일치예요'; return null; }
    return $days;
}

function qt_json_string($s) { return json_encode((string) $s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }

// same layout the local tools/make-qt.sh reads and writes
function qt_render($cal) {
    ksort($cal);
    $parts = [];
    foreach ($cal as $date => $e) {
        $s = " \"$date\": {\n  \"book\": " . qt_json_string($e['book']) . ",\n  \"range\": " . qt_json_string($e['range'])
           . ",\n  \"ref\": " . qt_json_string($e['book'] . ' ' . $e['range']);
        if (!empty($e['title'])) $s .= ",\n  \"title\": " . qt_json_string($e['title']);
        $parts[] = $s . "\n }";
    }
    return "{\n" . implode(",\n", $parts) . "\n}\n";
}

$calPath = __DIR__ . '/qt-2026.json';
$cal = is_file($calPath) ? json_decode((string) file_get_contents($calPath), true) : [];
if (!is_array($cal)) qt_out(['ok' => false, 'message' => '달력 파일(qt-2026.json)을 읽지 못했어요.'], 500);
$lastMonth = $cal ? substr(max(array_keys($cal)), 0, 7) : '0000-00';
$threshold = $after ?: $lastMonth;

// book abbreviation -> full name, straight from the Bible file the page uses
$bibleRaw = is_file(__DIR__ . '/bible.json') ? (string) file_get_contents(__DIR__ . '/bible.json') : '';
preg_match_all('/"name":\s*"([^"]+)",\s*"short":\s*"([^"]+)"/u', $bibleRaw, $bm, PREG_SET_ORDER);
unset($bibleRaw);
$names = [];
foreach ($bm as $b) $names[$b[2]] = $b[1];
if (count($names) < 60) qt_out(['ok' => false, 'message' => '성경 데이터(bible.json)에서 책 이름을 읽지 못했어요.'], 500);

$listHtml = qt_fetch(QT_BASE . 'notice.asp');
if ($listHtml === null) qt_out(['ok' => false, 'message' => '두란노 공지 게시판에 접속하지 못했어요. 잠시 후 다시 눌러주세요.'], 502);
preg_match_all('#notice_detail\.asp\?sn=(\d+)[^>]*>\s*<span[^>]*>([^<]*)</span>#u', $listHtml, $nm, PREG_SET_ORDER);
$notices = [];
foreach ($nm as $n) {
    if (preg_match('/(\d{4})년\s*(\d{1,2})월호\s*본문/u', $n[2], $t)) {
        $ym = sprintf('%04d-%02d', $t[1], $t[2]);
        if (!isset($notices[$ym])) $notices[$ym] = (int) $n[1];
    }
}
ksort($notices);

$added = []; $errors = []; $seen = array_keys($notices);
foreach ($notices as $ym => $sn) {
    if ($ym <= $threshold) continue;
    $page = qt_fetch(QT_BASE . "notice_detail.asp?sn=$sn&page=notice&pg=1");
    if ($page === null) { $errors[] = ['month' => $ym, 'sn' => $sn, 'reason' => '공지 글을 열지 못했어요']; continue; }
    $err = '';
    $days = qt_parse_notice(qt_text($page), $ym, $names, $err);
    if ($days === null) { $errors[] = ['month' => $ym, 'sn' => $sn, 'reason' => $err]; continue; }
    foreach ($days as $d => [$book, $range]) {
        $key = sprintf('%s-%02d', $ym, $d);
        $cal[$key] = ['book' => $book, 'range' => $range, 'title' => $cal[$key]['title'] ?? ''];
    }
    $added[] = ['month' => $ym, 'days' => count($days), 'sn' => $sn];
}

if ($added && !$dry) {
    @copy($calPath, __DIR__ . '/qt-2026.prev.json');
    $tmp = $calPath . '.tmp';
    if (file_put_contents($tmp, qt_render($cal), LOCK_EX) === false || !@rename($tmp, $calPath)) {
        @unlink($tmp);
        qt_out(['ok' => false, 'message' => '달력 파일을 저장하지 못했어요(쓰기 권한).'], 500);
    }
}

qt_out([
    'ok'         => true,
    'dry_run'    => $dry,
    'added'      => $added,
    'errors'     => $errors,
    'last_month' => $cal ? substr(max(array_keys($cal)), 0, 7) : null,
    'board'      => $seen ? max($seen) : null,
]);
