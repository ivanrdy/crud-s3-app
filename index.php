<?php
require __DIR__ . '/vendor/autoload.php';

use Aws\S3\S3Client;

function envv($k, $d=null){ $v=getenv($k); return ($v===false || $v==='') ? $d : $v; }
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

// ---- DB connection ----
$driver = strtolower(envv('DB_DRIVER','pgsql')); // 'mysql' or 'pgsql'
$host   = envv('DB_HOST','localhost');
$port   = envv('DB_PORT', $driver==='mysql' ? '3306' : '5432');
$name   = envv('DB_NAME','appdb');
$user   = envv('DB_USER','app');
$pass   = envv('DB_PASSWORD','secret');

$dsn = $driver==='mysql'
    ? "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4"
    : "pgsql:host={$host};port={$port};dbname={$name}";

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo "<pre>DB connection failed: ".h($e->getMessage())."</pre>"; exit;
}

// Auto-migrate a small items table (per driver)
function migrate(PDO $pdo, string $driver): void {
    if ($driver === 'mysql') {
        $sql = "CREATE TABLE IF NOT EXISTS items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            description TEXT,
            image_key VARCHAR(512),
            image_url VARCHAR(1024),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;";
    } else {
        $sql = "CREATE TABLE IF NOT EXISTS items (
            id SERIAL PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            description TEXT,
            image_key VARCHAR(512),
            image_url VARCHAR(1024),
            created_at TIMESTAMPTZ DEFAULT NOW()
        );";
    }
    $pdo->exec($sql);
}

migrate($pdo, $driver);

// ---- Health endpoint ----
if (($_GET['action'] ?? '') === 'health') { header('Content-Type: text/plain'); echo 'ok'; exit; }

// ---- S3 client ----
$bucket  = envv('S3_BUCKET');
$region  = envv('S3_REGION', 'us-east-1');
$endpoint= envv('S3_ENDPOINT');
$pathSty = filter_var(envv('S3_PATH_STYLE','true'), FILTER_VALIDATE_BOOLEAN);

$s3Cfg = [
    'version'     => 'latest',
    'region'      => $region,
    'credentials' => [
        'key'    => envv('S3_ACCESS_KEY_ID'),
        'secret' => envv('S3_SECRET_ACCESS_KEY'),
    ],
];
if ($endpoint) { $s3Cfg['endpoint'] = $endpoint; $s3Cfg['use_path_style_endpoint'] = $pathSty; }
$s3 = new S3Client($s3Cfg);

// ---- Actions ----
$action = $_GET['action'] ?? 'list';
$msg = '';

// Create or Update handler
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $desc  = trim($_POST['description'] ?? '');

    if ($title === '') {
        $msg = 'Title is required';
    } else {
        $imageKey = null; $imageUrl = null;
        if (!empty($_FILES['image']['name'])) {
            $f = $_FILES['image'];
            if ($f['error'] !== UPLOAD_ERR_OK) {
                $msg = 'Upload error';
            } else {
                $allowed = ['image/jpeg','image/png','image/gif','image/webp'];
                $mime = mime_content_type($f['tmp_name']);
                if (!in_array($mime, $allowed, true)) {
                    $msg = 'Only images (jpg/png/gif/webp) are allowed';
                } else {
                    $ext = pathinfo($f['name'], PATHINFO_EXTENSION);
                    $imageKey = 'uploads/'.date('Y/m/d/').bin2hex(random_bytes(6)).'.'.$ext;
                    // Upload
                    $put = $s3->putObject([
                        'Bucket' => $bucket,
                        'Key'    => $imageKey,
                        'SourceFile' => $f['tmp_name'],
                        'ContentType' => $mime,
                        // 'ACL' => 'public-read', // keep private by default
                    ]);
                    // Build URL (works for AWS and custom endpoints)
                    $imageUrl = $s3->getObjectUrl($bucket, $imageKey);
                }
            }
        }

        if ($msg === '') {
            if (($action) === 'create') {
                $stmt = $pdo->prepare("INSERT INTO items (title, description, image_key, image_url) VALUES (:t,:d,:k,:u)");
                $stmt->execute([':t'=>$title, ':d'=>$desc, ':k'=>$imageKey, ':u'=>$imageUrl]);
                header('Location: ?saved=1'); exit;
            } elseif (($action) === 'update') {
                $id = (int)($_POST['id'] ?? 0);
                if ($imageKey && $imageUrl) {
                    $stmt = $pdo->prepare("UPDATE items SET title=:t, description=:d, image_key=:k, image_url=:u WHERE id=:id");
                    $stmt->execute([':t'=>$title, ':d'=>$desc, ':k'=>$imageKey, ':u'=>$imageUrl, ':id'=>$id]);
                } else {
                    $stmt = $pdo->prepare("UPDATE items SET title=:t, description=:d WHERE id=:id");
                    $stmt->execute([':t'=>$title, ':d'=>$desc, ':id'=>$id]);
                }
                header('Location: ?updated=1'); exit;
            }
        }
    }
}

