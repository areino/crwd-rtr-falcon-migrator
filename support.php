<?php
declare(strict_types=1);

// Single-file supporters page with JSON storage and simple admin review
// To set the admin password, either set environment variable SUPPORT_PAGE_ADMIN or edit $ADMIN_PASSWORD below.

error_reporting(E_ALL);
ini_set('display_errors', '0');

$DATA_FILE = __DIR__ . '/supporters.json';
$ADMIN_PASSWORD = getenv('SUPPORT_PAGE_ADMIN') ?: 'changeme';

// Edit the statement below to the text you want people to support.
$SUPPORT_STATEMENT = "We, the undersigned, support this statement.";

function readJsonData(string $filePath): array {
    $defaultData = [
        'approved' => [],
        'pending' => [],
    ];

    if (!is_file($filePath)) {
        return $defaultData;
    }

    $contents = @file_get_contents($filePath);
    if ($contents === false || trim($contents) === '') {
        return $defaultData;
    }

    $decoded = json_decode($contents, true);
    if (!is_array($decoded) || !isset($decoded['approved']) || !isset($decoded['pending'])) {
        return $defaultData;
    }

    // Normalize structure in case of partial corruption
    $decoded['approved'] = is_array($decoded['approved']) ? $decoded['approved'] : [];
    $decoded['pending'] = is_array($decoded['pending']) ? $decoded['pending'] : [];

    return $decoded;
}

function writeJsonData(string $filePath, array $data): bool {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return false;
    }

    $directory = dirname($filePath);
    if (!is_dir($directory)) {
        return false;
    }

    $fileHandle = @fopen($filePath, 'c+');
    if ($fileHandle === false) {
        return false;
    }

    $locked = @flock($fileHandle, LOCK_EX);
    if (!$locked) {
        fclose($fileHandle);
        return false;
    }

    $result = true;
    try {
        ftruncate($fileHandle, 0);
        rewind($fileHandle);
        if (@fwrite($fileHandle, $json) === false) {
            $result = false;
        }
        fflush($fileHandle);
    } finally {
        flock($fileHandle, LOCK_UN);
        fclose($fileHandle);
    }

    if ($result) {
        @chmod($filePath, 0644);
    }

    return $result;
}

function normalizeName(string $input): string {
    $name = trim($input);
    $name = preg_replace('/\s+/', ' ', $name);
    if ($name === null) {
        $name = trim($input);
    }
    return $name;
}

function isValidName(string $name): bool {
    if ($name === '') {
        return false;
    }
    if (mb_strlen($name) > 200) {
        return false;
    }
    return true;
}

function generateId(): string {
    try {
        return bin2hex(random_bytes(8));
    } catch (Throwable $e) {
        return uniqid('', true);
    }
}

