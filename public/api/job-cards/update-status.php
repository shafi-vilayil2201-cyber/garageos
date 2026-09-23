<?php

require_once __DIR__ . '/../../../app/Auth/Auth.php';
require_once __DIR__ . '/../../../app/Security/Csrf.php';
require_once __DIR__ . '/../../../app/Domain/JobCardStatus.php';

$pdo = require __DIR__ . '/../../../config/database.php';

$auth = new Auth($pdo);
$user = $auth->user();

header('Content-Type: application/json');

if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthenticated']);
    exit;
}

if (!user_can($user, 'job_cards.manage')) {
    http_response_code(403);
    echo json_encode(['error' => "You don't have permission to do that."]);
    exit;
}

// Same CSRF token as every other form, but a JSON error instead of
// csrf_verify()'s plain-text response — this endpoint is only ever
// called from fetch(), never a browser form submission.
$submittedToken = $_POST['_csrf'] ?? '';
$expectedToken = $_SESSION['csrf_token'] ?? '';

if ($submittedToken === '' || $expectedToken === '' || !hash_equals($expectedToken, $submittedToken)) {
    http_response_code(419);
    echo json_encode(['error' => 'Your session expired. Reload the page and try again.']);
    exit;
}

$jobCardId = (int) ($_POST['job_card_id'] ?? 0);
$newStatus = $_POST['status'] ?? '';
$boardStatuses = ['received', 'in_progress', 'quality_check', 'ready', 'delivered'];

if (!$jobCardId || !in_array($newStatus, $boardStatuses, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request.']);
    exit;
}

$statement = $pdo->prepare("SELECT * FROM job_cards WHERE id = :id AND organization_id = :organization_id AND deleted_at IS NULL");
$statement->execute(['id' => $jobCardId, 'organization_id' => $user['organization_id']]);
$jobCard = $statement->fetch(PDO::FETCH_ASSOC);

if (!$jobCard) {
    http_response_code(404);
    echo json_encode(['error' => 'Job card not found.']);
    exit;
}

if (!job_card_can_transition($jobCard['status'], $newStatus)) {
    http_response_code(422);
    echo json_encode(['error' => 'A job card can only move forward — it can\'t go back to an earlier stage.']);
    exit;
}

apply_job_card_status($pdo, $jobCard, $newStatus, $user['organization_id'], $user);

echo json_encode(['ok' => true, 'status' => $newStatus]);
