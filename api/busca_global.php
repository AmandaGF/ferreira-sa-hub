<?php
/**
 * Busca global unificada — retorna resultados de clientes, processos,
 * leads, tarefas (do usuário) e wiki em um único endpoint.
 *
 * GET ?q=termo (mínimo 3 chars)
 * Response: { ok, grupos: { clientes:[], processos:[], leads:[], tarefas:[], wiki:[] } }
 */

require_once __DIR__ . '/../core/middleware.php';
require_login();

header('Content-Type: application/json; charset=utf-8');
$pdo = db();
$userId = current_user_id();

$q = trim($_GET['q'] ?? '');
if (mb_strlen($q) < 3) {
    echo json_encode(array('ok' => true, 'grupos' => new stdClass()));
    exit;
}

$like = '%' . $q . '%';
// Pra CPF/telefone: SO usa o filtro se o termo tiver digitos.
// Senao '%' . '' . '%' = '%%' que casa com TODOS os clientes que tem CPF/phone preenchido
// (bug Nilce r5 31/05/2026: buscar 'Marisa' trazia '123 Milhas', 'AASP' etc).
$qDig = preg_replace('/\D/', '', $q);
$likeDoc = $qDig ? '%' . $qDig . '%' : '%__nao_casa__%';
$grupos = array();

// ── CLIENTES ──
// 31/05/2026 (Amanda): 'estou colocando o nome da cliente mas nao
// estao aparecendo todos os cadastros'. 2 bugs:
//  1) name LIKE %Maria Silva% nao acha 'Maria DA Silva' -- precisa quebrar
//     em palavras com AND parcial.
//  2) LIMIT 5 era baixo demais -- subiu pra 15.
try {
    $palavras = preg_split('/\s+/', trim($q));
    $palavras = array_filter($palavras, function($p){ return mb_strlen($p) >= 2; });

    $clauseNome = array();
    $paramsNome = array();
    if (!empty($palavras)) {
        foreach ($palavras as $p) {
            $clauseNome[] = "name LIKE ?";
            $paramsNome[] = '%' . $p . '%';
        }
    } else {
        $clauseNome[] = "name LIKE ?";
        $paramsNome[] = $like;
    }
    $clauseNomeStr = '(' . implode(' AND ', $clauseNome) . ')';

    // 08/06/2026 (Amanda): 'buscar Maria nao mostra todos os cadastros'.
    // LIMIT subiu 15 -> 50 (escritorio com 5000+ clientes tem dezenas de Marias,
    // Joaos, etc). Truque do +1: peco 51 e se vier 51, sei que ha mais -- mostro
    // mensagem 'refine a busca' sem precisar fazer COUNT(*) pesado.
    $sql = "SELECT id, name AS titulo, cpf AS subtitulo, phone
            FROM clients
            WHERE $clauseNomeStr
               OR REPLACE(REPLACE(REPLACE(cpf,'.',''),'-',''),'/','') LIKE ?
               OR REPLACE(REPLACE(REPLACE(phone,'(',''),')',''),'-','') LIKE ?
            ORDER BY (name LIKE ?) DESC, name
            LIMIT 51";
    $stmt = $pdo->prepare($sql);
    $params = array_merge($paramsNome, array($likeDoc, $likeDoc, $q . '%'));
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    if ($rows) {
        $truncadoClientes = count($rows) > 50;
        if ($truncadoClientes) array_pop($rows); // descarta o 51 que veio so pra detectar truncamento
        $grupos['clientes'] = array();
        foreach ($rows as $r) {
            $grupos['clientes'][] = array(
                'id'        => (int)$r['id'],
                'titulo'    => $r['titulo'],
                'subtitulo' => $r['subtitulo'] ? 'CPF ' . $r['subtitulo'] : ($r['phone'] ? 'Tel ' . $r['phone'] : ''),
                'url'       => 'modules/clientes/ver.php?id=' . (int)$r['id'],
                'icon'      => '👤',
            );
        }
        if ($truncadoClientes) {
            // Item-aviso no fim do grupo. Frontend renderiza diferente (cor cinza + clique vai pra clientes/?busca=q)
            $grupos['clientes'][] = array(
                'id'        => 0,
                'titulo'    => '+ Mais resultados — refine a busca pra ver todos',
                'subtitulo' => '50 mostrados • clique pra abrir lista completa',
                'url'       => 'modules/clientes/?q=' . urlencode($q),
                'icon'      => '🔎',
                'tipo'      => 'truncado',
            );
        }
    }
} catch (Exception $e) {}

