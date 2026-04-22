<?php
/**
 * Ferreira & Sá Hub — Importar Publicações DJen
 * Cole o texto copiado do portal DJen — o sistema identifica e vincula automaticamente
 */
require_once __DIR__ . '/../../core/middleware.php';
require_once __DIR__ . '/../../core/functions_djen.php';
require_login();
if (!has_min_role('operacional') && !has_min_role('gestao')) { flash_set('error', 'Sem permissao.'); redirect(url('modules/dashboard/')); }

$pdo = db();
$userId = current_user_id();

// Self-heal: colunas pra resumo e orientação (preenchidas quando o texto colado já vier com
// linhas "Resumo:" e "Orientação:" — típico de texto formatado pelo Claude Cowork).
try { $pdo->exec("ALTER TABLE case_publicacoes ADD COLUMN resumo_ia TEXT NULL"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE case_publicacoes ADD COLUMN orientacao_ia TEXT NULL"); } catch (Exception $e) {}

// Buscar usuarios ativos
$usuarios = array();
try {
    $usuarios = $pdo->query("SELECT id, name FROM users WHERE is_active = 1 ORDER BY name")->fetchAll();
} catch (Exception $e) {}

// ── Parser do texto bruto do DJen ──
function parsear_djen($texto) {
    $publicacoes = array();
    $blocos = preg_split('/(?=Processo\s+\d{7}-\d{2}\.\d{4}\.\d{1,2}\.\d{2}\.\d{4})/u', $texto, -1, PREG_SPLIT_NO_EMPTY);
    foreach ($blocos as $bloco) {
        $bloco = trim($bloco);
        if (!$bloco) continue;
        $pub = array(
            'numero_processo'  => '',
            'orgao'            => '',
            'data_disp'        => date('Y-m-d'),
            'tipo_comunicacao' => 'intimacao',
            'meio'             => 'DJEN',
            'partes'           => array(),
            'advogados'        => array(),
            'conteudo'         => '',
            'segredo'          => false,
            'comarca'          => '',
        );
        // Numero do processo
        if (preg_match('/Processo\s+(\d{7}-\d{2}\.\d{4}\.\d{1,2}\.\d{2}\.\d{4})/u', $bloco, $m)) {
            $pub['numero_processo'] = $m[1];
        }
        if (!$pub['numero_processo']) continue;

        // Orgao
        if (preg_match('/(?:Org[aã]o|Orgao)\s*[:\-]?\s*(.+?)(?:\n|Data)/ui', $bloco, $m)) {
            $pub['orgao'] = trim($m[1]);
        }
        // Data de disponibilizacao
        if (preg_match('/Data de disponibiliza[cç][aã]o\s*[:\-]?\s*(\d{2}\/\d{2}\/\d{4})/ui', $bloco, $m)) {
            $partes_data = explode('/', $m[1]);
            if (count($partes_data) === 3) {
                $pub['data_disp'] = $partes_data[2] . '-' . $partes_data[1] . '-' . $partes_data[0];
            }
        }
        // Tipo de comunicacao
        if (preg_match('/Tipo de comunica[cç][aã]o\s*[:\-]?\s*(.+?)(?:\n)/ui', $bloco, $m)) {
            $tipo = strtolower(trim($m[1]));
            if (strpos($tipo, 'intima') !== false) $pub['tipo_comunicacao'] = 'intimacao';
            elseif (strpos($tipo, 'cita') !== false) $pub['tipo_comunicacao'] = 'citacao';
            elseif (strpos($tipo, 'edital') !== false) $pub['tipo_comunicacao'] = 'edital';
            else $pub['tipo_comunicacao'] = 'outro';
        }
        // Segredo de justica
        if (stripos($bloco, 'SEGREDO DE JUSTI') !== false) {
            $pub['segredo'] = true;
        }
        // Partes
        if (preg_match('/Parte\(s\)(.*?)Advogado\(s\)/us', $bloco, $m)) {
            $linhas = array_filter(array_map('trim', explode("\n", trim($m[1]))));
            foreach ($linhas as $l) {
                $l = preg_replace('/^[\*\-\x{2022}]\s*/u', '', $l);
                if ($l && stripos($l, 'SEGREDO') === false) {
                    $pub['partes'][] = $l;
                }
            }
        }
        // Advogados
        if (preg_match('/Advogado\(s\)(.*?)(?:Poder Judici|$)/us', $bloco, $m)) {
            $linhas = array_filter(array_map('trim', explode("\n", trim($m[1]))));
            foreach ($linhas as $l) {
                $l = preg_replace('/^[\*\-\x{2022}]\s*/u', '', $l);
                if ($l && preg_match('/OAB/i', $l)) {
                    $pub['advogados'][] = $l;
                }
            }
        }
        // Conteudo completo
        $pub['conteudo'] = $bloco;

        // Resumo e Orientação (opcionais — se vierem já escritos no texto colado)
        $pub['resumo'] = '';
        $pub['orientacao'] = '';
        if (preg_match('/(?:^|\n)\s*Resumo\s*[:\-]\s*(.+?)(?=\n\s*(?:Orienta[cç][aã]o|Conte[uú]do|Poder Judici)|\n\n|$)/uis', $bloco, $mR)) {
            $pub['resumo'] = trim(preg_replace('/\s+/u', ' ', $mR[1]));
        }
        if (preg_match('/(?:^|\n)\s*Orienta[cç][aã]o\s*[:\-]\s*(.+?)(?=\n\s*(?:Conte[uú]do|Poder Judici|Resumo)|\n\n|$)/uis', $bloco, $mO)) {
            $pub['orientacao'] = trim(preg_replace('/\s+/u', ' ', $mO[1]));
        }

        // Comarca extraida do orgao
        if (preg_match('/Comarca\s+de\s+([^,\n]+)/ui', $pub['orgao'], $m)) {
            $pub['comarca'] = trim($m[1]);
        }

        $publicacoes[] = $pub;
    }
    return $publicacoes;
}

// Sugestao de prazo por tipo
function prazo_sugerido_djen($tipo) {
    $prazos = array(
        'intimacao' => 15, 'citacao' => 15, 'decisao' => 15,
        'sentenca' => 15, 'despacho' => 5, 'acordao' => 15,
        'edital' => 20, 'outro' => 0,
    );
    return isset($prazos[$tipo]) ? $prazos[$tipo] : 0;
}

// Calcular data fim em dias uteis
function calcular_data_fim_djen($dataInicio, $dias, $pdo) {
    if (!$dias) return null;
    // Usar funcao do sistema se disponivel
    if (function_exists('calcular_prazo_completo')) {
        $res = calcular_prazo_completo($dataInicio, $dias, 'dias', null);
        return isset($res['data_fatal']) ? $res['data_fatal'] : null;
    }
    // Fallback simples
    try {
        $atual = new DateTime($dataInicio);
        $atual->modify('+1 day');
        $cont = 0;
        while ($cont < $dias) {
            $dow = (int)$atual->format('N');
            if ($dow < 6) { $cont++; }
            if ($cont < $dias) { $atual->modify('+1 day'); }
        }
        return $atual->format('Y-m-d');
    } catch (Exception $e) { return null; }
}

// ── POST: processar ──
$resultado = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    // Etapa 1: parsear texto
    if ($_POST['action'] === 'parsear') {
        $texto = $_POST['texto_djen'] ?? '';
        $publicacoes = parsear_djen($texto);
        $parsed = array();
        foreach ($publicacoes as $pub) {
            $numLimpo = preg_replace('/\D/', '', $pub['numero_processo']);
            // Matcha TAMBÉM em arquivados/cancelados — pode ter publicação nova em processo encerrado.
            // Se houver vários com mesmo número, prioriza os ativos.
            $stmtCase = $pdo->prepare(
                "SELECT cs.id, cs.title, cs.comarca, cs.case_type, cs.responsible_user_id, cs.status,
                        c.name as client_name, c.id as client_id
                 FROM cases cs
                 LEFT JOIN clients c ON c.id = cs.client_id
                 WHERE REPLACE(REPLACE(REPLACE(cs.case_number,'-',''),'.',''),'/','') = ?
                 ORDER BY FIELD(cs.status,'arquivado','cancelado','concluido') ASC, cs.id DESC
                 LIMIT 1"
            );
            $stmtCase->execute(array($numLimpo));
            $caso = $stmtCase->fetch();
            $pub['case_id']      = $caso ? (int)$caso['id'] : null;
            $pub['case_title']   = $caso ? $caso['title'] : null;
            $pub['case_status']  = $caso ? $caso['status'] : null;
            $pub['client_name']  = $caso ? $caso['client_name'] : null;
            $pub['client_id']    = $caso ? (int)$caso['client_id'] : null;
            $pub['responsavel']  = $caso ? (int)$caso['responsible_user_id'] : $userId;
            $pub['prazo_dias'] = prazo_sugerido_djen($pub['tipo_comunicacao']);
            $pub['data_fim']   = calcular_data_fim_djen($pub['data_disp'], $pub['prazo_dias'], $pdo);

            // Resumo + orientação: usa o que foi extraído do texto colado (opcional).
            // Se o texto vier sem essas linhas, os campos ficam vazios — sistema não gera nada.
            $pub['resumo_ia'] = isset($pub['resumo']) ? $pub['resumo'] : '';
            $pub['orientacao_ia'] = isset($pub['orientacao']) ? $pub['orientacao'] : '';

            // Verificar duplicata
            $pub['ja_importada'] = false;
            $pub['pub_id_existente'] = null;
            if ($pub['case_id']) {
                try {
                    $stmtDup = $pdo->prepare(
                        "SELECT id FROM case_publicacoes
                         WHERE case_id = ? AND data_disponibilizacao = ? AND tipo_publicacao = ?
                         AND LEFT(conteudo, 100) = LEFT(?, 100)
                         LIMIT 1"
                    );
                    $stmtDup->execute(array(
                        $pub['case_id'],
                        $pub['data_disp'],
                        $pub['tipo_comunicacao'],
                        $pub['conteudo']
                    ));
                    $dup = $stmtDup->fetch();
                    if ($dup) {
                        $pub['ja_importada'] = true;
                        $pub['pub_id_existente'] = $dup['id'];
                    }
                } catch (Exception $e) {}
            }
            $parsed[] = $pub;
        }
        $resultado = $parsed;
    }

    // Etapa 2: importar selecionados
    if ($_POST['action'] === 'importar') {
        if (!validate_csrf()) { flash_set('error', 'Token invalido.'); redirect(module_url('admin', 'djen_importar.php')); exit; }
        $itens = $_POST['itens'] ?? array();
        $importados = 0;
        $erros = array();

        foreach ($itens as $idx => $item) {
            if (empty($item['_sel'])) continue; // nao selecionado

            $caseId     = (int)($item['case_id'] ?? 0);
            $dataDisp   = $item['data_disp'] ?? date('Y-m-d');
            $tipoPub    = $item['tipo_comunicacao'] ?? 'intimacao';
            $conteudo   = trim($item['conteudo'] ?? '');
            $orgao      = trim($item['orgao'] ?? '');
            $comarca    = trim($item['comarca'] ?? '');
            $prazoDias  = (int)($item['prazo_dias'] ?? 0);
            $dataFim    = !empty($item['data_fim']) ? $item['data_fim'] : null;
            $responsavel = (int)($item['responsavel'] ?? $userId);
            $numero     = trim($item['numero_processo'] ?? '');
            $resumoIa   = trim($item['resumo_ia'] ?? '');
            $orientacaoIa = trim($item['orientacao_ia'] ?? '');

            if (!$conteudo || !$numero) continue;

            // Criar pasta se solicitado
            if (!$caseId && !empty($item['criar_pasta'])) {
                $clientId = (int)($item['client_id_novo'] ?? 0);
                $tituloNovo = trim($item['title_novo'] ?? ('Processo ' . $numero));
                if ($clientId && $tituloNovo) {
                    try {
                        $pdo->prepare(
                            "INSERT INTO cases (client_id, title, case_number, court, comarca, status,
                             responsible_user_id, sistema_tribunal, created_at, updated_at)
                             VALUES (?,?,?,?,?,'em_andamento',?,'TJRJ',NOW(),NOW())"
                        )->execute(array($clientId, $tituloNovo, $numero, $orgao, $comarca, $responsavel));
                        $caseId = (int)$pdo->lastInsertId();
                        audit_log('CASE_CRIADO_DJEN', 'case', $caseId, 'Criado via importacao DJen: ' . $numero);
                    } catch (Exception $e) {
                        $erros[] = 'Erro ao criar pasta para ' . $numero . ': ' . $e->getMessage();
                        continue;
                    }
                }
            }

            if (!$caseId) {
                $erros[] = 'Processo ' . $numero . ' sem pasta vinculada.';
                continue;
            }

            // Verificar duplicata — so bloqueia se nao veio flag de forcar reimport
            $forcar = !empty($item['forcar_reimport']) && $item['forcar_reimport'] === '1';
            if (!$forcar) {
                try {
                    $stmtDup = $pdo->prepare(
                        "SELECT id FROM case_publicacoes WHERE case_id = ? AND data_disponibilizacao = ? AND tipo_publicacao = ? AND LEFT(conteudo, 100) = LEFT(?, 100) LIMIT 1"
                    );
                    $stmtDup->execute(array($caseId, $dataDisp, $tipoPub, $conteudo));
                    if ($stmtDup->fetch()) {
                        $erros[] = 'ignorada:' . $numero;
                        continue;
                    }
                } catch (Exception $e) {}
            }

            // Recalcular data_fim com feriados no backend
            if ($prazoDias > 0) {
                $dataFim = calcular_data_fim_djen($dataDisp, $prazoDias, $pdo);
            }

            // Salvar publicacao
            try {
                $pdo->prepare(
                    "INSERT INTO case_publicacoes
                     (case_id, data_disponibilizacao, conteudo, caderno, tribunal,
                      tipo_publicacao, fonte, prazo_dias, data_prazo_fim,
                      status_prazo, visivel_cliente, resumo_ia, orientacao_ia, criado_por, created_at)
                     VALUES (?,?,?,'DJEN',?,?,'manual',?,?,'pendente',0,?,?,?,NOW())"
                )->execute(array(
                    $caseId, $dataDisp, $conteudo, $orgao, $tipoPub,
                    $prazoDias ?: null, $dataFim,
                    $resumoIa ?: null, $orientacaoIa ?: null, $userId
                ));
                $pubId = (int)$pdo->lastInsertId();

                // Cria andamento TRANCADO (visivel_cliente=0) na linha do tempo do processo.
                // Amanda destrava manualmente depois de revisar.
                try {
                    $tipoAndLbl = array(
                        'intimacao'=>'Intimação','citacao'=>'Citação','despacho'=>'Despacho',
                        'decisao'=>'Decisão','sentenca'=>'Sentença','acordao'=>'Acórdão',
                        'edital'=>'Edital','outro'=>'Publicação'
                    );
                    $lblAnd = isset($tipoAndLbl[$tipoPub]) ? $tipoAndLbl[$tipoPub] : 'Publicação';
                    $descAnd = '📢 ' . $lblAnd . ' — DJen (' . date('d/m/Y', strtotime($dataDisp)) . ')';
                    if ($resumoIa) $descAnd .= "\n\n📝 Resumo: " . $resumoIa;
                    if ($orientacaoIa) $descAnd .= "\n⚖️ Orientação: " . $orientacaoIa;
                    if ($dataFim) $descAnd .= "\n⏰ Prazo fatal: " . date('d/m/Y', strtotime($dataFim));
                    $descAnd .= "\n\n— Conteúdo completo —\n" . mb_substr($conteudo, 0, 2000, 'UTF-8');

                    $pdo->prepare(
                        "INSERT INTO case_andamentos
                         (case_id, data_andamento, tipo, descricao, visivel_cliente, created_by, created_at)
                         VALUES (?,?,'publicacao',?,0,?,NOW())"
                    )->execute(array($caseId, $dataDisp, $descAnd, $userId));
                } catch (Exception $eAnd) {
                    @file_put_contents(APP_ROOT . '/files/djen_ia.log', '[' . date('Y-m-d H:i:s') . "] ERRO ANDAMENTO pub={$pubId}: " . $eAnd->getMessage() . "\n", FILE_APPEND);
                }

                // Criar tarefa se tem prazo
                if ($dataFim) {
                    $stmtCase2 = $pdo->prepare("SELECT title, responsible_user_id FROM cases WHERE id = ?");
                    $stmtCase2->execute(array($caseId));
                    $casoRow = $stmtCase2->fetch();
                    $tituloCase = $casoRow ? $casoRow['title'] : 'Caso #' . $caseId;
                    $respCase = $casoRow ? (int)$casoRow['responsible_user_id'] : $responsavel;

                    $tipoLbl = array(
                        'intimacao'=>'INTIMAÇÃO','citacao'=>'CITAÇÃO','despacho'=>'DESPACHO',
                        'decisao'=>'DECISÃO','sentenca'=>'SENTENÇA','acordao'=>'ACÓRDÃO',
                        'edital'=>'EDITAL','outro'=>'PUBLICAÇÃO'
                    );
                    $lbl = isset($tipoLbl[$tipoPub]) ? $tipoLbl[$tipoPub] : 'PUBLICAÇÃO';

                    $prazoAlerta = date('Y-m-d', strtotime($dataFim . ' -3 days'));

                    $pdo->prepare(
                        "INSERT INTO case_tasks
                         (case_id, title, descricao, tipo, subtipo, due_date,
                          prazo_alerta, status, prioridade, assigned_to, created_at)
                         VALUES (?,?,?,'prazo','prazo_publicacao',?,?,'a_fazer','alta',?,NOW())"
                    )->execute(array(
                        $caseId,
                        'PRAZO - ' . $lbl . ' | ' . $tituloCase,
                        'Prazo de ' . $prazoDias . 'du a partir de ' . date('d/m/Y', strtotime($dataDisp)) . '. Vence: ' . date('d/m/Y', strtotime($dataFim)),
                        $dataFim, $prazoAlerta, $responsavel
                    ));
                    $taskId = (int)$pdo->lastInsertId();

                    $pdo->prepare("UPDATE case_publicacoes SET task_id = ? WHERE id = ?")->execute(array($taskId, $pubId));

                    // Evento na agenda
                    $pdo->prepare(
                        "INSERT INTO agenda_eventos
                         (case_id, titulo, descricao, data_inicio, data_fim, dia_todo,
                          tipo, responsavel_id, created_by, created_at)
                         VALUES (?,?,?,?,?,1,'prazo',?,?,NOW())"
                    )->execute(array(
                        $caseId, 'Publicacao: ' . $lbl . ' | ' . $tituloCase,
                        mb_substr($conteudo, 0, 300, 'UTF-8'),
                        $dataDisp . ' 08:00:00', $dataDisp . ' 08:30:00',
                        $responsavel, $userId
                    ));

                    // Notificar responsavel
                    if ($respCase && $respCase !== $userId) {
                        notify($respCase, 'Novo prazo: ' . $lbl,
                            'Vence em ' . date('d/m/Y', strtotime($dataFim)) . ' - ' . $tituloCase,
                            'warning', module_url('operacional', 'caso_ver.php?id=' . $caseId), '');
                    }
                }

                audit_log('PUBLICACAO_IMPORTADA_DJEN', 'case', $caseId, 'pub_id=' . $pubId . ' processo=' . $numero);
                $importados++;

            } catch (Exception $e) {
                $erros[] = 'Erro em ' . $numero . ': ' . $e->getMessage();
            }
        }

        flash_set('success', $importados . ' publicação(ões) importada(s).' . (!empty($erros) ? ' ' . count($erros) . ' ignorada(s).' : ''));
        if (!empty($erros)) { $_SESSION['djen_erros'] = $erros; }
        redirect(module_url('admin', 'djen_importar.php'));
        exit;
    }

    // Etapa 3: processar uma publicação PENDENTE da skill automatizada
    // (Amanda escolheu pasta existente OU criar nova pasta)
    if ($_POST['action'] === 'processar_pendente') {
        if (!validate_csrf()) { flash_set('error', 'Token inválido.'); redirect(module_url('admin', 'djen_importar.php')); exit; }
        $pendingId = (int)($_POST['pending_id'] ?? 0);
        $caseIdEscolhido = (int)($_POST['case_id_escolhido'] ?? 0);
        $acao = $_POST['acao_pendente'] ?? '';

        if (!$pendingId) { flash_set('error', 'Pendente inválida.'); redirect(module_url('admin', 'djen_importar.php')); exit; }
        $stmtP = $pdo->prepare("SELECT * FROM djen_pending WHERE id = ? AND status = 'pendente'");
        $stmtP->execute(array($pendingId));
        $pendente = $stmtP->fetch();
        if (!$pendente) { flash_set('error', 'Pendente não encontrada ou já processada.'); redirect(module_url('admin', 'djen_importar.php')); exit; }

        if ($acao === 'descartar') {
            $pdo->prepare("UPDATE djen_pending SET status='descartado' WHERE id = ?")->execute(array($pendingId));
            flash_set('success', 'Publicação descartada.');
            redirect(module_url('admin', 'djen_importar.php'));
            exit;
        }

        // Cria pasta nova se pediu
        if ($acao === 'criar' && !$caseIdEscolhido) {
            $clientIdNovo = (int)($_POST['client_id_novo'] ?? 0);
            $tituloNovo = trim($_POST['title_novo'] ?? ('Processo ' . $pendente['numero_processo']));
            if (!$clientIdNovo || !$tituloNovo) { flash_set('error', 'Escolha cliente e título.'); redirect(module_url('admin', 'djen_importar.php')); exit; }
            try {
                $pdo->prepare(
                    "INSERT INTO cases (client_id, title, case_number, court, comarca, status,
                     responsible_user_id, sistema_tribunal, created_at, updated_at)
                     VALUES (?,?,?,?,?,'em_andamento',?,'TJRJ',NOW(),NOW())"
                )->execute(array(
                    $clientIdNovo, $tituloNovo, $pendente['numero_processo'],
                    $pendente['orgao'], $pendente['comarca'], $userId
                ));
                $caseIdEscolhido = (int)$pdo->lastInsertId();
                if (function_exists('audit_log')) audit_log('CASE_CRIADO_DJEN', 'case', $caseIdEscolhido, 'via pendente skill: ' . $pendente['numero_processo']);
            } catch (Exception $e) {
                flash_set('error', 'Erro ao criar pasta: ' . $e->getMessage());
                redirect(module_url('admin', 'djen_importar.php'));
                exit;
            }
        }

        if (!$caseIdEscolhido) { flash_set('error', 'Pasta não informada.'); redirect(module_url('admin', 'djen_importar.php')); exit; }

        // Monta array no formato de $pub
        $pubArr = array(
            'numero_processo'  => $pendente['numero_processo'],
            'data_disp'        => $pendente['data_disp'] ?: date('Y-m-d'),
            'tipo_comunicacao' => $pendente['tipo_comunicacao'] ?: 'intimacao',
            'orgao'            => $pendente['orgao'] ?: '',
            'comarca'          => $pendente['comarca'] ?: '',
            'conteudo'         => $pendente['conteudo'] ?: '',
            'resumo'           => $pendente['resumo'] ?: '',
            'orientacao'       => $pendente['orientacao'] ?: '',
        );
        $result = djen_importar_publicacao($pdo, $pubArr, $caseIdEscolhido, $userId);
        if (is_array($result) && isset($result['pub_id'])) {
            $pdo->prepare("UPDATE djen_pending SET status='importado', case_id=? WHERE id = ?")->execute(array($caseIdEscolhido, $pendingId));
            flash_set('success', 'Publicação importada.');
        } elseif (is_array($result) && !empty($result['duplicated'])) {
            $pdo->prepare("UPDATE djen_pending SET status='importado', case_id=? WHERE id = ?")->execute(array($caseIdEscolhido, $pendingId));
            flash_set('success', 'Publicação já existia na pasta — marcada como processada.');
        } else {
            flash_set('error', 'Falha ao importar.');
        }
        redirect(module_url('admin', 'djen_importar.php'));
        exit;
    }
}

