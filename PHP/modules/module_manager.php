<?php
require_once __DIR__ . '/../rbac_middleware.php';
RBACMiddleware::checkPageAccess();

require_once __DIR__ . '/../core/bootstrap.php';

$conn = Database::getConnection();
$APP_BASE_URL = App::baseUrl();

$role = (string)($_SESSION['role'] ?? 'staff');
if (!RBACMiddleware::isAdminRole($role)) {
    header('Location: ' . $APP_BASE_URL . '/PHP/Dashboard.php');
    exit();
}

$errors = [];
$success = '';

$roleOptions = [
    'front_desk_staff' => 'Front Desk Staff',
    'housekeeping_manager' => 'Housekeeping Divisions Manager',
    'financial_manager' => 'Financial Manager',
    'inventory_pos_manager' => 'Inventory & POS Manager',
    'events_conference_manager' => 'Events & Conference Manager',
    'staff' => 'Staff (All Modules)',
    'admin' => 'Admin (All Modules)',
    'superadmin' => 'Superadmin (All Modules)',
];

$selectedRole = (string)Request::get('role', 'front_desk_staff');
if (!isset($roleOptions[$selectedRole])) {
    $selectedRole = 'front_desk_staff';
}

if ($conn) {
    try {
        $conn->query(
            "CREATE TABLE IF NOT EXISTS user_module_orders (\n"
            . "  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,\n"
            . "  role VARCHAR(30) NOT NULL,\n"
            . "  module_key VARCHAR(50) NOT NULL,\n"
            . "  position INT UNSIGNED NOT NULL DEFAULT 0,\n"
            . "  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n"
            . "  UNIQUE KEY uq_role_module (role, module_key),\n"
            . "  KEY idx_role_position (role, position)\n"
            . ") ENGINE=InnoDB"
        );

        $conn->query(
            "CREATE TABLE IF NOT EXISTS user_module_permissions (\n"
            . "  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,\n"
            . "  role VARCHAR(30) NOT NULL,\n"
            . "  module_key VARCHAR(50) NOT NULL,\n"
            . "  allowed TINYINT(1) NOT NULL DEFAULT 1,\n"
            . "  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n"
            . "  UNIQUE KEY uq_role_module (role, module_key),\n"
            . "  KEY idx_role_allowed (role, allowed)\n"
            . ") ENGINE=InnoDB"
        );
    } catch (Throwable $e) {
    }
}

$registry = RBACMiddleware::moduleRegistry($APP_BASE_URL);
$coreModuleKeys = array_values(array_filter(array_keys($registry), static function (string $k): bool {
    return !in_array($k, ['module_manager', 'employees'], true);
}));