// ── PROCESSOS ── (busca também por nome do cliente e partes)
// Amanda 10/06/2026: quebrar nome em palavras (AND parcial) e buscar em
// TODOS os campos de nome de case_partes (nome, razao_social, representante_nome,
// nome_fantasia, cpf, cnpj). Subtitulo mostra a parte adversa quando o match foi por la.
try {
    // Quebra em palavras pra busca em partes — 'Gabrielle Rodrigues' acha 'Gabrielle Rodrigues Beltrane'
    $palavrasProc = preg_split('/\s+/', trim($q));
    $palavrasProc = array_filter($palavrasProc, function($p){ return mb_strlen($p) >= 2; });
    $clauseParteNome = array();
    $paramsParteNome = array();
    if (!empty($palavrasProc) && count($palavrasProc) > 1) {
        foreach ($palavrasProc as $p) {
            $clauseParteNome[] = "cp.nome LIKE ?";
            $paramsParteNome[] = '%' . $p . '%';
        }
        $clauseParteNomeStr = '(' . implode(' AND ', $clauseParteNome) . ')';
    } else {
        $clauseParteNomeStr = 'cp.nome LIKE ?';
        $paramsParteNome[] = $like;
    }

    $qNumProc = preg_replace('/\D/', '', $q);
    $likeNumProc = $qNumProc ? '%' . $qNumProc . '%' : '%zzzzz%';

    // Amanda 11/06/2026: trazer c.status pra marcar arquivados em cinza no dropdown
    $sqlProc = "SELECT DISTINCT c.id, c.title AS titulo, c.case_number, c.status, cl.name AS cliente_nome, c.updated_at,
                       (SELECT GROUP_CONCAT(DISTINCT
                            COALESCE(NULLIF(cp2.nome,''), NULLIF(cp2.razao_social,''), NULLIF(cp2.representante_nome,''))
                            SEPARATOR ' / ')
                        FROM case_partes cp2
                        WHERE cp2.case_id = c.id
                          AND (cp2.nome LIKE ? OR cp2.razao_social LIKE ? OR cp2.representante_nome LIKE ? OR cp2.nome_fantasia LIKE ?)
                       ) AS parte_match
                FROM cases c
                LEFT JOIN clients cl ON cl.id = c.client_id
                LEFT JOIN case_partes cp ON cp.case_id = c.id
                WHERE c.title LIKE ?
                   OR c.case_number LIKE ?
                   OR REPLACE(REPLACE(REPLACE(c.case_number,'-',''),'.',''),'/','') LIKE ?
                   OR cl.name LIKE ?
                   OR $clauseParteNomeStr
                   OR cp.razao_social LIKE ?
                   OR cp.representante_nome LIKE ?
                   OR cp.nome_fantasia LIKE ?
                   OR REPLACE(REPLACE(REPLACE(cp.cpf,'.',''),'-',''),'/','') LIKE ?
                   OR REPLACE(REPLACE(REPLACE(cp.cnpj,'.',''),'-',''),'/','') LIKE ?
                ORDER BY
                    CASE WHEN c.status IN ('arquivado','cancelado','concluido') THEN 1 ELSE 0 END ASC,
                    (c.title LIKE ?) DESC, c.updated_at DESC
                LIMIT 10";
    $paramsExec = array_merge(
        array($like, $like, $like, $like),                  // subquery parte_match
        array($like, $like, $likeNumProc, $like),           // title, CNJ, CNJ_norm, cliente
        $paramsParteNome,                                    // clauseParteNomeStr (1 ou N params)
        array($like, $like, $like, $likeDoc, $likeDoc),     // razao_social/repr/fantasia/cpf/cnpj
        array($q . '%')                                      // ORDER BY relevance
    );
    $stmt = $pdo->prepare($sqlProc);
    $stmt->execute($paramsExec);
    $rows = $stmt->fetchAll();
    if ($rows) {
        $grupos['processos'] = array();
        foreach ($rows as $r) {
            $st = (string)($r['status'] ?? '');
            $arquivado = in_array($st, array('arquivado','cancelado','concluido'), true);
            $sub = $r['case_number'] ?: '';
            if ($r['cliente_nome']) $sub = ($sub ? $sub . ' • ' : '') . '👤 ' . $r['cliente_nome'];
            if ($r['parte_match']) $sub = ($sub ? $sub . ' • ' : '') . '⚖️ parte: ' . $r['parte_match'];
            if ($arquivado) {
                $rotuloSt = $st === 'arquivado' ? 'ARQUIVADO' : ($st === 'cancelado' ? 'CANCELADO' : 'CONCLUÍDO');
                $sub = '📦 ' . $rotuloSt . ($sub ? ' • ' . $sub : '');
            }
            $grupos['processos'][] = array(
                'id'        => (int)$r['id'],
                'titulo'    => $r['titulo'] ?: 'Processo #' . $r['id'],
                'subtitulo' => $sub,
                'url'       => 'modules/operacional/caso_ver.php?id=' . (int)$r['id'],
                'icon'      => '⚖️',
                'arquivado' => $arquivado,  // Amanda 11/06/2026: front estiliza em cinza
            );
        }
    }
} catch (Exception $e) {}