function h(string $text): string {
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$messages = [];
$errors = [];

$isAdmin = isset($_GET['admin']) && (string)$_GET['admin'] === (string)$ADMIN_PASSWORD;

// Ensure data file exists with default structure if missing
if (!is_file($DATA_FILE)) {
    @file_put_contents($DATA_FILE, json_encode(['approved' => [], 'pending' => []], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    @chmod($DATA_FILE, 0644);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = readJsonData($DATA_FILE);
    $action = isset($_POST['action']) ? (string)$_POST['action'] : '';

    if ($action === 'submit_name') {
        $fullNameRaw = (string)($_POST['full_name'] ?? '');
        $fullName = normalizeName($fullNameRaw);

        if (!isValidName($fullName)) {
            $errors[] = 'Please enter a valid full name (max 200 characters).';
        } else {
            $existsApproved = false;
            foreach ($data['approved'] as $approvedEntry) {
                if (isset($approvedEntry['name']) && mb_strtolower($approvedEntry['name']) === mb_strtolower($fullName)) {
                    $existsApproved = true;
                    break;
                }
            }

            $existsPending = false;
            if (!$existsApproved) {
                foreach ($data['pending'] as $pendingEntry) {
                    if (isset($pendingEntry['name']) && mb_strtolower($pendingEntry['name']) === mb_strtolower($fullName)) {
                        $existsPending = true;
                        break;
                    }
                }
            }

            if ($existsApproved) {
                $messages[] = 'Your name is already listed as a supporter. Thank you!';
            } elseif ($existsPending) {
                $messages[] = 'Your name is already pending review.';
            } else {
                $data['pending'][] = [
                    'id' => generateId(),
                    'name' => $fullName,
                    'submittedAt' => date('c'),
                ];
                if (writeJsonData($DATA_FILE, $data)) {
                    $messages[] = 'Thanks! Your name was submitted for review.';
                } else {
                    $errors[] = 'Could not save your submission. Please try again later.';
                }
            }
        }
    } elseif ($isAdmin && ($action === 'approve' || $action === 'delete')) {
        $id = (string)($_POST['id'] ?? '');
        if ($id === '') {
            $errors[] = 'Invalid request (missing id).';
        } else {
            $foundIndex = null;
            foreach ($data['pending'] as $index => $entry) {
                if (($entry['id'] ?? null) === $id) {
                    $foundIndex = $index;
                    break;
                }
            }

            if ($foundIndex === null) {
                $errors[] = 'Submission not found or already processed.';
            } else {
                if ($action === 'approve') {
                    $entry = $data['pending'][$foundIndex];
                    unset($data['pending'][$foundIndex]);
                    $data['pending'] = array_values($data['pending']);
                    $data['approved'][] = [
                        'name' => $entry['name'],
                        'approvedAt' => date('c'),
                    ];
                    if (writeJsonData($DATA_FILE, $data)) {
                        $messages[] = 'Submission approved.';
                    } else {
                        $errors[] = 'Failed to save changes.';
                    }
                } else { // delete
                    unset($data['pending'][$foundIndex]);
                    $data['pending'] = array_values($data['pending']);
                    if (writeJsonData($DATA_FILE, $data)) {
                        $messages[] = 'Submission deleted.';
                    } else {
                        $errors[] = 'Failed to save changes.';
                    }
                }
            }
        }
    }
}

// Display
$dataForDisplay = readJsonData($DATA_FILE);
$approvedCount = is_array($dataForDisplay['approved']) ? count($dataForDisplay['approved']) : 0;
$pendingCount = is_array($dataForDisplay['pending']) ? count($dataForDisplay['pending']) : 0;

$basePath = $_SERVER['PHP_SELF'] ?? '';
$queryAdmin = $isAdmin ? ('?admin=' . rawurlencode((string)($_GET['admin'] ?? ''))) : '';
$actionUrl = $basePath . $queryAdmin;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Support Statement</title>
  <style>
    :root { --bg:#0b0c0f; --card:#12151a; --text:#e6e7eb; --muted:#9aa0aa; --accent:#4f8cff; --ok:#2ecc71; --warn:#e67e22; --err:#e74c3c; }
    * { box-sizing: border-box; }
    body { margin:0; font-family: system-ui, -apple-system, Segoe UI, Roboto, Ubuntu, Cantarell, Noto Sans, Helvetica, Arial, "Apple Color Emoji", "Segoe UI Emoji"; background: var(--bg); color: var(--text); }
    .container { max-width: 820px; margin: 32px auto; padding: 0 16px; }
    .card { background: var(--card); border: 1px solid #1e232b; border-radius: 12px; padding: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.25); }
    h1, h2, h3 { margin: 0 0 12px; line-height: 1.2; }
    h1 { font-size: 28px; }
    h2 { font-size: 20px; color: var(--muted); }
    p { color: var(--text); line-height: 1.6; }
    blockquote { margin: 12px 0 18px; padding: 12px 16px; background: #0e1116; border-left: 3px solid var(--accent); border-radius: 6px; }
    ul { margin: 10px 0 0; padding-left: 22px; }
    li { margin: 6px 0; }
    .muted { color: var(--muted); }
    form { margin-top: 14px; }
    input[type="text"] { width: 100%; padding: 12px 14px; background: #0e1116; border: 1px solid #1d2129; color: var(--text); border-radius: 8px; outline: none; }
    input[type="text"]::placeholder { color: #68707c; }
    button { margin-top: 10px; padding: 10px 14px; background: var(--accent); color: white; border: 0; border-radius: 8px; cursor: pointer; }
    button.secondary { background: #2d3340; }
    .row { display: grid; grid-template-columns: 1fr; gap: 16px; }
    .stack { display: grid; gap: 10px; }
    .messages { margin: 12px 0; display: grid; gap: 8px; }
    .msg { padding: 10px 12px; border-radius: 8px; }
    .ok { background: rgba(46, 204, 113, 0.15); border: 1px solid rgba(46, 204, 113, 0.35); }
    .err { background: rgba(231, 76, 60, 0.15); border: 1px solid rgba(231, 76, 60, 0.35); }
    .admin { margin-top: 28px; }
    table { width: 100%; border-collapse: collapse; margin-top: 8px; }
    th, td { border-bottom: 1px solid #1d2129; padding: 10px; text-align: left; }
    th { color: var(--muted); font-weight: 600; }
    .actions { display: flex; gap: 8px; }
    .badge { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 12px; background: #1d2330; color: var(--muted); border: 1px solid #2a3142; }
  </style>
</head>
<body>
  <div class="container">
    <div class="card">
      <h1>Support Statement</h1>
      <blockquote><?= nl2br(h($SUPPORT_STATEMENT)) ?></blockquote>

      <?php if (!empty($messages) || !empty($errors)): ?>
        <div class="messages">
          <?php foreach ($messages as $m): ?>
            <div class="msg ok"><?= h($m) ?></div>
          <?php endforeach; ?>
          <?php foreach ($errors as $e): ?>
            <div class="msg err"><?= h($e) ?></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div class="row">
        <div class="stack">
          <h2>Supporters <span class="badge"><?= (int)$approvedCount ?></span></h2>
          <?php if ($approvedCount === 0): ?>
            <p class="muted">No supporters yet. Be the first!</p>
          <?php else: ?>
            <ul>
              <?php foreach ($dataForDisplay['approved'] as $approvedEntry): ?>
                <li><?= h((string)($approvedEntry['name'] ?? '')) ?></li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>

        <div class="stack">
          <h2>Add your name</h2>
          <p class="muted">Your name will be added after admin review.</p>
          <form method="post" action="<?= h($actionUrl) ?>">
            <input type="hidden" name="action" value="submit_name" />
            <label for="full_name" class="muted">Full name</label>
            <input type="text" id="full_name" name="full_name" placeholder="Jane Doe" required />
            <button type="submit">Submit</button>
          </form>
        </div>
      </div>

      <?php if ($isAdmin): ?>
        <div class="admin">
          <h2>Admin review <span class="badge">Pending: <?= (int)$pendingCount ?></span></h2>
          <?php if ($pendingCount === 0): ?>
            <p class="muted">No pending submissions.</p>
          <?php else: ?>
            <table>
              <thead>
                <tr>
                  <th>Name</th>
                  <th>Submitted</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($dataForDisplay['pending'] as $pendingEntry): ?>
                  <tr>
                    <td><?= h((string)($pendingEntry['name'] ?? '')) ?></td>
                    <td class="muted"><?= h((string)($pendingEntry['submittedAt'] ?? '')) ?></td>
                    <td>
                      <div class="actions">
                        <form method="post" action="<?= h($actionUrl) ?>">
                          <input type="hidden" name="action" value="approve" />
                          <input type="hidden" name="id" value="<?= h((string)($pendingEntry['id'] ?? '')) ?>" />
                          <button type="submit">Approve</button>
                        </form>
                        <form method="post" action="<?= h($actionUrl) ?>" onsubmit="return confirm('Delete this submission?');">
                          <input type="hidden" name="action" value="delete" />
                          <input type="hidden" name="id" value="<?= h((string)($pendingEntry['id'] ?? '')) ?>" />
                          <button type="submit" class="secondary">Delete</button>
                        </form>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>
      <?php else: ?>
        <p class="muted" style="margin-top: 24px;">Admin? Append <code>?admin=YOUR_PASSWORD</code> to the URL to review submissions.</p>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>
