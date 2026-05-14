<?php
session_start();
require 'db.php';
if (!isset($_SESSION['user_id'])) { http_response_code(401); exit; }
$user_id = (int)$_SESSION['user_id'];
$contacto_id = (int)($_GET['contacto_id'] ?? 0);
$last_id = (int)($_GET['last_id'] ?? 0);
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
$stmt = $pdo->prepare("SELECT id, mensagem, anexo, criado_em FROM mensagens_contacto WHERE contacto_id = ? AND id > ? AND user_id <> ? ORDER BY id ASC LIMIT 20");
$stmt->execute([$contacto_id, $last_id, $user_id]);
$rows = $stmt->fetchAll();
echo "event: ping\n";
echo "data: {}\n\n";
foreach ($rows as $r) {
  echo "event: mensagem\n";
  echo 'data: ' . json_encode($r) . "\n\n";
}
@ob_flush(); @flush();