// Carrega pendentes (enviadas pela skill via endpoint, aguardando Amanda)
$pendentes = array();
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS djen_pending (
        id INT AUTO_INCREMENT PRIMARY KEY,
        numero_processo VARCHAR(40) NOT NULL,
        data_disp DATE NULL,
        tipo_comunicacao VARCHAR(30) NULL,
        orgao VARCHAR(200) NULL,
        comarca VARCHAR(100) NULL,
        partes TEXT NULL,
        advogados TEXT NULL,
        conteudo TEXT NOT NULL,
        resumo TEXT NULL,
        orientacao TEXT NULL,
        segredo TINYINT(1) DEFAULT 0,
        status ENUM('pendente','importado','descartado') DEFAULT 'pendente',
        case_id INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_numero (numero_processo),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pendentes = $pdo->query("SELECT * FROM djen_pending WHERE status = 'pendente' ORDER BY created_at DESC LIMIT 50")->fetchAll();
} catch (Exception $e) {}

// Buscar clientes para select
$clientes = array();
try { $clientes = $pdo->query("SELECT id, name, cpf FROM clients ORDER BY name")->fetchAll(); } catch (Exception $e) {}

$pageTitle = 'Importar Publicações DJen';
require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.djen-wrap { max-width:1100px; margin:0 auto; }
.djen-card { background:var(--bg-card); border:1px solid var(--border); border-radius:12px; padding:1.4rem; margin-bottom:1.2rem; }
.pub-row { border:1px solid var(--border); border-radius:10px; padding:1rem; margin-bottom:.8rem; transition:.15s; }
.pub-row.encontrado { border-left:4px solid #059669; }
.pub-row.nao-encontrado { border-left:4px solid #d97706; }
.pub-row.segredo { border-left:4px solid #6b7280; }
.pub-row.arquivado { border-left:4px solid #f59e0b; background:#fffbeb; }
.ia-box { background:#eef2ff; border:1px solid #c7d2fe; border-radius:8px; padding:.55rem .75rem; margin-top:.5rem; font-size:.75rem; line-height:1.5; }
.ia-box .ia-label { font-size:.65rem; text-transform:uppercase; font-weight:700; color:#6366f1; letter-spacing:.4px; }
.ia-box .ia-orientacao { margin-top:.35rem; padding-top:.35rem; border-top:1px dashed #c7d2fe; color:#4338ca; font-weight:600; }
.pub-row .numero { font-size:.85rem; font-weight:800; color:var(--petrol-900); font-family:monospace; }
.pub-row .orgao-txt { font-size:.75rem; color:var(--text-muted); margin-top:2px; }
.pub-row .case-badge { font-size:.7rem; font-weight:700; padding:2px 8px; border-radius:4px; }
.pub-row .case-badge.ok { background:#ecfdf5; color:#059669; }
.pub-row .case-badge.warn { background:#fef3c7; color:#d97706; }
.pub-row .case-badge.seg { background:#f1f5f9; color:#6b7280; }
.pub-row .conteudo-txt { font-size:.78rem; color:#374151; white-space:pre-wrap; max-height:80px; overflow:hidden; margin-top:.5rem; line-height:1.5; }
.pub-row .conteudo-txt.expandido { max-height:none; }
.criar-pasta-form { background:#fffbeb; border:1px solid #fcd34d; border-radius:8px; padding:.8rem; margin-top:.6rem; }
.prazo-input { width:60px; font-size:.8rem; padding:3px 6px; border:1px solid var(--border); border-radius:6px; text-align:center; }
.btn-expandir { background:none; border:none; color:#3b82f6; font-size:.7rem; cursor:pointer; padding:0; font-family:inherit; }
.stat-pill { font-size:.72rem; font-weight:700; padding:3px 10px; border-radius:20px; }
.stat-pill.verde { background:#ecfdf5; color:#059669; }
.stat-pill.amarelo { background:#fef3c7; color:#d97706; }
.stat-pill.cinza { background:#f1f5f9; color:#6b7280; }
.pub-row.ja-importada { display:none; opacity:.55; }
.pub-row.ja-importada.visivel-dup { display:block; opacity:1; }
</style>

<div class="djen-wrap">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.2rem;flex-wrap:wrap;gap:.8rem;">
        <div>
            <h2 style="margin:0;font-size:1.3rem;color:var(--petrol-900);">Importar Publicações DJen</h2>
            <p style="margin:.2rem 0 0;font-size:.8rem;color:var(--text-muted);">Cole o texto copiado do portal DJen — o sistema identifica e vincula automaticamente</p>
        </div>
        <a href="<?= module_url('admin', 'datajud_monitor.php') ?>" class="btn btn-outline btn-sm">Monitor DataJud</a>
    </div>

    <?php
    if (!empty($_SESSION['djen_erros'])) {
        $ignoradas = array_filter($_SESSION['djen_erros'], function($e){ return strpos($e, 'ignorada:') === 0; });
        $errosReais = array_filter($_SESSION['djen_erros'], function($e){ return strpos($e, 'ignorada:') !== 0; });
        if (!empty($ignoradas)):
    ?>
    <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:.8rem 1.2rem;margin-bottom:.8rem;">
        <div style="font-size:.8rem;font-weight:700;color:#3b82f6;margin-bottom:.3rem;">
            <?= count($ignoradas) ?> publicação(ões) já existiam e foram ignoradas:
        </div>
        <?php foreach ($ignoradas as $ig): ?>
            <div style="font-size:.72rem;color:#3b82f6;"><?= e(str_replace('ignorada:', '', $ig)) ?></div>
        <?php endforeach; ?>
    </div>
    <?php endif; if (!empty($errosReais)): ?>
    <div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:.8rem 1.2rem;margin-bottom:.8rem;">
        <div style="font-size:.8rem;font-weight:700;color:#dc2626;margin-bottom:.3rem;">Erros:</div>
        <?php foreach ($errosReais as $err): ?>
            <div style="font-size:.75rem;color:#dc2626;"><?= e($err) ?></div>
        <?php endforeach; ?>
    </div>
    <?php endif;
        unset($_SESSION['djen_erros']);
    } ?>

    <!-- Etapa 1: Colar texto -->
    <div class="djen-card">
        <h3 style="margin:0 0 .8rem;font-size:.95rem;">1. Cole o texto das publicações</h3>
        <details style="margin-bottom:.6rem;font-size:.75rem;">
            <summary style="cursor:pointer;color:#6366f1;font-weight:600;">📋 Como o sistema separa as publicações? (clique pra ver)</summary>
            <div style="margin-top:.5rem;padding:.6rem .8rem;background:#f8fafc;border-left:3px solid #6366f1;border-radius:4px;font-size:.72rem;line-height:1.6;">
                Cada publicação precisa começar com a linha <code>Processo NNNNNNN-NN.AAAA.J.TR.NNNN</code> (CNJ completo). O sistema separa automaticamente quando detecta esse padrão.<br>
                <br>
                <strong>Campos reconhecidos:</strong><br>
                • <code>Orgão:</code> vara/tribunal<br>
                • <code>Data de disponibilização:</code> DD/MM/AAAA<br>
                • <code>Tipo de comunicação:</code> Intimação / Citação / Edital...<br>
                • <code>Parte(s)</code> e <code>Advogado(s)</code> em blocos<br>
                • <code>Resumo:</code> (opcional) — resumo curto da publicação<br>
                • <code>Orientação:</code> (opcional) — o que fazer e em quanto tempo<br>
                <br>
                Se o texto colado já vier com <strong>Resumo:</strong> e <strong>Orientação:</strong> escritos, o sistema extrai e exibe junto. Senão, fica em branco — sem geração automática.
            </div>
        </details>
        <form method="POST">
            <input type="hidden" name="action" value="parsear">
            <textarea name="texto_djen" id="textoDjen" class="form-input"
                rows="10" style="width:100%;font-size:.78rem;font-family:monospace;resize:vertical;"
                placeholder="Cole aqui o texto completo das publicações..."></textarea>
            <div style="display:flex;gap:.6rem;align-items:center;margin-top:.6rem;flex-wrap:wrap;">
                <button type="submit" class="btn btn-primary btn-sm">Identificar Publicações</button>
                <button type="button" onclick="document.getElementById('textoDjen').value=''" class="btn btn-outline btn-sm">Limpar</button>
                <span style="font-size:.72rem;color:var(--text-muted);">O sistema separa e vincula às pastas automaticamente</span>
            </div>
        </form>
    </div>

    <?php if (!empty($pendentes)): ?>
    <div class="djen-card" style="border:2px solid #6366f1;background:#eef2ff;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.8rem;flex-wrap:wrap;gap:.6rem;">
            <h3 style="margin:0;font-size:.95rem;color:#4338ca;">🤖 Publicações pendentes da skill automatizada (<?= count($pendentes) ?>)</h3>
            <span style="font-size:.7rem;color:#4338ca;">Publicações sem pasta — escolha uma existente ou crie nova</span>
        </div>

        <?php foreach ($pendentes as $pi):
            $partesArr = !empty($pi['partes']) ? json_decode($pi['partes'], true) : array();
            if (!is_array($partesArr)) $partesArr = array();
            $clienteSugNome = null;
            foreach ($clientes as $cl) {
                foreach ($partesArr as $pn) {
                    if (mb_stripos($pn, $cl['name']) !== false || mb_stripos($cl['name'], $pn) !== false) {
                        $clienteSugNome = $cl['name']; break 2;
                    }
                }
            }
            $tituloSug = 'Processo ' . $pi['numero_processo'];
            if (count($partesArr) >= 2) {
                $p1 = preg_replace('/\s+/', ' ', trim($partesArr[0]));
                $p2 = preg_replace('/\s+/', ' ', trim($partesArr[1]));
                if ($p1 && $p2 && strlen($p1) < 80 && strlen($p2) < 80) $tituloSug = $p1 . ' x ' . $p2;
            } elseif (count($partesArr) === 1) {
                $tituloSug = trim($partesArr[0]) . ' — ' . $pi['numero_processo'];
            }
        ?>
        <div class="pub-row" style="border-left:4px solid #6366f1;background:#fff;">
            <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;margin-bottom:.3rem;">
                <span class="numero"><?= e($pi['numero_processo']) ?></span>
                <span class="case-badge" style="background:#eef2ff;color:#6366f1;border:1px solid #c7d2fe;">Recebida <?= e(date('d/m H:i', strtotime($pi['created_at']))) ?></span>
                <span style="font-size:.68rem;color:var(--text-muted);">
                    <?= e(ucfirst($pi['tipo_comunicacao'])) ?> &middot; <?= $pi['data_disp'] ? date('d/m/Y', strtotime($pi['data_disp'])) : '—' ?>
                </span>
                <?php if ($pi['segredo']): ?><span class="case-badge seg">Segredo</span><?php endif; ?>
            </div>
            <?php if ($pi['orgao']): ?><div class="orgao-txt"><?= e($pi['orgao']) ?></div><?php endif; ?>

            <?php if (!empty($pi['resumo']) || !empty($pi['orientacao'])): ?>
            <div class="ia-box">
                <?php if (!empty($pi['resumo'])): ?><div><span class="ia-label">📝 Resumo:</span> <?= e($pi['resumo']) ?></div><?php endif; ?>
                <?php if (!empty($pi['orientacao'])): ?><div class="ia-orientacao"><span class="ia-label">⚖️ Orientação:</span> <?= e($pi['orientacao']) ?></div><?php endif; ?>
            </div>
            <?php endif; ?>

            <div class="conteudo-txt" id="cpend<?= $pi['id'] ?>"><?= e(mb_substr($pi['conteudo'], 0, 250, 'UTF-8')) ?></div>
            <button type="button" class="btn-expandir" onclick="var el=document.getElementById('cpend<?= $pi['id'] ?>');el.classList.toggle('expandido');el.style.maxHeight=el.classList.contains('expandido')?'none':'80px';">Ver completo</button>

            <?php if (!empty($partesArr)): ?>
                <div style="font-size:.68rem;color:var(--text-muted);margin-top:.4rem;">📋 Partes: <strong><?= e(implode(' · ', array_slice($partesArr, 0, 4))) ?></strong></div>
            <?php endif; ?>

            <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-top:.7rem;align-items:center;">
                <!-- Form A: vincular a pasta existente via autocomplete -->
                <form method="POST" style="display:flex;gap:.4rem;align-items:center;flex-wrap:wrap;">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="processar_pendente">
                    <input type="hidden" name="pending_id" value="<?= (int)$pi['id'] ?>">
                    <input type="hidden" name="acao_pendente" value="vincular">
                    <label style="font-size:.7rem;color:#4338ca;font-weight:600;">Vincular a pasta existente:</label>
                    <input type="number" name="case_id_escolhido" placeholder="ID da pasta" style="width:110px;font-size:.78rem;padding:3px 8px;border:1px solid #c7d2fe;border-radius:6px;" required>
                    <button type="submit" class="btn btn-primary btn-sm" style="font-size:.72rem;padding:4px 10px;">Importar</button>
                </form>

                <!-- Form B: criar pasta nova -->
                <button type="button" class="btn btn-outline btn-sm" style="font-size:.72rem;border-color:#d97706;color:#d97706;" onclick="var b=document.getElementById('crPend<?= $pi['id'] ?>');b.style.display=b.style.display==='none'?'block':'none';">+ Criar pasta nova</button>

                <!-- Descartar -->
                <form method="POST" style="display:inline;" onsubmit="return confirm('Descartar esta publicação? Não pode desfazer.');">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="processar_pendente">
                    <input type="hidden" name="pending_id" value="<?= (int)$pi['id'] ?>">
                    <input type="hidden" name="acao_pendente" value="descartar">
                    <button type="submit" class="btn btn-outline btn-sm" style="font-size:.72rem;border-color:#94a3b8;color:#64748b;">Descartar</button>
                </form>
            </div>

            <!-- Form B expandido: criar pasta nova -->
            <form method="POST" id="crPend<?= $pi['id'] ?>" class="criar-pasta-form" style="display:none;margin-top:.5rem;">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="processar_pendente">
                <input type="hidden" name="pending_id" value="<?= (int)$pi['id'] ?>">
                <input type="hidden" name="acao_pendente" value="criar">
                <div style="font-size:.75rem;font-weight:700;color:#d97706;margin-bottom:.5rem;">Nova pasta</div>
                <?php if ($clienteSugNome): ?>
                    <div style="font-size:.7rem;color:#059669;margin-bottom:.4rem;">✅ Cliente identificado: <strong><?= e($clienteSugNome) ?></strong></div>
                <?php else: ?>
                    <div style="font-size:.7rem;color:#d97706;margin-bottom:.4rem;">⚠️ Nenhum cliente bateu com as partes — selecione manualmente</div>
                <?php endif; ?>
                <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:.4rem;">
                    <input type="text" name="title_novo" style="width:280px;font-size:.78rem;padding:4px 8px;border:1px solid var(--border);border-radius:6px;" value="<?= e($tituloSug) ?>" placeholder="Título" required>
                    <select name="client_id_novo" style="width:220px;font-size:.78rem;padding:4px 8px;border:1px solid var(--border);border-radius:6px;" required>
                        <option value="">— Cliente —</option>
                        <?php foreach ($clientes as $cl):
                            $presel = false;
                            foreach ($partesArr as $pn) {
                                if (mb_stripos($pn, $cl['name']) !== false || mb_stripos($cl['name'], $pn) !== false) { $presel = true; break; }
                            }
                        ?>
                        <option value="<?= $cl['id'] ?>" <?= $presel ? 'selected' : '' ?>><?= e($cl['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-primary btn-sm" style="font-size:.72rem;">Criar e importar</button>
                </div>
            </form>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($resultado !== null): ?>
    <?php
    $qtdEncontrados = count(array_filter($resultado, function($p){ return $p['case_id']; }));
    $qtdNaoEncontrados = count(array_filter($resultado, function($p){ return !$p['case_id']; }));
    $qtdSegredo      = count(array_filter($resultado, function($p){ return $p['segredo']; }));
    $qtdJaImportadas = count(array_filter($resultado, function($p){ return !empty($p['ja_importada']); }));
    ?>
    <div class="djen-card">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.8rem;flex-wrap:wrap;gap:.6rem;">
            <h3 style="margin:0;font-size:.95rem;">2. Revisar e Importar (<?= count($resultado) ?> publicações)</h3>
            <div style="display:flex;gap:.4rem;flex-wrap:wrap;">
                <span class="stat-pill verde"><?= $qtdEncontrados ?> com pasta</span>
                <span class="stat-pill amarelo"><?= $qtdNaoEncontrados ?> sem pasta</span>
                <?php if ($qtdSegredo): ?><span class="stat-pill cinza"><?= $qtdSegredo ?> segredo</span><?php endif; ?>
                <?php if ($qtdJaImportadas): ?>
                    <button type="button" class="stat-pill" id="btnMostrarDups"
                        style="background:#eff6ff;color:#3b82f6;border:none;cursor:pointer;font-family:inherit;"
                        onclick="toggleDuplicatas()">
                        <?= $qtdJaImportadas ?> já importada(s) — clique para ver
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <form method="POST">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="importar">

            <div style="display:flex;gap:.5rem;align-items:center;margin-bottom:.8rem;flex-wrap:wrap;">
                <button type="button" onclick="selDjen(true)" class="btn btn-outline btn-sm">Selecionar todos</button>
                <button type="button" onclick="selDjen(false)" class="btn btn-outline btn-sm">Desmarcar todos</button>
                <button type="button" onclick="selDjenEnc()" class="btn btn-outline btn-sm" style="border-color:#059669;color:#059669;">So com pasta</button>
                <span style="font-size:.72rem;color:var(--text-muted);margin-left:.5rem;" id="contadorSel">0 selecionados</span>
            </div>

            <?php foreach ($resultado as $idx => $pub):
                $encontrado = !empty($pub['case_id']);
                $jaImportada = !empty($pub['ja_importada']);
                $caseArquivado = $encontrado && isset($pub['case_status']) && in_array($pub['case_status'], array('arquivado','cancelado','concluido'));
                $rowClass = $caseArquivado ? 'arquivado' : ($pub['segredo'] ? 'segredo' : ($encontrado ? 'encontrado' : 'nao-encontrado'));
                if ($jaImportada) $rowClass .= ' ja-importada';
                $statusCaseLbl = array(
                    'arquivado' => 'ARQUIVADO', 'cancelado' => 'CANCELADO', 'concluido' => 'CONCLUÍDO',
                    'em_andamento' => '', 'em_elaboracao' => '', 'aguardando_docs' => '',
                    'suspenso' => 'SUSPENSO', 'distribuido' => '', 'aguardando_prazo' => '',
                );
                $lblStatusCase = (isset($pub['case_status']) && isset($statusCaseLbl[$pub['case_status']])) ? $statusCaseLbl[$pub['case_status']] : '';
            ?>
            <div class="pub-row <?= $rowClass ?>" id="pubRow<?= $idx ?>">
                <div style="display:flex;align-items:flex-start;gap:.7rem;">
                    <input type="checkbox" name="itens[<?= $idx ?>][_sel]" value="1"
                           class="cb-pub <?= $jaImportada ? 'cb-dup' : '' ?>"
                           onchange="contSel()"
                           <?= ($encontrado && !$jaImportada) ? 'checked' : '' ?>
                           style="margin-top:3px;width:16px;height:16px;flex-shrink:0;">
                    <?php if ($jaImportada): ?>
                        <input type="hidden" name="itens[<?= $idx ?>][forcar_reimport]" class="inp-reimport" value="0">
                    <?php endif; ?>
                    <div style="flex:1;min-width:0;">
                        <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;margin-bottom:.3rem;">
                            <span class="numero"><?= e($pub['numero_processo']) ?></span>
                            <?php if ($jaImportada): ?>
                                <span class="case-badge" style="background:#eff6ff;color:#3b82f6;border:1px solid #bfdbfe;">Já importada</span>
                            <?php endif; ?>
                            <?php if ($encontrado): ?>
                                <span class="case-badge ok"><?= e($pub['case_title']) ?> — <?= e($pub['client_name']) ?></span>
                                <?php if ($lblStatusCase): ?>
                                    <span class="case-badge" style="background:#fef3c7;color:#d97706;border:1px solid #fde68a;"><?= e($lblStatusCase) ?></span>
                                <?php endif; ?>
                            <?php elseif ($pub['segredo']): ?>
                                <span class="case-badge seg">Segredo de Justiça — crie a pasta abaixo</span>
                            <?php else: ?>
                                <span class="case-badge warn">Pasta não encontrada</span>
                            <?php endif; ?>
                            <span style="font-size:.68rem;color:var(--text-muted);">
                                <?= e(ucfirst($pub['tipo_comunicacao'])) ?> &middot; <?= date('d/m/Y', strtotime($pub['data_disp'])) ?>
                            </span>
                        </div>
                        <div class="orgao-txt"><?= e($pub['orgao']) ?></div>

                        <?php if (!empty($pub['resumo_ia']) || !empty($pub['orientacao_ia'])): ?>
                        <div class="ia-box">
                            <?php if (!empty($pub['resumo_ia'])): ?>
                                <div><span class="ia-label">📝 Resumo:</span> <?= e($pub['resumo_ia']) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($pub['orientacao_ia'])): ?>
                                <div class="ia-orientacao"><span class="ia-label">⚖️ Orientação:</span> <?= e($pub['orientacao_ia']) ?></div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <div class="conteudo-txt" id="cont<?= $idx ?>"><?= e(mb_substr($pub['conteudo'], 0, 300, 'UTF-8')) ?></div>
                        <button type="button" class="btn-expandir" onclick="expDjen(<?= $idx ?>)">Ver completo</button>

                        <input type="hidden" name="itens[<?= $idx ?>][numero_processo]" value="<?= e($pub['numero_processo']) ?>">
                        <input type="hidden" name="itens[<?= $idx ?>][case_id]" value="<?= (int)($pub['case_id'] ?? 0) ?>">
                        <input type="hidden" name="itens[<?= $idx ?>][client_id]" value="<?= (int)($pub['client_id'] ?? 0) ?>">
                        <input type="hidden" name="itens[<?= $idx ?>][data_disp]" value="<?= e($pub['data_disp']) ?>">
                        <input type="hidden" name="itens[<?= $idx ?>][tipo_comunicacao]" value="<?= e($pub['tipo_comunicacao']) ?>">
                        <input type="hidden" name="itens[<?= $idx ?>][orgao]" value="<?= e($pub['orgao']) ?>">
                        <input type="hidden" name="itens[<?= $idx ?>][comarca]" value="<?= e($pub['comarca'] ?? '') ?>">
                        <input type="hidden" name="itens[<?= $idx ?>][conteudo]" value="<?= e($pub['conteudo']) ?>">
                        <input type="hidden" name="itens[<?= $idx ?>][resumo_ia]" value="<?= e($pub['resumo_ia'] ?? '') ?>">
                        <input type="hidden" name="itens[<?= $idx ?>][orientacao_ia]" value="<?= e($pub['orientacao_ia'] ?? '') ?>">
                        <input type="hidden" name="itens[<?= $idx ?>][data_fim]" id="dataFim<?= $idx ?>" value="<?= e($pub['data_fim'] ?? '') ?>">

                        <div style="display:flex;align-items:center;gap:.5rem;margin-top:.5rem;flex-wrap:wrap;">
                            <label style="font-size:.72rem;color:var(--text-muted);font-weight:600;">Prazo (du):</label>
                            <input type="number" class="prazo-input" name="itens[<?= $idx ?>][prazo_dias]"
                                   value="<?= (int)$pub['prazo_dias'] ?>" min="0" max="365"
                                   onchange="recalcFim(<?= $idx ?>, this.value, '<?= e($pub['data_disp']) ?>')">
                            <span style="font-size:.72rem;font-weight:700;color:<?= $pub['data_fim'] ? '#dc2626' : 'var(--text-muted)' ?>;" id="labelFim<?= $idx ?>">
                                <?= $pub['data_fim'] ? 'Vence: ' . date('d/m/Y', strtotime($pub['data_fim'])) : 'Sem prazo' ?>
                            </span>
                            <label style="font-size:.72rem;color:var(--text-muted);font-weight:600;margin-left:.5rem;">Responsável:</label>
                            <select name="itens[<?= $idx ?>][responsavel]" style="font-size:.72rem;padding:2px 6px;border:1px solid var(--border);border-radius:6px;">
                                <?php foreach ($usuarios as $u): ?>
                                <option value="<?= $u['id'] ?>" <?= ($u['id'] == ($pub['responsavel'] ?? $userId)) ? 'selected' : '' ?>><?= e(explode(' ', $u['name'])[0]) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <?php if (!$encontrado): // libera pra segredo também — só precisa de cliente+título
                            // Título sugerido: "Parte1 x Parte2" (2 primeiras) senão só CNJ
                            $tituloSug = 'Processo ' . $pub['numero_processo'];
                            if (!empty($pub['partes']) && count($pub['partes']) >= 2) {
                                $p1 = preg_replace('/\s+/', ' ', trim($pub['partes'][0]));
                                $p2 = preg_replace('/\s+/', ' ', trim($pub['partes'][1]));
                                if ($p1 && $p2 && strlen($p1) < 80 && strlen($p2) < 80) {
                                    $tituloSug = $p1 . ' x ' . $p2;
                                }
                            } elseif (!empty($pub['partes'])) {
                                $tituloSug = trim($pub['partes'][0]) . ' — ' . $pub['numero_processo'];
                            }
                            // Cliente sugerido (match com partes)
                            $clienteSugNome = null;
                            foreach ($clientes as $cl) {
                                foreach ($pub['partes'] as $pn) {
                                    if (mb_stripos($pn, $cl['name']) !== false || mb_stripos($cl['name'], $pn) !== false) {
                                        $clienteSugNome = $cl['name']; break 2;
                                    }
                                }
                            }
                        ?>
                        <div style="margin-top:.6rem;">
                            <button type="button" class="btn btn-sm btn-outline" style="font-size:.7rem;border-color:#d97706;color:#d97706;" onclick="togCriar(<?= $idx ?>)">+ Criar pasta deste processo</button>
                            <div id="criarPasta<?= $idx ?>" class="criar-pasta-form" style="display:none;">
                                <div style="font-size:.75rem;font-weight:700;color:#d97706;margin-bottom:.5rem;">Nova pasta <span style="font-weight:400;color:var(--text-muted);">— dados já preenchidos a partir da publicação</span></div>
                                <?php if ($clienteSugNome): ?>
                                <div style="font-size:.72rem;color:#059669;margin-bottom:.5rem;">✅ Cliente identificado automaticamente: <strong><?= e($clienteSugNome) ?></strong> — confirme ou altere abaixo</div>
                                <?php else: ?>
                                <div style="font-size:.72rem;color:#d97706;margin-bottom:.5rem;">⚠️ Nenhum cliente foi identificado nas partes — selecione manualmente</div>
                                <?php endif; ?>
                                <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:.5rem;">
                                    <div style="display:flex;flex-direction:column;gap:2px;">
                                        <label style="font-size:.65rem;color:var(--text-muted);">Título *</label>
                                        <input type="text" name="itens[<?= $idx ?>][title_novo]" class="form-input" style="width:300px;font-size:.78rem;" value="<?= e($tituloSug) ?>">
                                    </div>
                                    <div style="display:flex;flex-direction:column;gap:2px;">
                                        <label style="font-size:.65rem;color:var(--text-muted);">Cliente *</label>
                                        <select name="itens[<?= $idx ?>][client_id_novo]" class="form-select" style="width:240px;font-size:.78rem;">
                                            <option value="">— Selecione —</option>
                                            <?php foreach ($clientes as $cl):
                                                $presel = false;
                                                foreach ($pub['partes'] as $pn) {
                                                    if (mb_stripos($pn, $cl['name']) !== false || mb_stripos($cl['name'], $pn) !== false) { $presel = true; break; }
                                                }
                                            ?>
                                            <option value="<?= $cl['id'] ?>" <?= $presel ? 'selected' : '' ?>><?= e($cl['name']) ?><?= $cl['cpf'] ? ' (' . e($cl['cpf']) . ')' : '' ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <?php if (!empty($pub['partes'])): ?>
                                <div style="font-size:.68rem;color:var(--text-muted);">📋 Partes identificadas: <strong><?= e(implode(' · ', array_slice($pub['partes'], 0, 4))) ?></strong></div>
                                <?php endif; ?>
                                <?php if (!empty($pub['comarca']) || !empty($pub['orgao'])): ?>
                                <div style="font-size:.68rem;color:var(--text-muted);margin-top:.2rem;">🏛️ Vara/Comarca preenchidas: <?= e(($pub['orgao'] ?: '') . ($pub['comarca'] ? ' — ' . $pub['comarca'] : '')) ?></div>
                                <?php endif; ?>
                                <input type="hidden" name="itens[<?= $idx ?>][criar_pasta]" value="1">
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>

            <div style="display:flex;gap:.8rem;align-items:center;margin-top:1rem;padding-top:1rem;border-top:1px solid var(--border);flex-wrap:wrap;">
                <button type="submit" class="btn btn-primary" onclick="return confImport()">Importar Selecionados</button>
                <span style="font-size:.78rem;color:var(--text-muted);">Prazos e tarefas serão criados automaticamente</span>
            </div>
        </form>
    </div>
    <?php endif; ?>
</div>

<script>
function contSel() {
    var t = document.querySelectorAll('.cb-pub:checked').length;
    var el = document.getElementById('contadorSel');
    if (el) el.textContent = t + ' selecionado(s)';
}
contSel();

function selDjen(sel) {
    document.querySelectorAll('.cb-pub').forEach(function(cb) { cb.checked = sel; });
    contSel();
}
function selDjenEnc() {
    document.querySelectorAll('.cb-pub').forEach(function(cb) {
        var row = cb.closest('.pub-row');
        cb.checked = row && row.classList.contains('encontrado');
    });
    contSel();
}
function expDjen(idx) {
    var el = document.getElementById('cont' + idx);
    if (el) { el.classList.toggle('expandido'); el.style.maxHeight = el.classList.contains('expandido') ? 'none' : '80px'; }
}
function togCriar(idx) {
    var el = document.getElementById('criarPasta' + idx);
    if (el) el.style.display = el.style.display === 'none' ? 'block' : 'none';
    var cb = document.querySelector('#pubRow' + idx + ' .cb-pub');
    if (cb) { cb.checked = true; contSel(); }
}
function recalcFim(idx, dias, dataInicio) {
    dias = parseInt(dias) || 0;
    var label = document.getElementById('labelFim' + idx);
    var inputFim = document.getElementById('dataFim' + idx);
    if (!dias || !dataInicio) { if (label) label.textContent = 'Sem prazo'; if (inputFim) inputFim.value = ''; return; }
    var d = new Date(dataInicio); d.setDate(d.getDate() + 1);
    var cont = 0, max = 500;
    while (cont < dias && max > 0) { if (d.getDay() !== 0 && d.getDay() !== 6) cont++; if (cont < dias) d.setDate(d.getDate() + 1); max--; }
    var y = d.getFullYear(), m = String(d.getMonth()+1).padStart(2,'0'), dd = String(d.getDate()).padStart(2,'0');
    if (label) label.textContent = 'Vence: ' + dd + '/' + m + '/' + y + ' (aprox.)';
    if (inputFim) inputFim.value = y + '-' + m + '-' + dd;
}
function confImport() {
    var sel = document.querySelectorAll('.cb-pub:checked').length;
    if (!sel) { alert('Selecione ao menos uma publica\u00e7\u00e3o.'); return false; }
    return confirm('Importar ' + sel + ' publica\u00e7\u00e3o(\u00f5es)?\nPrazos e tarefas serão criados automaticamente.');
}

// Duplicatas
var dupsVisiveis = false;
function toggleDuplicatas() {
    dupsVisiveis = !dupsVisiveis;
    document.querySelectorAll('.pub-row.ja-importada').forEach(function(el) {
        el.classList.toggle('visivel-dup', dupsVisiveis);
    });
    var btn = document.getElementById('btnMostrarDups');
    if (btn) {
        btn.textContent = dupsVisiveis
            ? 'Ocultar já importadas'
            : document.querySelectorAll('.pub-row.ja-importada').length + ' já importada(s) — clique para ver';
    }
}

// Ao marcar uma duplicata, ativa o forcar_reimport
document.addEventListener('change', function(e) {
    if (e.target && e.target.classList.contains('cb-dup')) {
        var row = e.target.closest('.pub-row');
        if (row) {
            var inp = row.querySelector('.inp-reimport');
            if (inp) inp.value = e.target.checked ? '1' : '0';
        }
        contSel();
    }
});
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
