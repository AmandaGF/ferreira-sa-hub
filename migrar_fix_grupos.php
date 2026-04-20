<?php
/**
 * Corrige retroativamente conversas de grupo do WhatsApp que foram criadas
 * antes do fix de detecção (commit que introduziu zapi_eh_grupo):
 *
 * - zapi_normaliza_telefone removia '@g.us' silenciosamente → telefone virava
 *   uma string de 18 dígitos (ID do grupo sem sufixo).
 * - zapi_buscar_ou_criar_conversa fazia LIKE '%' . substr(telefone, -9) pra
 *   achar o cliente — os últimos 9 dígitos do ID do grupo casavam por
 *   coincidência com clientes reais.
 * - zapi_sync_foto_contato baixou a foto do grupo e salvou como foto_path
 *   do cliente vinculado erroneamente (quando clients.foto_path era NULL).
 *
 * O script:
 * 1. Detecta conversas de grupo (telefone contém '@g.us' OU tem 15+ dígitos
 *    começando com padrão típico de grupo '12036' ou '12034' do WhatsApp).
 * 2. Marca eh_grupo = 1, limpa foto_perfil_url e desvincula client_id/lead_id.
 * 3. Pros clientes que tinham foto_wa_*.jpg salvada erroneamente via esse
 *    vínculo, limpa foto_path (volta a mostrar iniciais; cliente pode fazer
 *    upload correto pela Central VIP).
 */
require_once __DIR__ . '/core/database.php';

$key = $_GET['key'] ?? '';
if ($key !== 'fsa-hub-deploy-2026') {
    http_response_code(403);
    exit('Chave inválida');
}

$pdo = db();
$dryRun = !isset($_GET['exec']);

// Self-heal coluna eh_grupo se ainda não existir
try { $pdo->exec("ALTER TABLE zapi_conversas ADD COLUMN eh_grupo TINYINT(1) NOT NULL DEFAULT 0"); } catch (Exception $e) {}

header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><meta charset="utf-8"><title>Corrigir grupos WhatsApp</title>';
echo '<style>body{font-family:Inter,Arial,sans-serif;max-width:1200px;margin:2rem auto;padding:0 1rem;color:#052228} h1,h2{color:#052228} table{border-collapse:collapse;width:100%;margin:1rem 0;font-size:13px} th,td{border:1px solid #d1d5db;padding:.4rem .6rem;text-align:left} th{background:#f3f4f6} .badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600} .ok{background:#d1fae5;color:#065f46} .warn{background:#fef3c7;color:#78350f} .err{background:#fee2e2;color:#991b1b} .act{background:#dc2626;color:#fff;padding:.6rem 1.2rem;border-radius:8px;text-decoration:none;display:inline-block;margin-top:1rem;font-weight:700} .muted{color:#6b7280;font-size:13px}</style>';
echo '<h1>Corrigir conversas de grupo do WhatsApp</h1>';
echo '<p class="muted">Modo: <strong>' . ($dryRun ? 'DRY RUN (só mostra)' : 'EXECUÇÃO REAL') . '</strong></p>';

// 1. Identificar candidatos a grupo. Heurística refinada (idempotente — re-rodar é OK):
//    - Contém '@g.us' (certeza)
//    - Começa com '12036' ou '12034' E tem 18+ dígitos (IDs típicos de grupo
//      WhatsApp no formato novo)
//    - eh_grupo = 1 (já marcado anteriormente, pra garantir rename do nome)
//    - EXCLUI '@lid' (é individual Multi-Device, não grupo)
$sql = "SELECT co.id, co.telefone, co.nome_contato, co.client_id, co.foto_perfil_url,
               COALESCE(co.eh_grupo, 0) AS ja_grupo,
               cl.name AS client_name, cl.foto_path AS client_foto_path
        FROM zapi_conversas co
        LEFT JOIN clients cl ON cl.id = co.client_id
        WHERE co.telefone NOT LIKE '%@lid%'
          AND (
              COALESCE(co.eh_grupo, 0) = 1
              OR co.telefone LIKE '%@g.us%'
              OR (co.telefone LIKE '12036%' AND CHAR_LENGTH(co.telefone) >= 15)
              OR (co.telefone LIKE '12034%' AND CHAR_LENGTH(co.telefone) >= 15)
          )
        ORDER BY co.id DESC";
