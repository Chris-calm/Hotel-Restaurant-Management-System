<?php
require_once __DIR__ . '/../rbac_middleware.php';
RBACMiddleware::checkPageAccess();

require_once __DIR__ . '/../core/bootstrap.php';

$conn = Database::getConnection();

$pendingApprovals = [];

$pageTitle = 'Events & Conferences - Hotel Management System';
$extraHeadHtml = <<<'HTML'
<style>
    #content {
        overflow-y: auto;
        overflow-x: hidden;
        scrollbar-width: none;
        -ms-overflow-style: none;
    }
    #content::-webkit-scrollbar { display: none; width: 0; height: 0; }
    main {
        overflow-y: auto;
        overflow-x: hidden;
        scrollbar-width: none;
        -ms-overflow-style: none;
    }
    main::-webkit-scrollbar { display: none; width: 0; height: 0; }
    * { scrollbar-width: none; -ms-overflow-style: none; }
    *::-webkit-scrollbar { display: none; width: 0; height: 0; }

    .dashboard-card {
        transition: all 0.4s ease;
        position: relative;
        overflow: hidden;
    }
    .dashboard-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, transparent, rgba(255,255,255,0.4), transparent);
        transition: left 0.6s ease;
    }
    .dashboard-card:hover::before { left: 100%; }
    .dashboard-card:hover {
        transform: translateY(-6px) scale(1.01);
        box-shadow: 0 18px 36px rgba(0,0,0,0.08);
        border-color: #3b82f6;
    }
    .dashboard-card .icon-container { transition: all 0.4s ease; }
    .dashboard-card:hover .icon-container {
        transform: scale(1.08) rotate(4deg);
        background: linear-gradient(135deg, #3b82f6, #1d4ed8);
    }
    .dashboard-card:hover .icon-container i { color: white !important; }

    .chart-container {
        background: white;
        border-radius: 12px;
        border: 1px solid #e5e7eb;
        padding: 24px;
        transition: all 0.4s ease;
    }
    .chart-container:hover {
        transform: translateY(-4px);
        box-shadow: 0 12px 24px rgba(0,0,0,0.1);
        border-color: #3b82f6;
    }
</style>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        var roomSel = document.querySelector('select[name="function_room_id"]');
        var estInput = document.querySelector('input[name="estimated_total"]');
        var depInput = document.querySelector('input[name="deposit_amount"]');
        var phoneInput = document.querySelector('input[name="client_phone"]');
        var guestsInput = document.querySelector('input[name="expected_guests"]');
        var clientUserSel = document.querySelector('select[name="client_user_id"]');
        var clientNameInput = document.querySelector('input[name="client_name"]');
        var clientEmailInput = document.querySelector('input[name="client_email"]');

        var confirmModal = document.getElementById('confirmModal');
        var confirmTitle = document.getElementById('confirmModalTitle');
        var confirmMessage = document.getElementById('confirmModalMessage');
        var confirmCancel = document.getElementById('confirmModalCancel');
        var confirmOk = document.getElementById('confirmModalOk');
        var pendingConfirmForm = null;

        function fmtMoney(n) {
            var x = Number(n);
            if (!isFinite(x) || x < 0) x = 0;
            return x.toFixed(2);
        }

        function recalc() {
            if (!roomSel || !estInput || !depInput) {
                return;
            }
            var opt = roomSel.options[roomSel.selectedIndex];
            var rateRaw = opt ? (opt.getAttribute('data-base-rate') || '') : '';
            rateRaw = String(rateRaw).replace(/,/g, '').trim();
            var rate = Number(rateRaw);
            if (!isFinite(rate) || rate < 0) rate = 0;

            if (guestsInput) {
                var capRaw = opt ? (opt.getAttribute('data-capacity') || '') : '';
                var cap = Number(String(capRaw).replace(/[^0-9]/g, ''));
                if (!isFinite(cap) || cap < 0) cap = 0;
                if (Number(roomSel.value || 0) > 0 && cap > 0) {
                    guestsInput.max = String(cap);
                    var cur = Number(String(guestsInput.value || '0').replace(/[^0-9]/g, ''));
                    if (isFinite(cur) && cur > cap) {
                        guestsInput.value = String(cap);
                    }
                } else {
                    guestsInput.removeAttribute('max');
                }
            }

            if (Number(roomSel.value || 0) > 0) {
                estInput.value = fmtMoney(rate);
                depInput.value = fmtMoney(rate * 0.20);
                estInput.readOnly = true;
                depInput.readOnly = true;
            } else {
                estInput.readOnly = false;
                depInput.readOnly = false;
            }
        }

        if (roomSel) {
            roomSel.addEventListener('change', recalc);
            recalc();
        }

        if (phoneInput) {
            phoneInput.addEventListener('input', function () {
                var v = String(phoneInput.value || '');
                v = v.replace(/\D+/g, '');
                if (v.length > 10) v = v.slice(0, 10);
                phoneInput.value = v;
            });
        }

        function applyClientFromOption() {
            if (!clientUserSel) return;
            var opt = clientUserSel.options[clientUserSel.selectedIndex];
            if (!opt) return;

            if (clientNameInput) {
                clientNameInput.value = opt.getAttribute('data-client-name') || '';
            }
            if (phoneInput) {
                var ph = opt.getAttribute('data-client-phone') || '';
                ph = String(ph).replace(/\D+/g, '');
                if (ph.length > 10) ph = ph.slice(0, 10);
                phoneInput.value = ph;
            }
            if (clientEmailInput) {
                clientEmailInput.value = opt.getAttribute('data-client-email') || '';
            }
        }

        if (clientUserSel) {
            clientUserSel.addEventListener('change', applyClientFromOption);
            applyClientFromOption();
        }

        function closeConfirm() {
            if (confirmModal) {
                confirmModal.classList.add('hidden');
            }
            pendingConfirmForm = null;
        }

        function openConfirm(title, message, formEl) {
            if (!confirmModal) {
                if (formEl) {
                    formEl.submit();
                }
                return;
            }
            if (confirmTitle) confirmTitle.textContent = title || 'Confirm';
            if (confirmMessage) confirmMessage.textContent = message || 'Are you sure?';
            pendingConfirmForm = formEl || null;
            confirmModal.classList.remove('hidden');
        }

        if (confirmCancel) {
            confirmCancel.addEventListener('click', function (e) {
                e.preventDefault();
                closeConfirm();
            });
        }
        if (confirmOk) {
            confirmOk.addEventListener('click', function (e) {
                e.preventDefault();
                if (pendingConfirmForm) {
                    pendingConfirmForm.submit();
                }
                closeConfirm();
            });
        }
        if (confirmModal) {
            confirmModal.addEventListener('click', function (e) {
                if (e.target === confirmModal) {
                    closeConfirm();
                }
            });
        }

        document.querySelectorAll('form.js-confirm-delete').forEach(function (formEl) {
            formEl.addEventListener('submit', function (e) {
                e.preventDefault();
                var title = formEl.getAttribute('data-confirm-title') || 'Confirm';
                var msg = formEl.getAttribute('data-confirm-message') || 'Are you sure?';
                openConfirm(title, msg, formEl);
            });
        });

        var roomForm = document.querySelector('form[data-form="function_room"]');
        if (roomForm) {
            var capInput = roomForm.querySelector('input[name="capacity"]');
            var rateInput = roomForm.querySelector('input[name="base_rate"]');
            var tierSel = roomForm.querySelector('select[name="pricing_tier"]');
            var tierHidden = roomForm.querySelector('input[name="pricing_tier"]');
            function suggestRate() {
                if (!capInput || !rateInput) return;

                var cap = Number(String(capInput.value || '').replace(/[^0-9]/g, ''));
                if (!isFinite(cap) || cap < 0) cap = 0;

                var tier = 'Budget';
                var mult = 100;
                var min = 1500;
                if (cap >= 200) {
                    tier = 'Premium';
                    mult = 500;
                    min = 10000;
                } else if (cap >= 101) {
                    tier = 'Standard';
                    mult = 300;
                    min = 5000;
                }
                var max = 200000;

                var base = Math.max(min, cap * mult);
                if (base > max) base = max;
                base = Math.round(base / 100) * 100;

                if (tierSel) {
                    tierSel.value = tier;
                }
                if (tierHidden) {
                    tierHidden.value = tier;
                }
                rateInput.value = String(Number(base).toFixed(2));
                rateInput.readOnly = true;
            }
            if (capInput) {
                capInput.addEventListener('input', suggestRate);
                suggestRate();
            }
            if (tierSel) {
                tierSel.disabled = true;
            }
        }
    });
</script>
HTML;

$errors = [];
$flashLocal = null;

$APP_BASE_URL = App::baseUrl();

$editFunctionRoomId = (int)Request::get('edit_room_id', 0);
$editEventId = (int)Request::get('edit_event_id', 0);
$editingFunctionRoom = null;
$editingEvent = null;

$hasFunctionRooms = false;
$hasEvents = false;
$hasFunctionRoomImageColumn = false;
$hasEventImageColumn = false;
if ($conn) {
    try {
        $dbRow = $conn->query('SELECT DATABASE()');
        $db = $dbRow ? (string)($dbRow->fetch_row()[0] ?? '') : '';
        $db = $conn->real_escape_string($db);
        if ($db !== '') {
            $res = $conn->query(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'function_rooms'"
            );
            $hasFunctionRooms = $res ? ((int)($res->fetch_row()[0] ?? 0) === 1) : false;

            $res = $conn->query(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'events'"
            );
            $hasEvents = $res ? ((int)($res->fetch_row()[0] ?? 0) === 1) : false;

            if ($hasFunctionRooms) {
                $res = $conn->query(
                    "SELECT COUNT(*)
                     FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = '{$db}'
                       AND TABLE_NAME = 'function_rooms'
                       AND COLUMN_NAME = 'image_path'"
                );
                $hasFunctionRoomImageColumn = $res ? ((int)($res->fetch_row()[0] ?? 0) === 1) : false;
            }

            if ($hasEvents) {
                $res = $conn->query(
                    "SELECT COUNT(*)
                     FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = '{$db}'
                       AND TABLE_NAME = 'events'
                       AND COLUMN_NAME = 'image_path'"
                );
                $hasEventImageColumn = $res ? ((int)($res->fetch_row()[0] ?? 0) === 1) : false;
            }
        }
    } catch (Throwable $e) {
    }
}

