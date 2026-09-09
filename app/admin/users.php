<?php
session_start();
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/functions.php';

requireRole('admin');

$flash = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'create_user') {
            $name = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            $role = $_POST['role'] ?? 'user';
            $contact = trim($_POST['contact_no'] ?? '');

            if ($name === '' || $email === '' || $password === '') {
                $flash = 'Name, email, and password are required.';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role, contact_no, is_verified) VALUES (?, ?, ?, ?, ?, 1)");
                $stmt->execute([$name, $email, $hash, $role, $contact]);
                if (function_exists('logActivity')) {
                    logActivity($pdo, $_SESSION['user_id'], $_SESSION['email'], 'user_created', 'success');
                }
                $flash = "Account created for $name.";
            }
        }

        if ($action === 'update_role') {
            $userId = (int)($_POST['user_id'] ?? 0);
            $role = $_POST['role'] ?? 'user';
            $pdo->prepare("UPDATE users SET role = ? WHERE id = ?")->execute([$role, $userId]);
            $flash = 'Role updated.';
        }

        if ($action === 'toggle_active') {
            $userId = (int)($_POST['user_id'] ?? 0);
            $newState = (int)($_POST['new_state'] ?? 0);
            $pdo->prepare("UPDATE users SET is_verified = ? WHERE id = ?")->execute([$newState, $userId]);
            $flash = $newState ? 'Account reactivated.' : 'Account deactivated.';
        }

        // ---- Archive (replaces Delete) ----
        if ($action === 'archive_user') {
            $userId = (int)($_POST['user_id'] ?? 0);
            if ($userId === (int)$_SESSION['user_id']) {
                $flash = "You can't archive your own account while logged in.";
            } else {
                $pdo->prepare("UPDATE users SET archived_at = NOW() WHERE id = ?")->execute([$userId]);
                if (function_exists('logActivity')) {
                    logActivity($pdo, $_SESSION['user_id'], $_SESSION['email'], 'user_archived', 'success');
                }
                $flash = 'Account archived.';
            }
        }

        if ($action === 'restore_user') {
            $userId = (int)($_POST['user_id'] ?? 0);
            $pdo->prepare("UPDATE users SET archived_at = NULL WHERE id = ?")->execute([$userId]);
            if (function_exists('logActivity')) {
                logActivity($pdo, $_SESSION['user_id'], $_SESSION['email'], 'user_restored', 'success');
            }
            $flash = 'Account restored.';
        }
    } catch (PDOException $e) {
        $flash = 'Action failed: ' . $e->getMessage();
    }
}

$roleFilter = $_GET['role'] ?? '';
$search = $_GET['q'] ?? '';
$view = $_GET['view'] === 'archived' ? 'archived' : 'active';

