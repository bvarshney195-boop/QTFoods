<?php

declare(strict_types=1);

$statePath = (string) getenv('QT_TEST_RECEIVER_STATE');
$secret = (string) getenv('QT_TEST_RECEIVER_SECRET');

if (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) === '/health') {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'up'], JSON_THROW_ON_ERROR);
    return;
}

$body = (string) file_get_contents('php://input');
$event = json_decode($body, true);
$eventId = is_array($event) ? (string) ($event['id'] ?? '') : '';
$idempotencyKey = (string) ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '');
$headerEventId = (string) ($_SERVER['HTTP_X_QT_EVENT_ID'] ?? '');
$signature = (string) ($_SERVER['HTTP_X_QT_SIGNATURE'] ?? '');
$expectedSignature = 'sha256='.hash_hmac('sha256', $body, $secret);

if ($statePath === '' || $secret === '' || $eventId === ''
    || ! hash_equals($eventId, $idempotencyKey)
    || ! hash_equals($eventId, $headerEventId)
    || ! hash_equals($expectedSignature, $signature)) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'invalid signed event envelope'], JSON_THROW_ON_ERROR);
    return;
}

$handle = fopen($statePath, 'c+');
if ($handle === false || ! flock($handle, LOCK_EX)) {
    http_response_code(503);
    echo '{"error":"receiver state unavailable"}';
    return;
}

rewind($handle);
$stored = stream_get_contents($handle);
$state = is_string($stored) && $stored !== '' ? json_decode($stored, true) : [];
if (! is_array($state)) {
    $state = [];
}
$state['events'] ??= [];
$record = $state['events'][$eventId] ?? [
    'attempts' => 0,
    'accepted' => false,
    'side_effect_count' => 0,
    'replay_count' => 0,
    'acknowledgement_id' => 'external-ack-'.$eventId,
];
$record['attempts']++;
$wasAccepted = (bool) $record['accepted'];
$failuresBeforeAccept = max(0, (int) ($event['payload']['failures_before_accept'] ?? 0));

if (! $wasAccepted && $record['attempts'] <= $failuresBeforeAccept) {
    $state['events'][$eventId] = $record;
    persist($handle, $state);
    flock($handle, LOCK_UN);
    fclose($handle);
    http_response_code(503);
    header('Content-Type: application/json');
    echo json_encode(['accepted' => false, 'attempt' => $record['attempts']], JSON_THROW_ON_ERROR);
    return;
}

if ($wasAccepted) {
    $record['replay_count']++;
} else {
    $record['accepted'] = true;
    $record['side_effect_count']++;
}
$state['events'][$eventId] = $record;
persist($handle, $state);
flock($handle, LOCK_UN);
fclose($handle);

$acknowledgedEventId = (string) ($event['payload']['acknowledged_event_id_override'] ?? $eventId);
header('Content-Type: application/json');
header('X-Acknowledgement-ID: '.$record['acknowledgement_id']);
header('X-Acknowledged-Event-ID: '.$acknowledgedEventId);
echo json_encode([
    'acknowledgement_id' => $record['acknowledgement_id'],
    'acknowledged_event_id' => $acknowledgedEventId,
    'accepted' => true,
    'idempotent_replay' => $wasAccepted,
    'receiver' => 'live-test-receiver',
    'processed_at' => gmdate(DATE_ATOM),
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

function persist($handle, array $state): void
{
    rewind($handle);
    ftruncate($handle, 0);
    fwrite($handle, json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    fflush($handle);
}
