<?php
require_once '../../includes/bootstrap.php';
require_login();
require_permission('pwa', 'view');

$user    = current_user();
$pwaConf = db()->query("SELECT * FROM pwa_module_access ORDER BY module")->fetchAll(PDO::FETCH_ASSOC);
$roles   = db()->query("SELECT id, name FROM roles ORDER BY name")->fetchAll(PDO::FETCH_KEY_PAIR);

// Build set of globally-enabled module keys
$enabledModules = [];
foreach ($pwaConf as $row) {
    if ($row['is_enabled']) $enabledModules[] = $row['module'];
}

$pwaModules = [
    'dashboard'  => ['icon'=>'🏠','label'=>'Dashboard'],
    'employees'  => ['icon'=>'👥','label'=>'Employees'],
    'attendance' => ['icon'=>'📅','label'=>'Attendance'],
    'payroll'    => ['icon'=>'💰','label'=>'Payroll'],
    'letters'    => ['icon'=>'📄','label'=>'Letters'],
    'assets'     => ['icon'=>'💼','label'=>'Assets'],
    'training'   => ['icon'=>'🎓','label'=>'Training'],
];

$page_title = 'PWA Settings';
include '../../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1 class="page-title">PWA &amp; Mobile Access</h1>
        <p class="page-subtitle">Configure Progressive Web App and mobile module access</p>
    </div>
</div>

<?php render_flash(); ?>

<!-- PWA Install Banner -->
<div class="card mb-4" id="pwaInstallCard" style="display:none">
    <div class="card-body d-flex align-items-center justify-content-between">
        <div>
            <strong>📱 Install <?= APP_NAME ?> on your device</strong>
            <p class="text-muted mb-0">Get the full mobile experience with offline access and push notifications.</p>
        </div>
        <button class="btn btn-primary" id="pwaInstallBtn">Install App</button>
    </div>
</div>

<!-- PWA Status -->
<div class="row mb-4">
    <div class="col-4">
        <div class="card">
            <div class="card-header"><h3 class="card-title">PWA Status</h3></div>
            <div class="card-body">
                <div id="swStatus" class="d-flex align-items-center gap-2 mb-2">
                    <span class="pill pill-warn">Checking...</span> Service Worker
                </div>
                <div id="notifStatus" class="d-flex align-items-center gap-2 mb-2">
                    <span class="pill pill-warn">Checking...</span> Push Notifications
                </div>
                <div id="installStatus" class="d-flex align-items-center gap-2">
                    <span class="pill pill-warn">Checking...</span> App Install
                </div>
                <hr>
                <button class="btn btn-sm btn-primary w-100 mt-2" id="enableNotifBtn">Enable Push Notifications</button>
            </div>
        </div>
    </div>
    <div class="col-8">
        <div class="card">
            <div class="card-header"><h3 class="card-title">Installation Guide</h3></div>
            <div class="card-body">
                <div class="tabs mb-3">
                    <button type="button" class="tab-btn active" onclick="switchTab(event,'ios')">iOS / Safari</button>
                    <button type="button" class="tab-btn" onclick="switchTab(event,'android')">Android / Chrome</button>
                    <button type="button" class="tab-btn" onclick="switchTab(event,'desktop')">Desktop</button>
                </div>
                <div id="tab-ios">
                    <ol style="font-size:.9rem;line-height:2">
                        <li>Open <strong><?= APP_URL ?></strong> in Safari</li>
                        <li>Tap the <strong>Share</strong> button (square with arrow)</li>
                        <li>Scroll and tap <strong>"Add to Home Screen"</strong></li>
                        <li>Confirm name and tap <strong>Add</strong></li>
                    </ol>
                </div>
                <div id="tab-android" style="display:none">
                    <ol style="font-size:.9rem;line-height:2">
                        <li>Open <strong><?= APP_URL ?></strong> in Chrome</li>
                        <li>Tap the <strong>⋮ menu</strong> (three dots)</li>
                        <li>Tap <strong>"Add to Home screen"</strong></li>
                        <li>Confirm and tap <strong>Add</strong></li>
                    </ol>
                </div>
                <div id="tab-desktop" style="display:none">
                    <ol style="font-size:.9rem;line-height:2">
                        <li>Open <strong><?= APP_URL ?></strong> in Chrome or Edge</li>
                        <li>Click the <strong>install icon</strong> in the address bar</li>
                        <li>Click <strong>"Install"</strong> in the dialog</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Role-based PWA Access -->