// ── LEADS (PIPELINE) ──
try {
    $stmt = $pdo->prepare(
        "SELECT id, name AS titulo, phone AS subtitulo, stage
         FROM pipeline_leads
         WHERE (name LIKE ? OR phone LIKE ?) AND stage NOT IN ('finalizado','perdido','arquivado')
         ORDER BY (name LIKE ?) DESC, updated_at DESC
         LIMIT 5"
    );
    $stmt->execute(array($like, $likeDoc, $q . '%'));
    $rows = $stmt->fetchAll();
    if ($rows) {
        $grupos['leads'] = array();
        foreach ($rows as $r) {
            $grupos['leads'][] = array(
                'id'        => (int)$r['id'],
                'titulo'    => $r['titulo'] ?: 'Lead #' . $r['id'],
                'subtitulo' => ($r['subtitulo'] ? 'Tel ' . $r['subtitulo'] : '') . ($r['stage'] ? ' • ' . str_replace('_', ' ', $r['stage']) : ''),
                'url'       => 'modules/pipeline/lead_ver.php?id=' . (int)$r['id'],
                'icon'      => '🎯',
            );
        }
    }
} catch (Exception $e) {}

// ── TAREFAS ── (ampliada: busca em título, descrição, cliente vinculado e caso; todas as tarefas do sistema)
try {
    $stmt = $pdo->prepare(
        "SELECT DISTINCT t.id, t.title AS titulo, t.descricao, t.due_date, t.status, t.assigned_to, t.case_id,
                c.title AS case_title, cl.name AS cliente_nome, u.name AS responsavel_nome
         FROM case_tasks t
         LEFT JOIN cases c ON c.id = t.case_id
         LEFT JOIN clients cl ON cl.id = c.client_id
         LEFT JOIN users u ON u.id = t.assigned_to
         WHERE t.title LIKE ?
            OR t.descricao LIKE ?
            OR cl.name LIKE ?
            OR c.title LIKE ?
         ORDER BY (t.assigned_to = ?) DESC, (t.status != 'concluido') DESC, (t.title LIKE ?) DESC, t.due_date ASC
         LIMIT 8"
    );
    $stmt->execute(array($like, $like, $like, $like, $userId, $q . '%'));
    $rows = $stmt->fetchAll();
    if ($rows) {
        $grupos['tarefas'] = array();
        foreach ($rows as $r) {
            $sub = '';
            if ($r['status'] === 'concluido' || $r['status'] === 'feito') $sub = '✓ Concluída';
            elseif ($r['due_date']) $sub = 'Prazo: ' . date('d/m', strtotime($r['due_date']));
            if ($r['case_title']) $sub = ($sub ? $sub . ' • ' : '') . $r['case_title'];
            elseif ($r['cliente_nome']) $sub = ($sub ? $sub . ' • ' : '') . $r['cliente_nome'];
            if ($r['responsavel_nome'] && (int)$r['assigned_to'] !== $userId) {
                $sub = ($sub ? $sub . ' • ' : '') . 'de ' . explode(' ', $r['responsavel_nome'])[0];
            }
            $url = 'modules/tarefas/';
            if ($r['case_id']) $url = 'modules/operacional/caso_ver.php?id=' . (int)$r['case_id'] . '#tarefa-' . (int)$r['id'];
            $grupos['tarefas'][] = array(
                'id'        => (int)$r['id'],
                'titulo'    => $r['titulo'],
                'subtitulo' => $sub,
                'url'       => $url,
                'icon'      => '📋',
            );
        }
    }
} catch (Exception $e) {}

