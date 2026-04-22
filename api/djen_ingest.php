<?php
/**
 * Endpoint de ingestão de publicações do DJen pela skill automatizada.
 *
 * Uso:
 *   POST https://ferreiraesa.com.br/conecta/api/djen_ingest.php?key=fsa-hub-deploy-2026
 *   Content-Type: application/json
 *   Body: {"text": "Processo 0822497-92.2025.8.19.0066\nOrgão: ...\n..."}
 *
 * Ou body text/plain direto (o texto bruto das publicações).
 *
 * Retorno JSON:
 * {
 *   "ok": true,
 *   "total_parsed": 12,
 *   "imported": 8,      // com match de pasta — foram auto-importadas
 *   "duplicated": 2,    // já existiam — ignoradas
 *   "pending": 2,       // sem pasta correspondente — aguardam revisão humana
 *   "errors": []
 * }
 */

while (ob_get_level() > 0) @ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/functions_utils.php';
require_once __DIR__ . '/../core/functions_djen.php';

// Carrega calcular_prazo_completo se existir (feriados + dias úteis)
$fnPrazo = __DIR__ . '/../core/functions_prazos.php';
if (file_exists($fnPrazo)) require_once $fnPrazo;
$fnNotify = __DIR__ . '/../core/functions_notify.php';
if (file_exists($fnNotify)) require_once $fnNotify;

// Auth
$key = isset($_GET['key']) ? $_GET['key'] : '';
if ($key !== 'fsa-hub-deploy-2026') {
    http_response_code(403);
    echo json_encode(array('ok' => false, 'erro' => 'Chave inválida'));
    exit;
}

// Extrai texto do body
$texto = '';
$raw = file_get_contents('php://input');
if ($raw) {
    $json = json_decode($raw, true);
    if (is_array($json) && isset($json['text'])) {
        $texto = $json['text'];
    } else {
        $texto = $raw;
    }
}
if (!$texto && isset($_POST['text'])) $texto = $_POST['text'];

if (!$texto || strlen(trim($texto)) < 20) {
    http_response_code(400);
    echo json_encode(array('ok' => false, 'erro' => 'Nenhum texto recebido ou texto muito curto. Mande no body (text/plain) ou em JSON {"text":"..."}.'));
    exit;
}

try {
    $pdo = db();

    // Pega 1º admin ativo pra atribuir como "criado por" (userId do sistema)
    $userId = null;
    try {
        $userId = (int)$pdo->query("SELECT id FROM users WHERE role='admin' AND is_active=1 ORDER BY id LIMIT 1")->fetchColumn();
    } catch (Exception $e) {}

    $publicacoes = djen_parsear_texto($texto);

    $res = array(
        'ok' => true,
        'total_parsed' => count($publicacoes),
        'imported' => 0,
        'duplicated' => 0,
        'pending' => 0,
        'errors' => array(),
        'details' => array(),
    );

    foreach ($publicacoes as $pub) {
        $numero = $pub['numero_processo'];
        try {
            $case = djen_buscar_case_por_cnj($pdo, $numero);
            if ($case) {
                $result = djen_importar_publicacao($pdo, $pub, (int)$case['id'], $userId);
                if (is_array($result) && !empty($result['duplicated'])) {
                    $res['duplicated']++;
                    $res['details'][] = array('numero' => $numero, 'status' => 'duplicated', 'case_id' => (int)$case['id']);
                } elseif (is_array($result) && isset($result['pub_id'])) {
                    $res['imported']++;
                    $res['details'][] = array(
                        'numero' => $numero, 'status' => 'imported',
                        'case_id' => (int)$case['id'],
                        'case_title' => $case['title'],
                        'pub_id' => $result['pub_id'],
                        'task_id' => $result['task_id'],
                    );
                } else {
                    $res['errors'][] = array('numero' => $numero, 'erro' => 'Falha ao importar');
                }
            } else {
                $result = djen_salvar_pendente($pdo, $pub);
                if (is_array($result) && !empty($result['duplicated'])) {
                    $res['duplicated']++;
                    $res['details'][] = array('numero' => $numero, 'status' => 'duplicated_pending');
                } else {
                    $res['pending']++;
                    $res['details'][] = array('numero' => $numero, 'status' => 'pending', 'pending_id' => isset($result['id']) ? $result['id'] : null);
                }
            }
        } catch (Exception $eIn) {
            $res['errors'][] = array('numero' => $numero, 'erro' => $eIn->getMessage());
            @file_put_contents(__DIR__ . '/../files/djen_ingest.log',
                '[' . date('Y-m-d H:i:s') . '] ERRO ' . $numero . ': ' . $eIn->getMessage() . "\n",
                FILE_APPEND);
        }
    }

    echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(array('ok' => false, 'erro' => $e->getMessage()));
    @file_put_contents(__DIR__ . '/../files/djen_ingest.log',
        '[' . date('Y-m-d H:i:s') . '] FATAL: ' . $e->getMessage() . "\n",
        FILE_APPEND);
}
