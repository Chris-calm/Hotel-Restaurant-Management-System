<?php
require_once __DIR__ . '/../rbac_middleware.php';
RBACMiddleware::checkPageAccess();

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../domain/Housekeeping/HousekeepingService.php';
require_once __DIR__ . '/../domain/Maintenance/MaintenanceService.php';

$conn = Database::getConnection();
$housekeepingService = new HousekeepingService(
    new HousekeepingRepository($conn),
    new RoomRepository($conn)
);

$maintenanceService = new MaintenanceService(
    new MaintenanceRepository($conn),
    new RoomRepository($conn)
);

$pendingApprovals = [];

$tab = (string)Request::get('tab', 'housekeeping');

$roomQ = (string)Request::get('room_q', '');
$rooms = $housekeepingService->listRooms($roomQ);
$tasks = $housekeepingService->listOpenTasks();

$ticketStatus = (string)Request::get('ticket_status', '');
$ticketPriority = (string)Request::get('ticket_priority', '');
$ticketQ = (string)Request::get('ticket_q', '');

$tickets = [];
$maintRooms = [];
$assets = [];
$categories = [];
$vendors = [];
$users = [];

if ($tab === 'maintenance') {
    $tickets = $maintenanceService->listTickets([
        'status' => $ticketStatus,
        'priority' => $ticketPriority,
        'q' => $ticketQ,
    ]);
    $maintRooms = $maintenanceService->listRooms('');
    $assets = $maintenanceService->listAssets('');
    $categories = $maintenanceService->listCategories();
    $vendors = $maintenanceService->listVendors();
    $users = $maintenanceService->listUsers();
}

$errors = [];

if (Request::isPost()) {
    $action = (string)Request::post('action', '');

    if ($action === 'create_task') {
        $payload = [
            'room_id' => Request::int('post', 'room_id', 0),
            'task_type' => (string)Request::post('task_type', 'Cleaning'),
            'priority' => (string)Request::post('priority', 'Normal'),
            'notes' => (string)Request::post('notes', ''),
        ];

        $id = $housekeepingService->createCleaningTask($payload, $errors);
        if ($id > 0) {
            Flash::set('success', 'Housekeeping task created.');
            Response::redirect('housekeeping_maintenance.php?tab=housekeeping');
        }
    }

    if ($action === 'set_task_status') {
        $taskId = Request::int('post', 'task_id', 0);
        $status = (string)Request::post('status', '');
        $ok = $housekeepingService->setTaskStatus($taskId, $status, $errors);
        if ($ok) {
            Flash::set('success', 'Task updated.');
            Response::redirect('housekeeping_maintenance.php?tab=housekeeping');
        }
    }

    if ($action === 'create_maintenance_ticket') {
        $payload = [
            'room_id' => Request::int('post', 'room_id', 0),
            'asset_id' => Request::int('post', 'asset_id', 0),
            'category_id' => Request::int('post', 'category_id', 0),
            'priority' => (string)Request::post('priority', 'Normal'),
            'title' => (string)Request::post('title', ''),
            'description' => (string)Request::post('description', ''),
            'assigned_to' => Request::int('post', 'assigned_to', 0),
            'vendor_id' => Request::int('post', 'vendor_id', 0),
            'requires_downtime' => (string)Request::post('requires_downtime', '') === '1',
        ];

        $id = $maintenanceService->createTicket($payload, $errors);
        if ($id > 0) {
            Flash::set('success', 'Maintenance ticket created.');
            Response::redirect('housekeeping_maintenance.php?tab=maintenance');
        }
    }

    if ($action === 'maintenance_set_status') {
        $ticketId = Request::int('post', 'ticket_id', 0);
        $status = (string)Request::post('status', '');
        $ok = $maintenanceService->updateTicketStatus($ticketId, $status, $errors);
        if ($ok) {
            Flash::set('success', 'Ticket updated.');
            Response::redirect('housekeeping_maintenance.php?tab=maintenance');
        }
    }

    if ($action === 'maintenance_update_assignment') {
        $ticketId = Request::int('post', 'ticket_id', 0);
        $assignedTo = Request::int('post', 'assigned_to', 0);
        $vendorId = Request::int('post', 'vendor_id', 0);

        $ok = $maintenanceService->updateAssignment(
            $ticketId,
            $assignedTo > 0 ? $assignedTo : null,
            $vendorId > 0 ? $vendorId : null,
            $errors
        );
        if ($ok) {
            Flash::set('success', 'Assignment updated.');
            Response::redirect('housekeeping_maintenance.php?tab=maintenance');
        }
    }

    if ($action === 'maintenance_add_log') {
        $ticketId = Request::int('post', 'ticket_id', 0);
        $msg = (string)Request::post('message', '');
        $id = $maintenanceService->addLog($ticketId, $msg, $errors);
        if ($id > 0) {
            Flash::set('success', 'Log added.');
            Response::redirect('housekeeping_maintenance.php?tab=maintenance');
        }
    }

    if ($action === 'maintenance_add_cost') {
        $payload = [
            'ticket_id' => Request::int('post', 'ticket_id', 0),
            'cost_type' => (string)Request::post('cost_type', 'Other'),
            'description' => (string)Request::post('description', ''),
            'qty' => (string)Request::post('qty', '1'),
            'unit_cost' => (string)Request::post('unit_cost', '0'),
            'reference_no' => (string)Request::post('reference_no', ''),
        ];
        $id = $maintenanceService->addCost($payload, $errors);
        if ($id > 0) {
            Flash::set('success', 'Cost added.');
            Response::redirect('housekeeping_maintenance.php?tab=maintenance');
        }
    }
}