// ── CHAMADOS (helpdesk) ──
try {
    $stmt = $pdo->prepare(
        "SELECT h.id, h.titulo, h.status, h.prioridade, h.created_at,
                cl.name AS cliente_nome, cs.title AS case_title
         FROM helpdesk_tickets h
         LEFT JOIN clients cl ON cl.id = h.client_id
         LEFT JOIN cases cs ON cs.id = h.caso_id
         WHERE h.titulo LIKE ?
            OR h.descricao LIKE ?
            OR cl.name LIKE ?
            OR cs.title LIKE ?
         ORDER BY h.created_at DESC
         LIMIT 5"
    );
    $stmt->execute(array($like, $like, $like, $like));
    $rows = $stmt->fetchAll();
    if ($rows) {
        $grupos['chamados'] = array();
        foreach ($rows as $r) {
            $sub = $r['status'] ? ucfirst($r['status']) : '';
            if ($r['cliente_nome']) $sub = ($sub ? $sub . ' • ' : '') . $r['cliente_nome'];
            elseif ($r['case_title']) $sub = ($sub ? $sub . ' • ' : '') . $r['case_title'];
            $grupos['chamados'][] = array(
                'id'        => (int)$r['id'],
                'titulo'    => $r['titulo'] ?: 'Chamado #' . $r['id'],
                'subtitulo' => $sub,
                'url'       => 'modules/helpdesk/ver.php?id=' . (int)$r['id'],
                'icon'      => '🎫',
            );
        }
    }
} catch (Exception $e) {}

