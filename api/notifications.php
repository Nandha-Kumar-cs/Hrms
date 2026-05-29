<?php
require_once '../includes/bootstrap.php';
header('Content-Type: application/json');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['error'=>'Unauthorized']);
    exit;
}

$user   = current_user();
$action = $_GET['action'] ?? 'list';

if ($action === 'list') {
    $limit  = (int)($_GET['limit'] ?? 20);
    $offset = (int)($_GET['offset'] ?? 0);
    $unread = $_GET['unread'] ?? '';

    $where = "WHERE n.user_id={$user['id']}";
    if ($unread === '1') $where .= " AND n.is_read=0";

    $notifications = db()->query("SELECT n.*, DATE_FORMAT(n.created_at,'%d %b %Y %H:%i') AS formatted_date
        FROM notifications n $where ORDER BY n.created_at DESC LIMIT $limit OFFSET $offset")->fetchAll(PDO::FETCH_ASSOC);

    $unreadCount = db()->query("SELECT COUNT(*) FROM notifications WHERE user_id={$user['id']} AND is_read=0")->fetchColumn();

    echo json_encode([
        'notifications' => $notifications,
        'unread_count'  => (int)$unreadCount,
        'success'       => true,
    ]);

} elseif ($action === 'mark_read') {
    $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
    if ($id) {
        db()->prepare("UPDATE notifications SET is_read=1, read_at=NOW() WHERE id=:id AND user_id=:uid")
             ->execute([':id'=>$id,':uid'=>$user['id']]);
    } else {
        // Mark all read
        db()->prepare("UPDATE notifications SET is_read=1, read_at=NOW() WHERE user_id=:uid AND is_read=0")
             ->execute([':uid'=>$user['id']]);
    }
    echo json_encode(['success'=>true]);

} elseif ($action === 'subscribe') {
    // Push subscription
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || empty($input['endpoint'])) {
        echo json_encode(['error'=>'Invalid subscription data']);
        exit;
    }
    $endpoint = $input['endpoint'];
    $p256dh   = $input['keys']['p256dh'] ?? '';
    $auth     = $input['keys']['auth'] ?? '';

    // Upsert subscription
    $existing = db()->query("SELECT id FROM push_subscriptions WHERE user_id={$user['id']} AND endpoint='".addslashes($endpoint)."'")->fetchColumn();
    if ($existing) {
        db()->prepare("UPDATE push_subscriptions SET p256dh=:p, auth=:a, updated_at=NOW() WHERE id=:id")
             ->execute([':p'=>$p256dh,':a'=>$auth,':id'=>$existing]);
    } else {
        db()->prepare("INSERT INTO push_subscriptions (user_id,endpoint,p256dh,auth,created_at) VALUES (:uid,:ep,:p,:a,NOW())")
             ->execute([':uid'=>$user['id'],':ep'=>$endpoint,':p'=>$p256dh,':a'=>$auth]);
    }
    echo json_encode(['success'=>true]);

} elseif ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
        db()->prepare("DELETE FROM notifications WHERE id=:id AND user_id=:uid")
             ->execute([':id'=>$id,':uid'=>$user['id']]);
    }
    echo json_encode(['success'=>true]);

} else {
    http_response_code(400);
    echo json_encode(['error'=>'Unknown action']);
}