if (Request::isPost()) {
    $selectedRole = (string)Request::post('role', $selectedRole);
    if (!isset($roleOptions[$selectedRole])) {
        $errors['role'] = 'Role is invalid.';
    }

    $allowedPosted = Request::post('allowed_modules', []);
    if (!is_array($allowedPosted)) {
        $allowedPosted = [];
    }
    $allowedPosted = array_values(array_filter(array_map('trim', $allowedPosted), static function ($v): bool {
        return is_string($v) && $v !== '';
    }));

    $allowedPostedFiltered = [];
    foreach ($allowedPosted as $k) {
        if (in_array($k, $coreModuleKeys, true) && !in_array($k, $allowedPostedFiltered, true)) {
            $allowedPostedFiltered[] = $k;
        }
    }

    if (empty($allowedPostedFiltered) && !RBACMiddleware::isAdminRole($selectedRole)) {
        $errors['allowed_modules'] = 'Please select at least one module.';
    }

    $orderRaw = (string)Request::post('module_order', '');
    $keys = array_values(array_filter(array_map('trim', explode(',', $orderRaw)), static function (string $v): bool {
        return $v !== '';
    }));

    $allowedKeys = RBACMiddleware::isAdminRole($selectedRole)
        ? RBACMiddleware::allowedModules($conn, $selectedRole)
        : $allowedPostedFiltered;

    $filtered = [];
    foreach ($keys as $k) {
        if (in_array($k, $allowedKeys, true) && !in_array($k, $filtered, true)) {
            $filtered[] = $k;
        }
    }

    if (empty($filtered)) {
        $errors['module_order'] = 'Please provide a module order.';
    }

    if (empty($errors) && $conn) {
        try {
            if (!RBACMiddleware::isAdminRole($selectedRole)) {
                $pDel = $conn->prepare('DELETE FROM user_module_permissions WHERE role = ?');
                if ($pDel instanceof mysqli_stmt) {
                    $pDel->bind_param('s', $selectedRole);
                    $pDel->execute();
                    $pDel->close();
                }

                $pIns = $conn->prepare('INSERT INTO user_module_permissions (role, module_key, allowed) VALUES (?, ?, 1)');
                if ($pIns instanceof mysqli_stmt) {
                    foreach ($allowedPostedFiltered as $k) {
                        $pIns->bind_param('ss', $selectedRole, $k);
                        $pIns->execute();
                    }
                    $pIns->close();
                }
            }

            $del = $conn->prepare('DELETE FROM user_module_orders WHERE role = ?');
            if ($del instanceof mysqli_stmt) {
                $del->bind_param('s', $selectedRole);
                $del->execute();
                $del->close();
            }

            $ins = $conn->prepare('INSERT INTO user_module_orders (role, module_key, position) VALUES (?, ?, ?)');
            if (!($ins instanceof mysqli_stmt)) {
                $errors['general'] = 'Failed to save module order.';
            } else {
                $pos = 1;
                foreach ($filtered as $k) {
                    $ins->bind_param('ssi', $selectedRole, $k, $pos);
                    $ins->execute();
                    $pos++;
                }
                $ins->close();
                $success = 'Module order saved.';
            }
        } catch (Throwable $e) {
            $errors['general'] = 'Failed to save module order.';
        }
    } elseif (empty($errors)) {
        $errors['general'] = 'Database unavailable.';
    }
}

$currentAllowed = RBACMiddleware::allowedModules($conn, $selectedRole);
$orderedModules = RBACMiddleware::getOrderedModules($conn, $selectedRole);

