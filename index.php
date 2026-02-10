<?php
date_default_timezone_set('America/New_York');

/**
 * Kanban-style Year Calendar (Months → 4 Weeks)
 *
 * - Scans a folder of JSON files
 * - Extracts "deadline" (date) from each
 * - Renders a horizontally scrollable year view:
 *     12 months, each month split into 4 week-columns
 * - Items appear in the week-column their deadline falls into
 *
 * Expected JSON example:
 * {
 *   "title": "My task",
 *   "deadline": "2026-03-14"   // or ISO8601 like "2026-03-14T12:00:00Z"
 * }
 */

// -------------------- CONFIG --------------------
$DATA_DIR = __DIR__ . '/data'; // folder containing .json files

// -------------------- RESCHEDULE ENDPOINT --------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reschedule') {
    header('Content-Type: application/json');
    
    $file = $_POST['file'] ?? '';
    $newDeadline = $_POST['deadline'] ?? '';
    
    if (empty($file) || empty($newDeadline)) {
        echo json_encode(['success' => false, 'error' => 'Missing file or deadline']);
        exit;
    }
    
    // Validate deadline format (YYYY-MM-DD)
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $newDeadline)) {
        echo json_encode(['success' => false, 'error' => 'Invalid deadline format']);
        exit;
    }
    
    // Security: prevent path traversal - validate subdirectory structure
    // Extract subdirectory and filename
    $fileParts = explode('/', str_replace('\\', '/', $file));
    
    // Should be in format: subdirectory/filename.json (max 2 parts)
    if (count($fileParts) !== 2) {
        echo json_encode(['success' => false, 'error' => 'Invalid file path format']);
        exit;
    }
    
    $subdir = basename($fileParts[0]);
    $filename = basename($fileParts[1]);
    
    // Reconstruct safe path
    $filePath = $DATA_DIR . '/' . $subdir . '/' . $filename;
    
    // Ensure the file exists and is within the data directory
    $realPath = realpath($filePath);
    $realDataDir = realpath($DATA_DIR);
    
    if (!$realPath || !$realDataDir || strpos($realPath, $realDataDir) !== 0) {
        echo json_encode(['success' => false, 'error' => 'File not found or invalid path']);
        exit;
    }
    
    try {
        // Step A: Read the JSON file
        $jsonData = file_get_contents($filePath);
        if ($jsonData === false) {
            throw new Exception('Failed to read file');
        }
        
        $data = json_decode($jsonData, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Failed to parse JSON: ' . json_last_error_msg());
        }
        
        if (!is_array($data)) {
            throw new Exception('Expected JSON object, got ' . gettype($data));
        }
        
        // Extract ID from filename using regex
        // Pattern supports both formats: ID-YYYY-MM-DD[-suffix].json and ID___YYYY-MM-DD[-suffix].json
        $pattern = '/^(.+?)(?:___|-)(\d{4}-\d{2}-\d{2})(?:-[a-zA-Z]+)?\.json$/';
        $basename = basename($file);
        if (!preg_match($pattern, $basename, $matches)) {
            throw new Exception('Invalid filename format');
        }
        
        $itemId = $matches[1];
        $oldDate = $matches[2];
        
        // Detect separator used in original filename
        $separator = (strpos($basename, '___') !== false) ? '___' : '-';
        
        // Get directory path (preserve subdirectory structure)
        $fileDir = dirname($filePath);
        
        // Step B: Modify status to "rescheduled"
        $data['status'] = 'rescheduled';
        
        // Step C: Write directly to the deprecated filename (atomic operation)
        $deprFile = $itemId . $separator . $oldDate . '-depr.json';
        $deprPath = $fileDir . '/' . $deprFile;
        
        if (!file_put_contents($deprPath, json_encode($data, JSON_PRETTY_PRINT))) {
            throw new Exception('Failed to write deprecated file');
        }
        
        // Remove the original file now that deprecated version is created
        if (file_exists($filePath)) {
            unlink($filePath);
        }
        
        // Step D: Create duplicate with new deadline and -updateMe suffix
        // Check if the new file already exists to prevent overwriting
        $newFile = $itemId . $separator . $newDeadline . '-updateMe.json';
        $newPath = $fileDir . '/' . $newFile;
        
        if (file_exists($newPath)) {
            throw new Exception('A file with the new deadline already exists');
        }
        
        // Prepare data for new file: remove rescheduled status and update deadline
        unset($data['status']);
        $data['deadline'] = $newDeadline;
        
        if (!file_put_contents($newPath, json_encode($data, JSON_PRETTY_PRINT))) {
            throw new Exception('Failed to write new file');
        }
        
        echo json_encode(['success' => true]);
        exit;
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// -------------------- ADD ITEM ENDPOINT --------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'additem') {
    header('Content-Type: application/json');
    
    $program = $_POST['program'] ?? '';
    $deadline = $_POST['deadline'] ?? '';
    $name = $_POST['name'] ?? '';
    
    if (empty($program) || empty($deadline) || empty($name)) {
        echo json_encode(['success' => false, 'error' => 'Missing required fields']);
        exit;
    }
    
    // Validate deadline format (YYYY-MM-DD)
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $deadline)) {
        echo json_encode(['success' => false, 'error' => 'Invalid deadline format']);
        exit;
    }
    
    // Security: sanitize program name (prevent path traversal)
    $program = basename($program);
    if (empty($program) || $program === '.' || $program === '..') {
        echo json_encode(['success' => false, 'error' => 'Invalid program name']);
        exit;
    }
    
    try {
        // Ensure the program directory exists
        $programDir = $DATA_DIR . '/' . $program;
        if (!is_dir($programDir)) {
            if (!mkdir($programDir, 0755, true)) {
                throw new Exception('Failed to create program directory');
            }
        }
        
        // Generate filename: {timestamp}___YYYY-MM-DD-importMe.json
        $timestamp = time();
        $filename = $timestamp . '___' . $deadline . '-importMe.json';
        $filePath = $programDir . '/' . $filename;
        
        // Check if file already exists (should be extremely rare due to timestamp)
        if (file_exists($filePath)) {
            throw new Exception('Unable to create item - a conflict was detected. Please try again.');
        }
        
        // Create JSON data
        // Note: 'id' is set to 'tmp' as a placeholder; it will be updated by downstream processing
        $data = [
            'status' => 'notDone',
            'id' => 'tmp',
            'integrity' => 'notLate',
            'program' => $program,
            'deadline' => $deadline,
            'name' => $name,
            'tags' => 'wobj'
        ];
        
        // Write the file
        if (!file_put_contents($filePath, json_encode($data, JSON_PRETTY_PRINT))) {
            throw new Exception('Failed to write file');
        }
        
        echo json_encode(['success' => true]);
        exit;
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// Year to render (defaults to current year)
$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
if ($year < 1970 || $year > 2100) {
    $year = (int)date('Y');
}

// Optional: only show items for this year (recommended)
$ONLY_THIS_YEAR = true;

// -------------------- HELPERS --------------------
function safe_basename_no_ext(string $path): string
{
    $b = basename($path);
    return preg_replace('/\.[^.]+$/', '', $b);
}

function parse_deadline_to_datetime($deadline): ?DateTimeImmutable
{
    if (!is_string($deadline) || trim($deadline) === '') {
        return null;
    }
    try {
        // Accept "YYYY-MM-DD" or ISO8601
        return new DateTimeImmutable($deadline);
    } catch (Throwable $e) {
        // Try strict date-only
        $d = DateTimeImmutable::createFromFormat('Y-m-d', $deadline);
        return $d ?: null;
    }
}

/**
 * Map a day-of-month to one of 4 "weeks":
 *  1: days 1-7
 *  2: days 8-14
 *  3: days 15-21
 *  4: days 22-end
 */
function week_bucket_1_to_4(int $day): int
{
    if ($day <= 7) {
        return 1;
    }
    if ($day <= 14) {
        return 2;
    }
    if ($day <= 21) {
        return 3;
    }
    return 4;
}

function h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

// -------------------- CURRENT DATE HIGHLIGHTING --------------------
$now = new DateTimeImmutable('now');
$currentYear  = (int)$now->format('Y');
$isThisYear   = ($year === $currentYear);

$currentMonth = $isThisYear ? (int)$now->format('n') : null;
$currentWeek  = $isThisYear ? week_bucket_1_to_4((int)$now->format('j')) : null;

// -------------------- LOAD ITEMS --------------------
$items = []; // items[month][week] = list of items
$allPrograms = []; // collect unique programs

for ($m = 1; $m <= 12; $m++) {
    for ($w = 1; $w <= 4; $w++) {
        $items[$m][$w] = [];
    }
}

$errors = [];
if (!is_dir($DATA_DIR)) {
    $errors[] = "Data directory not found: {$DATA_DIR}";
} else {
    // Scan both top-level and subdirectories for JSON files
    $files = array_merge(
        glob($DATA_DIR . '/*.json') ?: [],
        glob($DATA_DIR . '/*/*.json') ?: []
    );
    
    foreach ($files as $file) {
        $raw = @file_get_contents($file);
        if ($raw === false) {
            $errors[] = "Could not read: " . basename($file);
            continue;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $errors[] = "Invalid JSON: " . basename($file);
            continue;
        }

        // Collect program names
        if (isset($data['program']) && !empty($data['program'])) {
            $allPrograms[$data['program']] = true;
        }

        $dt = parse_deadline_to_datetime($data['deadline'] ?? null);
        if (!$dt) {
            continue;
        }

        if ($ONLY_THIS_YEAR && (int)$dt->format('Y') !== $year) {
            continue;
        }

        $month = (int)$dt->format('n'); // 1..12
        $day   = (int)$dt->format('j'); // 1..31
        $week  = week_bucket_1_to_4($day);

        $title = $data['title'] ?? $data['name'] ?? safe_basename_no_ext($file);

        // Get relative file path (including subdirectory)
        $relativeFile = str_replace($DATA_DIR . '/', '', $file);

        // Keep anything else you want to display
        $items[$month][$week][] = [
            'title'    => (string)$title,
            'file'     => $relativeFile,
            'deadline' => $dt->format('Y-m-d'),
            'raw'      => $data,
        ];
    }
}

// Sort and convert programs to array
$allPrograms = array_keys($allPrograms);
sort($allPrograms);

// Optional: sort items inside each bucket by deadline then title
for ($m = 1; $m <= 12; $m++) {
    for ($w = 1; $w <= 4; $w++) {
        usort($items[$m][$w], function ($a, $b) {
            $c = strcmp($a['deadline'], $b['deadline']);
            return $c !== 0 ? $c : strcmp($a['title'], $b['title']);
        });
    }
}

$monthNames = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June',
    7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
];