// Delete handler
if ($action === 'delete' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    // Fetch image key to delete from bucket (best-effort)
    $row = $pdo->prepare('SELECT image_key FROM items WHERE id=:id');
    $row->execute([':id'=>$id]);
    if ($r = $row->fetch()) {
        if (!empty($r['image_key'])) {
            try { $s3->deleteObject(['Bucket'=>$bucket, 'Key'=>$r['image_key']]); } catch (Throwable $e) { /* ignore */ }
        }
    }
    $pdo->prepare('DELETE FROM items WHERE id=:id')->execute([':id'=>$id]);
    header('Location: ?deleted=1'); exit;
}

// Fetch for edit or list
$editing = null;
if ($action === 'edit' && isset($_GET['id'])) {
    $stmt = $pdo->prepare('SELECT * FROM items WHERE id=:id');
    $stmt->execute([':id'=>(int)$_GET['id']]);
    $editing = $stmt->fetch();
}

$items = $pdo->query('SELECT * FROM items ORDER BY id DESC')->fetchAll();

// ---- UI ----
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>PHP CRUD + S3</title>
<style>
 body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,"Helvetica Neue",Arial; margin:24px; color:#222}
 .container{max-width:960px;margin:0 auto}
 header{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px}
 table{border-collapse:collapse;width:100%}
 th,td{border:1px solid #ddd;padding:8px;vertical-align:top}
 th{background:#f6f6f6;text-align:left}
 .card{border:1px solid #ddd;border-radius:12px;padding:16px;margin-bottom:24px}
 .row{display:grid;grid-template-columns:1fr 2fr;gap:12px}
 input[type=text],textarea{width:100%;padding:8px;border:1px solid #ccc;border-radius:8px}
 input[type=file]{margin-top:8px}
 .btn{display:inline-block;padding:8px 14px;border-radius:8px;border:1px solid #999;background:#fff;cursor:pointer}
 .btn.primary{background:#0b6efd;color:#fff;border-color:#0b6efd}
 .tag{display:inline-block;background:#e8f2ff;color:#0b6efd;padding:2px 8px;border-radius:999px;margin-left:8px}
 img{max-width:160px;height:auto;border-radius:8px}
 .muted{color:#666;font-size:12px}
 .alert{padding:10px 12px;border-radius:8px;margin:12px 0;background:#fff3cd;border:1px solid #ffe69c}
</style>
</head>
<body>
<div class="container">
  <header>
    <h1>PHP CRUD + S3 <span class="tag"><?php echo h(strtoupper($driver)); ?></span></h1>
    <a class="btn" href="?">Home</a>
  </header>

  <?php if ($msg): ?><div class="alert"><?php echo h($msg); ?></div><?php endif; ?>
  <?php if (isset($_GET['saved'])): ?><div class="alert">Saved.</div><?php endif; ?>
  <?php if (isset($_GET['updated'])): ?><div class="alert">Updated.</div><?php endif; ?>
  <?php if (isset($_GET['deleted'])): ?><div class="alert">Deleted.</div><?php endif; ?>

  <div class="card">
    <h2><?php echo $editing ? 'Edit Item #'.h($editing['id']) : 'Create New Item'; ?></h2>
    <form method="post" enctype="multipart/form-data" action="?action=<?php echo $editing ? 'update' : 'create'; ?>">
      <?php if ($editing): ?><input type="hidden" name="id" value="<?php echo (int)$editing['id']; ?>"><?php endif; ?>
      <div class="row">
        <label>Title</label>
        <input type="text" name="title" value="<?php echo h($editing['title'] ?? ''); ?>" required>
      </div>
      <div class="row" style="margin-top:8px;">
        <label>Description</label>
        <textarea name="description" rows="3"><?php echo h($editing['description'] ?? ''); ?></textarea>
      </div>
      <div class="row" style="margin-top:8px;">
        <label>Image (jpg/png/gif/webp)</label>
        <div>
          <input type="file" name="image" accept="image/*">
          <?php if ($editing && $editing['image_url']): ?>
            <div class="muted">Current: <a href="<?php echo h($editing['image_url']); ?>" target="_blank">view image</a></div>
          <?php endif; ?>
        </div>
      </div>
      <div style="margin-top:12px;">
        <button class="btn primary" type="submit"><?php echo $editing ? 'Update' : 'Create'; ?></button>
        <?php if ($editing): ?><a class="btn" href="?">Cancel</a><?php endif; ?>
      </div>
    </form>
  </div>

  <div class="card">
    <h2>Items</h2>
    <table>
      <thead><tr><th>ID</th><th>Title</th><th>Description</th><th>Image</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($items as $it): ?>
        <tr>
          <td><?php echo (int)$it['id']; ?></td>
          <td><?php echo h($it['title']); ?></td>
          <td><?php echo nl2br(h($it['description'] ?? '')); ?></td>
          <td><?php if (!empty($it['image_url'])): ?><img src="<?php echo h($it['image_url']); ?>" alt="img"><?php endif; ?></td>
          <td>
            <a class="btn" href="?action=edit&id=<?php echo (int)$it['id']; ?>">Edit</a>
            <a class="btn" href="?action=delete&id=<?php echo (int)$it['id']; ?>" onclick="return confirm('Delete this item?');">Delete</a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <p class="muted">Healthcheck: <code>/index.php?action=health</code></p>
</div>
</body>
</html>