$pageTitle = 'Module Manager';
$pendingApprovals = [];
include __DIR__ . '/../partials/page_start.php';
include __DIR__ . '/../partials/sidebar.php';
?>
<section id="content">
    <?php include __DIR__ . '/../partials/header.php'; ?>
    <main class="w-full px-6 py-6">
        <div class="mb-6 flex items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-light text-gray-900">Module Manager</h1>
                <p class="text-sm text-gray-500 mt-1">Reorder modules per staff division. Only Admin/Superadmin can access.</p>
            </div>
            <a href="<?= htmlspecialchars($APP_BASE_URL) ?>/PHP/Dashboard.php" class="px-4 py-2 rounded-lg border border-gray-200 text-gray-700 text-sm hover:bg-gray-50 transition">Back</a>
        </div>

        <?php if (!empty($success)): ?>
            <div class="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                <?= htmlspecialchars($success) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($errors['general'])): ?>
            <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                <?= htmlspecialchars($errors['general']) ?>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div class="bg-white rounded-lg border border-gray-100 p-6 lg:col-span-1">
                <form method="post" id="moduleManagerForm" class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Staff Division</label>
                        <select name="role" id="roleSelect" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
                            <?php foreach ($roleOptions as $k => $label): ?>
                                <option value="<?= htmlspecialchars($k) ?>" <?= $selectedRole === $k ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (!empty($errors['role'])): ?>
                            <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['role']) ?></div>
                        <?php endif; ?>
                    </div>

                    <?php if (!RBACMiddleware::isAdminRole($selectedRole)): ?>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Allowed Modules</label>
                            <div class="grid grid-cols-1 gap-2">
                                <?php foreach ($coreModuleKeys as $mk): ?>
                                    <?php $ml = (string)($registry[$mk]['label'] ?? $mk); ?>
                                    <label class="flex items-center gap-2 text-sm text-gray-700">
                                        <input type="checkbox" name="allowed_modules[]" value="<?= htmlspecialchars($mk) ?>" <?= in_array($mk, $currentAllowed, true) ? 'checked' : '' ?> />
                                        <span><?= htmlspecialchars($ml) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <?php if (!empty($errors['allowed_modules'])): ?>
                                <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['allowed_modules']) ?></div>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-xs text-gray-500">Admin/Superadmin always have access to all modules.</div>
                    <?php endif; ?>

                    <input type="hidden" name="module_order" id="moduleOrderInput" value="" />
                    <?php if (!empty($errors['module_order'])): ?>
                        <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['module_order']) ?></div>
                    <?php endif; ?>

                    <button type="submit" class="w-full px-4 py-2 rounded-lg bg-blue-600 text-white text-sm hover:bg-blue-700 transition">Save Order</button>
                </form>

                <div class="mt-4 text-xs text-gray-500">
                    Tip: Drag the modules in the right panel, then click Save.
                </div>
            </div>

            <div class="bg-white rounded-lg border border-gray-100 p-6 lg:col-span-2">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-lg font-medium text-gray-900">Modules for: <?= htmlspecialchars($roleOptions[$selectedRole] ?? $selectedRole) ?></h2>
                    <div class="text-xs text-gray-500">Drag & drop to reorder</div>
                </div>

                <ul id="moduleList" class="space-y-2">
                    <?php foreach ($orderedModules as $m): ?>
                        <li class="flex items-center justify-between border border-gray-200 rounded-lg px-4 py-3 bg-white" draggable="true" data-key="<?= htmlspecialchars((string)($m['key'] ?? '')) ?>">
                            <div class="flex items-center gap-3">
                                <span class="text-gray-400" style="cursor:grab;">≡</span>
                                <div>
                                    <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars((string)($m['label'] ?? 'Module')) ?></div>
                                    <div class="text-xs text-gray-500"><?= htmlspecialchars((string)($m['url'] ?? '')) ?></div>
                                </div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </main>
</section>

<script>
(function() {
  var roleSelect = document.getElementById('roleSelect');
  if (roleSelect) {
    roleSelect.addEventListener('change', function() {
      var role = roleSelect.value;
      var url = new URL(window.location.href);
      url.searchParams.set('role', role);
      window.location.href = url.toString();
    });
  }

  var list = document.getElementById('moduleList');
  var hidden = document.getElementById('moduleOrderInput');
  var form = document.getElementById('moduleManagerForm');
  if (!list || !hidden || !form) return;

  var dragged = null;

  function setHidden() {
    var keys = [];
    list.querySelectorAll('li[data-key]').forEach(function(li) {
      var k = li.getAttribute('data-key') || '';
      if (k) keys.push(k);
    });
    hidden.value = keys.join(',');
  }

  setHidden();

  list.addEventListener('dragstart', function(e) {
    var li = e.target && e.target.closest ? e.target.closest('li[data-key]') : null;
    if (!li) return;
    dragged = li;
    e.dataTransfer.effectAllowed = 'move';
    try { e.dataTransfer.setData('text/plain', li.getAttribute('data-key') || ''); } catch (err) {}
  });

  list.addEventListener('dragover', function(e) {
    if (!dragged) return;
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';

    var target = e.target && e.target.closest ? e.target.closest('li[data-key]') : null;
    if (!target || target === dragged) return;

    var rect = target.getBoundingClientRect();
    var before = (e.clientY - rect.top) < rect.height / 2;
    if (before) {
      list.insertBefore(dragged, target);
    } else {
      list.insertBefore(dragged, target.nextSibling);
    }
  });

  list.addEventListener('drop', function(e) {
    if (!dragged) return;
    e.preventDefault();
    dragged = null;
    setHidden();
  });

  list.addEventListener('dragend', function() {
    dragged = null;
    setHidden();
  });

  form.addEventListener('submit', function() {
    setHidden();
  });
})();
</script>

<?php
include __DIR__ . '/../partials/page_end.php';