?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title><?=h($year)?> Kanban Calendar</title>
    <style>
    :root {
        --bg: #0b0f17;
        --panel: #121a28;
        --panel2: #0f1623;
        --text: #e8eefc;
        --muted: #a7b3cf;
        --border: rgba(255, 255, 255, .10);
        --shadow: 0 10px 30px rgba(0, 0, 0, .35);
        --radius: 14px;
        --gap: 12px;
        --colW: unset;
        --weekHeader: #22314c;
        --card: #162238;
        --cardBorder: rgba(255, 255, 255, .12);
    }

    html,
    body {
        overflow-x: hidden;
    }

    body {
        margin: 0;
        font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Arial, "Apple Color Emoji", "Segoe UI Emoji";
        background: radial-gradient(1200px 700px at 20% 0%, #18233a, var(--bg));
        color: var(--text);
    }

    header {
        position: sticky;
        top: 0;
        z-index: 5;
        backdrop-filter: blur(10px);
        background: rgba(11, 15, 23, .7);
        border-bottom: 1px solid var(--border);
        padding: 14px 16px;
        display: flex;
        align-items: center;
        gap: 12px;
    }

    header h1 {
        font-size: 16px;
        margin: 0;
        letter-spacing: .2px;
        font-weight: 700;
    }

    header .meta {
        margin-left: auto;
        display: flex;
        gap: 10px;
        align-items: center;
        color: var(--muted);
        font-size: 13px;
    }

    .pill {
        border: 1px solid var(--border);
        background: rgba(255, 255, 255, .04);
        padding: 6px 10px;
        border-radius: 999px;
        color: var(--muted);
        text-decoration: none;
    }

    .wrap {
        padding: 16px;
    }

    .scroll {
        overflow-x: auto;
        padding-bottom: 10px;
    }

    .year {
        display: flex;
        gap: var(--gap);
        align-items: stretch;
        width: max-content;
    }

    .month {
        width: var(--colW);
        height: 80vh;
        min-width: var(--colW);
        background: linear-gradient(180deg, rgba(255, 255, 255, .04), rgba(255, 255, 255, .02));
        border: 1px solid var(--border);
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        overflow: hidden;
        display: flex;
        flex-direction: column;
    }

    .monthTitle {
        padding: 12px 12px;
        background: rgba(255, 255, 255, .03);
        border-bottom: 1px solid var(--border);
        display: flex;
        justify-content: space-between;
        align-items: baseline;
        gap: 8px;
    }

    .monthTitle .name {
        font-weight: 800;
        letter-spacing: .2px;
        font-size: 14px;
    }

    .monthTitle .count {
        font-size: 12px;
        color: var(--muted);
    }

    .weeks {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 0;
        height: 100%;
        background: var(--panel2);
    }

    .week {
        border-right: 1px solid var(--border);
        display: flex;
        flex-direction: column;
        min-height: 260px;
        width: 20rem;
    }

    .week:last-child {
        border-right: none;
    }

    .weekHeader {
        background: rgba(34, 49, 76, .55);
        padding: 8px 8px;
        font-size: 12px;
        color: var(--muted);
        border-bottom: 1px solid var(--border);
        display: flex;
        justify-content: space-between;
        gap: 6px;
    }

    .lane {
        padding: 8px;
        display: flex;
        flex-direction: column;
        gap: 8px;
        flex: 1;
    }

    .item {
        background: rgba(22, 34, 56, .85);
        border: 1px solid var(--cardBorder);
        border-radius: 12px;
        padding: 8px 9px;
        box-shadow: 0 8px 18px rgba(0, 0, 0, .25);
    }

    .item .t {
        font-size: 12.5px;
        line-height: 1.2;
        font-weight: 700;
        margin: 0 0 6px 0;
        word-break: break-word;
    }

    .item .sub {
        font-size: 11.5px;
        color: var(--muted);
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
    }

    .empty {
        border: 1px dashed rgba(255, 255, 255, .12);
        border-radius: 12px;
        padding: 10px 9px;
        color: rgba(167, 179, 207, .8);
        font-size: 11.5px;
    }

    .errors {
        margin: 12px 0 0 0;
        padding: 10px 12px;
        border: 1px solid rgba(255, 120, 120, .35);
        background: rgba(255, 80, 80, .08);
        border-radius: 12px;
        color: #ffd3d3;
        font-size: 12.5px;
    }

    .hint {
        margin-top: 10px;
        color: var(--muted);
        font-size: 12px;
    }


    /* =========================
       LIGHT SYSTEM-UI THEME
       ========================= */

    :root {
        color-scheme: light;

        --bg: #f6f7fb;
        --panel: #ffffff;
        --panel2: #f2f4f8;

        --text: #111827;
        --muted: #55637a;

        --border: rgba(17, 24, 39, 0.14);

        --shadow:
            0 10px 24px rgba(17, 24, 39, 0.08),
            0 2px 6px rgba(17, 24, 39, 0.06);

        --radius: 14px;
        --gap: 12px;

        --weekHeader: #e9eef7;

        --card: #ffffff;
        --cardBorder: rgba(17, 24, 39, 0.14);

        --focus: rgba(59, 130, 246, 0.35);
        --selectionBg: rgba(59, 130, 246, 0.18);
        --selectionText: #111827;

        --codeBg: rgba(17, 24, 39, 0.06);
        --codeBorder: rgba(17, 24, 39, 0.12);

        --link: #1d4ed8;
        --linkHover: #1e40af;
    }

    /* =========================
       BASE / RESET-LIKE LAYER
       ========================= */

    html,
    body {
        background: var(--bg) !important;
        color: var(--text) !important;
    }

    * {
        font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Arial, "Apple Color Emoji", "Segoe UI Emoji" !important;
        color: inherit;
    }

    a {
        color: var(--link);
    }

    a:hover {
        color: var(--linkHover);
    }

    ::selection {
        background: var(--selectionBg);
        color: var(--selectionText);
    }

    code,
    pre,
    kbd,
    samp {
        background: var(--codeBg);
        border: 1px solid var(--codeBorder);
        border-radius: 10px;
        padding: 2px 6px;
    }

    input,
    textarea,
    select,
    button {
        color: var(--text);
        background: var(--panel);
        border: 1px solid var(--border);
        border-radius: 12px;
        box-shadow: none;
    }

    button,
    .pill {
        background: rgba(17, 24, 39, 0.04) !important;
        border: 1px solid var(--border) !important;
        color: var(--muted) !important;
    }

    :focus-visible {
        outline: 3px solid var(--focus);
        outline-offset: 2px;
    }

    header {
        background: rgba(246, 247, 251, 0.78) !important;
        border-bottom: 1px solid var(--border) !important;
        backdrop-filter: blur(10px);
    }

    .month {
        background: var(--panel) !important;
        border: 1px solid var(--border) !important;
        box-shadow: var(--shadow) !important;
    }

    .weeks {
        background: var(--panel2) !important;
    }

    .weekHeader {
        background: var(--weekHeader) !important;
        color: var(--muted) !important;
        border-bottom: 1px solid var(--border) !important;
    }

    .week {
        border-right: 1px solid var(--border) !important;
    }

    .item {
        background: var(--card) !important;
        border: 1px solid var(--cardBorder) !important;
        box-shadow: 0 8px 18px rgba(17, 24, 39, 0.08) !important;
    }

    .item .sub {
        color: var(--muted) !important;
    }

    .empty {
        border: 1px dashed rgba(17, 24, 39, 0.18) !important;
        color: rgba(85, 99, 122, 0.95) !important;
        background: rgba(17, 24, 39, 0.02);
    }

    .errors {
        border: 1px solid rgba(220, 38, 38, 0.25) !important;
        background: rgba(220, 38, 38, 0.06) !important;
        color: #7f1d1d !important;
    }

    *::-webkit-scrollbar {
        height: 10px;
        width: 10px;
    }

    *::-webkit-scrollbar-track {
        background: rgba(17, 24, 39, 0.04);
    }

    *::-webkit-scrollbar-thumb {
        background: rgba(17, 24, 39, 0.18);
        border-radius: 999px;
        border: 2px solid rgba(17, 24, 39, 0.04);
    }

    *::-webkit-scrollbar-thumb:hover {
        background: rgba(17, 24, 39, 0.28);
    }

    /* =========================
       CURRENT / PAST HIGHLIGHTS
       ========================= */

    /* Past months: slightly darker / subdued */
    .month--past {
        opacity: 0.4;
        filter: saturate(0.92);
    }

    /* Current month: subtle emphasis */
    .month--current {
        border-color: rgba(59, 130, 246, 0.35) !important;
        box-shadow:
            0 10px 24px rgba(17, 24, 39, 0.10),
            0 0 0 2px rgba(59, 130, 246, 0.18) !important;
    }

    /* Current week column: highlight the whole lane */
    .week--current {
        position: relative;
        background: rgba(59, 130, 246, 0.06);
    }

    /* Slim accent bar on the current week */
    .week--current::before {
        content: "";
        position: absolute;
        left: 0;
        top: 0;
        bottom: 0;
        width: 3px;
        background: rgba(59, 130, 246, 0.55);
    }

    /* Current week header */
    .weekHeader--current {
        background: rgba(59, 130, 246, 0.14) !important;
        color: rgba(17, 24, 39, 0.85) !important;
        font-weight: 700;
    }

    /* =========================
       RESCHEDULE FEATURE
       ========================= */
    
    .reschedule-btn {
        margin-top: 8px;
        padding: 4px 8px;
        font-size: 11px;
        border-radius: 8px;
        cursor: pointer;
        width: 100%;
        transition: all 0.2s;
    }

    .reschedule-btn:hover {
        background: rgba(59, 130, 246, 0.12) !important;
        border-color: rgba(59, 130, 246, 0.35) !important;
    }

    .reschedule-modal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.5);
        z-index: 1000;
        justify-content: center;
        align-items: center;
    }

    .reschedule-modal.active {
        display: flex;
    }

    .reschedule-box {
        background: var(--panel);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        padding: 20px;
        max-width: 400px;
        width: 90%;
        box-shadow: var(--shadow);
    }

    .reschedule-box h3 {
        margin: 0 0 16px 0;
        font-size: 16px;
        font-weight: 700;
    }

    .reschedule-form-group {
        margin-bottom: 16px;
    }

    .reschedule-form-group label {
        display: block;
        font-size: 13px;
        font-weight: 600;
        margin-bottom: 6px;
        color: var(--muted);
    }

    .reschedule-form-group select {
        width: 100%;
        padding: 8px 10px;
        font-size: 13px;
        border-radius: 8px;
    }

    .reschedule-actions {
        display: flex;
        gap: 10px;
        margin-top: 20px;
    }

    .reschedule-actions button {
        flex: 1;
        padding: 10px;
        font-size: 13px;
        font-weight: 600;
        cursor: pointer;
        border-radius: 8px;
        transition: all 0.2s;
    }

    .reschedule-confirm {
        background: rgba(59, 130, 246, 0.15) !important;
        border-color: rgba(59, 130, 246, 0.35) !important;
        color: #1d4ed8 !important;
    }

    .reschedule-confirm:hover {
        background: rgba(59, 130, 246, 0.25) !important;
    }

    .reschedule-cancel:hover {
        background: rgba(220, 38, 38, 0.12) !important;
        border-color: rgba(220, 38, 38, 0.35) !important;
    }

    .reschedule-message {
        position: fixed;
        top: 80px;
        right: 20px;
        padding: 12px 20px;
        background: var(--panel);
        border: 1px solid var(--border);
        border-radius: 10px;
        box-shadow: var(--shadow);
        z-index: 1001;
        display: none;
        font-size: 13px;
        max-width: 300px;
    }

    .reschedule-message.show {
        display: block;
        animation: slideIn 0.3s ease;
    }

    .reschedule-message.success {
        border-color: rgba(34, 197, 94, 0.35);
        background: rgba(34, 197, 94, 0.08);
        color: #166534;
    }

    .reschedule-message.error {
        border-color: rgba(220, 38, 38, 0.35);
        background: rgba(220, 38, 38, 0.08);
        color: #7f1d1d;
    }

    @keyframes slideIn {
        from {
            transform: translateX(400px);
            opacity: 0;
        }
        to {
            transform: translateX(0);
            opacity: 1;
        }
    }

    /* =========================
       ADD ITEM BUTTON
       ========================= */
    
    .add-item-btn {
        margin-top: 8px;
        padding: 4px 8px;
        font-size: 11px;
        border-radius: 8px;
        cursor: pointer;
        width: 100%;
        transition: all 0.2s;
        background: rgba(34, 197, 94, 0.08) !important;
        border: 1px solid rgba(34, 197, 94, 0.25) !important;
        color: #166534 !important;
    }

    .add-item-btn:hover {
        background: rgba(34, 197, 94, 0.15) !important;
        border-color: rgba(34, 197, 94, 0.35) !important;
    }

    /* =========================
       ADD ITEM MODAL
       ========================= */
    
    .add-item-modal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.5);
        z-index: 1000;
        justify-content: center;
        align-items: center;
    }

    .add-item-modal.active {
        display: flex;
    }

    .add-item-box {
        background: var(--panel);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        padding: 20px;
        max-width: 400px;
        width: 90%;
        box-shadow: var(--shadow);
    }

    .add-item-box h3 {
        margin: 0 0 16px 0;
        font-size: 16px;
        font-weight: 700;
    }

    .add-item-form-group {
        margin-bottom: 16px;
    }

    .add-item-form-group label {
        display: block;
        font-size: 13px;
        font-weight: 600;
        margin-bottom: 6px;
        color: var(--muted);
    }

    .add-item-form-group select,
    .add-item-form-group input {
        width: 100%;
        padding: 8px 10px;
        font-size: 13px;
        border-radius: 8px;
    }

    .add-item-actions {
        display: flex;
        gap: 10px;
        margin-top: 20px;
    }

    .add-item-actions button {
        flex: 1;
        padding: 10px;
        font-size: 13px;
        font-weight: 600;
        cursor: pointer;
        border-radius: 8px;
        transition: all 0.2s;
    }

    .add-item-confirm {
        background: rgba(34, 197, 94, 0.15) !important;
        border-color: rgba(34, 197, 94, 0.35) !important;
        color: #166534 !important;
    }

    .add-item-confirm:hover {
        background: rgba(34, 197, 94, 0.25) !important;
    }

    .add-item-cancel:hover {
        background: rgba(220, 38, 38, 0.12) !important;
        border-color: rgba(220, 38, 38, 0.35) !important;
    }
    </style>
