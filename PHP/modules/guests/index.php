<?php
require_once __DIR__ . '/../../rbac_middleware.php';
RBACMiddleware::checkPageAccess();

require_once __DIR__ . '/../../core/bootstrap.php';

require_once __DIR__ . '/../../domain/Guests/GuestService.php';

$conn = Database::getConnection();
$service = new GuestService(new GuestRepository($conn));

$q = (string)Request::get('q', '');
$guests = $service->list($q);

$pageTitle = 'Guests - Hotel Management System';
$pendingApprovals = [];
$flash = Flash::get();

include __DIR__ . '/../../partials/page_start.php';
include __DIR__ . '/../../partials/sidebar.php';
?>
<section id="content">
    <?php include __DIR__ . '/../../partials/header.php'; ?>
    <main class="w-full px-6 py-6">
        <div class="mb-6">
            <div class="flex items-start justify-between gap-4 flex-wrap">
                <div>
                    <h1 class="text-2xl font-light text-gray-900">Guests</h1>
                    <p class="text-sm text-gray-500 mt-1">Manage guest profiles (CRM base)</p>
                </div>
                <div class="flex items-center gap-2">
                    <a href="create.php" class="px-4 py-2 rounded-lg bg-blue-600 text-white text-sm hover:bg-blue-700 transition">New Guest</a>
                </div>
            </div>
        </div>

        <?php if ($flash): ?>
            <div class="mb-6 rounded-lg border border-gray-100 bg-white p-4 text-sm">
                <span class="font-medium text-gray-900"><?= htmlspecialchars(ucfirst($flash['type'])) ?>:</span>
                <span class="text-gray-700"><?= htmlspecialchars($flash['message']) ?></span>
            </div>
        <?php endif; ?>

        <div class="bg-white rounded-lg border border-gray-100 p-4 mb-6">
            <form method="get" class="flex items-center gap-3 flex-wrap">
                <input name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search name/email/phone" class="border border-gray-200 rounded-lg px-3 py-2 text-sm w-full md:w-80" />
                <button class="px-4 py-2 rounded-lg bg-gray-900 text-white text-sm hover:bg-black transition">Search</button>
                <a href="index.php" class="px-4 py-2 rounded-lg border border-gray-200 text-sm hover:bg-gray-50 transition">Reset</a>
            </form>
        </div>

        <?php if (empty($guests)): ?>
            <div class="bg-white rounded-lg border border-gray-100 p-10 text-center text-gray-500">No guests found.</div>
        <?php else: ?>
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
                <?php foreach ($guests as $g): ?>
                    <?php
                        $status = (string)($g['status'] ?? '');
                        $badge = 'border-gray-200 bg-gray-50 text-gray-700';
                        if ($status === 'Active') {
                            $badge = 'border-green-200 bg-green-50 text-green-700';
                        } elseif ($status === 'VIP') {
                            $badge = 'border-yellow-200 bg-yellow-50 text-yellow-800';
                        } elseif ($status === 'Blacklisted') {
                            $badge = 'border-red-200 bg-red-50 text-red-700';
                        }
                        $name = trim((string)($g['first_name'] ?? '') . ' ' . (string)($g['last_name'] ?? ''));

                        $pp = trim((string)($g['profile_picture_path'] ?? ''));
                        $idPhoto = trim((string)($g['id_photo_path'] ?? ''));
                        $tier = trim((string)($g['loyalty_tier'] ?? ''));
                        if ($tier === '') {
                            $tier = 'None';
                        }
                        $points = (int)($g['loyalty_points'] ?? 0);
                        $avatarSrc = '';
                        $imgRaw = $pp !== '' ? $pp : $idPhoto;
                        if ($imgRaw !== '') {
                            if (preg_match('/^https?:\/\//i', $imgRaw)) {
                                $avatarSrc = $imgRaw;
                            } elseif (substr($imgRaw, 0, 1) === '/') {
                                $avatarSrc = $APP_BASE_URL . $imgRaw;
                            } else {
                                $avatarSrc = $APP_BASE_URL . '/' . $imgRaw;
                            }
                        }
                        $initials = '';
                        if ($name !== '') {
                            $parts = preg_split('/\s+/', $name);
                            if (is_array($parts) && isset($parts[0])) {
                                $initials .= strtoupper(substr((string)$parts[0], 0, 1));
                            }
                            if (is_array($parts) && count($parts) > 1) {
                                $initials .= strtoupper(substr((string)$parts[count($parts) - 1], 0, 1));
                            }
                        }
                        if ($initials === '') {
                            $initials = 'G';
                        }
                    ?>
                    <div class="rounded-xl border border-gray-100 bg-white p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex items-start gap-3">
                                <div class="shrink-0">
                                    <?php if ($avatarSrc !== ''): ?>
                                        <img src="<?= htmlspecialchars($avatarSrc) ?>" alt="" class="w-10 h-10 rounded-full" style="object-fit:cover;" />
                                    <?php else: ?>
                                        <div class="w-10 h-10 rounded-full bg-gray-100 text-gray-700 flex items-center justify-center text-xs font-semibold">
                                            <?= htmlspecialchars($initials) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <div class="text-sm font-semibold text-gray-900"><?= htmlspecialchars($name) ?></div>
                                <div class="text-xs text-gray-500 mt-1"><?= htmlspecialchars((string)($g['email'] ?? '')) ?></div>
                                <div class="text-xs text-gray-500 mt-1"><?= htmlspecialchars((string)($g['phone'] ?? '')) ?></div>
                                    <div class="text-xs text-gray-500 mt-2">
                                        <?= htmlspecialchars($tier) ?> • <?= htmlspecialchars((string)$points) ?> pts
                                    </div>
                                </div>
                            </div>
                            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs border <?= htmlspecialchars($badge) ?>">
                                <?= htmlspecialchars($status) ?>
                            </span>
                        </div>

                        <div class="mt-4 flex items-center gap-2">
                            <a href="view.php?id=<?= (int)$g['id'] ?>" class="px-3 py-2 rounded-lg border border-gray-200 text-xs hover:bg-gray-50 transition">View</a>
                            <a href="edit.php?id=<?= (int)$g['id'] ?>" class="px-3 py-2 rounded-lg bg-gray-900 text-white text-xs hover:bg-black transition">Edit</a>
                            <a href="delete.php?id=<?= (int)$g['id'] ?>" class="ml-auto px-3 py-2 rounded-lg border border-red-200 text-red-700 text-xs hover:bg-red-50 transition">Delete</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>
</section>
<?php include __DIR__ . '/../../partials/page_end.php';
