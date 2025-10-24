<?php
/* index.php — Minimal MongoDB text-only blog (one file) */

declare(strict_types=1);

// --- Basic env checks ---
if (!class_exists('MongoDB\\Driver\\Manager')) {
    http_response_code(500);
    echo "<h1>MongoDB PHP extension missing</h1>";
    echo "<p>Install it first, e.g. on Debian/Ubuntu:</p>";
    echo "<pre>sudo apt-get install php-mongodb && sudo service apache2 restart</pre>";
    exit;
}

// --- Config ---
$mongoUri   = 'mongodb://localhost:27017';
$dbName     = 'simple_blog';
$collName   = 'posts';
$fullNS     = $dbName . '.' . $collName';
$limit      = 50; // show latest 50

$manager = new MongoDB\Driver\Manager($mongoUri);

// --- Simple CSRF token (sessionless, per-request fallback) ---
if (!isset($_COOKIE['csrf'])) {
    $token = bin2hex(random_bytes(16));
    setcookie('csrf', $token, 0, '/', '', false, true);
    $_COOKIE['csrf'] = $token;
}
$csrf = $_COOKIE['csrf'] ?? '';

// --- Handle POST (create) ---
$msg = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = $_POST['csrf'] ?? '';
    if (!hash_equals($csrf, $postedToken)) {
        $msg = ['type' => 'error', 'text' => 'Invalid CSRF token.'];
    } else {
        $text = trim((string)($_POST['text'] ?? ''));
        if ($text === '') {
            $msg = ['type' => 'error', 'text' => 'Please write something before posting.'];
        } elseif (mb_strlen($text) > 5000) {
            $msg = ['type' => 'error', 'text' => 'Post is too long (max 5000 chars).'];
        } else {
            try {
                $bulk = new MongoDB\Driver\BulkWrite();
                $doc  = [
                    '_id'        => new MongoDB\BSON\ObjectId(),
                    'text'       => $text,
                    'created_at' => new MongoDB\BSON\UTCDateTime(), // now
                    'ip'         => $_SERVER['REMOTE_ADDR'] ?? null,
                    'ua'         => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 512),
                ];
                $bulk->insert($doc);
                $manager->executeBulkWrite($fullNS, $bulk);
                // Redirect to avoid resubmission on refresh
                header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
                exit;
            } catch (Throwable $e) {
                $msg = ['type' => 'error', 'text' => 'Failed to save post: ' . htmlspecialchars($e->getMessage())];
            }
        }
    }
}

// --- Handle deletion (optional, simple) ---
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    if (preg_match('/^[a-f0-9]{24}$/i', $id)) {
        try {
            $bulk = new MongoDB\Driver\BulkWrite();
            $bulk->delete(['_id' => new MongoDB\BSON\ObjectId($id)], ['limit' => 1]);
            $manager->executeBulkWrite($fullNS, $bulk);
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
            exit;
        } catch (Throwable $e) {
            $msg = ['type' => 'error', 'text' => 'Delete failed: ' . htmlspecialchars($e->getMessage())];
        }
    }
}

// --- Load latest posts ---
$query  = new MongoDB\Driver\Query([], ['sort' => ['created_at' => -1], 'limit' => $limit]);
$cursor = $manager->executeQuery($fullNS, $query);
$posts  = iterator_to_array($cursor);

// --- Helper to format dates ---
function fmtDate(?MongoDB\BSON\UTCDateTime $dt): string {
    if (!$dt) return '';
    $unix = (int) floor($dt->toDateTime()->format('U'));
    return date('Y-m-d H:i', $unix);
}

