<?php
try {
  $config = require __DIR__ . '/config.php';
  $host = $config['HOSTINGER_DB_HOST'];
  $port = $config['HOSTINGER_DB_PORT'] ?: '3306';
  $db = $config['HOSTINGER_DB_NAME'];
  $user = $config['HOSTINGER_DB_USER'];
  $pass = $config['HOSTINGER_DB_PASSWORD'];
  $dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";
  $pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
} catch (PDOException $e) {
  http_response_code(500);
  header('Content-Type: application/json');
  echo json_encode(['error' => 'Database connection error', 'message' => $e->getMessage()]);
  exit;
}
function insertVisitante($pdo, $v) {
  $sql = "INSERT INTO visitantes (id,data,visitando,telefone,nome,sobrenome,idade,estado_civil,filhos,cidade,frequenta_igreja,qual,pedido,origem,external_id,msg_enviada,respondeu_msg,observacoes) VALUES (UUID(),?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
  $stmt = $pdo->prepare($sql);
  $stmt->execute([
    $v['data'],
    isset($v['visitando']) ? $v['visitando'] : null,
    $v['telefone'] ?? null,
    $v['nome'],
    $v['sobrenome'] ?? null,
    isset($v['idade']) ? $v['idade'] : null,
    $v['estado_civil'] ?? null,
    $v['filhos'] ?? null,
    $v['cidade'] ?? null,
    $v['frequenta_igreja'] ?? null,
    $v['qual'] ?? null,
    $v['pedido'] ?? null,
    $v['origem'] ?? 'webhook',
    $v['external_id'] ?? null,
    isset($v['msg_enviada']) && $v['msg_enviada'] ? 1 : 0,
    isset($v['respondeu_msg']) && $v['respondeu_msg'] ? 1 : 0,
    $v['observacoes'] ?? null,
  ]);
  return $pdo->lastInsertId();
}
function findByExternalId($pdo, $externalId) {
  $stmt = $pdo->prepare('SELECT id FROM visitantes WHERE external_id = ? LIMIT 1');
  $stmt->execute([$externalId]);
  $row = $stmt->fetch();
  return $row ?: null;
}