$candidatos = $pdo->query($sql)->fetchAll();

echo '<h2>🟠 Conversas identificadas como grupo: ' . count($candidatos) . '</h2>';

if (count($candidatos) > 0) {
    echo '<table><tr><th>ID</th><th>Telefone/ID</th><th>Nome</th><th>Cliente vinculado (errado)</th><th>Foto do cliente (possivelmente errada)</th></tr>';
    foreach ($candidatos as $c) {
        $suspeitaFoto = '';
        if (!empty($c['client_foto_path']) && strpos($c['client_foto_path'], 'foto_wa_') === 0) {
            $suspeitaFoto = '<span class="badge err">⚠️ ' . htmlspecialchars($c['client_foto_path']) . ' — vai limpar</span>';
        } elseif (!empty($c['client_foto_path'])) {
            $suspeitaFoto = '<span class="badge warn">' . htmlspecialchars($c['client_foto_path']) . ' (uploadada — preservada)</span>';
        } else {
            $suspeitaFoto = '<span class="muted">(sem foto)</span>';
        }
        echo '<tr>';
        echo '<td>' . (int)$c['id'] . '</td>';
        echo '<td><code>' . htmlspecialchars($c['telefone']) . '</code></td>';
        echo '<td>' . htmlspecialchars($c['nome_contato'] ?: '—') . '</td>';
        echo '<td>' . ($c['client_id'] ? '#' . (int)$c['client_id'] . ' — ' . htmlspecialchars($c['client_name'] ?: '') : '<span class="muted">nenhum</span>') . '</td>';
        echo '<td>' . $suspeitaFoto . '</td>';
        echo '</tr>';
    }
    echo '</table>';

    if (!$dryRun) {
        $upConv = $pdo->prepare("UPDATE zapi_conversas SET eh_grupo = 1, foto_perfil_url = NULL, client_id = NULL, lead_id = NULL WHERE id = ?");
        // Renomeia nome_contato das conversas que eram grupo mas tinham nome de cliente
        // gravado errado. Próxima mensagem do grupo vai atualizar pro chatName real.
        $upNome = $pdo->prepare("UPDATE zapi_conversas SET nome_contato = ? WHERE id = ? AND (nome_contato IS NULL OR nome_contato NOT LIKE '👥%')");
        $upCli  = $pdo->prepare("UPDATE clients SET foto_path = NULL WHERE id = ? AND foto_path = ?");
        $convsFixadas = 0; $clientesLimpos = 0; $nomesFixados = 0;
        foreach ($candidatos as $c) {
            if ($upConv->execute(array($c['id']))) $convsFixadas += $upConv->rowCount();
            // Nome genérico até chegar nova mensagem do grupo (aí o webhook seta o chatName real).
            $nomeGenerico = '👥 Grupo (aguardando nome)';
            if ($upNome->execute(array($nomeGenerico, $c['id']))) $nomesFixados += $upNome->rowCount();
            if ($c['client_id'] && !empty($c['client_foto_path']) && strpos($c['client_foto_path'], 'foto_wa_') === 0) {
                if ($upCli->execute(array($c['client_id'], $c['client_foto_path']))) {
                    $clientesLimpos += $upCli->rowCount();
                }
            }
        }
        echo '<p class="badge ok">✓ ' . $convsFixadas . ' conversa(s) marcada(s) como grupo + cliente deslinkado.</p>';
        echo '<p class="badge ok">✓ ' . $nomesFixados . ' conversa(s) renomeada(s) pra "👥 Grupo (aguardando nome)". Próxima msg do grupo atualiza pro nome real.</p>';
        echo '<p class="badge ok">✓ ' . $clientesLimpos . ' cliente(s) com foto <code>foto_wa_*</code> suspeita limpa (volta pras iniciais).</p>';
    }
}

// Rodapé
if ($dryRun) {
    echo '<hr><p><strong>Dry-run — nada foi alterado.</strong></p>';
    echo '<a class="act" href="?key=fsa-hub-deploy-2026&exec=1">▶ Executar (corrige ' . count($candidatos) . ' conversa(s))</a>';
} else {
    echo '<hr><p class="muted">Concluído em ' . date('d/m/Y H:i:s') . '.</p>';
    echo '<p class="muted">Clientes com foto_path limpa voltam a mostrar iniciais. Eles podem uploadar foto própria pela Central VIP → Meus Dados.</p>';
}