include __DIR__ . '/../partials/page_start.php';
include __DIR__ . '/../partials/sidebar.php';
?>
<section id="content">
    <?php include __DIR__ . '/../partials/header.php'; ?>
    <main class="w-full px-6 py-6">
        <div class="mb-6">
            <h1 class="text-2xl font-light text-gray-900">Housekeeping & Maintenance</h1>
            <p class="text-sm text-gray-500 mt-1">Cleaning tasks, inspections, maintenance tickets</p>
        </div>

        <div class="mb-6 flex items-center gap-2">
            <a href="housekeeping_maintenance.php?tab=housekeeping" class="px-4 py-2 rounded-lg text-sm border <?= $tab === 'housekeeping' ? 'bg-blue-600 text-white border-blue-600' : 'border-gray-200 hover:bg-gray-50' ?>">Housekeeping</a>
            <a href="housekeeping_maintenance.php?tab=maintenance" class="px-4 py-2 rounded-lg text-sm border <?= $tab === 'maintenance' ? 'bg-blue-600 text-white border-blue-600' : 'border-gray-200 hover:bg-gray-50' ?>">Maintenance</a>
        </div>

        <?php $flash = Flash::get(); ?>
        <?php if ($flash): ?>
            <div class="mb-4 rounded-lg border px-4 py-3 text-sm <?= $flash['type'] === 'success' ? 'border-green-200 bg-green-50 text-green-800' : 'border-red-200 bg-red-50 text-red-800' ?>">
                <?= htmlspecialchars($flash['message']) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                <div class="font-medium mb-1">Action failed</div>
                <?php foreach ($errors as $msg): ?>
                    <div><?= htmlspecialchars($msg) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($tab === 'housekeeping'): ?>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div class="bg-white rounded-lg border border-gray-100 p-6">
                <div class="flex items-center justify-between gap-4 mb-4">
                    <h3 class="text-lg font-medium text-gray-900">Housekeeping Board</h3>
                    <div class="text-xs text-gray-500">Open & In Progress tasks</div>
                </div>

                <div class="space-y-3">
                    <?php if (empty($tasks)): ?>
                        <div class="text-sm text-gray-500">No open housekeeping tasks.</div>
                    <?php endif; ?>

                    <?php foreach ($tasks as $t): ?>
                        <div class="rounded-lg border border-gray-100 p-4">
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars('Room ' . ($t['room_no'] ?? '')) ?> • <?= htmlspecialchars($t['room_type_name'] ?? '') ?></div>
                                    <div class="text-xs text-gray-500 mt-1"><?= htmlspecialchars(($t['task_type'] ?? '') . ' • ' . ($t['priority'] ?? '') . ' • ' . ($t['status'] ?? '')) ?></div>
                                    <?php if (trim((string)($t['notes'] ?? '')) !== ''): ?>
                                        <div class="text-xs text-gray-600 mt-2"><?= nl2br(htmlspecialchars($t['notes'])) ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="flex items-center gap-2">
                                    <form method="post">
                                        <input type="hidden" name="action" value="set_task_status" />
                                        <input type="hidden" name="task_id" value="<?= (int)$t['id'] ?>" />
                                        <input type="hidden" name="status" value="In Progress" />
                                        <button class="px-3 py-1.5 rounded-lg border border-gray-200 text-xs hover:bg-gray-50 transition">Start</button>
                                    </form>
                                    <form method="post">
                                        <input type="hidden" name="action" value="set_task_status" />
                                        <input type="hidden" name="task_id" value="<?= (int)$t['id'] ?>" />
                                        <input type="hidden" name="status" value="Done" />
                                        <button class="px-3 py-1.5 rounded-lg bg-green-600 text-white text-xs hover:bg-green-700 transition">Done</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="bg-white rounded-lg border border-gray-100 p-6">
                <div class="flex items-center justify-between gap-4 mb-4">
                    <h3 class="text-lg font-medium text-gray-900">Create Housekeeping Task</h3>
                    <div class="text-xs text-gray-500">Sets room to Cleaning</div>
                </div>

                <form method="get" class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Room Search</label>
                    <div class="flex items-center gap-2">
                        <input name="room_q" value="<?= htmlspecialchars($roomQ) ?>" placeholder="Room no, type, status" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                        <button class="px-4 py-2 rounded-lg border border-gray-200 text-sm hover:bg-gray-50 transition">Search</button>
                    </div>
                </form>

                <form method="post" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <input type="hidden" name="action" value="create_task" />

                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Room</label>
                        <select name="room_id" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
                            <option value="0">Select Room</option>
                            <?php foreach ($rooms as $r): ?>
                                <option value="<?= (int)$r['id'] ?>">
                                    <?= htmlspecialchars('Room ' . $r['room_no'] . ' • ' . $r['room_type_name'] . ' • ' . $r['status']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Task Type</label>
                        <select name="task_type" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
                            <?php foreach (HousekeepingService::allowedTaskTypes() as $tt): ?>
                                <option value="<?= htmlspecialchars($tt) ?>"><?= htmlspecialchars($tt) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Priority</label>
                        <select name="priority" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
                            <?php foreach (HousekeepingService::allowedPriorities() as $p): ?>
                                <option value="<?= htmlspecialchars($p) ?>" <?= $p === 'Normal' ? 'selected' : '' ?>><?= htmlspecialchars($p) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                        <textarea name="notes" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" rows="3"></textarea>
                    </div>

                    <div class="md:col-span-2 flex items-center gap-2 pt-2">
                        <button class="px-4 py-2 rounded-lg bg-blue-600 text-white text-sm hover:bg-blue-700 transition">Create Task</button>
                        <a href="housekeeping_maintenance.php" class="px-4 py-2 rounded-lg border border-gray-200 text-sm hover:bg-gray-50 transition">Reset</a>
                    </div>
                </form>
            </div>
        </div>
        <?php else: ?>
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div class="bg-white rounded-lg border border-gray-100 p-6 lg:col-span-2">
                <div class="flex items-center justify-between gap-4 mb-4">
                    <h3 class="text-lg font-medium text-gray-900">Maintenance Tickets</h3>
                    <div class="text-xs text-gray-500">Open → Closed workflow</div>
                </div>

                <form method="get" class="mb-4 grid grid-cols-1 md:grid-cols-4 gap-3">
                    <input type="hidden" name="tab" value="maintenance" />
                    <input name="ticket_q" value="<?= htmlspecialchars($ticketQ) ?>" placeholder="Search ticket/room/asset" class="border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                    <select name="ticket_status" class="border border-gray-200 rounded-lg px-3 py-2 text-sm">
                        <option value="">All Status</option>
                        <?php foreach (MaintenanceService::allowedStatuses() as $s): ?>
                            <option value="<?= htmlspecialchars($s) ?>" <?= $s === $ticketStatus ? 'selected' : '' ?>><?= htmlspecialchars($s) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="ticket_priority" class="border border-gray-200 rounded-lg px-3 py-2 text-sm">
                        <option value="">All Priority</option>
                        <?php foreach (MaintenanceService::allowedPriorities() as $p): ?>
                            <option value="<?= htmlspecialchars($p) ?>" <?= $p === $ticketPriority ? 'selected' : '' ?>><?= htmlspecialchars($p) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="px-4 py-2 rounded-lg border border-gray-200 text-sm hover:bg-gray-50 transition">Filter</button>
                </form>

                <div class="space-y-3">
                    <?php if (empty($tickets)): ?>
                        <div class="text-sm text-gray-500">No tickets found.</div>
                    <?php endif; ?>

                    <?php foreach ($tickets as $t): ?>
                        <div class="rounded-lg border border-gray-100 p-4">
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <div class="text-sm font-medium text-gray-900">
                                        <?= htmlspecialchars(($t['ticket_no'] ?? '') . ' • ' . ($t['title'] ?? '')) ?>
                                    </div>
                                    <div class="text-xs text-gray-500 mt-1">
                                        <?= htmlspecialchars(($t['status'] ?? '') . ' • ' . ($t['priority'] ?? '') . ' • ' . ($t['category_name'] ?? '')) ?>
                                    </div>
                                    <div class="text-xs text-gray-600 mt-1">
                                        <?php if (trim((string)($t['room_no'] ?? '')) !== ''): ?>
                                            <?= htmlspecialchars('Room ' . ($t['room_no'] ?? '')) ?>
                                        <?php endif; ?>
                                        <?php if (trim((string)($t['asset_code'] ?? '')) !== ''): ?>
                                            <?= htmlspecialchars(' • Asset ' . ($t['asset_code'] ?? '')) ?>
                                        <?php endif; ?>
                                        <?php if ((int)($t['requires_downtime'] ?? 0) === 1): ?>
                                            <?= htmlspecialchars(' • Downtime') ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-xs text-gray-500 mt-1">
                                        <?= htmlspecialchars('Assigned: ' . (($t['assigned_username'] ?? '') !== '' ? $t['assigned_username'] : '-')) ?>
                                        <?= htmlspecialchars(' • Vendor: ' . (($t['vendor_name'] ?? '') !== '' ? $t['vendor_name'] : '-')) ?>
                                    </div>
                                </div>

                                <div class="flex flex-col gap-2">
                                    <form method="post" class="flex items-center gap-2">
                                        <input type="hidden" name="action" value="maintenance_set_status" />
                                        <input type="hidden" name="ticket_id" value="<?= (int)$t['id'] ?>" />
                                        <select name="status" class="border border-gray-200 rounded-lg px-2 py-1 text-xs">
                                            <?php foreach (MaintenanceService::allowedStatuses() as $s): ?>
                                                <option value="<?= htmlspecialchars($s) ?>" <?= $s === ($t['status'] ?? '') ? 'selected' : '' ?>><?= htmlspecialchars($s) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button class="px-3 py-1.5 rounded-lg bg-blue-600 text-white text-xs hover:bg-blue-700 transition">Update</button>
                                    </form>

                                    <form method="post" class="flex items-center gap-2">
                                        <input type="hidden" name="action" value="maintenance_update_assignment" />
                                        <input type="hidden" name="ticket_id" value="<?= (int)$t['id'] ?>" />
                                        <select name="assigned_to" class="border border-gray-200 rounded-lg px-2 py-1 text-xs">
                                            <option value="0">Assignee</option>
                                            <?php foreach ($users as $u): ?>
                                                <option value="<?= (int)$u['id'] ?>" <?= (int)($t['assigned_to'] ?? 0) === (int)$u['id'] ? 'selected' : '' ?>><?= htmlspecialchars($u['username']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <select name="vendor_id" class="border border-gray-200 rounded-lg px-2 py-1 text-xs">
                                            <option value="0">Vendor</option>
                                            <?php foreach ($vendors as $v): ?>
                                                <option value="<?= (int)$v['id'] ?>" <?= (int)($t['vendor_id'] ?? 0) === (int)$v['id'] ? 'selected' : '' ?>><?= htmlspecialchars($v['name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button class="px-3 py-1.5 rounded-lg border border-gray-200 text-xs hover:bg-gray-50 transition">Save</button>
                                    </form>
                                </div>
                            </div>

                            <div class="mt-3 grid grid-cols-1 md:grid-cols-2 gap-3">
                                <form method="post" class="flex items-center gap-2">
                                    <input type="hidden" name="action" value="maintenance_add_log" />
                                    <input type="hidden" name="ticket_id" value="<?= (int)$t['id'] ?>" />
                                    <input name="message" placeholder="Add note" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-xs" />
                                    <button class="px-3 py-2 rounded-lg border border-gray-200 text-xs hover:bg-gray-50 transition">Add</button>
                                </form>

                                <form method="post" class="grid grid-cols-5 gap-2">
                                    <input type="hidden" name="action" value="maintenance_add_cost" />
                                    <input type="hidden" name="ticket_id" value="<?= (int)$t['id'] ?>" />
                                    <select name="cost_type" class="border border-gray-200 rounded-lg px-2 py-2 text-xs col-span-1">
                                        <?php foreach (MaintenanceService::allowedCostTypes() as $ct): ?>
                                            <option value="<?= htmlspecialchars($ct) ?>"><?= htmlspecialchars($ct) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input name="description" placeholder="Cost desc" class="border border-gray-200 rounded-lg px-2 py-2 text-xs col-span-2" />
                                    <input name="qty" value="1" class="border border-gray-200 rounded-lg px-2 py-2 text-xs col-span-1" />
                                    <input name="unit_cost" value="0" class="border border-gray-200 rounded-lg px-2 py-2 text-xs col-span-1" />
                                    <div class="col-span-5 flex items-center justify-end">
                                        <button class="px-3 py-1.5 rounded-lg border border-gray-200 text-xs hover:bg-gray-50 transition">Add Cost</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="bg-white rounded-lg border border-gray-100 p-6">
                <div class="flex items-center justify-between gap-4 mb-4">
                    <h3 class="text-lg font-medium text-gray-900">Create Ticket</h3>
                    <div class="text-xs text-gray-500">Room or Asset</div>
                </div>

                <form method="post" class="space-y-4">
                    <input type="hidden" name="action" value="create_maintenance_ticket" />

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Room (optional)</label>
                        <select name="room_id" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
                            <option value="0">Select Room</option>
                            <?php foreach ($maintRooms as $r): ?>
                                <option value="<?= (int)$r['id'] ?>"><?= htmlspecialchars('Room ' . $r['room_no'] . ' • ' . $r['room_type_name'] . ' • ' . $r['status']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Asset (optional)</label>
                        <select name="asset_id" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
                            <option value="0">Select Asset</option>
                            <?php foreach ($assets as $a): ?>
                                <option value="<?= (int)$a['id'] ?>"><?= htmlspecialchars(($a['asset_code'] ?? '') . ' • ' . ($a['name'] ?? '') . (trim((string)($a['room_no'] ?? '')) !== '' ? ' • Room ' . $a['room_no'] : '')) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Category</label>
                        <select name="category_id" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
                            <option value="0">Select Category</option>
                            <?php foreach ($categories as $c): ?>
                                <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Priority</label>
                        <select name="priority" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
                            <?php foreach (MaintenanceService::allowedPriorities() as $p): ?>
                                <option value="<?= htmlspecialchars($p) ?>" <?= $p === 'Normal' ? 'selected' : '' ?>><?= htmlspecialchars($p) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Title</label>
                        <input name="title" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                        <textarea name="description" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" rows="3"></textarea>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Assign To</label>
                        <select name="assigned_to" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
                            <option value="0">Unassigned</option>
                            <?php foreach ($users as $u): ?>
                                <option value="<?= (int)$u['id'] ?>"><?= htmlspecialchars($u['username'] . ' (' . $u['role'] . ')') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Vendor</label>
                        <select name="vendor_id" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
                            <option value="0">None</option>
                            <?php foreach ($vendors as $v): ?>
                                <option value="<?= (int)$v['id'] ?>"><?= htmlspecialchars($v['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="flex items-center gap-2">
                        <input type="hidden" name="requires_downtime" value="0" />
                        <input type="checkbox" name="requires_downtime" value="1" class="h-4 w-4" />
                        <label class="text-sm text-gray-700">Requires downtime (set room Out of Order)</label>
                    </div>

                    <div class="flex items-center gap-2 pt-2">
                        <button class="px-4 py-2 rounded-lg bg-blue-600 text-white text-sm hover:bg-blue-700 transition">Create Ticket</button>
                        <a href="housekeeping_maintenance.php?tab=maintenance" class="px-4 py-2 rounded-lg border border-gray-200 text-sm hover:bg-gray-50 transition">Reset</a>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </main>
</section>
<?php include __DIR__ . '/../partials/page_end.php';
