<?php
// Faux SSM Core pour les tests : enregistre chaque requête reçue et répond avec le statut demandé.
//   php -S 127.0.0.1:PORT tests/fake_ssm.php   (variables d'environnement SSM_FAKE_DIR)
$dir = getenv('SSM_FAKE_DIR');
$headers = [];
foreach ($_SERVER as $k => $v) {
    if (strpos($k, 'HTTP_') === 0) {
        $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = $v;
    }
}
$headers['content-type'] = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';
file_put_contents($dir . '/requests.jsonl', json_encode([
    'method' => $_SERVER['REQUEST_METHOD'], 'uri' => $_SERVER['REQUEST_URI'], 'headers' => $headers, 'body' => file_get_contents('php://input'),
]) . "\n", FILE_APPEND);

$status = (int) @file_get_contents($dir . '/status');
$body = (string) @file_get_contents($dir . '/body');
http_response_code($status ?: 200);
if ($status >= 300 && $status < 400) {
    header('Location: https://ailleurs.example/api/v1/heartbeat');
}
header('Content-Type: application/json');
// Par défaut, la réponse ressemble à celle du vrai SSM Core (HeartbeatResponse) : sans `site_id`
// le test de reconnaissance du site ne testerait jamais rien.
if ($body === '') {
    echo json_encode(['site_id' => 42, 'status' => 'accepted', 'inventory_id' => null, 'updates_pending' => 0, 'next_heartbeat_in' => 1800]);
} else {
    echo $body;
}
