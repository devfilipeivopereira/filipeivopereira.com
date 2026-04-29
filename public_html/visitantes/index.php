<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

$cors = [
  'Access-Control-Allow-Origin' => '*',
  'Access-Control-Allow-Headers' => 'authorization, x-client-info, apikey, content-type, x-signature, x-api-key',
  'Access-Control-Allow-Methods' => 'GET, POST, PATCH, DELETE, OPTIONS'
];
foreach ($cors as $k => $v) header("$k: $v");
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

try {
  $config = require __DIR__ . '/config.php';
  $secret = $config['WEBHOOK_SECRET'];
  require __DIR__ . '/db.php';
} catch (Throwable $e) {
  http_response_code(500);
  header('Content-Type: application/json');
  echo json_encode(['error' => 'Configuration error', 'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
  exit;
}

function pathRel($p) { 
  if (function_exists('str_starts_with')) {
    return str_starts_with($p, '/visitantes') ? substr($p, 11) : $p;
  }
  return substr($p, 0, 11) === '/visitantes' ? substr($p, 11) : $p;
}
function jsonInput() { return file_get_contents('php://input'); }
function jsonBody() { $raw = jsonInput(); $j = json_decode($raw, true); return [$raw, is_array($j) ? $j : []]; }
function resJson($code, $obj) { http_response_code($code); header('Content-Type: application/json'); echo json_encode($obj); }
function normalize($input) {
  $getFirst = function($v) { return is_array($v) ? ($v[0] ?? null) : $v; };
  $hasRaw = isset($input['your-name']) || isset($input['checkbox-191']) || isset($input['Whastapp']);
  if (!$hasRaw) {
    return [
      'data' => $input['data'] ?? null,
      'visitando' => $input['visitando'] ?? null,
      'telefone' => $input['telefone'] ?? null,
      'nome' => $input['nome'] ?? null,
      'sobrenome' => $input['sobrenome'] ?? null,
      'idade' => $input['idade'] ?? null,
      'estado_civil' => $input['estado_civil'] ?? null,
      'filhos' => $input['filhos'] ?? null,
      'cidade' => $input['cidade'] ?? null,
      'frequenta_igreja' => $input['frequenta_igreja'] ?? null,
      'qual' => $input['qual'] ?? null,
      'pedido' => $input['pedido'] ?? null,
      'origem' => $input['origem'] ?? null,
      'external_id' => $input['external_id'] ?? null,
    ];
  }
  $primeiroNome = trim(strval($input['your-name'] ?? ''));
  $sobrenome = trim(strval($input['last-name'] ?? ''));
  $telefone = preg_replace('/\D/', '', strval($input['Whastapp'] ?? ''));
  $freqRaw = $getFirst($input['checkbox-191'] ?? null);
  $visitando = is_string($freqRaw) && trim($freqRaw) !== '' ? $freqRaw : ($input['visitando'] ?? '1ª Vez');
  $idadeRaw = $input['number-255'] ?? null;
  $idade = is_numeric($idadeRaw) ? intval($idadeRaw) : null;
  $map = ['solteiro'=>'Solteiro(a)','casado'=>'Casado(a)','divorciado'=>'Divorciado(a)','viuvo'=>'Viúvo(a)','viúva'=>'Viúvo(a)','viuva'=>'Viúvo(a)'];
  $estado_civil = null; $ec = $input['select-371'] ?? null; if ($ec) { $k = strtolower(strval($ec)); $estado_civil = $map[$k] ?? $ec; }
  $filhos = null; $fr = $getFirst($input['checkbox-628'] ?? null); if (is_string($fr)) { $k = strtolower($fr); $filhos = $k==='sim'?'Sim':(($k==='nao'||$k==='não')?'Não':$fr); }
  $fi = $getFirst($input['checkbox-352'] ?? null); $frequenta_igreja = null; if (is_string($fi)) { $k = strtolower($fi); $frequenta_igreja = $k==='sim'?'Sim':(($k==='nao'||$k==='não')?'Não':$fi); }
  $cidade = trim(strval($input['select-130'] ?? '')) ?: null;
  $qual = trim(strval($input['text-443'] ?? '')) ?: null; if ($frequenta_igreja==='Sim' && !$qual) $qual = 'IFC';
  $pedido = trim(strval($input['textarea-943'] ?? '')) ?: null;
  return [
    'data' => $input['data'] ?? null,
    'visitando' => $visitando,
    'telefone' => $telefone ?: null,
    'nome' => $primeiroNome,
    'sobrenome' => $sobrenome ?: null,
    'idade' => $idade,
    'estado_civil' => $estado_civil,
    'filhos' => $filhos,
    'cidade' => $cidade,
    'frequenta_igreja' => $frequenta_igreja,
    'qual' => $qual,
    'pedido' => $pedido,
    'origem' => $input['origem'] ?? 'webhook',
    'external_id' => $input['external_id'] ?? null,
  ];
}
function validatePayload($p) {
  $errors = [];
  if (!isset($p['nome']) || !is_string($p['nome']) || trim($p['nome'])==='') $errors[] = 'Campo "nome" é obrigatório e deve ser uma string não vazia';
  if (!isset($p['visitando']) || !is_string($p['visitando']) || trim($p['visitando'])==='') $errors[] = 'Campo "visitando" é obrigatório e deve ser uma string não vazia';
  if (isset($p['telefone']) && $p['telefone']!==null) { if (!preg_match('/^[\d\s\(\)\+\-]+$/', $p['telefone'])) $errors[] = 'Campo "telefone" contém caracteres inválidos. Use apenas dígitos, espaços, parênteses, + e -'; }
  if (isset($p['idade']) && $p['idade']!==null) { $i = intval($p['idade']); if ($i<0 || $i>150) $errors[] = 'Campo "idade" deve ser um número inteiro entre 0 e 150'; }
  $validEC = ['Solteiro(a)','Casado(a)','Divorciado(a)','Viúvo(a)','Outro']; if (isset($p['estado_civil']) && $p['estado_civil']!==null && !in_array($p['estado_civil'],$validEC)) $errors[] = 'Campo "estado_civil" inválido';
  $validFI = ['Sim','Não','Às vezes']; if (isset($p['frequenta_igreja']) && $p['frequenta_igreja']!==null && !in_array($p['frequenta_igreja'],$validFI)) $errors[] = 'Campo "frequenta_igreja" inválido';
  if (isset($p['data']) && $p['data']!==null) { $d1 = preg_match('/^\d{4}-\d{2}-\d{2}$/',$p['data']); $d2 = preg_match('/^\d{2}\/\d{2}\/\d{4}$/',$p['data']); if (!$d1 && !$d2) $errors[] = 'Campo "data" deve estar no formato YYYY-MM-DD ou DD/MM/YYYY'; }
  return $errors;
}
function hmacValid($raw,$sig,$secret) { if (!$sig || !$secret) return false; $calc = hash_hmac('sha256',$raw,$secret); return hash_equals($calc, strtolower($sig)); }
$uri = $_SERVER['REQUEST_URI'];
$pathParam = isset($_GET['route']) ? $_GET['route'] : null;
$path = $pathParam ? $pathParam : pathRel(parse_url($uri, PHP_URL_PATH));
$method = $_SERVER['REQUEST_METHOD'];
if ($path === '/health/db') { try { $pdo->query('SELECT 1'); resJson(200, ['ok'=>true]); } catch (Throwable $e) { resJson(500, ['ok'=>false,'error'=>$e->getMessage()]); } exit; }
if ($path === '/webhook/visitantes' && $method==='POST') {
  if (!$secret) { resJson(500, ['error'=>'Server configuration error']); exit; }
  [$raw,$body] = jsonBody();
  $sig = $_SERVER['HTTP_X_SIGNATURE'] ?? null; $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? null;
  $auth = false; if ($sig) $auth = hmacValid($raw,$sig,$secret); if (!$auth && $apiKey) $auth = ($apiKey === $secret);
  if (!$auth) { resJson(401, ['error'=>'Unauthorized. Provide valid X-Signature (HMAC) or X-API-Key header.']); exit; }
  $payload = normalize($body);
  $errors = validatePayload($payload); if (!empty($errors)) { resJson(400, ['error'=>'Validation failed','details'=>$errors]); exit; }
  $df = $payload['data'] ?? null; if ($df && preg_match('/^\d{2}\/\d{2}\/\d{4}$/',$df)) { $parts = explode('/',$df); $df = $parts[2].'-'.$parts[1].'-'.$parts[0]; }
  if (!empty($payload['external_id'])) { $existing = findByExternalId($pdo, $payload['external_id']); if ($existing) { resJson(200, ['message'=>'Visitante já existe (requisição idempotente)','id'=>$existing['id'],'status'=>'duplicate']); exit; } }
  $toInsert = $payload; $toInsert['data'] = $df ?: date('Y-m-d');
  try { insertVisitante($pdo, $toInsert); resJson(201, ['message'=>'Visitante criado com sucesso','status'=>'created','insertId'=>null]); } catch (Throwable $e) { resJson(500, ['error'=>'Database error','details'=>$e->getMessage()]); }
  exit;
}
if ($path === '/api/visitantes' && $method==='GET') {
  try {
    $search = $_GET['search'] ?? null; $cidade = $_GET['cidade'] ?? null; $estadoCivil = $_GET['estadoCivil'] ?? null; $frequentaIgreja = $_GET['frequentaIgreja'] ?? null;
    $sql = 'SELECT id,data_submissao,data,visitando,telefone,nome,sobrenome,idade,estado_civil,filhos,cidade,frequenta_igreja,qual,pedido,origem,last_updated,external_id,created_at,msg_enviada,respondeu_msg,observacoes FROM visitantes WHERE 1=1'; $params=[];
    if ($search) { $sql .= ' AND (nome LIKE ? OR telefone LIKE ?)'; $params[]='%'.$search.'%'; $params[]='%'.$search.'%'; }
    if ($cidade) { $sql .= ' AND cidade = ?'; $params[]=$cidade; }
    if ($estadoCivil) { $sql .= ' AND estado_civil = ?'; $params[]=$estadoCivil; }
    if ($frequentaIgreja) { $sql .= ' AND frequenta_igreja = ?'; $params[]=$frequentaIgreja; }
    $sql .= ' ORDER BY data_submissao DESC'; 
    $stmt = $pdo->prepare($sql); 
    $stmt->execute($params); 
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC); 
    resJson(200, $rows);
  } catch (Throwable $e) { 
    resJson(500, ['error'=>$e->getMessage(), 'file'=>$e->getFile(), 'line'=>$e->getLine()]); 
  }
  exit;
}
if ($path === '/api/visitantes' && $method==='POST') {
  [$raw,$body] = jsonBody(); $v = $body; if (!isset($v['data'])||!isset($v['visitando'])||!isset($v['nome'])) { resJson(400,['error'=>'Campos obrigatórios ausentes']); exit; }
  $v['origem'] = 'manual'; try { insertVisitante($pdo, $v); resJson(201, ['ok'=>true]); } catch (Throwable $e) { resJson(500, ['error'=>$e->getMessage()]); } exit;
}
if (preg_match('#^/api/visitantes/([A-Za-z0-9\-]+)$#',$path,$m) && $method==='PATCH') {
  [$raw,$body] = jsonBody(); $id=$m[1]; $allowed=['data','visitando','telefone','nome','sobrenome','idade','estado_civil','filhos','cidade','frequenta_igreja','qual','pedido','origem','external_id','msg_enviada','respondeu_msg','observacoes']; $fields=array_keys($body); $fields=array_values(array_filter($fields,fn($k)=>in_array($k,$allowed))); if (empty($fields)) { resJson(400,['error'=>'Nenhum campo válido para atualizar']); exit; } $sets=[];$params=[]; foreach($fields as $k){ $sets[]="$k = ?"; $params[]=$body[$k]; } $params[]=$id; try { $stmt = $pdo->prepare('UPDATE visitantes SET '.implode(', ',$sets).' WHERE id = ?'); $stmt->execute($params); resJson(200,['ok'=>true]); } catch (Throwable $e) { resJson(500,['error'=>$e->getMessage()]); } exit;
}
if (preg_match('#^/api/visitantes/([A-Za-z0-9\-]+)$#',$path,$m) && $method==='DELETE') { $id=$m[1]; try { $stmt=$pdo->prepare('DELETE FROM visitantes WHERE id = ?'); $stmt->execute([$id]); resJson(200,['ok'=>true]); } catch (Throwable $e) { resJson(500,['error'=>$e->getMessage()]); } exit; }
if ($path === '/api/visitantes/stats' && $method==='GET') { try { $search=$_GET['search']??null; $cidade=$_GET['cidade']??null; $estadoCivil=$_GET['estadoCivil']??null; $frequentaIgreja=$_GET['frequentaIgreja']??null; $where='1=1'; $params=[]; if ($search){ $where.=' AND (nome LIKE ? OR telefone LIKE ?)'; $params[]='%'.$search.'%'; $params[]='%'.$search.'%'; } if ($cidade){ $where.=' AND cidade = ?'; $params[]=$cidade; } if ($estadoCivil){ $where.=' AND estado_civil = ?'; $params[]=$estadoCivil; } if ($frequentaIgreja){ $where.=' AND frequenta_igreja = ?'; $params[]=$frequentaIgreja; } $stmt=$pdo->prepare("SELECT COUNT(*) AS total FROM visitantes WHERE $where"); $stmt->execute($params); $total=$stmt->fetch()['total']; $stmt=$pdo->prepare("SELECT COUNT(*) AS recent FROM visitantes WHERE $where AND COALESCE(data_submissao, STR_TO_DATE(data, '%Y-%m-%d')) >= DATE_SUB(NOW(), INTERVAL 7 DAY)"); $stmt->execute($params); $recent=$stmt->fetch()['recent']; $stmt=$pdo->prepare("SELECT COUNT(*) AS withPedidos FROM visitantes WHERE $where AND pedido IS NOT NULL AND TRIM(pedido) <> ''"); $stmt->execute($params); $with=$stmt->fetch()['withPedidos']; resJson(200,['total'=>$total,'recent'=>$recent,'withPedidos'=>$with]); } catch (Throwable $e) { resJson(500,['error'=>$e->getMessage()]); } exit; }
if ($path === '/api/visitantes/chart' && $method==='GET') { try { $stmt=$pdo->query("SELECT DATE_FORMAT(data, '%Y-%m') AS month, COUNT(*) AS count FROM visitantes GROUP BY month ORDER BY month ASC"); $rows=$stmt->fetchAll(); $out=array_map(fn($r)=>['month'=>$r['month'],'count'=>intval($r['count'])],$rows); resJson(200,$out); } catch (Throwable $e) { resJson(500,['error'=>$e->getMessage()]); } exit; }
resJson(404, ['error'=>'Not Found']);