</head>

<body>

    <!-- Replace your existing <script>(function(){ ... })();</script> with this one -->
    <script>
    (function() {
        function isScrollableX(el) {
            if (!el) return false;
            const s = getComputedStyle(el);
            const canScroll = (s.overflowX === 'auto' || s.overflowX === 'scroll');
            return canScroll && el.scrollWidth > el.clientWidth + 1;
        }

        function findScrollParent(el) {
            let p = el && el.parentElement;
            while (p) {
                if (isScrollableX(p)) return p;
                p = p.parentElement;
            }
            return document.scrollingElement || document.documentElement;
        }

        // Compute scrollLeft target to align `el`'s left edge with scroller's left edge
        function targetLeftForElement(scroller, el) {
            const sRect = scroller.getBoundingClientRect();
            const eRect = el.getBoundingClientRect();
            const delta = (eRect.left - sRect.left);
            return scroller.scrollLeft + delta;
        }

        function getMonths() {
            return Array.from(document.querySelectorAll('.year .month'));
        }

        function getActiveMonthIndex(scroller, months) {
            // Choose the month whose left edge is closest to the scroller's left edge
            const sLeft = scroller.getBoundingClientRect().left;
            let bestIdx = 0;
            let bestDist = Infinity;

            for (let i = 0; i < months.length; i++) {
                const mLeft = months[i].getBoundingClientRect().left;
                const dist = Math.abs(mLeft - sLeft);
                if (dist < bestDist) {
                    bestDist = dist;
                    bestIdx = i;
                }
            }
            return bestIdx;
        }

        function animateScrollLeft(el, to, durationMs) {
            const from = el.scrollLeft;
            const delta = to - from;
            if (delta === 0) return;

            const start = performance.now();

            // easeOutCubic
            const ease = (t) => 1 - Math.pow(1 - t, 3);

            function tick(now) {
                const t = Math.min(1, (now - start) / durationMs);
                el.scrollLeft = from + delta * ease(t);
                if (t < 1) requestAnimationFrame(tick);
            }
            requestAnimationFrame(tick);
        }

        function scrollToMonthIndex(scroller, months, idx, durationMs) {
            idx = Math.max(0, Math.min(months.length - 1, idx));
            const target = targetLeftForElement(scroller, months[idx]);
            animateScrollLeft(scroller, target, durationMs ?? 180); // <-- faster: 120–200ms feels snappy
        }

        function snapToCurrentMonth() {
            const currentMonth = document.getElementById('month-current');
            if (!currentMonth) return;

            const scroller = findScrollParent(currentMonth);
            const target = targetLeftForElement(scroller, currentMonth);
            scroller.scrollLeft = target;
        }

        function setupArrowKeyNav() {
            const currentMonth = document.getElementById('month-current');
            // Use calendarScroll if present; otherwise find via currentMonth
            const fallbackScroller = document.getElementById('calendarScroll');
            const scroller = fallbackScroller || (currentMonth ? findScrollParent(currentMonth) : null);
            if (!scroller) return;

            const months = getMonths();
            if (!months.length) return;

            // Keyboard handler: left/right jumps to previous/next month boundary
            document.addEventListener('keydown', (e) => {
                // Don’t hijack arrows while typing in inputs/textareas or contenteditable
                const t = e.target;
                const tag = t && t.tagName ? t.tagName.toLowerCase() : '';
                const typing = tag === 'input' || tag === 'textarea' || tag === 'select' || (t && t.isContentEditable);
                if (typing) return;

                if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return;

                e.preventDefault();

                const activeIdx = getActiveMonthIndex(scroller, months);
                const nextIdx = e.key === 'ArrowRight' ? activeIdx + 1 : activeIdx - 1;

                scrollToMonthIndex(scroller, months, nextIdx, 140);
            }, {
                passive: false
            });
        }

        // Run after layout is stable
        window.addEventListener('load', () => {
            // Optional: keep your current-month snap behavior
            snapToCurrentMonth();

            // Arrow-key month snapping
            setupArrowKeyNav();
        });

        // If already loaded (bfcache)
        if (document.readyState === 'complete') {
            snapToCurrentMonth();
            setupArrowKeyNav();
        }
    })();
    </script>



    <header>
        <h1><?=h($year)?> Kanban Calendar</h1>
        <div class="meta">
            <a class="pill" href="?year=<?=h($year - 1)?>">← <?=h($year - 1)?></a>
            <a class="pill" href="?year=<?=h((int)date('Y'))?>">This year</a>
            <a class="pill" href="?year=<?=h($year + 1)?>"><?=h($year + 1)?> →</a>
        </div>
    </header>

    <div class="wrap">
        <div class="scroll" id="calendarScroll">
            <div class="year">
                <?php for ($m = 1; $m <= 12; $m++):
                    $monthCount = 0;
                    for ($w = 1; $w <= 4; $w++) {
                        $monthCount += count($items[$m][$w]);
                    }

                    // Month classes: past / current (only meaningful for current year view)
                    $monthClasses = ['month'];
                    if ($isThisYear && $currentMonth !== null && $m < $currentMonth) {
                        $monthClasses[] = 'month--past';
                    }
                    if ($isThisYear && $currentMonth !== null && $m === $currentMonth) {
                        $monthClasses[] = 'month--current';
                    }
                    $monthClassAttr = implode(' ', $monthClasses);
                ?>
                <?php
                  $monthIdAttr = ($isThisYear && $currentMonth !== null && $m === $currentMonth) ? ' id="month-current"' : '';
                ?>
                <section class="<?=h($monthClassAttr)?>" <?=$monthIdAttr?>>
                    <div class="monthTitle">
                        <div class="name"><?=h($monthNames[$m])?></div>
                        <div class="count"><?=h($monthCount)?> item<?= $monthCount === 1 ? '' : 's' ?></div>
                    </div>

                    <div class="weeks">
                        <?php for ($w = 1; $w <= 4; $w++):
                            $rangeLabel = match ($w) {
                                1 => "1–7",
                                2 => "8–14",
                                3 => "15–21",
                                4 => "22–end",
                            };
                            $bucket = $items[$m][$w];

                            $isCurrentWeek = ($isThisYear && $currentMonth !== null && $currentWeek !== null && $m === $currentMonth && $w === $currentWeek);
                        ?>
                        <div class="week<?= $isCurrentWeek ? ' week--current' : '' ?>">
                            <div class="weekHeader<?= $isCurrentWeek ? ' weekHeader--current' : '' ?>">
                                <span>Week <?=h($w)?></span>
                                <span><?=h($rangeLabel)?></span>
                            </div>
                            <div class="lane">
                                <?php if (count($bucket) === 0): ?>
                                <div class="empty">No deadlines</div>
                                <?php else: ?>
                                <?php foreach ($bucket as $it): ?>
                                <div class="item" title="<?=h($it['file'])?>">
                                    <p class="t"><?=h($it['title'])?></p>
                                    <div class="sub">
                                        <span>📅 <?=h($it['deadline'])?></span>
                                        <span>🗂 <?=h($it['file'])?></span>
                                    </div>
                                    <button class="reschedule-btn" data-file="<?=h($it['file'])?>">↻ Reschedule</button>
                                </div>
                                <?php endforeach; ?>
                                <?php endif; ?>
                                <button class="add-item-btn" data-month="<?=h($m)?>" data-week="<?=h($w)?>">+ Add</button>
                            </div>
                        </div>
                        <?php endfor; ?>
                    </div>
                </section>
                <?php endfor; ?>
            </div>
        </div>

        <?php if (!empty($errors)): ?>
        <div class="errors">
            <strong>Scan warnings:</strong>
            <ul>
                <?php foreach ($errors as $e): ?>
                <li><?=h($e)?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <div class="hint">
            Put your JSON files in <code><?=h(basename($DATA_DIR))?></code> next to this script (or change <code>$DATA_DIR</code>).<br />
            Each file should contain a <code>"deadline"</code> field like <code>"<?=h($year)?>-03-14"</code> (ISO8601 also works).
        </div>
    </div>

    <!-- Reschedule Modal -->
    <div class="reschedule-modal" id="rescheduleModal">
        <div class="reschedule-box">
            <h3>Reschedule Item</h3>
            <div class="reschedule-form-group">
                <label for="reschedule-month">Month</label>
                <select id="reschedule-month">
                    <option value="1">January</option>
                    <option value="2">February</option>
                    <option value="3">March</option>
                    <option value="4">April</option>
                    <option value="5">May</option>
                    <option value="6">June</option>
                    <option value="7">July</option>
                    <option value="8">August</option>
                    <option value="9">September</option>
                    <option value="10">October</option>
                    <option value="11">November</option>
                    <option value="12">December</option>
                </select>
            </div>
            <div class="reschedule-form-group">
                <label for="reschedule-week">Week</label>
                <select id="reschedule-week">
                    <option value="1">Week 1 (1–7)</option>
                    <option value="2">Week 2 (8–14)</option>
                    <option value="3">Week 3 (15–21)</option>
                    <option value="4">Week 4 (22–end)</option>
                </select>
            </div>
            <div class="reschedule-actions">
                <button class="reschedule-cancel" id="reschedule-cancel">Cancel</button>
                <button class="reschedule-confirm" id="reschedule-confirm">Confirm</button>
            </div>
        </div>
    </div>

    <!-- Message Toast -->
    <div class="reschedule-message" id="rescheduleMessage"></div>

    <!-- Add Item Modal -->
    <div class="add-item-modal" id="addItemModal">
        <div class="add-item-box">
            <h3>Add New Item</h3>
            <div class="add-item-form-group">
                <label for="add-item-month">Month</label>
                <select id="add-item-month">
                    <option value="1">January</option>
                    <option value="2">February</option>
                    <option value="3">March</option>
                    <option value="4">April</option>
                    <option value="5">May</option>
                    <option value="6">June</option>
                    <option value="7">July</option>
                    <option value="8">August</option>
                    <option value="9">September</option>
                    <option value="10">October</option>
                    <option value="11">November</option>
                    <option value="12">December</option>
                </select>
            </div>
            <div class="add-item-form-group">
                <label for="add-item-week">Week</label>
                <select id="add-item-week">
                    <option value="1">Week 1 (1–7)</option>
                    <option value="2">Week 2 (8–14)</option>
                    <option value="3">Week 3 (15–21)</option>
                    <option value="4">Week 4 (22–end)</option>
                </select>
            </div>
            <div class="add-item-form-group">
                <label for="add-item-program">Program</label>
                <select id="add-item-program">
                    <?php foreach ($allPrograms as $prog): ?>
                    <option value="<?=h($prog)?>"><?=h($prog)?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="add-item-form-group">
                <label for="add-item-name">Name</label>
                <input type="text" id="add-item-name" placeholder="Enter item name" />
            </div>
            <div class="add-item-actions">
                <button class="add-item-cancel" id="add-item-cancel">Cancel</button>
                <button class="add-item-confirm" id="add-item-confirm">Add Item</button>
            </div>
        </div>
    </div>

    <!-- Reschedule JavaScript -->
    <script>
    (function() {
        const modal = document.getElementById('rescheduleModal');
        const monthSelect = document.getElementById('reschedule-month');
        const weekSelect = document.getElementById('reschedule-week');
        const confirmBtn = document.getElementById('reschedule-confirm');
        const cancelBtn = document.getElementById('reschedule-cancel');
        const messageEl = document.getElementById('rescheduleMessage');
        
        let currentFile = null;
        const currentYear = <?=json_encode($year)?>;

        // Show message toast
        function showMessage(text, type = 'success') {
            messageEl.textContent = text;
            messageEl.className = 'reschedule-message show ' + type;
            setTimeout(() => {
                messageEl.className = 'reschedule-message';
            }, 4000);
        }

        // Calculate Monday of week
        function getMondayOfWeek(year, month, week) {
            let startDay;
            switch(week) {
                case 1: startDay = 1; break;
                case 2: startDay = 8; break;
                case 3: startDay = 15; break;
                case 4: startDay = 22; break;
            }
            
            // Create date for the start day of the week bucket
            const date = new Date(year, month - 1, startDay);
            
            // Get the day of week (0 = Sunday, 1 = Monday, etc.)
            const dayOfWeek = date.getDay();
            
            // Find the Monday on or after this date
            if (dayOfWeek === 0) {
                // Sunday -> add 1 day to get Monday
                date.setDate(date.getDate() + 1);
            } else if (dayOfWeek !== 1) {
                // Tuesday-Saturday -> calculate days until next Monday
                const daysUntilMonday = 8 - dayOfWeek;
                date.setDate(date.getDate() + daysUntilMonday);
            }
            // If dayOfWeek === 1 (Monday), no change needed
            
            return date;
        }

        // Calculate deadline (Sunday after the week's Monday)
        function getDeadline(mondayDate) {
            const deadline = new Date(mondayDate);
            deadline.setDate(deadline.getDate() + 6);
            return deadline;
        }

        // Format date as YYYY-MM-DD
        function formatDate(date) {
            const year = date.getFullYear();
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const day = String(date.getDate()).padStart(2, '0');
            return `${year}-${month}-${day}`;
        }

        // Open modal
        function openModal(file) {
            currentFile = file;
            modal.classList.add('active');
        }

        // Close modal
        function closeModal() {
            modal.classList.remove('active');
            currentFile = null;
        }

        // Handle reschedule
        async function handleReschedule() {
            if (!currentFile) return;

            const month = parseInt(monthSelect.value);
            const week = parseInt(weekSelect.value);

            // Calculate dates
            const monday = getMondayOfWeek(currentYear, month, week);
            const deadline = getDeadline(monday);
            const deadlineStr = formatDate(deadline);

            try {
                const response = await fetch(window.location.pathname, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `action=reschedule&file=${encodeURIComponent(currentFile)}&deadline=${encodeURIComponent(deadlineStr)}`
                });

                const result = await response.json();

                if (result.success) {
                    showMessage('Item rescheduled successfully! Reloading...', 'success');
                    setTimeout(() => {
                        window.location.reload();
                    }, 1500);
                } else {
                    showMessage('Error: ' + (result.error || 'Unknown error'), 'error');
                }
            } catch (error) {
                showMessage('Error: ' + error.message, 'error');
            }

            closeModal();
        }

        // Event listeners
        document.addEventListener('click', (e) => {
            if (e.target.classList.contains('reschedule-btn')) {
                const file = e.target.dataset.file;
                openModal(file);
            }

            // Close on backdrop click
            if (e.target === modal) {
                closeModal();
            }
        });

        cancelBtn.addEventListener('click', closeModal);
        confirmBtn.addEventListener('click', handleReschedule);

        // Close on Escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && modal.classList.contains('active')) {
                closeModal();
            }
        });
    })();

    // ==================== ADD ITEM MODAL ====================
    (function() {
        const modal = document.getElementById('addItemModal');
        const monthSelect = document.getElementById('add-item-month');
        const weekSelect = document.getElementById('add-item-week');
        const programSelect = document.getElementById('add-item-program');
        const nameInput = document.getElementById('add-item-name');
        const confirmBtn = document.getElementById('add-item-confirm');
        const cancelBtn = document.getElementById('add-item-cancel');
        const messageEl = document.getElementById('rescheduleMessage');
        
        const currentYear = <?=json_encode($year)?>;

        // Show message toast
        function showMessage(text, type = 'success') {
            messageEl.textContent = text;
            messageEl.className = 'reschedule-message show ' + type;
            setTimeout(() => {
                messageEl.className = 'reschedule-message';
            }, 4000);
        }

        // Calculate Monday of week
        function getMondayOfWeek(year, month, week) {
            let startDay;
            switch(week) {
                case 1: startDay = 1; break;
                case 2: startDay = 8; break;
                case 3: startDay = 15; break;
                case 4: startDay = 22; break;
            }
            
            // Create date for the start day of the week bucket
            const date = new Date(year, month - 1, startDay);
            
            // Get the day of week (0 = Sunday, 1 = Monday, etc.)
            const dayOfWeek = date.getDay();
            
            // Find the Monday on or after this date
            if (dayOfWeek === 0) {
                // Sunday -> add 1 day to get Monday
                date.setDate(date.getDate() + 1);
            } else if (dayOfWeek !== 1) {
                // Tuesday-Saturday -> calculate days until next Monday
                const daysUntilMonday = 8 - dayOfWeek;
                date.setDate(date.getDate() + daysUntilMonday);
            }
            // If dayOfWeek === 1 (Monday), no change needed
            
            return date;
        }

        // Calculate deadline (Sunday after the week's Monday)
        function getDeadline(mondayDate) {
            const deadline = new Date(mondayDate);
            deadline.setDate(deadline.getDate() + 6);
            return deadline;
        }

        // Format date as YYYY-MM-DD
        function formatDate(date) {
            const year = date.getFullYear();
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const day = String(date.getDate()).padStart(2, '0');
            return `${year}-${month}-${day}`;
        }

        // Open modal
        function openModal(month, week) {
            monthSelect.value = month;
            weekSelect.value = week;
            nameInput.value = '';
            // Set first program as default if available
            if (programSelect.options.length > 0) {
                programSelect.selectedIndex = 0;
            }
            modal.classList.add('active');
            nameInput.focus();
        }

        // Close modal
        function closeModal() {
            modal.classList.remove('active');
        }

        // Handle add item
        async function handleAddItem() {
            const month = parseInt(monthSelect.value);
            const week = parseInt(weekSelect.value);
            const program = programSelect.value;
            const name = nameInput.value.trim();

            if (!name) {
                showMessage('Please enter an item name', 'error');
                return;
            }

            if (!program) {
                showMessage('Please select a program', 'error');
                return;
            }

            // Calculate deadline
            const monday = getMondayOfWeek(currentYear, month, week);
            const deadline = getDeadline(monday);
            const deadlineStr = formatDate(deadline);

            try {
                const response = await fetch(window.location.pathname, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `action=additem&program=${encodeURIComponent(program)}&deadline=${encodeURIComponent(deadlineStr)}&name=${encodeURIComponent(name)}`
                });

                const result = await response.json();

                if (result.success) {
                    showMessage('Item added successfully! Reloading...', 'success');
                    setTimeout(() => {
                        window.location.reload();
                    }, 1500);
                } else {
                    showMessage('Error: ' + (result.error || 'Unknown error'), 'error');
                }
            } catch (error) {
                showMessage('Error: ' + error.message, 'error');
            }

            closeModal();
        }

        // Event listeners
        document.addEventListener('click', (e) => {
            if (e.target.classList.contains('add-item-btn')) {
                const month = e.target.dataset.month;
                const week = e.target.dataset.week;
                openModal(month, week);
            }

            // Close on backdrop click
            if (e.target === modal) {
                closeModal();
            }
        });

        cancelBtn.addEventListener('click', closeModal);
        confirmBtn.addEventListener('click', handleAddItem);

        // Handle Enter key in name input
        nameInput.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                handleAddItem();
            }
        });

        // Close on Escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && modal.classList.contains('active')) {
                closeModal();
            }
        });
    })();
    </script>

</body>

</html>