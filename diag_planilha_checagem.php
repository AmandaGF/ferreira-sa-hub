<?php
// Diag: cruzar lista da planilha externa da Amanda (26/Abr/2026) com o sistema.
// Pra cada item (cliente + tipo de ação): procura caso em `cases` (LIKE em title
// ou case_type), e se não achar, checa se ao menos o cliente existe em `clients`.
// Resultado em tabela HTML: ✓ caso encontrado · ⚠ só cliente · ✕ nada.
require_once __DIR__ . '/core/database.php';

if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
$pdo = db();

// ITENS A VERIFICAR — só os SEM riscado verde/vermelho na planilha (Amanda disse
// que riscado não precisa estar em lugar nenhum). Cada par = [keyword cliente, keyword tipo]
$itens = array(
    array('UELINTON DOUGLAS', 'Consumidor'),
    array('Natã Oliveira', 'GPX'),
    array('Sidney de Oliveira Ferreira', 'Paternidade'),
    array('Maiara Pereira Cardozo', 'Consumidor'),
    array('Leonardo Tavares', 'Cobrança'),
    array('Carolaine de Souza Barros', 'união estável'),
    array('Tamires da Silva', 'Execução'),
    array('André Carlos de Andrade Junior', 'Trabalhista'),
    array('Jaqueline Nascimento Iwashima', 'Rec. e Diss'),
    array('Jaqueline Nascimento Iwashima', 'Alimentos'),
    array('Jaqueline Nascimento Iwashima', 'Compensatórios'),
    array('YOHANNA MARTINS', 'Convivência'),
    array('Carla Beatriz de Mattos Mendes', 'Revisional'),
    array('Angelica Alves Gonçalves', 'Execução'),
    array('Vanderléia Américo', 'Paternidade'),
    array('André Carius da Silva', 'Trabalhista'),
    array('Edir e Elias', 'GoPass'),
    array('LEONARDO TAVARES FERREIRA', 'Consumidor'),
    array('LORENA QUINTANILHA', 'Convivência'),
    array('THAIS CAROLINE DE LIMA CARDOSO', 'Alimentos'),
    array('Cleitom Avelino Eduardo', 'Inventário'),
    array('Cleitom Avelino Eduardo', 'Regularização'),
    array('Rayane Rodrigues da Silva', 'Convivência'),
    array('JORGE MARCELO PEÑARANDA', 'Curatela'),
    array('LIVIA VITORIA DUARTE', 'Guarda'),
    array('LIVIA VITORIA DUARTE', 'Alimentos'),
    array('ALYSSON FREITAS', 'Consumidor'),
    array('Wallace Alberto da Silva Maria', 'Seguro de Vida'),
    array('Wallace Alberto da Silva Maria', 'Consignatória'),
    array('Kamyle Milton', 'Alimentos'),
    array('Maria Eduarda da Silva Sousa', 'Convivência'),
    array('Maria Eduarda da Silva Sousa', 'Guarda Unilateral'),
    array('ALANE SANTOS OLIVEIRA', 'Convivência'),
    array('SANDRO DA SILVA LEITE', 'Revisional'),
    array('FABRICIO FURTADO MARQUES', 'Assessoria'),
    array('Hugo Teixeira Fernandes', 'Notificação Extrajudicial'),
    array('Regina Célia Caetano', 'Indenizatória'),
    array('Lidiane Zarina Diniz', 'Indenizatória'),
    array('Edir e Elias', 'Goepass'),
    array('ESTER DOS SANTOS PATRICIO', 'Alimentos'),
    array('Ana Caroline Dias Pereira', 'Alimentos'),
    array('Estoel Nathan Costa Silva', 'Guarda Compartilhada'),
    array('Luana Regina de Freitas', 'Alimentos'),
    array('Luana Regina de Freitas Veiga', 'Convivência'),
    array('Bruna Sena Oliveira', 'Paternidade'),
    array('JENIFER DE FREITAS SILVA', 'Alimentos'),
    array('Marcelo Silva de Jesus', 'Trabalhista'),
    array('Marcelo Silva de Jesus', 'Bianca'),
    array('IBRAHIM MELO BRANDÃO', 'Indenizatória'),
    array('Thais da Cruz Feliciano', 'Alimentos'),
    array('Jorge Antônio Peñaranda', 'LOAS'),
    array('Beatriz Elias de Peñaranda', 'LOAS'),
    array('Thaina Karoline Pereira', 'Execução'),
    array('Thaina Karoline Pereira', 'Abandono Afetivo'),
    array('Mayara da Silva Santos', 'Indenizatória'),
    array('Denise de Fatima da Silva Lopes', 'Indenizatória'),
    array('Thaina Karoline Pereira', 'Guarda Unilateral'),
    array('KAYLA KEROLAYNE BORGES', 'Alimentos'),
    array('Jane Reis da Silva', 'Desconto em Folha'),
    array('Jane Reis da Silva', 'Abandono Afetivo'),
    array('MARIA APARECIDA PEREIRA DA SILVA', 'Alimentos'),
    array('Cailane Santos Oliveira', 'Alimentos'),
    array('Cailane Santos Oliveira', 'Convivência'),
    array('Ana Leticia Lamas', 'Juros Abusivos'),
    array('JUCILENE REIS DE ALCANTRA', 'Alimentos'),
    array('Vanessa Costa de Souza', 'Alimentos'),
    array('Vanessa Costa de Souza', 'Convivência'),
    array('Suelen Ribeiro da Silva', 'Alimentos'),
    array('Suelen Ribeiro da Silva', 'Convivência'),
    array('Suelen Ribeiro da Silva', 'Guarda Compartilhada'),
    array('Enayle Garcia Fontes', 'Pensão por Morte'),
    array('Maria Vitória Caetano Ponciano', 'Execução'),
    array('Maria Vitória Caetano Ponciano', 'Revisional'),
    array('Evelyn Lemes Theodoro', 'Execução'),
    array('DAYANA CABRAL FERNANDES', 'Majoração'),
    array('Ubirajara Fonte dos Santos', 'Divórcio'),
    array('Maria Aparecida de Lima Cassimiro', 'Inventário'),
    array('ISABELLE FIGUEIRA DA SILVA', 'Execução'),
);