// --- Output HTML ---
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Tiny PHP MongoDB Blog</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
:root { --bg:#0f172a; --card:#111827; --muted:#9ca3af; --text:#e5e7eb; --accent:#22d3ee; }
* { box-sizing:border-box; }
body { margin:0; font:16px/1.5 system-ui, -apple-system, Segoe UI, Roboto, Ubuntu, Cantarell, Noto Sans, sans-serif; background:linear-gradient(160deg,#0b1023,#0f172a 40%,#0b1023); color:var(--text); }
.container { max-width:760px; margin:40px auto; padding:0 16px; }
header { text-align:center; margin-bottom:20px; }
h1 { font-size:28px; margin:0 0 8px; letter-spacing:.5px; }
.sub { color:var(--muted); font-size:14px; }
.card { background:rgba(17,24,39,.8); border:1px solid rgba(255,255,255,.06); border-radius:16px; box-shadow:0 10px 30px rgba(0,0,0,.35); overflow:hidden; }
.form { padding:16px; display:grid; gap:12px; }
textarea { width:100%; min-height:120px; resize:vertical; padding:12px; color:var(--text); background:#0b1222; border:1px solid #1f2937; border-radius:12px; outline:none; }
textarea:focus { border-color:var(--accent); box-shadow:0 0 0 3px rgba(34,211,238,.2); }
.actions { display:flex; gap:8px; align-items:center; justify-content:space-between; }
button { cursor:pointer; border:0; padding:10px 14px; border-radius:12px; color:#031b1f; background:var(--accent); font-weight:700; }
button:hover { filter:brightness(1.05); }
.count { color:var(--muted); font-size:12px; }
.msg { margin:12px 0; padding:10px 12px; border-radius:12px; }
.msg.error { background:#3b0a0a; color:#fca5a5; border:1px solid #7f1d1d; }
.list { margin-top:22px; display:grid; gap:12px; }
.post { background:#0b1222; border:1px solid #1f2937; border-radius:14px; padding:14px; }
.meta { display:flex; gap:10px; align-items:center; color:var(--muted); font-size:12px; margin-bottom:8px; }
.text { white-space:pre-wrap; word-wrap:break-word; font-size:15px; }
a.del { margin-left:auto; color:#fca5a5; text-decoration:none; border:1px solid #7f1d1d; padding:4px 8px; border-radius:8px; }
a.del:hover { background:#7f1d1d; color:#fff; }
footer { margin:26px 0; text-align:center; color:var(--muted); font-size:12px; }
</style>
</head>
<body>
<div class="container">
  <header>
    <h1>📝 Tiny PHP MongoDB Blog</h1>
    <div class="sub">Text-only posts stored in MongoDB on <code>localhost</code></div>
  </header>

  <section class="card">
    <form class="form" method="post" action="">
      <?php if ($msg): ?>
        <div class="msg <?= htmlspecialchars($msg['type']) ?>"><?= htmlspecialchars($msg['text']) ?></div>
      <?php endif; ?>
      <textarea name="text" maxlength="5000" placeholder="Write something… (max 5000 characters)"></textarea>
      <div class="actions">
        <span class="count"><?= count($posts) ?> recent posts shown</span>
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
        <button type="submit">Post</button>
      </div>
    </form>
  </section>

  <section class="list">
    <?php if (!$posts): ?>
      <div class="post">
        <div class="text">No posts yet. Be the first!</div>
      </div>
    <?php else: ?>
      <?php foreach ($posts as $p): ?>
        <?php
          $id   = (string)($p->_id ?? '');
          $text = htmlspecialchars((string)($p->text ?? ''));
          $date = fmtDate(($p->created_at ?? null));
        ?>
        <article class="post">
          <div class="meta">
            <span><?= $date ?></span>
            <a class="del" href="?delete=<?= htmlspecialchars($id) ?>" onclick="return confirm('Delete this post?')">Delete</a>
          </div>
          <div class="text"><?= $text ?></div>
        </article>
      <?php endforeach; ?>
    <?php endif; ?>
  </section>

  <footer>Stored in <code><?= htmlspecialchars($dbName) ?></code> / <code><?= htmlspecialchars($collName) ?></code> · MongoDB URI: <code><?= htmlspecialchars($mongoUri) ?></code></footer>
</div>
</body>
</html>

