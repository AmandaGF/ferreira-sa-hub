<?php
/**
 * Popula Portal de Links com os tribunais estaduais organizados por região.
 * Idempotente: remove categorias 'Tribunais - *' e reinsere. Não afeta outras categorias.
 * URL: /conecta/migrar_tribunais_links.php?key=fsa-hub-deploy-2026
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit; }
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

// Descobre user_id admin (pra created_by). Usa o primeiro admin ativo.
$userId = (int)$pdo->query("SELECT id FROM users WHERE role = 'admin' AND is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
if (!$userId) { echo "ERRO: nenhum admin ativo encontrado.\n"; exit(1); }

echo "=== Migração: Tribunais Estaduais no Portal de Links ===\n\n";

// Limpeza: apaga links cuja categoria começa com "Tribunais - " (idempotência)
$del = $pdo->prepare("DELETE FROM portal_links WHERE category LIKE 'Tribunais - %'");
$del->execute();
echo "Links antigos de 'Tribunais - *' removidos: {$del->rowCount()}\n\n";

// Estrutura: [categoria, titulo, url, hint]
$links = array(

    // ════════ NORTE (AC, AP, AM, PA, RR, RO, TO) ════════
    array('Tribunais - Norte', 'TJAC — Acre — e-SAJ (1º/2º Grau)', 'https://esaj.tjac.jus.br/sajcas/login', 'Tribunal de Justiça do Acre — sistema e-SAJ'),
    array('Tribunais - Norte', 'TJAP — Amapá — PJe 1º Grau', 'https://pje.tjap.jus.br/1g/login.seam', 'Tribunal de Justiça do Amapá — PJe 1º Grau'),
    array('Tribunais - Norte', 'TJAP — Amapá — PJe 2º Grau', 'https://pje.tjap.jus.br/2g/login.seam', 'Tribunal de Justiça do Amapá — PJe 2º Grau'),
    array('Tribunais - Norte', 'TJAP — Amapá — Tucujuris (1º Grau)', 'https://tucujuris.tjap.jus.br/tucujuris/pages/login/login.html', 'Sistema Tucujuris do TJAP — 1º Grau'),
    array('Tribunais - Norte', 'TJAM — Amazonas — e-SAJ (1º/2º Grau)', 'https://consultasaj.tjam.jus.br/sajcas/login', 'Tribunal de Justiça do Amazonas — e-SAJ'),
    array('Tribunais - Norte', 'TJAM — Amazonas — Projudi (1º Grau)', 'https://projudi.tjam.jus.br/projudi/', 'Projudi TJAM — 1º Grau'),
    array('Tribunais - Norte', 'TJPA — Pará — PJe 1º Grau', 'https://pje.tjpa.jus.br/pje/login.seam', 'Tribunal de Justiça do Pará — PJe 1º Grau'),
    array('Tribunais - Norte', 'TJPA — Pará — PJe 2º Grau', 'https://pje.tjpa.jus.br/pje-2g/login.seam', 'Tribunal de Justiça do Pará — PJe 2º Grau'),
    array('Tribunais - Norte', 'TJRR — Roraima — PJe 1ª Instância', 'http://pje.tjrr.jus.br/pje/login.seam', 'Tribunal de Justiça de Roraima — 1ª Instância'),
    array('Tribunais - Norte', 'TJRR — Roraima — PJe 2ª Instância', 'http://pje2.tjrr.jus.br/pje/login.seam', 'Tribunal de Justiça de Roraima — 2ª Instância'),
    array('Tribunais - Norte', 'TJRR — Roraima — Projudi (1º/2º Grau)', 'https://projudi.tjrr.jus.br/projudi/', 'Projudi TJRR — 1º e 2º Grau'),
    array('Tribunais - Norte', 'TJRO — Rondônia — PJe 1º Grau', 'https://pjepg.tjro.jus.br/pje/login.seam', 'Tribunal de Justiça de Rondônia — PJe 1º Grau'),
    array('Tribunais - Norte', 'TJRO — Rondônia — PJe 2º Grau', 'https://pjesg.tjro.jus.br/pje/login.seam', 'Tribunal de Justiça de Rondônia — PJe 2º Grau'),
    array('Tribunais - Norte', 'TJTO — Tocantins — eproc 1º Grau', 'https://eproc1.tjto.jus.br/eprocV2_prod_1grau/', 'Tribunal de Justiça do Tocantins — eproc 1º Grau'),
    array('Tribunais - Norte', 'TJTO — Tocantins — eproc 2º Grau', 'https://eproc2.tjto.jus.br/eprocV2_prod_2grau/', 'Tribunal de Justiça do Tocantins — eproc 2º Grau'),
    array('Tribunais - Norte', 'TJTO — Tocantins — IDP (Gov.br)', 'https://idp.tjto.jus.br/Account/Login', 'Autenticação unificada Gov.br do TJTO'),

    // ════════ NORDESTE (AL, BA, CE, MA, PB, PE, PI, RN, SE) ════════
    array('Tribunais - Nordeste', 'TJAL — Alagoas — e-SAJ (1º/2º Grau)', 'https://www2.tjal.jus.br/sajcas/login', 'Tribunal de Justiça de Alagoas — e-SAJ'),
    array('Tribunais - Nordeste', 'TJBA — Bahia — PJe 1º Grau', 'https://pje.tjba.jus.br/pje/login.seam', 'Tribunal de Justiça da Bahia — PJe 1º Grau'),
    array('Tribunais - Nordeste', 'TJBA — Bahia — PJe 2º Grau', 'https://pje2g.tjba.jus.br/pje/login.seam', 'Tribunal de Justiça da Bahia — PJe 2º Grau'),
    array('Tribunais - Nordeste', 'TJCE — Ceará — PJe 1º Grau', 'https://pje.tjce.jus.br/pje1grau/login.seam', 'Tribunal de Justiça do Ceará — PJe 1º Grau'),
    array('Tribunais - Nordeste', 'TJCE — Ceará — PJe 2º Grau', 'https://pje.tjce.jus.br/pje2grau/login.seam', 'Tribunal de Justiça do Ceará — PJe 2º Grau'),
    array('Tribunais - Nordeste', 'TJMA — Maranhão — PJe 1º Grau', 'https://pje.tjma.jus.br/pje/login.seam', 'Tribunal de Justiça do Maranhão — PJe 1º Grau'),
    array('Tribunais - Nordeste', 'TJMA — Maranhão — PJe 2º Grau', 'https://pje2.tjma.jus.br/pje2g/login.seam', 'Tribunal de Justiça do Maranhão — PJe 2º Grau'),
    array('Tribunais - Nordeste', 'TJPB — Paraíba — PJe 1º Grau', 'https://pje.tjpb.jus.br/pje/login.seam', 'Tribunal de Justiça da Paraíba — PJe 1º Grau'),
    array('Tribunais - Nordeste', 'TJPB — Paraíba — PJe 2º Grau', 'https://pjesg.tjpb.jus.br/pje2g/login.seam', 'Tribunal de Justiça da Paraíba — PJe 2º Grau'),
    array('Tribunais - Nordeste', 'TJPE — Pernambuco — PJe 1º Grau', 'https://pje.cloud.tjpe.jus.br/1g/login.seam', 'Tribunal de Justiça de Pernambuco — PJe 1º Grau'),
    array('Tribunais - Nordeste', 'TJPE — Pernambuco — PJe 2º Grau', 'https://pje.cloud.tjpe.jus.br/2g/login.seam', 'Tribunal de Justiça de Pernambuco — PJe 2º Grau'),
    array('Tribunais - Nordeste', 'TJPI — Piauí — PJe 1º Grau', 'https://tjpi.pje.jus.br/1g/login.seam', 'Tribunal de Justiça do Piauí — PJe 1º Grau'),
    array('Tribunais - Nordeste', 'TJPI — Piauí — PJe 2º Grau', 'https://tjpi.pje.jus.br/2g/login.seam', 'Tribunal de Justiça do Piauí — PJe 2º Grau'),
    array('Tribunais - Nordeste', 'TJPI — Piauí — ThemisWeb (Portal)', 'https://www.tjpi.jus.br/themisweb/', 'Portal ThemisWeb do TJPI'),
    array('Tribunais - Nordeste', 'TJRN — Rio Grande do Norte — PJe 1º Grau', 'https://pje1g.tjrn.jus.br/pje/login.seam', 'Tribunal de Justiça do RN — PJe 1º Grau'),
    array('Tribunais - Nordeste', 'TJRN — Rio Grande do Norte — PJe 2º Grau', 'https://pje2g.tjrn.jus.br/pje/login.seam', 'Tribunal de Justiça do RN — PJe 2º Grau'),
    array('Tribunais - Nordeste', 'TJSE — Sergipe — Portal do Advogado (tjnet/SCPV)', 'https://www.tjse.jus.br/tjnet/portaladv/login.wsp', 'Tribunal de Justiça de Sergipe — Portal do Advogado'),
    array('Tribunais - Nordeste', 'TJSE — Sergipe — eproc (em implantação)', 'https://www.tjse.jus.br/portal/servicos/judiciais/eproc', 'Eproc TJSE (implantação escalonada desde 2025)'),

    // ════════ CENTRO-OESTE (DF, GO, MT, MS) ════════
    array('Tribunais - Centro-Oeste', 'TJDFT — Distrito Federal — PJe 1º Grau', 'https://pje.tjdft.jus.br/pje/login.seam', 'Tribunal de Justiça do DF e Territórios — PJe 1º Grau'),
    array('Tribunais - Centro-Oeste', 'TJDFT — Distrito Federal — PJe 2º Grau', 'https://pje2i.tjdft.jus.br/pje/login.seam', 'Tribunal de Justiça do DF e Territórios — PJe 2º Grau'),
    array('Tribunais - Centro-Oeste', 'TJGO — Goiás — Projudi (1º/2º Grau)', 'https://projudi.tjgo.jus.br/LogOn', 'Projudi TJGO — 1º e 2º Grau'),
    array('Tribunais - Centro-Oeste', 'TJGO — Goiás — PJD (1º/2º Grau)', 'https://pjd.tjgo.jus.br/LogOn', 'Processo Judicial Digital TJGO'),
    array('Tribunais - Centro-Oeste', 'TJMT — Mato Grosso — PJe 1º Grau', 'https://pje.tjmt.jus.br/pje/login.seam', 'Tribunal de Justiça do MT — PJe 1º Grau'),
    array('Tribunais - Centro-Oeste', 'TJMT — Mato Grosso — PJe 2º Grau', 'https://pje2.tjmt.jus.br/pje2/login.seam', 'Tribunal de Justiça do MT — PJe 2º Grau'),
    array('Tribunais - Centro-Oeste', 'TJMT — Mato Grosso — PEA (Portal do Advogado)', 'https://pea.tjmt.jus.br/', 'Portal do Advogado do TJMT'),
    array('Tribunais - Centro-Oeste', 'TJMS — Mato Grosso do Sul — e-SAJ (1º/2º Grau)', 'https://esaj.tjms.jus.br/sajcas/login', 'Tribunal de Justiça do MS — e-SAJ'),

    // ════════ SUDESTE (ES, MG, RJ, SP) ════════
    array('Tribunais - Sudeste', 'TJES — Espírito Santo — PJe 1º Grau', 'https://pje.tjes.jus.br/pje/login.seam', 'Tribunal de Justiça do ES — PJe 1º Grau'),
    array('Tribunais - Sudeste', 'TJES — Espírito Santo — PJe 2º Grau', 'https://pje.tjes.jus.br/pje2g/login.seam', 'Tribunal de Justiça do ES — PJe 2º Grau'),
    array('Tribunais - Sudeste', 'TJMG — Minas Gerais — PJe 1º Grau', 'https://pje.tjmg.jus.br/pje/login.seam', 'Tribunal de Justiça de MG — PJe 1º Grau'),
    array('Tribunais - Sudeste', 'TJMG — Minas Gerais — PJe Recursal', 'https://pjerecursal.tjmg.jus.br/pje/login.seam', 'Turma Recursal TJMG'),
    array('Tribunais - Sudeste', 'TJMG — Minas Gerais — JPe-Themis (2ª Instância)', 'https://www.tjmg.jus.br/portal-tjmg/processos/jpe-themis-processo-eletronico-de-2-instancia/', 'Acesso via portal — sem URL pública direta'),
    array('Tribunais - Sudeste', 'TJRJ — Rio de Janeiro — PJe 1º Grau', 'https://tjrj.pje.jus.br/1g/login.seam', 'Tribunal de Justiça do RJ — PJe 1º Grau'),
    array('Tribunais - Sudeste', 'TJRJ — Rio de Janeiro — PJe 2º Grau', 'https://tjrj.pje.jus.br/2g/login.seam', 'Tribunal de Justiça do RJ — PJe 2º Grau'),
    array('Tribunais - Sudeste', 'TJRJ — Rio de Janeiro — eproc / Portal TJ (1º/2º Grau)', 'https://portaltj.tjrj.jus.br/login', 'Portal TJ / eproc TJRJ'),
    array('Tribunais - Sudeste', 'TJRJ — Rio de Janeiro — Portal de Serviços (DCP/legado)', 'https://www3.tjrj.jus.br/portalservicos/', 'Portal de Serviços legado do TJRJ — 1º Grau'),
    array('Tribunais - Sudeste', 'TJRJ — Rio de Janeiro — IdServerJus', 'https://www3.tjrj.jus.br/idserverjus-front/', 'Autenticação institucional do TJRJ'),
    array('Tribunais - Sudeste', 'TJSP — São Paulo — e-SAJ (1º/2º Grau)', 'https://esaj.tjsp.jus.br/sajcas/login', 'Tribunal de Justiça de SP — e-SAJ'),

    // ════════ SUL (PR, RS, SC) ════════
    array('Tribunais - Sul', 'TJPR — Paraná — Projudi 1º Grau', 'https://projudi.tjpr.jus.br/projudi/', 'Projudi TJPR — 1º Grau'),
    array('Tribunais - Sul', 'TJPR — Paraná — Projudi 2º Grau', 'https://projudi2.tjpr.jus.br/projudi/', 'Projudi TJPR — 2º Grau'),
    array('Tribunais - Sul', 'TJRS — Rio Grande do Sul — eproc 1º Grau', 'https://eproc1g.tjrs.jus.br/eproc/externo_controlador.php?acao=principal', 'Tribunal de Justiça do RS — eproc 1º Grau'),
    array('Tribunais - Sul', 'TJRS — Rio Grande do Sul — eproc 2º Grau', 'https://eproc2g.tjrs.jus.br/eproc/externo_controlador.php?acao=principal', 'Tribunal de Justiça do RS — eproc 2º Grau'),
    array('Tribunais - Sul', 'TJRS — Rio Grande do Sul — PPE (Portal Proc. Eletrônico Unificado)', 'https://ppe.tjrs.jus.br/ppe/signin', 'Portal Processo Eletrônico Unificado TJRS'),
    array('Tribunais - Sul', 'TJSC — Santa Catarina — eproc 1º Grau', 'https://eproc1g.tjsc.jus.br/eproc/', 'Tribunal de Justiça de SC — eproc 1º Grau'),
    array('Tribunais - Sul', 'TJSC — Santa Catarina — eproc 2º Grau', 'https://eproc2g.tjsc.jus.br/eproc/externo_controlador.php?acao=principal', 'Tribunal de Justiça de SC — eproc 2º Grau'),
);

$stmt = $pdo->prepare(
    'INSERT INTO portal_links (category, title, url, username, password_encrypted, hint, audience, is_favorite, sort_order, created_by)
     VALUES (?, ?, ?, NULL, NULL, ?, "internal", 0, ?, ?)'
);

$imported = 0;
foreach ($links as $idx => $l) {
    try {
        $stmt->execute(array($l[0], $l[1], $l[2], $l[3], $idx, $userId));
        $imported++;
    } catch (Exception $ex) {
        echo "ERRO no link #$idx ({$l[1]}): " . $ex->getMessage() . "\n";
    }
}

echo "✔ $imported links de tribunais inseridos.\n\n";

// Sumário
$cats = $pdo->query("SELECT category, COUNT(*) as total FROM portal_links WHERE category LIKE 'Tribunais - %' GROUP BY category ORDER BY category")->fetchAll();
echo "Categorias criadas:\n";
foreach ($cats as $c) echo "  - {$c['category']}: {$c['total']} links\n";

echo "\n=== FIM ===\n";
