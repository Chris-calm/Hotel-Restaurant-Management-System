<?php
require_once __DIR__ . '/../rbac_middleware.php';
RBACMiddleware::checkPageAccess();

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../domain/Housekeeping/HousekeepingService.php';

$conn = Database::getConnection();
$service = new HousekeepingService(
    new HousekeepingRepository($conn),
    new RoomRepository($conn)
);

$pendingApprovals = [];

$roomQ = (string)Request::get('room_q', '');
$rooms = $service->listRooms($roomQ);
$tasks = $service->listOpenTasks();

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

        $id = $service->createCleaningTask($payload, $errors);
        if ($id > 0) {
            Flash::set('success', 'Housekeeping task created.');
            Response::redirect('housekeeping_maintenance.php');
        }
    }

    if ($action === 'set_task_status') {
        $taskId = Request::int('post', 'task_id', 0);
        $status = (string)Request::post('status', '');
        $ok = $service->setTaskStatus($taskId, $status, $errors);
        if ($ok) {
            Flash::set('success', 'Task updated.');
            Response::redirect('housekeeping_maintenance.php');
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
    </main>
</section>
<?php include __DIR__ . '/../partials/page_end.php';