$where = $view === 'archived' ? "WHERE archived_at IS NOT NULL" : "WHERE archived_at IS NULL";
$params = [];
if ($roleFilter !== '') { $where .= " AND role = ?"; $params[] = $roleFilter; }
if ($search !== '') { $where .= " AND (name LIKE ? OR email LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }

$stmt = $pdo->prepare("SELECT * FROM users $where ORDER BY created_at DESC");
$stmt->execute($params);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

$archivedCount = $pdo->query("SELECT COUNT(*) FROM users WHERE archived_at IS NOT NULL")->fetchColumn();

$unreadAlerts = 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>User Management — HopeLine</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/app.css">
</head>
<body>

<?php require_once __DIR__ . '/../../assets/layouts/admin/admin_sidebar.php'; ?>

<main class="main">
    <div class="page-head">
        <div><h1>User Management</h1><p><?php echo count($users); ?> account(s)</p></div>
        <button class="btn-primary" onclick="document.getElementById('createModal').classList.add('show')">+ Create Account</button>
    </div>

    <?php if ($flash): ?><div class="flash"><?php echo htmlspecialchars($flash); ?></div><?php endif; ?>

    <div class="tabs">
        <a href="?view=active<?php echo $roleFilter ? '&role='.urlencode($roleFilter) : ''; ?><?php echo $search ? '&q='.urlencode($search) : ''; ?>" class="tab <?php echo $view === 'active' ? 'active' : ''; ?>" style="text-decoration:none;">Active</a>
        <a href="?view=archived<?php echo $roleFilter ? '&role='.urlencode($roleFilter) : ''; ?><?php echo $search ? '&q='.urlencode($search) : ''; ?>" class="tab <?php echo $view === 'archived' ? 'active' : ''; ?>" style="text-decoration:none;">Archived (<?php echo $archivedCount; ?>)</a>
    </div>

    <form method="GET" class="toolbar">
        <input type="hidden" name="view" value="<?php echo $view; ?>">
        <input type="text" name="q" placeholder="Search name or email..." value="<?php echo htmlspecialchars($search); ?>">
        <select name="role" onchange="this.form.submit()">
            <option value="">All Roles</option>
            <option value="admin" <?php echo $roleFilter === 'admin' ? 'selected' : ''; ?>>Admin</option>
            <option value="manager" <?php echo $roleFilter === 'manager' ? 'selected' : ''; ?>>Manager</option>
            <option value="user" <?php echo $roleFilter === 'user' ? 'selected' : ''; ?>>Responder</option>
        </select>
        <button type="submit" class="btn-primary" style="padding:8px 16px;">Filter</button>
    </form>

    <?php if (empty($users)): ?>
        <div class="empty-state"><?php echo $view === 'archived' ? 'No archived accounts.' : 'No accounts match these filters.'; ?></div>
    <?php else: ?>
    <table>
        <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Contact</th><th>Status</th><th><?php echo $view === 'archived' ? 'Archived' : 'Created'; ?></th><th>Actions</th></tr></thead>
        <tbody>
            <?php foreach ($users as $u): ?>
            <tr>
                <td><?php echo htmlspecialchars($u['name']); ?></td>
                <td><?php echo htmlspecialchars($u['email']); ?></td>
                <td>
                    <?php if ($view === 'active'): ?>
                    <form method="POST" style="display:inline-flex; gap:6px; align-items:center;">
                        <input type="hidden" name="action" value="update_role">
                        <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                        <select name="role" class="role-select" onchange="this.form.submit()">
                            <option value="admin" <?php echo $u['role']==='admin'?'selected':''; ?>>Admin</option>
                            <option value="manager" <?php echo $u['role']==='manager'?'selected':''; ?>>Manager</option>
                            <option value="user" <?php echo $u['role']==='user'?'selected':''; ?>>Responder</option>
                        </select>
                    </form>
                    <?php else: ?>
                        <span class="role-badge role-<?php echo $u['role']; ?>"><?php echo ucfirst($u['role']); ?></span>
                    <?php endif; ?>
                </td>
                <td><?php echo htmlspecialchars($u['contact_no'] ?: '—'); ?></td>
                <td><span class="status-badge <?php echo $u['is_verified'] ? 'status-active' : 'status-inactive'; ?>"><?php echo $u['is_verified'] ? 'Active' : 'Inactive'; ?></span></td>
                <td><?php echo date('M j, Y', strtotime($view === 'archived' ? $u['archived_at'] : $u['created_at'])); ?></td>
                <td class="row-actions">
                    <?php if ($view === 'active'): ?>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="action" value="toggle_active">
                            <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                            <input type="hidden" name="new_state" value="<?php echo $u['is_verified'] ? 0 : 1; ?>">
                            <button type="submit" class="btn-mini <?php echo $u['is_verified'] ? 'btn-deactivate' : 'btn-activate'; ?>">
                                <?php echo $u['is_verified'] ? 'Deactivate' : 'Activate'; ?>
                            </button>
                        </form>
                        <?php if ((int)$u['id'] !== (int)$_SESSION['user_id']): ?>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Archive this account? They will no longer be able to log in, but their history stays intact.');">
                            <input type="hidden" name="action" value="archive_user">
                            <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                            <button type="submit" class="btn-mini btn-deactivate">Archive</button>
                        </form>
                        <?php endif; ?>
                    <?php else: ?>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="action" value="restore_user">
                            <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                            <button type="submit" class="btn-mini btn-activate">Restore</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</main>

<div class="modal-overlay" id="createModal">
    <div class="modal">
        <h3>Create New Account</h3>
        <form method="POST">
            <input type="hidden" name="action" value="create_user">
            <div class="field"><label>Full Name</label><input type="text" name="name" required></div>
            <div class="field"><label>Email</label><input type="email" name="email" required></div>
            <div class="field"><label>Password</label><input type="password" name="password" required></div>
            <div class="field"><label>Contact Number</label><input type="text" name="contact_no"></div>
            <div class="field">
                <label>Role</label>
                <select name="role">
                    <option value="user">Barangay Responder</option>
                    <option value="manager">LDRRMO Manager</option>
                    <option value="admin">LDRRMO Admin</option>
                </select>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-cancel" onclick="document.getElementById('createModal').classList.remove('show')">Cancel</button>
                <button type="submit" class="btn-primary">Create Account</button>
            </div>
        </form>
    </div>
</div>

</body>
</html>