// ── ANDAMENTOS (descrição) — acha texto solto em qualquer andamento ──
try {
    $stmt = $pdo->prepare(
        "SELECT a.id, a.case_id, a.descricao, a.data_andamento, a.tipo,
                c.title AS case_title, cl.name AS cliente_nome
         FROM case_andamentos a
         LEFT JOIN cases c ON c.id = a.case_id
         LEFT JOIN clients cl ON cl.id = c.client_id
         WHERE a.descricao LIKE ?
         ORDER BY a.data_andamento DESC, a.id DESC
         LIMIT 5"
    );
    $stmt->execute(array($like));
    $rows = $stmt->fetchAll();
    if ($rows) {
        $grupos['andamentos'] = array();
        foreach ($rows as $r) {
            $preview = mb_substr(preg_replace('/\s+/', ' ', (string)$r['descricao']), 0, 80, 'UTF-8');
            $sub = $r['case_title'] ?: ($r['cliente_nome'] ?: '');
            if ($r['data_andamento']) $sub = date('d/m/Y', strtotime($r['data_andamento'])) . ($sub ? ' • ' . $sub : '');
            $grupos['andamentos'][] = array(
                'id'        => (int)$r['id'],
                'titulo'    => $preview,
                'subtitulo' => $sub,
                'url'       => 'modules/operacional/caso_ver.php?id=' . (int)$r['case_id'] . '#andamento-' . (int)$r['id'],
                'icon'      => '📝',
            );
        }
    }
} catch (Exception $e) {}