// Normaliza string (strip acentos, lower) pra match mais frouxo.
function _n($s) {
    $s = mb_strtolower($s, 'UTF-8');
    $de  = array('á','à','â','ã','ä','é','è','ê','ë','í','ì','î','ï','ó','ò','ô','õ','ö','ú','ù','û','ü','ç','ñ');
    $pra = array('a','a','a','a','a','e','e','e','e','i','i','i','i','o','o','o','o','o','u','u','u','u','c','n');
    return str_replace($de, $pra, $s);
}

// Pré-carrega TUDO uma vez só (evita 80 queries)
$casos = $pdo->query("SELECT cs.id, cs.title, cs.case_type, cs.status, cs.client_id, c.name AS client_name FROM cases cs LEFT JOIN clients c ON c.id = cs.client_id")->fetchAll();
$clientes = $pdo->query("SELECT id, name FROM clients")->fetchAll();

// Indexa pra busca rápida (normalizado)
$casosN = array();
foreach ($casos as $c) {
    $hay = _n(($c['client_name'] ?? '') . ' || ' . ($c['title'] ?? '') . ' || ' . ($c['case_type'] ?? ''));
    $casosN[] = array('hay' => $hay, 'row' => $c);
}
$clientesN = array();
foreach ($clientes as $cl) {
    $clientesN[] = array('hay' => _n($cl['name']), 'row' => $cl);
}

$resultados = array();
foreach ($itens as $idx => $item) {
    list($qCli, $qTipo) = $item;
    $qCliN = _n($qCli);
    $qTipoN = _n($qTipo);

    // 1. Busca caso onde nome do cliente E tipo de ação batem
    $achados = array();
    foreach ($casosN as $cN) {
        if (strpos($cN['hay'], $qCliN) !== false && strpos($cN['hay'], $qTipoN) !== false) {
            $achados[] = $cN['row'];
        }
    }

    if (!empty($achados)) {
        $resultados[] = array('item' => $item, 'tipo' => 'CASO', 'matches' => $achados);
        continue;
    }

    // 2. Não achou caso completo — vê se ao menos o cliente existe
    $achadosCli = array();
    foreach ($clientesN as $clN) {
        if (strpos($clN['hay'], $qCliN) !== false) $achadosCli[] = $clN['row'];
    }
    // Tenta também busca mais frouxa: só primeiros 2 termos do cliente
    if (empty($achadosCli)) {
        $partes = preg_split('/\s+/', $qCli);
        if (count($partes) >= 2) {
            $qFrouxa = _n($partes[0] . ' ' . $partes[1]);
            foreach ($clientesN as $clN) {
                if (strpos($clN['hay'], $qFrouxa) !== false) $achadosCli[] = $clN['row'];
            }
        }
    }

    if (!empty($achadosCli)) {
        // Mostra também QUAIS casos esse cliente tem (talvez bata por título alternativo)
        $casosDoCli = array();
        $idsCli = array_column($achadosCli, 'id');
        foreach ($casos as $c) {
            if (in_array((int)$c['client_id'], $idsCli, true)) $casosDoCli[] = $c;
        }
        $resultados[] = array('item' => $item, 'tipo' => 'SO_CLIENTE', 'matches' => $achadosCli, 'casos_cliente' => $casosDoCli);
    } else {
        $resultados[] = array('item' => $item, 'tipo' => 'NADA', 'matches' => array());
    }
}