<div class="card">
    <div class="card-header"><h3 class="card-title">Role-based Mobile Module Access</h3></div>
    <div class="card-body">
        <p class="text-muted">Configure which modules are accessible on mobile for each role. Edit via Roles &amp; Permissions.</p>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Role</th>
                        <?php foreach ($pwaModules as $key => $m): ?>
                            <th class="text-center"><?= $m['icon'] ?><br><small><?= $m['label'] ?></small></th>
                        <?php endforeach; ?>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($roles as $rid => $rname): ?>
                    <tr>
                        <td><strong><?= h($rname) ?></strong></td>
                        <?php foreach ($pwaModules as $key => $m): ?>
                            <td class="text-center">
                                <?= in_array($key, $enabledModules) ? '✅' : '❌' ?>
                            </td>
                        <?php endforeach; ?>
                        <td><a href="../roles/edit.php?id=<?= $rid ?>" class="btn btn-xs btn-secondary">Edit</a></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
// Tab switch
function switchTab(e, tab) {
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    ['ios','android','desktop'].forEach(t => document.getElementById('tab-'+t).style.display='none');
    e.target.classList.add('active');
    document.getElementById('tab-'+tab).style.display='block';
}

// PWA Status checks
document.addEventListener('DOMContentLoaded', () => {
    // Service Worker
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.getRegistration().then(reg => {
            const el = document.getElementById('swStatus');
            if (reg) {
                el.innerHTML = '<span class="pill pill-success">Active</span> Service Worker';
            } else {
                el.innerHTML = '<span class="pill pill-danger">Not Registered</span> Service Worker';
            }
        });
    } else {
        document.getElementById('swStatus').innerHTML = '<span class="pill pill-danger">Not Supported</span> Service Worker';
    }

    // Notifications
    if ('Notification' in window) {
        const el = document.getElementById('notifStatus');
        const perm = Notification.permission;
        if (perm === 'granted') {
            el.innerHTML = '<span class="pill pill-success">Granted</span> Push Notifications';
            document.getElementById('enableNotifBtn').textContent = 'Notifications Enabled ✓';
            document.getElementById('enableNotifBtn').disabled = true;
        } else if (perm === 'denied') {
            el.innerHTML = '<span class="pill pill-danger">Denied</span> Push Notifications';
        } else {
            el.innerHTML = '<span class="pill pill-warn">Not Requested</span> Push Notifications';
        }
    }

    // Install
    const installed = window.matchMedia('(display-mode: standalone)').matches;
    const el = document.getElementById('installStatus');
    if (installed) {
        el.innerHTML = '<span class="pill pill-success">Installed</span> App Install';
    } else {
        el.innerHTML = '<span class="pill pill-secondary">Browser</span> App Install';
    }
});

// Enable notifications button
document.getElementById('enableNotifBtn').addEventListener('click', async () => {
    if (!('Notification' in window)) return alert('Notifications not supported.');
    const perm = await Notification.requestPermission();
    if (perm === 'granted') {
        document.getElementById('enableNotifBtn').textContent = 'Notifications Enabled ✓';
        document.getElementById('enableNotifBtn').disabled = true;
        document.getElementById('notifStatus').innerHTML = '<span class="pill pill-success">Granted</span> Push Notifications';
        // Subscribe to push
        if ('serviceWorker' in navigator) {
            const reg = await navigator.serviceWorker.ready;
            // Push subscription would go here with VAPID key
        }
    }
});

// Install prompt
let deferredPrompt;
window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    deferredPrompt = e;
    document.getElementById('pwaInstallCard').style.display = 'flex';
});
document.getElementById('pwaInstallBtn')?.addEventListener('click', async () => {
    if (!deferredPrompt) return;
    deferredPrompt.prompt();
    const { outcome } = await deferredPrompt.userChoice;
    if (outcome === 'accepted') {
        document.getElementById('pwaInstallCard').style.display = 'none';
    }
});
</script>
<?php include '../../includes/footer.php'; ?>