// ── INTIMAÇÕES / PUBLICAÇÕES (case_publicacoes + djen_pending) ──
// Busca CNJ, órgão, resumo/orientação da IA e conteúdo da publicação.
// Pra CNJ aceita com ou sem formatação (normaliza só dígitos).
try {
    $qNum = preg_replace('/\D/', '', $q);
    $likeNum = $qNum ? '%' . $qNum . '%' : null;

    // 1) Publicações já vinculadas a caso
    $sqlCp = "SELECT cp.id, cp.tipo_publicacao, cp.status_prazo, cp.data_disponibilizacao,
                     cp.case_id, cs.title AS case_title, cs.case_number,
                     cl.name AS cliente_nome,
                     LEFT(cp.conteudo, 100) AS preview,
                     cp.resumo_ia, cp.orientacao_ia
              FROM case_publicacoes cp
              LEFT JOIN cases cs ON cs.id = cp.case_id
              LEFT JOIN clients cl ON cl.id = cs.client_id
              WHERE cp.conteudo LIKE ?
                 OR cp.resumo_ia LIKE ?
                 OR cp.orientacao_ia LIKE ?
                 OR cs.case_number LIKE ?
                 OR cs.title LIKE ?
                 OR cl.name LIKE ?";
    $paramsCp = array($like, $like, $like, $like, $like, $like);
    if ($likeNum) {
        $sqlCp .= " OR REPLACE(REPLACE(REPLACE(cs.case_number,'-',''),'.',''),'/','') LIKE ?";
        $paramsCp[] = $likeNum;
    }
    $sqlCp .= " ORDER BY cp.data_disponibilizacao DESC LIMIT 5";
    $stmt = $pdo->prepare($sqlCp);
    $stmt->execute($paramsCp);
    $rows = $stmt->fetchAll();

    // 2) Publicações pendentes (órfãs, ainda sem pasta)
    $sqlDp = "SELECT id, numero_processo, tipo_comunicacao, data_disp, orgao,
                     LEFT(conteudo, 100) AS preview, resumo, orientacao, status
              FROM djen_pending
              WHERE status = 'pendente'
                AND (conteudo LIKE ? OR resumo LIKE ? OR orientacao LIKE ?
                     OR numero_processo LIKE ? OR partes LIKE ? OR orgao LIKE ?";
    $paramsDp = array($like, $like, $like, $like, $like, $like);
    if ($likeNum) {
        $sqlDp .= " OR REPLACE(REPLACE(REPLACE(numero_processo,'-',''),'.',''),'/','') LIKE ?";
        $paramsDp[] = $likeNum;
    }
    $sqlDp .= ") ORDER BY data_disp DESC LIMIT 5";
    $stmtDp = $pdo->prepare($sqlDp);
    $stmtDp->execute($paramsDp);
    $rowsDp = $stmtDp->fetchAll();

    if ($rows || $rowsDp) {
        $grupos['intimacoes'] = array();
        foreach ($rows as $r) {
            $tipo  = ucfirst((string)$r['tipo_publicacao']);
            $tit   = $r['resumo_ia'] ?: mb_substr(preg_replace('/\s+/', ' ', (string)$r['preview']), 0, 80, 'UTF-8');
            $parts = array();
            if ($r['case_number'])   $parts[] = $r['case_number'];
            if ($r['cliente_nome'])  $parts[] = $r['cliente_nome'];
            elseif ($r['case_title']) $parts[] = $r['case_title'];
            if ($r['data_disponibilizacao']) $parts[] = date('d/m/Y', strtotime($r['data_disponibilizacao']));
            if ($r['status_prazo']) {
                $parts[] = $r['status_prazo'] === 'confirmado' ? '✓ cumprida'
                    : ($r['status_prazo'] === 'descartado' ? '⊘ descartada' : '⏳ pendente');
            }
            $grupos['intimacoes'][] = array(
                'id'        => (int)$r['id'],
                'titulo'    => ($tipo ? "[{$tipo}] " : '') . $tit,
                'subtitulo' => implode(' • ', $parts),
                'url'       => $r['case_id'] ? 'modules/operacional/caso_ver.php?id=' . (int)$r['case_id'] : 'modules/intimacoes/',
                'icon'      => '📢',
            );
        }
        foreach ($rowsDp as $r) {
            $tipo = ucfirst((string)$r['tipo_comunicacao']);
            $tit  = $r['resumo'] ?: mb_substr(preg_replace('/\s+/', ' ', (string)$r['preview']), 0, 80, 'UTF-8');
            $parts = array('⚠️ sem pasta');
            if ($r['numero_processo']) $parts[] = $r['numero_processo'];
            if ($r['orgao'])           $parts[] = $r['orgao'];
            if ($r['data_disp'])       $parts[] = date('d/m/Y', strtotime($r['data_disp']));
            $grupos['intimacoes'][] = array(
                'id'        => (int)$r['id'],
                'titulo'    => ($tipo ? "[{$tipo}] " : '') . $tit,
                'subtitulo' => implode(' • ', $parts),
                'url'       => 'modules/intimacoes/',
                'icon'      => '📢',
            );
        }
    }
} catch (Exception $e) {}

// ── AUDIENCISTAS ── (29/06/2026 Amanda: incluir busca no Ctrl+K)
// Busca por nome, telefone, OAB, área (TRT, JEC, etc) ou observação
try {
    $stmt = $pdo->prepare(
        "SELECT id, nome, telefone, email, oab, areas, ativo,
                (SELECT COUNT(*) FROM audiencias WHERE audiencista_id = a.id AND status='designada') AS abertas
         FROM audiencistas a
         WHERE (nome LIKE ?
             OR telefone LIKE ?
             OR email LIKE ?
             OR oab LIKE ?
             OR areas LIKE ?
             OR observacoes LIKE ?)
         ORDER BY ativo DESC, (nome LIKE ?) DESC, nome
         LIMIT 5"
    );
    $stmt->execute(array($like, $likeDoc, $like, $like, $like, $like, $q . '%'));
    $rows = $stmt->fetchAll();
    if ($rows) {
        $grupos['audiencistas'] = array();
        foreach ($rows as $r) {
            $parts = array();
            if ($r['telefone']) $parts[] = '📞 ' . $r['telefone'];
            if ($r['oab'])      $parts[] = 'OAB ' . $r['oab'];
            if ($r['areas'])    $parts[] = $r['areas'];
            if (!$r['ativo'])   $parts[] = '⊘ inativo';
            if ((int)$r['abertas'] > 0) $parts[] = (int)$r['abertas'] . ' designada(s) em aberto';
            $grupos['audiencistas'][] = array(
                'id'        => (int)$r['id'],
                'titulo'    => $r['nome'],
                'subtitulo' => implode(' • ', $parts),
                'url'       => 'modules/audiencistas/?ver=' . (int)$r['id'],
                'icon'      => '🎤',
                'arquivado' => !$r['ativo'],
            );
        }
    }
} catch (Exception $e) {}

// ── AGENDA (eventos) ── (29/06/2026 Amanda: incluir busca no Ctrl+K)
// Audiências, reuniões, balcão virtual, prazos — futuros e passados recentes (últimos 60d)
try {
    $stmt = $pdo->prepare(
        "SELECT e.id, e.titulo, e.tipo, e.data_inicio, e.local, e.status,
                cl.name AS cliente_nome, cs.title AS case_title,
                u.name AS responsavel_nome
         FROM agenda_eventos e
         LEFT JOIN clients cl ON cl.id = e.client_id
         LEFT JOIN cases cs ON cs.id = e.case_id
         LEFT JOIN users u ON u.id = e.responsavel_id
         WHERE (e.titulo LIKE ?
             OR e.local LIKE ?
             OR e.descricao LIKE ?
             OR cl.name LIKE ?
             OR cs.title LIKE ?)
           AND e.data_inicio >= DATE_SUB(NOW(), INTERVAL 60 DAY)
         ORDER BY (e.data_inicio >= NOW()) DESC, e.data_inicio ASC
         LIMIT 6"
    );
    $stmt->execute(array($like, $like, $like, $like, $like));
    $rows = $stmt->fetchAll();
    if ($rows) {
        $emojiTipo = array(
            'audiencia' => '⚖️', 'mediacao_cejusc' => '🤝', 'balcao_virtual' => '💻',
            'reuniao' => '👥', 'reuniao_lead' => '🆕', 'prazo' => '⏰',
            'onboarding' => '🎯', 'tarefa' => '📋', 'preparacao_audiencia' => '📚',
        );
        $grupos['agenda'] = array();
        foreach ($rows as $r) {
            $tipo = (string)$r['tipo'];
            $ico  = isset($emojiTipo[$tipo]) ? $emojiTipo[$tipo] : '📅';
            $quando = $r['data_inicio'] ? date('d/m H:i', strtotime($r['data_inicio'])) : '';
            $parts = array();
            if ($quando) $parts[] = $quando;
            if ($r['cliente_nome']) $parts[] = '👤 ' . $r['cliente_nome'];
            elseif ($r['case_title']) $parts[] = $r['case_title'];
            if ($r['local']) $parts[] = '📍 ' . mb_substr($r['local'], 0, 40);
            if ($r['responsavel_nome']) $parts[] = explode(' ', $r['responsavel_nome'])[0];
            $passado = $r['data_inicio'] && strtotime($r['data_inicio']) < time();
            $grupos['agenda'][] = array(
                'id'        => (int)$r['id'],
                'titulo'    => $ico . ' ' . ($r['titulo'] ?: ucfirst($tipo)),
                'subtitulo' => implode(' • ', $parts),
                'url'       => 'modules/agenda/?evento=' . (int)$r['id'],
                'icon'      => '📅',
                'arquivado' => $passado,
            );
        }
    }
} catch (Exception $e) {}

// ── WIKI ──
try {
    $stmt = $pdo->prepare(
        "SELECT id, titulo, categoria AS subtitulo
         FROM wiki_artigos
         WHERE titulo LIKE ? AND ativo = 1
         ORDER BY (titulo LIKE ?) DESC, titulo
         LIMIT 3"
    );
    $stmt->execute(array($like, $q . '%'));
    $rows = $stmt->fetchAll();
    if ($rows) {
        $grupos['wiki'] = array();
        foreach ($rows as $r) {
            $grupos['wiki'][] = array(
                'id'        => (int)$r['id'],
                'titulo'    => $r['titulo'],
                'subtitulo' => $r['subtitulo'] ?: '',
                'url'       => 'modules/wiki/ver.php?id=' . (int)$r['id'],
                'icon'      => '📚',
            );
        }
    }
} catch (Exception $e) {}

echo json_encode(array('ok' => true, 'grupos' => $grupos ?: new stdClass()));