if (Request::isPost() && $conn) {
    $action = (string)Request::post('action', '');

    $computeRoomTierAndRate = function (int $capacity): array {
        if ($capacity < 0) {
            $capacity = 0;
        }
        $tier = 'Budget';
        $mult = 100;
        $min = 1500;
        if ($capacity >= 200) {
            $tier = 'Premium';
            $mult = 500;
            $min = 10000;
        } elseif ($capacity >= 101) {
            $tier = 'Standard';
            $mult = 300;
            $min = 5000;
        }
        $max = 200000;
        $rate = max((float)$min, (float)$capacity * (float)$mult);
        if ($rate > $max) {
            $rate = $max;
        }
        $rate = round($rate / 100) * 100;
        return [$tier, (float)$rate];
    };

    if ($action === 'create_function_room' && $hasFunctionRooms) {
        $name = trim((string)Request::post('name', ''));
        $capacityRaw = trim((string)Request::post('capacity', '0'));
        $capacity = (int)$capacityRaw;
        $rateRaw = trim((string)Request::post('base_rate', '0'));
        $rate = $rateRaw;
        $imagePath = (string)Request::post('image_path', '');
        $status = (string)Request::post('status', 'Available');
        $active = ((string)Request::post('is_active', '1') === '1') ? 1 : 0;
        $notes = (string)Request::post('notes', '');

        if (isset($_FILES['room_image']) && is_array($_FILES['room_image']) && (int)($_FILES['room_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $err = (int)($_FILES['room_image']['error'] ?? UPLOAD_ERR_OK);
            if ($err !== UPLOAD_ERR_OK) {
                $errors['image_path'] = 'Failed to upload image.';
            } else {
                $tmp = (string)($_FILES['room_image']['tmp_name'] ?? '');
                $orig = (string)($_FILES['room_image']['name'] ?? '');
                $size = (int)($_FILES['room_image']['size'] ?? 0);

                if ($size <= 0) {
                    $errors['image_path'] = 'Invalid image file.';
                } elseif ($size > (8 * 1024 * 1024)) {
                    $errors['image_path'] = 'Image must be 8MB or less.';
                } else {
                    $ext = strtolower((string)pathinfo($orig, PATHINFO_EXTENSION));
                    $allowed = ['jpg', 'jpeg', 'png', 'webp'];
                    if (!in_array($ext, $allowed, true)) {
                        $errors['image_path'] = 'Image must be JPG, PNG, or WEBP.';
                    } else {
                        $root = dirname(__DIR__, 2);
                        $uploadDir = $root . '/uploads/events/function_rooms';
                        if (!is_dir($uploadDir)) {
                            @mkdir($uploadDir, 0775, true);
                        }

                        if (!is_dir($uploadDir) || !is_writable($uploadDir)) {
                            $errors['image_path'] = 'Upload directory is not writable.';
                        } else {
                            $filename = 'function_room_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $name) . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                            $dest = $uploadDir . '/' . $filename;
                            if (!move_uploaded_file($tmp, $dest)) {
                                $errors['image_path'] = 'Failed to save uploaded image.';
                            } else {
                                $imagePath = '/uploads/events/function_rooms/' . $filename;
                            }
                        }
                    }
                }
            }
        }

        if ($name === '') {
            $errors['name'] = 'Function room name is required.';
        }
        if ($capacityRaw === '' || !ctype_digit($capacityRaw) || $capacity < 0) {
            $errors['capacity'] = 'Capacity is invalid.';
        }
        if (empty($errors['capacity'])) {
            [$tierAuto, $rateAuto] = $computeRoomTierAndRate($capacity);
            $rate = (string)$rateAuto;
        }
        if (!in_array($status, ['Available', 'Maintenance'], true)) {
            $errors['status'] = 'Status is invalid.';
        }

        if (empty($errors)) {
            $sql = $hasFunctionRoomImageColumn
                ? "INSERT INTO function_rooms (name, capacity, base_rate, image_path, status, is_active, notes)
                   VALUES (?, ?, ?, NULLIF(?,''), ?, ?, ?)"
                : "INSERT INTO function_rooms (name, capacity, base_rate, status, is_active, notes)
                   VALUES (?, ?, ?, ?, ?, ?)";

            $stmt = $conn->prepare($sql);
            if ($stmt instanceof mysqli_stmt) {
                $rateF = (float)$rate;
                if ($hasFunctionRoomImageColumn) {
                    $stmt->bind_param('sidssis', $name, $capacity, $rateF, $imagePath, $status, $active, $notes);
                } else {
                    $stmt->bind_param('sidsis', $name, $capacity, $rateF, $status, $active, $notes);
                }
                $ok = $stmt->execute();
                $stmt->close();
                if ($ok) {
                    Flash::set('success', 'Function room saved.');
                    Response::redirect('events_conferences.php');
                }
            }

            $errors['general'] = 'Failed to save function room.';
        }
    }

    if ($action === 'update_function_room' && $hasFunctionRooms) {
        $roomId = (int)Request::post('id', 0);
        $name = trim((string)Request::post('name', ''));
        $capacityRaw = trim((string)Request::post('capacity', '0'));
        $capacity = (int)$capacityRaw;
        $rateRaw = trim((string)Request::post('base_rate', '0'));
        $rate = $rateRaw;
        $imagePath = (string)Request::post('image_path', '');
        $status = (string)Request::post('status', 'Available');
        $active = ((string)Request::post('is_active', '1') === '1') ? 1 : 0;
        $notes = (string)Request::post('notes', '');

        if ($roomId <= 0) {
            $errors['general'] = 'Invalid function room.';
        }

        $existingRoomImagePath = '';
        if (empty($errors)) {
            $roomImageSelect = $hasFunctionRoomImageColumn ? 'image_path' : "'' AS image_path";
            $stmt = $conn->prepare("SELECT {$roomImageSelect} FROM function_rooms WHERE id = ? LIMIT 1");
            if ($stmt instanceof mysqli_stmt) {
                $stmt->bind_param('i', $roomId);
                $stmt->execute();
                $r = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $existingRoomImagePath = (string)($r['image_path'] ?? '');
            }
        }

        if (isset($_FILES['room_image']) && is_array($_FILES['room_image']) && (int)($_FILES['room_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $err = (int)($_FILES['room_image']['error'] ?? UPLOAD_ERR_OK);
            if ($err !== UPLOAD_ERR_OK) {
                $errors['image_path'] = 'Failed to upload image.';
            } else {
                $tmp = (string)($_FILES['room_image']['tmp_name'] ?? '');
                $orig = (string)($_FILES['room_image']['name'] ?? '');
                $size = (int)($_FILES['room_image']['size'] ?? 0);

                if ($size <= 0) {
                    $errors['image_path'] = 'Invalid image file.';
                } elseif ($size > (8 * 1024 * 1024)) {
                    $errors['image_path'] = 'Image must be 8MB or less.';
                } else {
                    $ext = strtolower((string)pathinfo($orig, PATHINFO_EXTENSION));
                    $allowed = ['jpg', 'jpeg', 'png', 'webp'];
                    if (!in_array($ext, $allowed, true)) {
                        $errors['image_path'] = 'Image must be JPG, PNG, or WEBP.';
                    } else {
                        $root = dirname(__DIR__, 2);
                        $uploadDir = $root . '/uploads/events/function_rooms';
                        if (!is_dir($uploadDir)) {
                            @mkdir($uploadDir, 0775, true);
                        }

                        if (!is_dir($uploadDir) || !is_writable($uploadDir)) {
                            $errors['image_path'] = 'Upload directory is not writable.';
                        } else {
                            $filename = 'function_room_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $name) . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                            $dest = $uploadDir . '/' . $filename;
                            if (!move_uploaded_file($tmp, $dest)) {
                                $errors['image_path'] = 'Failed to save uploaded image.';
                            } else {
                                $imagePath = '/uploads/events/function_rooms/' . $filename;
                            }
                        }
                    }
                }
            }
        }

        if ($name === '') {
            $errors['name'] = 'Function room name is required.';
        }
        if ($capacityRaw === '' || !ctype_digit($capacityRaw) || $capacity < 0) {
            $errors['capacity'] = 'Capacity is invalid.';
        }
        if ($rateRaw === '' || !is_numeric($rate) || (float)$rate < 0) {
            $errors['base_rate'] = 'Base rate is invalid.';
        }
        if (!in_array($status, ['Available', 'Maintenance'], true)) {
            $errors['status'] = 'Status is invalid.';
        }

        if (empty($errors)) {
            $rateF = (float)$rate;
            if ($hasFunctionRoomImageColumn) {
                $finalImagePath = $imagePath !== '' ? $imagePath : $existingRoomImagePath;
                $stmt = $conn->prepare('UPDATE function_rooms SET name = ?, capacity = ?, base_rate = ?, image_path = NULLIF(?,\'\'), status = ?, is_active = ?, notes = ? WHERE id = ?');
                if ($stmt instanceof mysqli_stmt) {
                    $stmt->bind_param('sidssisi', $name, $capacity, $rateF, $finalImagePath, $status, $active, $notes, $roomId);
                    $ok = $stmt->execute();
                    $stmt->close();
                    if ($ok) {
                        Flash::set('success', 'Function room updated.');
                        Response::redirect('events_conferences.php');
                    }
                }
            } else {
                $stmt = $conn->prepare('UPDATE function_rooms SET name = ?, capacity = ?, base_rate = ?, status = ?, is_active = ?, notes = ? WHERE id = ?');
                if ($stmt instanceof mysqli_stmt) {
                    $stmt->bind_param('sidsisi', $name, $capacity, $rateF, $status, $active, $notes, $roomId);
                    $ok = $stmt->execute();
                    $stmt->close();
                    if ($ok) {
                        Flash::set('success', 'Function room updated.');
                        Response::redirect('events_conferences.php');
                    }
                }
            }

            $errors['general'] = 'Failed to update function room.';
        }
    }

    if ($action === 'delete_function_room' && $hasFunctionRooms) {
        $roomId = (int)Request::post('id', 0);
        if ($roomId <= 0) {
            $errors['general'] = 'Invalid function room.';
        } else {
            $inUse = false;
            if ($hasEvents) {
                $stmt = $conn->prepare('SELECT COUNT(*) AS c FROM events WHERE function_room_id = ?');
                if ($stmt instanceof mysqli_stmt) {
                    $stmt->bind_param('i', $roomId);
                    $stmt->execute();
                    $row = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    $inUse = ((int)($row['c'] ?? 0) > 0);
                }
            }
            if ($inUse) {
                $errors['general'] = 'Cannot delete function room because it has events assigned.';
            } else {
                $stmt = $conn->prepare('DELETE FROM function_rooms WHERE id = ? LIMIT 1');
                if ($stmt instanceof mysqli_stmt) {
                    $stmt->bind_param('i', $roomId);
                    $ok = $stmt->execute();
                    $stmt->close();
                    if ($ok) {
                        Flash::set('success', 'Function room deleted.');
                        Response::redirect('events_conferences.php');
                    }
                }
                $errors['general'] = 'Failed to delete function room.';
            }
        }
    }

    if ($action === 'create_event' && $hasEvents) {
        $title = trim((string)Request::post('title', ''));
        $eventImagePath = (string)Request::post('event_image_path', '');
        $clientUserId = (int)Request::post('client_user_id', 0);
        $clientName = trim((string)Request::post('client_name', ''));
        $clientPhone = trim((string)Request::post('client_phone', ''));
        $clientEmail = trim((string)Request::post('client_email', ''));
        $eventDate = trim((string)Request::post('event_date', ''));
        $startTime = trim((string)Request::post('start_time', ''));
        $endTime = trim((string)Request::post('end_time', ''));
        $expectedGuestsRaw = trim((string)Request::post('expected_guests', '0'));
        $expectedGuests = (int)$expectedGuestsRaw;
        $functionRoomId = (int)Request::post('function_room_id', 0);
        $status = (string)Request::post('status', 'Inquiry');
        $estimatedTotal = (string)Request::post('estimated_total', '0');
        $deposit = (string)Request::post('deposit_amount', '0');
        $notes = (string)Request::post('notes', '');

        if (isset($_FILES['event_image']) && is_array($_FILES['event_image']) && (int)($_FILES['event_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $err = (int)($_FILES['event_image']['error'] ?? UPLOAD_ERR_OK);
            if ($err !== UPLOAD_ERR_OK) {
                $errors['event_image_path'] = 'Failed to upload image.';
            } else {
                $tmp = (string)($_FILES['event_image']['tmp_name'] ?? '');
                $orig = (string)($_FILES['event_image']['name'] ?? '');
                $size = (int)($_FILES['event_image']['size'] ?? 0);

                if ($size <= 0) {
                    $errors['event_image_path'] = 'Invalid image file.';
                } elseif ($size > (8 * 1024 * 1024)) {
                    $errors['event_image_path'] = 'Image must be 8MB or less.';
                } else {
                    $ext = strtolower((string)pathinfo($orig, PATHINFO_EXTENSION));
                    $allowed = ['jpg', 'jpeg', 'png', 'webp'];
                    if (!in_array($ext, $allowed, true)) {
                        $errors['event_image_path'] = 'Image must be JPG, PNG, or WEBP.';
                    } else {
                        $root = dirname(__DIR__, 2);
                        $uploadDir = $root . '/uploads/events';
                        if (!is_dir($uploadDir)) {
                            @mkdir($uploadDir, 0775, true);
                        }

                        if (!is_dir($uploadDir) || !is_writable($uploadDir)) {
                            $errors['event_image_path'] = 'Upload directory is not writable.';
                        } else {
                            $filename = 'event_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                            $dest = $uploadDir . '/' . $filename;
                            if (!move_uploaded_file($tmp, $dest)) {
                                $errors['event_image_path'] = 'Failed to save uploaded image.';
                            } else {
                                $eventImagePath = '/uploads/events/' . $filename;
                            }
                        }
                    }
                }
            }
        }

        if ($title === '') {
            $errors['title'] = 'Event title is required.';
        }
        if ($clientUserId <= 0) {
            $errors['client_user_id'] = 'Client account is required.';
        } else {
            $stmt = $conn->prepare(
                "SELECT u.username, u.email AS user_email, g.first_name, g.last_name, g.phone AS guest_phone, g.email AS guest_email
                 FROM users u
                 LEFT JOIN guests g ON g.id = u.guest_id
                 WHERE u.id = ?
                 LIMIT 1"
            );
            if ($stmt instanceof mysqli_stmt) {
                $stmt->bind_param('i', $clientUserId);
                $stmt->execute();
                $u = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                $fn = trim((string)($u['first_name'] ?? ''));
                $ln = trim((string)($u['last_name'] ?? ''));
                $nm = trim($fn . ' ' . $ln);
                if ($nm === '') {
                    $nm = trim((string)($u['username'] ?? ''));
                }
                $clientName = $nm;

                $clientPhone = trim((string)($u['guest_phone'] ?? ''));
                $clientPhone = preg_replace('/\D+/', '', $clientPhone);

                $clientEmail = trim((string)($u['guest_email'] ?? ''));
                if ($clientEmail === '') {
                    $clientEmail = trim((string)($u['user_email'] ?? ''));
                }
            }
        }

        if ($clientName === '') {
            $errors['client_user_id'] = 'Client account is required.';
        }
        if ($clientPhone === '' || !preg_match('/^\d{10}$/', $clientPhone)) {
            $errors['client_phone'] = 'Phone must be exactly 10 digits.';
        }
        if ($clientEmail === '' || !preg_match('/^[A-Z0-9._%+-]+@gmail\.com$/i', $clientEmail)) {
            $errors['client_email'] = 'Email must be a valid @gmail.com address.';
        }
        if ($eventDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $eventDate)) {
            $errors['event_date'] = 'Event date is invalid.';
        }
        if ($expectedGuestsRaw === '' || !ctype_digit($expectedGuestsRaw) || $expectedGuests < 0) {
            $errors['expected_guests'] = 'Expected guests is invalid.';
        }

        if (empty($errors['expected_guests']) && $functionRoomId > 0) {
            $stmt = $conn->prepare('SELECT capacity FROM function_rooms WHERE id = ? LIMIT 1');
            if ($stmt instanceof mysqli_stmt) {
                $stmt->bind_param('i', $functionRoomId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $cap = (int)($row['capacity'] ?? 0);
                if ($cap > 0 && $expectedGuests > $cap) {
                    $errors['expected_guests'] = 'Expected guests cannot exceed the function room capacity.';
                }
            }
        }

        if ($startTime !== '' && !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $startTime)) {
            $errors['start_time'] = 'Start time is invalid.';
        }
        if ($endTime !== '' && !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $endTime)) {
            $errors['end_time'] = 'End time is invalid.';
        }
        if ($startTime !== '' && $endTime !== '' && empty($errors['start_time']) && empty($errors['end_time'])) {
            $t1 = strtotime('1970-01-01 ' . $startTime);
            $t2 = strtotime('1970-01-01 ' . $endTime);
            if ($t1 !== false && $t2 !== false && $t2 <= $t1) {
                $errors['end_time'] = 'End time must be after start time.';
            }
        }
        if (!in_array($status, ['Inquiry', 'Quoted', 'Confirmed', 'Ongoing', 'Completed', 'Cancelled'], true)) {
            $errors['status'] = 'Status is invalid.';
        }
        if ($functionRoomId > 0) {
            $stmt = $conn->prepare('SELECT base_rate FROM function_rooms WHERE id = ? LIMIT 1');
            if ($stmt instanceof mysqli_stmt) {
                $stmt->bind_param('i', $functionRoomId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $rateF = (float)($row['base_rate'] ?? 0);
                if ($rateF < 0) {
                    $rateF = 0;
                }
                $estimatedTotal = (string)$rateF;
                $deposit = (string)round($rateF * 0.20, 2);
            }
        }

        if (!is_numeric($estimatedTotal) || (float)$estimatedTotal < 0) {
            $errors['estimated_total'] = 'Estimated total is invalid.';
        }
        if (!is_numeric($deposit) || (float)$deposit < 0) {
            $errors['deposit_amount'] = 'Deposit amount is invalid.';
        }

        if (empty($errors) && $functionRoomId > 0) {
            $startForCheck = $startTime !== '' ? $startTime : '00:00:00';
            $endForCheck = $endTime !== '' ? $endTime : '23:59:59';

            $stmt = $conn->prepare(
                "SELECT COUNT(*) AS c
                 FROM events
                 WHERE function_room_id = ?
                   AND event_date = ?
                   AND status NOT IN ('Cancelled','Completed')
                   AND COALESCE(start_time, '00:00:00') < ?
                   AND COALESCE(end_time, '23:59:59') > ?"
            );
            if ($stmt instanceof mysqli_stmt) {
                $stmt->bind_param('isss', $functionRoomId, $eventDate, $endForCheck, $startForCheck);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $conflicts = (int)($row['c'] ?? 0);
                if ($conflicts > 0) {
                    $errors['function_room_id'] = 'Selected function room is already booked for this schedule.';
                }
            }
        }

        if (empty($errors)) {
            $eventNo = 'EVT-' . date('Ymd') . '-' . str_pad((string)random_int(1, 999999), 6, '0', STR_PAD_LEFT);

            $sql = $hasEventImageColumn
                ? "INSERT INTO events (event_no, title, image_path, client_name, client_phone, client_email, event_date, start_time, end_time, expected_guests, function_room_id, status, estimated_total, deposit_amount, notes)
                   VALUES (?, ?, NULLIF(?,''), ?, ?, ?, ?, NULLIF(?,''), NULLIF(?,''), ?, NULLIF(?,0), ?, ?, ?, ?)"
                : "INSERT INTO events (event_no, title, client_name, client_phone, client_email, event_date, start_time, end_time, expected_guests, function_room_id, status, estimated_total, deposit_amount, notes)
                   VALUES (?, ?, ?, ?, ?, ?, NULLIF(?,''), NULLIF(?,''), ?, NULLIF(?,0), ?, ?, ?, ?)";

            $stmt = $conn->prepare($sql);
            if ($stmt instanceof mysqli_stmt) {
                $estF = (float)$estimatedTotal;
                $depF = (float)$deposit;
                if ($hasEventImageColumn) {
                    $stmt->bind_param(
                        'sssssssssiisdds',
                        $eventNo,
                        $title,
                        $eventImagePath,
                        $clientName,
                        $clientPhone,
                        $clientEmail,
                        $eventDate,
                        $startTime,
                        $endTime,
                        $expectedGuests,
                        $functionRoomId,
                        $status,
                        $estF,
                        $depF,
                        $notes
                    );
                } else {
                    $stmt->bind_param(
                        'ssssssssiisdds',
                        $eventNo,
                        $title,
                        $clientName,
                        $clientPhone,
                        $clientEmail,
                        $eventDate,
                        $startTime,
                        $endTime,
                        $expectedGuests,
                        $functionRoomId,
                        $status,
                        $estF,
                        $depF,
                        $notes
                    );
                }
                $ok = $stmt->execute();
                $stmt->close();
                if ($ok) {
                    Flash::set('success', 'Event saved.');
                    Response::redirect('events_conferences.php');
                }
            }

            $errors['general'] = 'Failed to save event.';
        }
    }

    if ($action === 'update_event' && $hasEvents) {
        $eventId = (int)Request::post('id', 0);
        $title = trim((string)Request::post('title', ''));
        $eventImagePath = (string)Request::post('event_image_path', '');
        $clientUserId = (int)Request::post('client_user_id', 0);
        $clientName = trim((string)Request::post('client_name', ''));
        $clientPhone = trim((string)Request::post('client_phone', ''));
        $clientEmail = trim((string)Request::post('client_email', ''));
        $eventDate = trim((string)Request::post('event_date', ''));
        $startTime = trim((string)Request::post('start_time', ''));
        $endTime = trim((string)Request::post('end_time', ''));
        $expectedGuestsRaw = trim((string)Request::post('expected_guests', '0'));
        $expectedGuests = (int)$expectedGuestsRaw;
        $functionRoomId = (int)Request::post('function_room_id', 0);
        $status = (string)Request::post('status', 'Inquiry');
        $estimatedTotal = (string)Request::post('estimated_total', '0');
        $deposit = (string)Request::post('deposit_amount', '0');
        $notes = (string)Request::post('notes', '');

        if ($eventId <= 0) {
            $errors['general'] = 'Invalid event.';
        }

        $existingEventImagePath = '';
        if (empty($errors)) {
            $imgSel = $hasEventImageColumn ? 'image_path' : "'' AS image_path";
            $stmt = $conn->prepare("SELECT {$imgSel} FROM events WHERE id = ? LIMIT 1");
            if ($stmt instanceof mysqli_stmt) {
                $stmt->bind_param('i', $eventId);
                $stmt->execute();
                $r = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $existingEventImagePath = (string)($r['image_path'] ?? '');
            }
        }

        if (isset($_FILES['event_image']) && is_array($_FILES['event_image']) && (int)($_FILES['event_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $err = (int)($_FILES['event_image']['error'] ?? UPLOAD_ERR_OK);
            if ($err !== UPLOAD_ERR_OK) {
                $errors['event_image_path'] = 'Failed to upload image.';
            } else {
                $tmp = (string)($_FILES['event_image']['tmp_name'] ?? '');
                $orig = (string)($_FILES['event_image']['name'] ?? '');
                $size = (int)($_FILES['event_image']['size'] ?? 0);

                if ($size <= 0) {
                    $errors['event_image_path'] = 'Invalid image file.';
                } elseif ($size > (8 * 1024 * 1024)) {
                    $errors['event_image_path'] = 'Image must be 8MB or less.';
                } else {
                    $ext = strtolower((string)pathinfo($orig, PATHINFO_EXTENSION));
                    $allowed = ['jpg', 'jpeg', 'png', 'webp'];
                    if (!in_array($ext, $allowed, true)) {
                        $errors['event_image_path'] = 'Image must be JPG, PNG, or WEBP.';
                    } else {
                        $root = dirname(__DIR__, 2);
                        $uploadDir = $root . '/uploads/events';
                        if (!is_dir($uploadDir)) {
                            @mkdir($uploadDir, 0775, true);
                        }

                        if (!is_dir($uploadDir) || !is_writable($uploadDir)) {
                            $errors['event_image_path'] = 'Upload directory is not writable.';
                        } else {
                            $filename = 'event_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                            $dest = $uploadDir . '/' . $filename;
                            if (!move_uploaded_file($tmp, $dest)) {
                                $errors['event_image_path'] = 'Failed to save uploaded image.';
                            } else {
                                $eventImagePath = '/uploads/events/' . $filename;
                            }
                        }
                    }
                }
            }
        }

        if ($title === '') {
            $errors['title'] = 'Event title is required.';
        }
        if ($clientUserId <= 0) {
            $errors['client_user_id'] = 'Client account is required.';
        } else {
            $stmt = $conn->prepare(
                "SELECT u.username, u.email AS user_email, g.first_name, g.last_name, g.phone AS guest_phone, g.email AS guest_email
                 FROM users u
                 LEFT JOIN guests g ON g.id = u.guest_id
                 WHERE u.id = ?
                 LIMIT 1"
            );
            if ($stmt instanceof mysqli_stmt) {
                $stmt->bind_param('i', $clientUserId);
                $stmt->execute();
                $u = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                $fn = trim((string)($u['first_name'] ?? ''));
                $ln = trim((string)($u['last_name'] ?? ''));
                $nm = trim($fn . ' ' . $ln);
                if ($nm === '') {
                    $nm = trim((string)($u['username'] ?? ''));
                }
                $clientName = $nm;

                $clientPhone = trim((string)($u['guest_phone'] ?? ''));
                $clientPhone = preg_replace('/\D+/', '', $clientPhone);

                $clientEmail = trim((string)($u['guest_email'] ?? ''));
                if ($clientEmail === '') {
                    $clientEmail = trim((string)($u['user_email'] ?? ''));
                }
            }
        }

        if ($clientName === '') {
            $errors['client_user_id'] = 'Client account is required.';
        }
        if ($clientPhone === '' || !preg_match('/^\d{10}$/', $clientPhone)) {
            $errors['client_phone'] = 'Phone must be exactly 10 digits.';
        }
        if ($clientEmail === '' || !preg_match('/^[A-Z0-9._%+-]+@gmail\.com$/i', $clientEmail)) {
            $errors['client_email'] = 'Email must be a valid @gmail.com address.';
        }
        if ($eventDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $eventDate)) {
            $errors['event_date'] = 'Event date is invalid.';
        }
        if ($expectedGuestsRaw === '' || !ctype_digit($expectedGuestsRaw) || $expectedGuests < 0) {
            $errors['expected_guests'] = 'Expected guests is invalid.';
        }

        if (empty($errors['expected_guests']) && $functionRoomId > 0) {
            $stmt = $conn->prepare('SELECT capacity FROM function_rooms WHERE id = ? LIMIT 1');
            if ($stmt instanceof mysqli_stmt) {
                $stmt->bind_param('i', $functionRoomId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $cap = (int)($row['capacity'] ?? 0);
                if ($cap > 0 && $expectedGuests > $cap) {
                    $errors['expected_guests'] = 'Expected guests cannot exceed the function room capacity.';
                }
            }
        }

        if ($startTime !== '' && !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $startTime)) {
            $errors['start_time'] = 'Start time is invalid.';
        }
        if ($endTime !== '' && !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $endTime)) {
            $errors['end_time'] = 'End time is invalid.';
        }
        if ($startTime !== '' && $endTime !== '' && empty($errors['start_time']) && empty($errors['end_time'])) {
            $t1 = strtotime('1970-01-01 ' . $startTime);
            $t2 = strtotime('1970-01-01 ' . $endTime);
            if ($t1 !== false && $t2 !== false && $t2 <= $t1) {
                $errors['end_time'] = 'End time must be after start time.';
            }
        }

        if (!in_array($status, ['Inquiry', 'Quoted', 'Confirmed', 'Ongoing', 'Completed', 'Cancelled'], true)) {
            $errors['status'] = 'Status is invalid.';
        }

        if ($functionRoomId > 0) {
            $stmt = $conn->prepare('SELECT base_rate FROM function_rooms WHERE id = ? LIMIT 1');
            if ($stmt instanceof mysqli_stmt) {
                $stmt->bind_param('i', $functionRoomId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $rateF = (float)($row['base_rate'] ?? 0);
                if ($rateF < 0) {
                    $rateF = 0;
                }
                $estimatedTotal = (string)$rateF;
                $deposit = (string)round($rateF * 0.20, 2);
            }
        }

        if (!is_numeric($estimatedTotal) || (float)$estimatedTotal < 0) {
            $errors['estimated_total'] = 'Estimated total is invalid.';
        }
        if (!is_numeric($deposit) || (float)$deposit < 0) {
            $errors['deposit_amount'] = 'Deposit amount is invalid.';
        }

        if (empty($errors) && $functionRoomId > 0) {
            $startForCheck = $startTime !== '' ? $startTime : '00:00:00';
            $endForCheck = $endTime !== '' ? $endTime : '23:59:59';

            $stmt = $conn->prepare(
                "SELECT COUNT(*) AS c
                 FROM events
                 WHERE function_room_id = ?
                   AND event_date = ?
                   AND id <> ?
                   AND status NOT IN ('Cancelled','Completed')
                   AND COALESCE(start_time, '00:00:00') < ?
                   AND COALESCE(end_time, '23:59:59') > ?"
            );
            if ($stmt instanceof mysqli_stmt) {
                $stmt->bind_param('isiss', $functionRoomId, $eventDate, $eventId, $endForCheck, $startForCheck);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $conflicts = (int)($row['c'] ?? 0);
                if ($conflicts > 0) {
                    $errors['function_room_id'] = 'Selected function room is already booked for this schedule.';
                }
            }
        }

        if (empty($errors)) {
            $estF = (float)$estimatedTotal;
            $depF = (float)$deposit;

            if ($hasEventImageColumn) {
                $finalImagePath = $eventImagePath !== '' ? $eventImagePath : $existingEventImagePath;
                $stmt = $conn->prepare(
                    "UPDATE events
                     SET title = ?, image_path = NULLIF(?,''), client_name = ?, client_phone = ?, client_email = ?, event_date = ?,
                         start_time = NULLIF(?,''), end_time = NULLIF(?,''), expected_guests = ?, function_room_id = NULLIF(?,0),
                         status = ?, estimated_total = ?, deposit_amount = ?, notes = ?
                     WHERE id = ?"
                );
                if ($stmt instanceof mysqli_stmt) {
                    $stmt->bind_param('ssssssssiisddsi', $title, $finalImagePath, $clientName, $clientPhone, $clientEmail, $eventDate, $startTime, $endTime, $expectedGuests, $functionRoomId, $status, $estF, $depF, $notes, $eventId);
                    $ok = $stmt->execute();
                    $stmt->close();
                    if ($ok) {
                        Flash::set('success', 'Event updated.');
                        Response::redirect('events_conferences.php');
                    }
                }
            } else {
                $stmt = $conn->prepare(
                    "UPDATE events
                     SET title = ?, client_name = ?, client_phone = ?, client_email = ?, event_date = ?,
                         start_time = NULLIF(?,''), end_time = NULLIF(?,''), expected_guests = ?, function_room_id = NULLIF(?,0),
                         status = ?, estimated_total = ?, deposit_amount = ?, notes = ?
                     WHERE id = ?"
                );
                if ($stmt instanceof mysqli_stmt) {
                    $stmt->bind_param('sssssssiisddsi', $title, $clientName, $clientPhone, $clientEmail, $eventDate, $startTime, $endTime, $expectedGuests, $functionRoomId, $status, $estF, $depF, $notes, $eventId);
                    $ok = $stmt->execute();
                    $stmt->close();
                    if ($ok) {
                        Flash::set('success', 'Event updated.');
                        Response::redirect('events_conferences.php');
                    }
                }
            }

            $errors['general'] = 'Failed to update event.';
        }
    }

    if ($action === 'delete_event' && $hasEvents) {
        $eventId = (int)Request::post('id', 0);
        if ($eventId <= 0) {
            $errors['general'] = 'Invalid event.';
        } else {
            $stmt = $conn->prepare('DELETE FROM events WHERE id = ? LIMIT 1');
            if ($stmt instanceof mysqli_stmt) {
                $stmt->bind_param('i', $eventId);
                $ok = $stmt->execute();
                $stmt->close();
                if ($ok) {
                    Flash::set('success', 'Event deleted.');
                    Response::redirect('events_conferences.php');
                }
            }
            $errors['general'] = 'Failed to delete event.';
        }
    }
}

$functionRooms = [];
$clientUsers = [];
$eventsUpcoming = [];
$eventsPipeline = [];

$kpiRooms = 0;
$kpiUpcoming = 0;
$kpiInquiries = 0;
$kpiEstRevenueMonth = 0.0;

if ($conn) {
    $res = $conn->query(
        "SELECT u.id, u.username, u.role, u.email AS user_email, g.first_name, g.last_name, g.phone AS guest_phone, g.email AS guest_email
         FROM users u
         LEFT JOIN guests g ON g.id = u.guest_id
         ORDER BY u.username ASC
         LIMIT 500"
    );
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $clientUsers[] = $row;
        }
    }
}

if ($conn && $hasFunctionRooms) {
    $roomImageSelect = $hasFunctionRoomImageColumn ? 'image_path' : 'NULL AS image_path';
    $res = $conn->query(
        "SELECT fr.id, fr.name, fr.capacity, fr.base_rate, {$roomImageSelect}, fr.status, fr.is_active, fr.notes, fr.created_at,
                (
                    SELECT e.status
                    FROM events e
                    WHERE e.function_room_id = fr.id
                      AND e.status IN ('Inquiry','Quoted','Confirmed','Ongoing')
                      AND e.event_date >= CURDATE()
                    ORDER BY FIELD(e.status,'Ongoing','Confirmed','Quoted','Inquiry'), e.event_date ASC, e.start_time ASC
                    LIMIT 1
                ) AS active_event_status
         FROM function_rooms fr
         ORDER BY fr.id DESC"
    );
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $functionRooms[] = $row;
        }
    }
    $kpiRooms = count($functionRooms);

    if ($editFunctionRoomId > 0) {
        $stmt = $conn->prepare("SELECT id, name, capacity, base_rate, {$roomImageSelect}, status, is_active, notes FROM function_rooms WHERE id = ? LIMIT 1");
        if ($stmt instanceof mysqli_stmt) {
            $stmt->bind_param('i', $editFunctionRoomId);
            $stmt->execute();
            $editingFunctionRoom = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }
    }
}

if ($conn && $hasEvents) {
    $eventImageExpr = $hasEventImageColumn ? 'e.image_path' : 'NULL';
    $functionRoomImageSelect = $hasFunctionRoomImageColumn ? 'fr.image_path AS function_room_image_path' : 'NULL AS function_room_image_path';
    $res = $conn->query(
        "SELECT e.id, e.event_no, e.title, {$eventImageExpr} AS image_path, e.client_name, e.event_date, e.start_time, e.end_time, e.status,
                e.expected_guests, e.estimated_total, e.deposit_amount,
                fr.name AS function_room_name, {$functionRoomImageSelect}
         FROM events e
         LEFT JOIN function_rooms fr ON fr.id = e.function_room_id
         WHERE e.event_date >= CURDATE() AND e.status NOT IN ('Cancelled','Completed')
         ORDER BY e.event_date ASC, e.start_time ASC
         LIMIT 10"
    );
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $eventsUpcoming[] = $row;
        }
    }

    $res = $conn->query(
        "SELECT e.id, e.event_no, e.title, {$eventImageExpr} AS image_path, e.client_name, e.event_date, e.status, e.estimated_total,
                fr.name AS function_room_name, {$functionRoomImageSelect}
         FROM events e
         LEFT JOIN function_rooms fr ON fr.id = e.function_room_id
         WHERE e.status IN ('Inquiry','Quoted','Confirmed','Ongoing')
         ORDER BY FIELD(e.status,'Inquiry','Quoted','Confirmed','Ongoing'), e.event_date ASC
         LIMIT 12"
    );
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $eventsPipeline[] = $row;
        }
    }

    $res = $conn->query("SELECT COUNT(*) AS c FROM events WHERE status = 'Inquiry'");
    $kpiInquiries = $res ? (int)($res->fetch_assoc()['c'] ?? 0) : 0;
    $kpiUpcoming = count($eventsUpcoming);

    $res = $conn->query(
        "SELECT COALESCE(SUM(estimated_total),0) AS s
         FROM events
         WHERE status IN ('Confirmed','Ongoing','Completed')
           AND DATE_FORMAT(event_date,'%Y-%m') = DATE_FORMAT(CURDATE(),'%Y-%m')"
    );
    $kpiEstRevenueMonth = $res ? (float)($res->fetch_assoc()['s'] ?? 0) : 0.0;

    if ($editEventId > 0) {
        $sql =
            "SELECT id, event_no, title, {$eventImageExpr} AS image_path, client_name, client_phone, client_email, event_date, start_time, end_time,
                    expected_guests, function_room_id, status, estimated_total, deposit_amount, notes
             FROM events
             WHERE id = ?
             LIMIT 1";

        $stmt = null;
        try {
            $stmt = $conn->prepare($sql);
        } catch (Throwable $e) {
            $sql =
                "SELECT id, event_no, title, NULL AS image_path, client_name, client_phone, client_email, event_date, start_time, end_time,
                        expected_guests, function_room_id, status, estimated_total, deposit_amount, notes
                 FROM events
                 WHERE id = ?
                 LIMIT 1";
            $stmt = $conn->prepare($sql);
        }
        if ($stmt instanceof mysqli_stmt) {
            $stmt->bind_param('i', $editEventId);
            $stmt->execute();
            $editingEvent = $stmt->get_result()->fetch_assoc();
            $stmt->close();
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
            <h1 class="text-2xl font-light text-gray-900">Events & Conferences</h1>
            <p class="text-sm text-gray-500 mt-1">Event bookings, function rooms, packages, schedules</p>
        </div>

        <?php $flash = Flash::get(); ?>
        <?php if ($flash): ?>
            <div class="mb-4 rounded-lg border px-4 py-3 text-sm <?= $flash['type'] === 'success' ? 'border-green-200 bg-green-50 text-green-800' : 'border-red-200 bg-red-50 text-red-800' ?>">
                <?= htmlspecialchars($flash['message']) ?>
            </div>
        <?php endif; ?>

        <?php if (isset($errors['general'])): ?>
            <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                <?= htmlspecialchars($errors['general']) ?>
            </div>
        <?php endif; ?>

        <?php if (!$hasFunctionRooms || !$hasEvents): ?>
            <div class="mb-4 rounded-lg border border-yellow-200 bg-yellow-50 px-4 py-3 text-sm text-yellow-900">
                This module needs DB tables/columns. Run the updated schema SQL to create <span class="font-medium">function_rooms</span> and <span class="font-medium">events</span> tables (with image columns).
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            <div class="dashboard-card bg-white rounded-lg border border-gray-100 p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-500">Function Rooms</p>
                        <p class="text-2xl font-light text-gray-900"><?= (int)$kpiRooms ?></p>
                    </div>
                    <div class="icon-container w-12 h-12 bg-blue-50 rounded-lg flex items-center justify-center">
                        <i class='bx bx-buildings text-blue-600 text-xl'></i>
                    </div>
                </div>
            </div>

            <div class="dashboard-card bg-white rounded-lg border border-gray-100 p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-500">Upcoming Events</p>
                        <p class="text-2xl font-light text-gray-900"><?= (int)$kpiUpcoming ?></p>
                        <p class="text-xs text-gray-500 mt-1">Next 10 events</p>
                    </div>
                    <div class="icon-container w-12 h-12 bg-green-50 rounded-lg flex items-center justify-center">
                        <i class='bx bx-calendar-event text-green-600 text-xl'></i>
                    </div>
                </div>
            </div>

            <div class="dashboard-card bg-white rounded-lg border border-gray-100 p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-500">Inquiries</p>
                        <p class="text-2xl font-light text-gray-900"><?= (int)$kpiInquiries ?></p>
                        <p class="text-xs text-gray-500 mt-1">New leads</p>
                    </div>
                    <div class="icon-container w-12 h-12 bg-orange-50 rounded-lg flex items-center justify-center">
                        <i class='bx bx-message-rounded-dots text-orange-600 text-xl'></i>
                    </div>
                </div>
            </div>

            <div class="dashboard-card bg-white rounded-lg border border-gray-100 p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-500">Est. Revenue (Month)</p>
                        <p class="text-2xl font-light text-gray-900">₱<?= number_format((float)$kpiEstRevenueMonth, 2) ?></p>
                        <p class="text-xs text-gray-500 mt-1">Confirmed/Ongoing/Completed</p>
                    </div>
                    <div class="icon-container w-12 h-12 bg-purple-50 rounded-lg flex items-center justify-center">
                        <i class='bx bx-money text-purple-600 text-xl'></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div class="chart-container">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-medium text-gray-900">Event Pipeline</h3>
                    <div class="text-xs text-gray-500">Inquiry → Quoted → Confirmed → Ongoing</div>
                </div>

                <?php if (empty($eventsPipeline)): ?>
                    <div class="text-sm text-gray-500">No pipeline items yet.</div>
                <?php else: ?>
                    <div class="space-y-3">
                        <?php foreach ($eventsPipeline as $e): ?>
                            <?php
                                $st = (string)($e['status'] ?? '');
                                $badge = 'border-gray-200 bg-gray-50 text-gray-700';
                                if ($st === 'Inquiry') $badge = 'border-orange-200 bg-orange-50 text-orange-700';
                                if ($st === 'Quoted') $badge = 'border-blue-200 bg-blue-50 text-blue-700';
                                if ($st === 'Confirmed') $badge = 'border-green-200 bg-green-50 text-green-700';
                                if ($st === 'Ongoing') $badge = 'border-purple-200 bg-purple-50 text-purple-700';

                                $thumb = trim((string)($e['image_path'] ?? ''));
                                if ($thumb === '') {
                                    $thumb = trim((string)($e['function_room_image_path'] ?? ''));
                                }
                                if ($thumb !== '' && !preg_match('/^https?:\/\//i', $thumb)) {
                                    $thumb = $APP_BASE_URL . $thumb;
                                }
                            ?>
                            <div class="rounded-lg border border-gray-100 p-4 hover:bg-gray-50 transition">
                                <div class="flex items-start justify-between gap-4">
                                    <div class="flex items-start gap-3">
                                        <div class="w-14 h-12 rounded-lg border border-gray-100 bg-gray-50 overflow-hidden flex items-center justify-center flex-shrink-0">
                                            <?php if ($thumb !== ''): ?>
                                                <img src="<?= htmlspecialchars($thumb) ?>" alt="" style="height:100%;width:100%;object-fit:cover;" />
                                            <?php else: ?>
                                                <div class="text-[10px] text-gray-400">No image</div>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars((string)($e['title'] ?? '')) ?></div>
                                            <div class="text-xs text-gray-500 mt-1"><?= htmlspecialchars((string)($e['client_name'] ?? '')) ?> • <?= htmlspecialchars((string)($e['event_date'] ?? '')) ?></div>
                                            <div class="text-xs text-gray-500 mt-1"><?= htmlspecialchars((string)($e['function_room_name'] ?? 'Unassigned')) ?></div>
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs border <?= htmlspecialchars($badge) ?>"><?= htmlspecialchars($st) ?></span>
                                        <div class="text-xs text-gray-500 mt-2">₱<?= number_format((float)($e['estimated_total'] ?? 0), 2) ?></div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="chart-container">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-medium text-gray-900">Upcoming Schedule</h3>
                    <div class="text-xs text-gray-500">Next 10 events</div>
                </div>

                <?php if (empty($eventsUpcoming)): ?>
                    <div class="text-sm text-gray-500">No upcoming events yet.</div>
                <?php else: ?>
                    <div class="overflow-auto rounded-lg border border-gray-100">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50 text-gray-600">
                                <tr>
                                    <th class="text-left font-medium px-4 py-3">Date</th>
                                    <th class="text-left font-medium px-4 py-3">Event</th>
                                    <th class="text-left font-medium px-4 py-3">Room</th>
                                    <th class="text-right font-medium px-4 py-3">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <?php foreach ($eventsUpcoming as $e): ?>
                                    <?php
                                        $thumb = trim((string)($e['image_path'] ?? ''));
                                        if ($thumb === '') {
                                            $thumb = trim((string)($e['function_room_image_path'] ?? ''));
                                        }
                                        if ($thumb !== '' && !preg_match('/^https?:\/\//i', $thumb)) {
                                            $thumb = $APP_BASE_URL . $thumb;
                                        }
                                    ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-3 text-gray-700">
                                            <div class="font-medium"><?= htmlspecialchars((string)($e['event_date'] ?? '')) ?></div>
                                            <div class="text-xs text-gray-500"><?= htmlspecialchars(trim((string)($e['start_time'] ?? ''))) ?></div>
                                        </td>
                                        <td class="px-4 py-3">
                                            <div class="flex items-center gap-3">
                                                <div class="w-12 h-10 rounded-lg border border-gray-100 bg-gray-50 overflow-hidden flex items-center justify-center flex-shrink-0">
                                                    <?php if ($thumb !== ''): ?>
                                                        <img src="<?= htmlspecialchars($thumb) ?>" alt="" style="height:100%;width:100%;object-fit:cover;" />
                                                    <?php else: ?>
                                                        <div class="text-[10px] text-gray-400">No image</div>
                                                    <?php endif; ?>
                                                </div>
                                                <div>
                                                    <div class="text-gray-900 font-medium"><?= htmlspecialchars((string)($e['title'] ?? '')) ?></div>
                                                    <div class="text-xs text-gray-500 mt-1"><?= htmlspecialchars((string)($e['client_name'] ?? '')) ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-4 py-3 text-gray-700"><?= htmlspecialchars((string)($e['function_room_name'] ?? 'Unassigned')) ?></td>
                                        <td class="px-4 py-3 text-right text-gray-700"><?= htmlspecialchars((string)($e['status'] ?? '')) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mt-6">
            <div class="bg-white rounded-lg border border-gray-100 p-6 lg:col-span-1">
                <h3 class="text-lg font-medium text-gray-900 mb-4"><?= $editingFunctionRoom ? 'Edit Function Room' : 'Add Function Room' ?></h3>
                <form method="post" enctype="multipart/form-data" class="space-y-3" data-form="function_room">
                    <input type="hidden" name="action" value="<?= $editingFunctionRoom ? 'update_function_room' : 'create_function_room' ?>" />
                    <?php if ($editingFunctionRoom): ?>
                        <input type="hidden" name="id" value="<?= (int)($editingFunctionRoom['id'] ?? 0) ?>" />
                    <?php endif; ?>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Name</label>
                        <input name="name" required value="<?= htmlspecialchars((string)($editingFunctionRoom['name'] ?? '')) ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Capacity</label>
                        <input name="capacity" required type="number" min="0" step="1" value="<?= htmlspecialchars((string)($editingFunctionRoom['capacity'] ?? '0')) ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Pricing Tier</label>
                        <input type="hidden" name="pricing_tier" value="Standard" />
                        <select name="pricing_tier" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
                            <option value="Budget">Budget</option>
                            <option value="Standard">Standard</option>
                            <option value="Premium">Premium</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Base Rate</label>
                        <input name="base_rate" required readonly type="number" min="0" step="0.01" value="<?= htmlspecialchars((string)($editingFunctionRoom['base_rate'] ?? '0')) ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm bg-gray-50" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Upload Image (optional)</label>
                        <input type="file" name="room_image" accept="image/*" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                        <?php if (isset($errors['image_path'])): ?>
                            <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['image_path']) ?></div>
                        <?php endif; ?>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Image Path (optional)</label>
                        <input name="image_path" value="<?= htmlspecialchars((string)($editingFunctionRoom['image_path'] ?? '')) ?>" placeholder="e.g. /uploads/events/function_rooms/hall.webp" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                        <select name="status" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
                            <?php $frStatus = (string)($editingFunctionRoom['status'] ?? 'Available'); ?>
                            <option value="Available" <?= $frStatus === 'Available' ? 'selected' : '' ?>>Available</option>
                            <option value="Maintenance" <?= $frStatus === 'Maintenance' ? 'selected' : '' ?>>Maintenance</option>
                        </select>
                    </div>
                    <div class="flex items-center gap-2">
                        <input type="hidden" name="is_active" value="0" />
                        <?php $frActive = $editingFunctionRoom ? ((int)($editingFunctionRoom['is_active'] ?? 1) === 1) : true; ?>
                        <input type="checkbox" name="is_active" value="1" class="h-4 w-4" <?= $frActive ? 'checked' : '' ?> />
                        <label class="text-sm text-gray-700">Active</label>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                        <textarea name="notes" rows="3" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm"><?= htmlspecialchars((string)($editingFunctionRoom['notes'] ?? '')) ?></textarea>
                    </div>
                    <button class="w-full px-4 py-2 rounded-lg bg-blue-600 text-white text-sm hover:bg-blue-700 transition"><?= $editingFunctionRoom ? 'Update Room' : 'Save Room' ?></button>
                    <?php if ($editingFunctionRoom): ?>
                        <a href="events_conferences.php" class="block w-full text-center px-4 py-2 rounded-lg border border-gray-200 text-sm hover:bg-gray-50 transition">Cancel</a>
                    <?php endif; ?>
                </form>
            </div>

            <div class="bg-white rounded-lg border border-gray-100 p-6 lg:col-span-2">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-medium text-gray-900">Function Rooms</h3>
                    <div class="text-xs text-gray-500">List</div>
                </div>
                <?php if (empty($functionRooms)): ?>
                    <div class="text-sm text-gray-500">No function rooms yet.</div>
                <?php else: ?>
                    <div class="overflow-auto rounded-lg border border-gray-100">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50 text-gray-600">
                                <tr>
                                    <th class="text-left font-medium px-4 py-3">Venue</th>
                                    <th class="text-right font-medium px-4 py-3">Capacity</th>
                                    <th class="text-right font-medium px-4 py-3">Rate</th>
                                    <th class="text-right font-medium px-4 py-3">Status</th>
                                    <th class="text-right font-medium px-4 py-3">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <?php foreach ($functionRooms as $fr): ?>
                                    <?php
                                        $img = trim((string)($fr['image_path'] ?? ''));
                                        if ($img !== '' && !preg_match('/^https?:\/\//i', $img)) {
                                            $img = $APP_BASE_URL . $img;
                                        }
                                    ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-3">
                                            <div class="flex items-center gap-3">
                                                <div class="w-12 h-10 rounded-lg border border-gray-100 bg-gray-50 overflow-hidden flex items-center justify-center">
                                                    <?php if ($img !== ''): ?>
                                                        <img src="<?= htmlspecialchars($img) ?>" alt="" style="height:100%;width:100%;object-fit:cover;" />
                                                    <?php else: ?>
                                                        <div class="text-[10px] text-gray-400">No image</div>
                                                    <?php endif; ?>
                                                </div>
                                                <div>
                                                    <div class="text-gray-900 font-medium"><?= htmlspecialchars((string)($fr['name'] ?? '')) ?></div>
                                                    <?php $frNotes = trim((string)($fr['notes'] ?? '')); ?>
                                                    <?php if ($frNotes !== ''): ?>
                                                        <div class="text-xs text-gray-500 mt-1"><?= htmlspecialchars($frNotes) ?></div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-4 py-3 text-right text-gray-700"><?= (int)($fr['capacity'] ?? 0) ?></td>
                                        <td class="px-4 py-3 text-right text-gray-700">₱<?= number_format((float)($fr['base_rate'] ?? 0), 2) ?></td>
                                        <?php
                                            $baseStatus = (string)($fr['status'] ?? '');
                                            $activeEventStatus = (string)($fr['active_event_status'] ?? '');
                                            $displayStatus = $baseStatus;
                                            if ($baseStatus !== 'Maintenance' && $activeEventStatus !== '') {
                                                $displayStatus = $activeEventStatus;
                                            }
                                        ?>
                                        <td class="px-4 py-3 text-right text-gray-700"><?= htmlspecialchars($displayStatus) ?></td>
                                        <td class="px-4 py-3 text-right">
                                            <div class="flex items-center justify-end gap-2">
                                                <a class="px-3 py-1.5 rounded-lg border border-gray-200 text-xs hover:bg-gray-50 transition" href="events_conferences.php?edit_room_id=<?= (int)($fr['id'] ?? 0) ?>">Edit</a>
                                                <form method="post" class="js-confirm-delete" data-confirm-title="Delete Function Room" data-confirm-message="Delete this function room?">
                                                    <input type="hidden" name="action" value="delete_function_room" />
                                                    <input type="hidden" name="id" value="<?= (int)($fr['id'] ?? 0) ?>" />
                                                    <button class="px-3 py-1.5 rounded-lg border border-red-200 text-xs text-red-700 hover:bg-red-50 transition">Delete</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mt-6">
            <div class="bg-white rounded-lg border border-gray-100 p-6 lg:col-span-1">
                <h3 class="text-lg font-medium text-gray-900 mb-4"><?= $editingEvent ? 'Edit Event' : 'Add Event' ?></h3>
                <form method="post" enctype="multipart/form-data" class="space-y-3">
                    <input type="hidden" name="action" value="<?= $editingEvent ? 'update_event' : 'create_event' ?>" />
                    <?php if ($editingEvent): ?>
                        <input type="hidden" name="id" value="<?= (int)($editingEvent['id'] ?? 0) ?>" />
                    <?php endif; ?>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Title</label>
                        <input name="title" required value="<?= htmlspecialchars((string)($editingEvent['title'] ?? '')) ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Upload Image (optional)</label>
                        <input type="file" name="event_image" accept="image/*" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                        <?php if (isset($errors['event_image_path'])): ?>
                            <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['event_image_path']) ?></div>
                        <?php endif; ?>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Image Path (optional)</label>
                        <input name="event_image_path" value="<?= htmlspecialchars((string)($editingEvent['image_path'] ?? '')) ?>" placeholder="e.g. /uploads/events/event.webp" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Client Account</label>
                        <select name="client_user_id" required class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
                            <option value="">Select client account</option>
                            <?php
                                $selectedClientUserId = 0;
                                if ($editingEvent) {
                                    $em = trim((string)($editingEvent['client_email'] ?? ''));
                                    if ($em !== '' && $conn) {
                                        $stmt = $conn->prepare("SELECT u.id
                                            FROM users u
                                            LEFT JOIN guests g ON g.id = u.guest_id
                                            WHERE u.email = ? OR g.email = ?
                                            LIMIT 1");
                                        if ($stmt instanceof mysqli_stmt) {
                                            $stmt->bind_param('ss', $em, $em);
                                            $stmt->execute();
                                            $r = $stmt->get_result()->fetch_assoc();
                                            $stmt->close();
                                            $selectedClientUserId = (int)($r['id'] ?? 0);
                                        }
                                    }
                                }
                            ?>
                            <?php foreach ($clientUsers as $cu): ?>
                                <?php
                                    $fn = trim((string)($cu['first_name'] ?? ''));
                                    $ln = trim((string)($cu['last_name'] ?? ''));
                                    $displayName = trim($fn . ' ' . $ln);
                                    if ($displayName === '') {
                                        $displayName = (string)($cu['username'] ?? '');
                                    }
                                    $phone = (string)($cu['guest_phone'] ?? '');
                                    $email = (string)($cu['guest_email'] ?? '');
                                    if ($email === '') {
                                        $email = (string)($cu['user_email'] ?? '');
                                    }
                                ?>
                                <option
                                    value="<?= (int)($cu['id'] ?? 0) ?>"
                                    data-client-name="<?= htmlspecialchars($displayName) ?>"
                                    data-client-phone="<?= htmlspecialchars($phone) ?>"
                                    data-client-email="<?= htmlspecialchars($email) ?>"
                                    <?= ((int)($cu['id'] ?? 0) === (int)$selectedClientUserId) ? 'selected' : '' ?>
                                >
                                    <?= htmlspecialchars($displayName) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Client Name</label>
                        <input name="client_name" readonly value="<?= htmlspecialchars((string)($editingEvent['client_name'] ?? '')) ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm bg-gray-50" />
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
                            <input name="client_phone" readonly required type="tel" maxlength="10" value="<?= htmlspecialchars((string)($editingEvent['client_phone'] ?? '')) ?>" inputmode="numeric" pattern="[0-9]{10}" placeholder="10 digits" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm bg-gray-50" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                            <input name="client_email" readonly required value="<?= htmlspecialchars((string)($editingEvent['client_email'] ?? '')) ?>" type="email" pattern="[A-Za-z0-9._%+-]+@gmail\.com" placeholder="name@gmail.com" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm bg-gray-50" />
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Event Date</label>
                        <input type="date" name="event_date" required value="<?= htmlspecialchars((string)($editingEvent['event_date'] ?? '')) ?>" min="2000-01-01" max="2100-12-31" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Start</label>
                            <input type="time" name="start_time" value="<?= htmlspecialchars(trim((string)($editingEvent['start_time'] ?? ''))) ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">End</label>
                            <input type="time" name="end_time" value="<?= htmlspecialchars(trim((string)($editingEvent['end_time'] ?? ''))) ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Expected Guests</label>
                        <input name="expected_guests" required type="number" min="0" step="1" value="<?= htmlspecialchars((string)($editingEvent['expected_guests'] ?? '0')) ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Function Room</label>
                        <select name="function_room_id" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
                            <option value="0">Unassigned</option>
                            <?php foreach ($functionRooms as $fr): ?>
                                <?php $selRoom = $editingEvent ? ((int)($editingEvent['function_room_id'] ?? 0) === (int)($fr['id'] ?? 0)) : false; ?>
                                <option value="<?= (int)($fr['id'] ?? 0) ?>" data-base-rate="<?= htmlspecialchars((string)($fr['base_rate'] ?? '0')) ?>" data-capacity="<?= htmlspecialchars((string)($fr['capacity'] ?? '0')) ?>" <?= $selRoom ? 'selected' : '' ?>><?= htmlspecialchars((string)($fr['name'] ?? '')) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                        <select name="status" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
                            <?php $evStatus = (string)($editingEvent['status'] ?? 'Inquiry'); ?>
                            <option value="Inquiry" <?= $evStatus === 'Inquiry' ? 'selected' : '' ?>>Inquiry</option>
                            <option value="Quoted" <?= $evStatus === 'Quoted' ? 'selected' : '' ?>>Quoted</option>
                            <option value="Confirmed" <?= $evStatus === 'Confirmed' ? 'selected' : '' ?>>Confirmed</option>
                            <option value="Ongoing" <?= $evStatus === 'Ongoing' ? 'selected' : '' ?>>Ongoing</option>
                            <option value="Completed" <?= $evStatus === 'Completed' ? 'selected' : '' ?>>Completed</option>
                            <option value="Cancelled" <?= $evStatus === 'Cancelled' ? 'selected' : '' ?>>Cancelled</option>
                        </select>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Estimated Total</label>
                            <input name="estimated_total" type="number" min="0" step="0.01" value="<?= htmlspecialchars((string)($editingEvent['estimated_total'] ?? '0')) ?>" readonly class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm bg-gray-50" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Deposit</label>
                            <input name="deposit_amount" type="number" min="0" step="0.01" value="<?= htmlspecialchars((string)($editingEvent['deposit_amount'] ?? '0')) ?>" readonly class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm bg-gray-50" />
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                        <textarea name="notes" rows="3" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm"><?= htmlspecialchars((string)($editingEvent['notes'] ?? '')) ?></textarea>
                    </div>
                    <button class="w-full px-4 py-2 rounded-lg bg-green-600 text-white text-sm hover:bg-green-700 transition"><?= $editingEvent ? 'Update Event' : 'Save Event' ?></button>
                    <?php if ($editingEvent): ?>
                        <a href="events_conferences.php" class="block w-full text-center px-4 py-2 rounded-lg border border-gray-200 text-sm hover:bg-gray-50 transition">Cancel</a>
                    <?php endif; ?>
                </form>
            </div>

            <div class="bg-white rounded-lg border border-gray-100 p-6 lg:col-span-2">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-medium text-gray-900">All Events (Recent)</h3>
                    <div class="text-xs text-gray-500">Pipeline + schedule</div>
                </div>
                <?php if (!$hasEvents): ?>
                    <div class="text-sm text-gray-500">Events table not found yet.</div>
                <?php else: ?>
                    <?php
                        $eventsRecent = [];
                        if ($conn) {
                            $eventImageSelect = $hasEventImageColumn ? 'e.image_path' : 'NULL AS image_path';
                            $functionRoomImageSelect = $hasFunctionRoomImageColumn ? 'fr.image_path AS function_room_image_path' : 'NULL AS function_room_image_path';
                            $res = $conn->query(
                                "SELECT e.id, e.event_no, e.title, {$eventImageSelect}, e.client_name, e.client_phone, e.client_email, e.event_date, e.start_time, e.end_time,
                                        e.expected_guests, e.deposit_amount, e.status, e.estimated_total, e.notes,
                                        fr.name AS function_room_name, {$functionRoomImageSelect}
                                 FROM events e
                                 LEFT JOIN function_rooms fr ON fr.id = e.function_room_id
                                 ORDER BY e.id DESC
                                 LIMIT 15"
                            );
                            if ($res) {
                                while ($row = $res->fetch_assoc()) {
                                    $eventsRecent[] = $row;
                                }
                            }
                        }
                    ?>
                    <?php if (empty($eventsRecent)): ?>
                        <div class="text-sm text-gray-500">No events yet.</div>
                    <?php else: ?>
                        <div class="overflow-auto rounded-lg border border-gray-100">
                            <table class="min-w-full text-sm">
                                <thead class="bg-gray-50 text-gray-600">
                                    <tr>
                                        <th class="text-left font-medium px-4 py-3">Event</th>
                                        <th class="text-left font-medium px-4 py-3">Client</th>
                                        <th class="text-left font-medium px-4 py-3">Date/Time</th>
                                        <th class="text-left font-medium px-4 py-3">Room</th>
                                        <th class="text-right font-medium px-4 py-3">Guests</th>
                                        <th class="text-right font-medium px-4 py-3">Deposit</th>
                                        <th class="text-right font-medium px-4 py-3">Total</th>
                                        <th class="text-right font-medium px-4 py-3">Status</th>
                                        <th class="text-right font-medium px-4 py-3">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <?php foreach ($eventsRecent as $e): ?>
                                        <?php
                                            $thumb = trim((string)($e['image_path'] ?? ''));
                                            if ($thumb === '') {
                                                $thumb = trim((string)($e['function_room_image_path'] ?? ''));
                                            }
                                            if ($thumb !== '' && !preg_match('/^https?:\/\//i', $thumb)) {
                                                $thumb = $APP_BASE_URL . $thumb;
                                            }
                                        ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-4 py-3">
                                                <div class="flex items-center gap-3">
                                                    <div class="w-12 h-10 rounded-lg border border-gray-100 bg-gray-50 overflow-hidden flex items-center justify-center flex-shrink-0">
                                                        <?php if ($thumb !== ''): ?>
                                                            <img src="<?= htmlspecialchars($thumb) ?>" alt="" style="height:100%;width:100%;object-fit:cover;" />
                                                        <?php else: ?>
                                                            <div class="text-[10px] text-gray-400">No image</div>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div>
                                                        <div class="font-medium text-gray-900"><?= htmlspecialchars((string)($e['title'] ?? '')) ?></div>
                                                        <div class="text-xs text-gray-500 mt-1"><?= htmlspecialchars((string)($e['event_no'] ?? '')) ?></div>
                                                        <?php $evNotes = trim((string)($e['notes'] ?? '')); ?>
                                                        <?php if ($evNotes !== ''): ?>
                                                            <div class="text-xs text-gray-500 mt-1"><?= htmlspecialchars($evNotes) ?></div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-4 py-3 text-gray-700">
                                                <div class="font-medium"><?= htmlspecialchars((string)($e['client_name'] ?? '')) ?></div>
                                                <div class="text-xs text-gray-500 mt-1"><?= htmlspecialchars((string)($e['client_phone'] ?? '')) ?> • <?= htmlspecialchars((string)($e['client_email'] ?? '')) ?></div>
                                            </td>
                                            <td class="px-4 py-3 text-gray-700">
                                                <div class="font-medium"><?= htmlspecialchars((string)($e['event_date'] ?? '')) ?></div>
                                                <div class="text-xs text-gray-500 mt-1"><?= htmlspecialchars(trim((string)($e['start_time'] ?? ''))) ?> <?= (trim((string)($e['end_time'] ?? '')) !== '') ? '→ ' . htmlspecialchars(trim((string)($e['end_time'] ?? ''))) : '' ?></div>
                                            </td>
                                            <td class="px-4 py-3 text-gray-700"><?= htmlspecialchars((string)($e['function_room_name'] ?? 'Unassigned')) ?></td>
                                            <td class="px-4 py-3 text-right text-gray-700"><?= (int)($e['expected_guests'] ?? 0) ?></td>
                                            <td class="px-4 py-3 text-right text-gray-700">₱<?= number_format((float)($e['deposit_amount'] ?? 0), 2) ?></td>
                                            <td class="px-4 py-3 text-right text-gray-700">₱<?= number_format((float)($e['estimated_total'] ?? 0), 2) ?></td>
                                            <td class="px-4 py-3 text-right text-gray-700"><?= htmlspecialchars((string)($e['status'] ?? '')) ?></td>
                                            <td class="px-4 py-3 text-right">
                                                <div class="flex items-center justify-end gap-2">
                                                    <a class="px-3 py-1.5 rounded-lg border border-gray-200 text-xs hover:bg-gray-50 transition" href="events_conferences.php?edit_event_id=<?= (int)($e['id'] ?? 0) ?>">Edit</a>
                                                    <form method="post" class="js-confirm-delete" data-confirm-title="Delete Event" data-confirm-message="Delete this event?">
                                                        <input type="hidden" name="action" value="delete_event" />
                                                        <input type="hidden" name="id" value="<?= (int)($e['id'] ?? 0) ?>" />
                                                        <button class="px-3 py-1.5 rounded-lg border border-red-200 text-xs text-red-700 hover:bg-red-50 transition">Delete</button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <div id="confirmModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/50 px-4">
            <div class="w-full max-w-md rounded-xl bg-white border border-gray-200 shadow-xl">
                <div class="px-5 py-4 border-b border-gray-100">
                    <div id="confirmModalTitle" class="text-sm font-semibold text-gray-900">Confirm</div>
                    <div id="confirmModalMessage" class="text-xs text-gray-600 mt-1">Are you sure?</div>
                </div>
                <div class="px-5 py-4 flex items-center justify-end gap-2">
                    <button id="confirmModalCancel" class="px-4 py-2 rounded-lg border border-gray-200 text-sm hover:bg-gray-50 transition">Cancel</button>
                    <button id="confirmModalOk" class="px-4 py-2 rounded-lg bg-red-600 text-white text-sm hover:bg-red-700 transition">Delete</button>
                </div>
            </div>
        </div>
    </main>
</section>
<?php include __DIR__ . '/../partials/page_end.php';