// Render HTML
$total = count($resultados);
$casoOk = $clienteOk = $nada = 0;
foreach ($resultados as $r) {
    if ($r['tipo'] === 'CASO') $casoOk++;
    elseif ($r['tipo'] === 'SO_CLIENTE') $clienteOk++;
    else $nada++;
}
?>
<!DOCTYPE html>
<html><head><meta charset="utf-8"><title>Checagem planilha vs sistema</title>
<style>
body { font-family: system-ui, -apple-system, Arial; padding: 20px; max-width: 1400px; margin: 0 auto; }
h1 { color: #052228; }
.summary { display: flex; gap: 1rem; margin: 1rem 0; }
.summary > div { padding: .8rem 1.2rem; border-radius: 8px; font-weight: 700; }
.s-caso { background: #d1fae5; color: #065f46; }
.s-cli { background: #fef3c7; color: #92400e; }
.s-nada { background: #fee2e2; color: #991b1b; }
table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
th, td { padding: 8px 10px; border-bottom: 1px solid #e5e7eb; vertical-align: top; font-size: 13px; }
th { background: #052228; color: #fff; text-align: left; }
tr.r-caso { background: #f0fdf4; }
tr.r-cli  { background: #fffbeb; }
tr.r-nada { background: #fef2f2; }
.badge { display: inline-block; padding: 2px 8px; border-radius: 99px; font-size: 11px; font-weight: 700; }
.b-ok   { background: #10b981; color: #fff; }
.b-warn { background: #f59e0b; color: #fff; }
.b-no   { background: #dc2626; color: #fff; }
small { color: #6b7280; }
a { color: #B87333; text-decoration: none; }
a:hover { text-decoration: underline; }
</style></head>
<body>
<h1>Checagem da planilha vs sistema</h1>
<p><small>Lista da Amanda (26/Abr/2026) — só itens NÃO riscados (verde/vermelho são pra ignorar). Total: <strong><?= $total ?></strong> itens.</small></p>

<div class="summary">
    <div class="s-caso">✓ <?= $casoOk ?> casos encontrados</div>
    <div class="s-cli">⚠ <?= $clienteOk ?> só com cliente cadastrado (caso não bate)</div>
    <div class="s-nada">✕ <?= $nada ?> nem cliente encontrado</div>
</div>

<table>
<thead><tr><th>#</th><th>Item da planilha</th><th>Status</th><th>O que o sistema tem</th></tr></thead>
<tbody>
<?php foreach ($resultados as $i => $r):
    $cls = $r['tipo'] === 'CASO' ? 'r-caso' : ($r['tipo'] === 'SO_CLIENTE' ? 'r-cli' : 'r-nada');
?>
<tr class="<?= $cls ?>">
    <td><?= $i + 1 ?></td>
    <td><strong><?= htmlspecialchars($r['item'][0]) ?></strong> × <?= htmlspecialchars($r['item'][1]) ?></td>
    <td>
        <?php if ($r['tipo'] === 'CASO'): ?>
            <span class="badge b-ok">✓ CASO</span>
        <?php elseif ($r['tipo'] === 'SO_CLIENTE'): ?>
            <span class="badge b-warn">⚠ SÓ CLIENTE</span>
        <?php else: ?>
            <span class="badge b-no">✕ NADA</span>
        <?php endif; ?>
    </td>
    <td>
        <?php if ($r['tipo'] === 'CASO'): ?>
            <?php foreach ($r['matches'] as $m): ?>
                <div>→ <a href="modules/operacional/caso_ver.php?id=<?= $m['id'] ?>"><strong>#<?= $m['id'] ?> <?= htmlspecialchars($m['title']) ?></strong></a> · <small>tipo: <?= htmlspecialchars($m['case_type']) ?> · status: <?= htmlspecialchars($m['status']) ?> · cliente: <?= htmlspecialchars($m['client_name']) ?></small></div>
            <?php endforeach; ?>
        <?php elseif ($r['tipo'] === 'SO_CLIENTE'): ?>
            <div><small><strong>Cliente existe mas o caso desse tipo não foi encontrado.</strong></small></div>
            <?php foreach ($r['matches'] as $cl): ?>
                <div>· Cliente: <strong><?= htmlspecialchars($cl['name']) ?></strong> (id <?= $cl['id'] ?>)</div>
            <?php endforeach; ?>
            <?php if (!empty($r['casos_cliente'])): ?>
                <div><small>Casos desse cliente já cadastrados:</small></div>
                <?php foreach ($r['casos_cliente'] as $c): ?>
                    <div><small>&nbsp;&nbsp;→ <a href="modules/operacional/caso_ver.php?id=<?= $c['id'] ?>">#<?= $c['id'] ?> <?= htmlspecialchars($c['title']) ?></a> (<?= htmlspecialchars($c['case_type']) ?>) · <?= htmlspecialchars($c['status']) ?></small></div>
                <?php endforeach; ?>
            <?php else: ?>
                <div><small>&nbsp;&nbsp;<em>Cliente não tem nenhum caso cadastrado.</em></small></div>
            <?php endif; ?>
        <?php else: ?>
            <small>Nenhum cliente nem caso encontrado com esse nome.</small>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

</body></html>
