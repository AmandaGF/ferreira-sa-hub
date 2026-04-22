<?php
/**
 * Novo Ofício pra empregador — pensão alimentícia.
 * Monta e-mail Modelo 1 (solicitar contato RH) e Modelo 2 (envio com dados bancários)
 * com placeholders substituídos pelos dados do caso.
 * Aceita ?case_id=X pra pré-preencher.
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
if (!has_role('admin','gestao','operacional')) { redirect(url('modules/dashboard/')); }

$pageTitle = 'Novo Ofício — Pensão Alimentícia';
$pdo = db();

// Self-heal: campos extras pra registrar os dados do ofício completo
try { $pdo->exec("ALTER TABLE oficios_enviados ADD COLUMN case_id INT UNSIGNED NULL AFTER client_id"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE oficios_enviados ADD COLUMN empresa_cnpj VARCHAR(20) NULL AFTER empregador"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE oficios_enviados ADD COLUMN rh_email VARCHAR(150) NULL AFTER empresa_cnpj"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE oficios_enviados ADD COLUMN rh_contato VARCHAR(50) NULL AFTER rh_email"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE oficios_enviados ADD COLUMN funcionario_nome VARCHAR(150) NULL AFTER rh_contato"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE oficios_enviados ADD COLUMN funcionario_cargo VARCHAR(100) NULL AFTER funcionario_nome"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE oficios_enviados ADD COLUMN funcionario_matricula VARCHAR(30) NULL AFTER funcionario_cargo"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE oficios_enviados ADD COLUMN conta_banco VARCHAR(100) NULL AFTER funcionario_matricula"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE oficios_enviados ADD COLUMN conta_agencia VARCHAR(20) NULL AFTER conta_banco"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE oficios_enviados ADD COLUMN conta_numero VARCHAR(30) NULL AFTER conta_agencia"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE oficios_enviados ADD COLUMN conta_titular VARCHAR(150) NULL AFTER conta_numero"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE oficios_enviados ADD COLUMN conta_cpf VARCHAR(20) NULL AFTER conta_titular"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE oficios_enviados ADD COLUMN tipo_oficio VARCHAR(30) NULL DEFAULT 'pensao_empregador' AFTER conta_cpf"); } catch (Exception $e) {}

// Modo: criar novo OU editar existente
$oficioId = (int)($_GET['id'] ?? 0);
$oficioExistente = null;
if ($oficioId > 0) {
    $st = $pdo->prepare("SELECT * FROM oficios_enviados WHERE id = ?");
    $st->execute(array($oficioId));
    $oficioExistente = $st->fetch();
    if (!$oficioExistente) { flash_set('error', 'Ofício não encontrado.'); redirect(module_url('oficios')); }
    $pageTitle = 'Editar Ofício #' . $oficioId;
}

// Dados do caso (se vier via ?case_id=X ou se for edição de ofício com case_id)
$caseId = (int)($_GET['case_id'] ?? ($oficioExistente['case_id'] ?? 0));
$caso = null; $cliente = null;
if ($caseId > 0) {
    $st = $pdo->prepare(
        "SELECT cs.*, cl.name AS client_name, cl.cpf AS client_cpf, cl.phone AS client_phone
         FROM cases cs LEFT JOIN clients cl ON cl.id = cs.client_id
         WHERE cs.id = ?"
    );
    $st->execute(array($caseId));
    $caso = $st->fetch();
    if ($caso) {
        $cliente = array(
            'name' => $caso['client_name'],
            'cpf' => $caso['client_cpf'],
            'phone' => $caso['client_phone'],
            'id' => $caso['client_id'],
        );
    }
}
// Se edição sem case mas com client_id no ofício: busca cliente
if (!$cliente && !empty($oficioExistente['client_id'])) {
    $st = $pdo->prepare("SELECT id, name, cpf, phone FROM clients WHERE id = ?");
    $st->execute(array($oficioExistente['client_id']));
    $cliente = $st->fetch();
}

// Helper pra pegar valor inicial dos campos (preferindo o ofício existente)
function _of($campo, $oficio, $caso = null, $cliente = null, $default = '') {
    if ($oficio && isset($oficio[$campo]) && $oficio[$campo] !== null && $oficio[$campo] !== '') return $oficio[$campo];
    return $default;
}

// SUBMIT
if ($_SERVER['REQUEST_METHOD'] === 'POST' && validate_csrf()) {
    $dados = array(
        'client_id' => (int)($_POST['client_id'] ?? 0) ?: null,
        'case_id' => (int)($_POST['case_id'] ?? 0) ?: null,
        'numero_processo' => clean_str($_POST['numero_processo'] ?? '', 50),
        'empregador' => clean_str($_POST['empregador'] ?? '', 250),
        'empresa_cnpj' => clean_str($_POST['empresa_cnpj'] ?? '', 20),
        'rh_email' => clean_str($_POST['rh_email'] ?? '', 150),
        'rh_contato' => clean_str($_POST['rh_contato'] ?? '', 50),
        'funcionario_nome' => clean_str($_POST['funcionario_nome'] ?? '', 150),
        'funcionario_cargo' => clean_str($_POST['funcionario_cargo'] ?? '', 100),
        'funcionario_matricula' => clean_str($_POST['funcionario_matricula'] ?? '', 30),
        'conta_banco' => clean_str($_POST['conta_banco'] ?? '', 100),
        'conta_agencia' => clean_str($_POST['conta_agencia'] ?? '', 20),
        'conta_numero' => clean_str($_POST['conta_numero'] ?? '', 30),
        'conta_titular' => clean_str($_POST['conta_titular'] ?? '', 150),
        'conta_cpf' => clean_str($_POST['conta_cpf'] ?? '', 20),
        'tipo_oficio' => 'pensao_empregador',
        'data_envio' => $_POST['data_envio'] ?: date('Y-m-d'),
        'plataforma' => clean_str($_POST['plataforma'] ?? 'email', 50),
        'observacoes' => clean_str($_POST['observacoes'] ?? '', 500),
    );
    $idEdicao = (int)($_POST['oficio_id'] ?? 0);
    if ($idEdicao > 0) {
        // UPDATE
        $sets = array(); $vals = array();
        foreach ($dados as $k => $v) { $sets[] = "$k = ?"; $vals[] = $v; }
        $vals[] = $idEdicao;
        $pdo->prepare("UPDATE oficios_enviados SET " . implode(',', $sets) . " WHERE id = ?")->execute($vals);
        audit_log('oficio_editado', 'oficios', $idEdicao, 'Empregador: ' . $dados['empregador']);
        flash_set('success', 'Ofício #' . $idEdicao . ' atualizado!');
        redirect($dados['case_id'] ? module_url('operacional', 'caso_ver.php?id=' . $dados['case_id']) : module_url('oficios'));
    } else {
        // INSERT
        $sql = "INSERT INTO oficios_enviados (" . implode(',', array_keys($dados)) . ") VALUES (" . implode(',', array_fill(0, count($dados), '?')) . ")";
        $pdo->prepare($sql)->execute(array_values($dados));
        $oficioId = (int)$pdo->lastInsertId();

        audit_log('oficio_pensao_registrado', 'oficios', $oficioId, 'Empregador: ' . $dados['empregador']);

        // Andamento automático no processo — somente infos NÃO sensíveis
        // (sem e-mail/telefone do RH, sem CPF do titular, sem dados bancários)
        if ($dados['case_id']) {
            try {
                $linhas = array();
                $linhas[] = '📬 Ofício #' . $oficioId . ' enviado ao empregador — desconto de pensão em folha';
                $linhas[] = '• Empresa: ' . $dados['empregador'] . ($dados['empresa_cnpj'] ? ' (CNPJ ' . $dados['empresa_cnpj'] . ')' : '');
                if (!empty($dados['funcionario_nome'])) {
                    $linhas[] = '• Funcionário: ' . $dados['funcionario_nome']
                        . ($dados['funcionario_cargo'] ? ' — ' . $dados['funcionario_cargo'] : '')
                        . ($dados['funcionario_matricula'] ? ' (matrícula ' . $dados['funcionario_matricula'] . ')' : '');
                }
                $linhas[] = '• Forma de envio: ' . strtoupper($dados['plataforma'] ?: 'email');
                $linhas[] = '• Data do envio: ' . date('d/m/Y', strtotime($dados['data_envio']));
                if (!empty($dados['observacoes'])) $linhas[] = '• Obs: ' . $dados['observacoes'];
                $desc = implode("\n", $linhas);
                $pdo->prepare(
                    "INSERT INTO case_andamentos (case_id, data_andamento, tipo, descricao, created_by, visivel_cliente, created_at) VALUES (?, ?, 'oficio', ?, ?, 0, NOW())"
                )->execute(array($dados['case_id'], $dados['data_envio'], $desc, current_user_id()));
            } catch (Exception $e) {}
        }
        flash_set('success', 'Ofício #' . $oficioId . ' registrado!');
        redirect($dados['case_id'] ? module_url('operacional', 'caso_ver.php?id=' . $dados['case_id']) : module_url('oficios'));
    }
}

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.of-grid { display:grid;grid-template-columns:1fr 1fr;gap:.65rem; }
.of-sec h4 { font-size:.82rem;font-weight:800;color:var(--petrol-900);margin:1rem 0 .5rem;padding-bottom:.25rem;border-bottom:2px solid rgba(184,115,51,.3); }
.of-lab { font-size:.68rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.3px;display:block;margin-bottom:.2rem; }
.of-inp { width:100%;padding:.5rem .65rem;font-size:.85rem;border:1.5px solid #e5e7eb;border-radius:8px;font-family:inherit; }
.of-tpl { background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;padding:.85rem 1rem;margin-bottom:.6rem; }
.of-tpl-head { display:flex;justify-content:space-between;align-items:center;margin-bottom:.4rem; }
.of-tpl-title { font-size:.82rem;font-weight:800;color:var(--petrol-900); }
.of-tpl textarea { width:100%;min-height:170px;padding:.6rem .7rem;font-size:.78rem;border:1.5px solid #e5e7eb;border-radius:8px;font-family:Consolas,monospace;line-height:1.45;background:#fff; }
.of-btn-copy { background:#052228;color:#fff;border:none;padding:4px 10px;border-radius:6px;font-size:.68rem;font-weight:700;cursor:pointer; }
.of-btn-wa { background:#25D366;color:#fff;border:none;padding:4px 10px;border-radius:6px;font-size:.68rem;font-weight:700;cursor:pointer; }
@media (max-width:700px) { .of-grid { grid-template-columns:1fr; } }
</style>

<a href="<?= $caseId ? module_url('operacional','caso_ver.php?id='.$caseId) : module_url('oficios') ?>" class="btn btn-outline btn-sm">&larr; Voltar</a>

<h2 style="margin:.75rem 0 .25rem;font-size:1.2rem;color:var(--petrol-900);">📬 <?= $oficioExistente ? 'Editar Ofício #' . $oficioId : 'Ofício ao empregador — Pensão alimentícia' ?></h2>
<p style="font-size:.85rem;color:var(--text-muted);margin:0 0 1rem;">
    <?php if ($oficioExistente): ?>Ajuste os dados abaixo. Ao salvar, os templates refletem as mudanças e você pode copiar e reenviar se precisar.<?php else: ?>Preencha os dados do funcionário e da empresa. O sistema gera o e-mail pronto nos 2 modelos oficiais — você copia e envia pelo seu Gmail/Outlook.<?php endif; ?>
    <?php if ($caso): ?><br>Processo: <b><?= e($caso['title'] ?: 'Caso #' . $caseId) ?></b><?= $caso['case_number'] ? ' · ' . e($caso['case_number']) : '' ?><?php endif; ?>
</p>

<form method="POST" style="max-width:960px;">
    <?= csrf_input() ?>
    <?php if ($oficioExistente): ?>
        <input type="hidden" name="oficio_id" value="<?= (int)$oficioId ?>">
    <?php endif; ?>
    <input type="hidden" name="client_id" value="<?= (int)($cliente['id'] ?? $oficioExistente['client_id'] ?? 0) ?>">
    <input type="hidden" name="case_id" value="<?= (int)$caseId ?>">

    <div class="of-sec">
        <h4>🏢 Empresa (empregadora)</h4>
        <div class="of-grid">
            <div><span class="of-lab">Razão social / nome fantasia *</span><input type="text" name="empregador" id="empregador" class="of-inp" required placeholder="Ex: Empresa X Ltda" value="<?= e($oficioExistente['empregador'] ?? '') ?>" oninput="atualizarPreviews()"></div>
            <div><span class="of-lab">CNPJ</span><input type="text" name="empresa_cnpj" id="empresa_cnpj" class="of-inp" placeholder="00.000.000/0000-00" value="<?= e($oficioExistente['empresa_cnpj'] ?? '') ?>" oninput="atualizarPreviews()"></div>
            <div><span class="of-lab">E-mail do RH</span><input type="email" name="rh_email" id="rh_email" class="of-inp" placeholder="rh@empresa.com.br" value="<?= e($oficioExistente['rh_email'] ?? '') ?>" oninput="atualizarPreviews()"></div>
            <div><span class="of-lab">WhatsApp/telefone do RH</span><input type="text" name="rh_contato" id="rh_contato" class="of-inp" placeholder="(24) 99999-0000" value="<?= e($oficioExistente['rh_contato'] ?? '') ?>" oninput="atualizarPreviews()"></div>
        </div>
    </div>

    <div class="of-sec">
        <h4>👤 Funcionário (alimentante)</h4>
        <div class="of-grid">
            <div><span class="of-lab">Nome</span><input type="text" name="funcionario_nome" id="funcionario_nome" class="of-inp" placeholder="Nome completo do funcionário" value="<?= e($oficioExistente['funcionario_nome'] ?? '') ?>" oninput="atualizarPreviews()"></div>
            <div><span class="of-lab">Cargo</span><input type="text" name="funcionario_cargo" id="funcionario_cargo" class="of-inp" value="<?= e($oficioExistente['funcionario_cargo'] ?? '') ?>" oninput="atualizarPreviews()"></div>
            <div><span class="of-lab">Matrícula</span><input type="text" name="funcionario_matricula" id="funcionario_matricula" class="of-inp" value="<?= e($oficioExistente['funcionario_matricula'] ?? '') ?>" oninput="atualizarPreviews()"></div>
        </div>
    </div>

    <div class="of-sec">
        <h4>🏦 Dados para depósito (representante legal)</h4>
        <div class="of-grid">
            <div><span class="of-lab">Titular da conta</span><input type="text" name="conta_titular" id="conta_titular" class="of-inp" value="<?= e($oficioExistente['conta_titular'] ?? $cliente['name'] ?? '') ?>" oninput="atualizarPreviews()"></div>
            <div><span class="of-lab">CPF titular</span><input type="text" name="conta_cpf" id="conta_cpf" class="of-inp" value="<?= e($oficioExistente['conta_cpf'] ?? $cliente['cpf'] ?? '') ?>" oninput="atualizarPreviews()"></div>
            <div><span class="of-lab">Banco</span><input type="text" name="conta_banco" id="conta_banco" class="of-inp" placeholder="Ex: Itaú (341)" value="<?= e($oficioExistente['conta_banco'] ?? '') ?>" oninput="atualizarPreviews()"></div>
            <div><span class="of-lab">Agência</span><input type="text" name="conta_agencia" id="conta_agencia" class="of-inp" value="<?= e($oficioExistente['conta_agencia'] ?? '') ?>" oninput="atualizarPreviews()"></div>
            <div><span class="of-lab">Conta</span><input type="text" name="conta_numero" id="conta_numero" class="of-inp" placeholder="00000-0" value="<?= e($oficioExistente['conta_numero'] ?? '') ?>" oninput="atualizarPreviews()"></div>
            <div><span class="of-lab">Número do processo</span><input type="text" name="numero_processo" id="numero_processo" class="of-inp" value="<?= e($oficioExistente['numero_processo'] ?? $caso['case_number'] ?? '') ?>" oninput="atualizarPreviews()"></div>
        </div>
    </div>

    <div class="of-sec">
        <h4>✉️ E-mail — Modelo 1 (1º contato: pedir e-mail do setor RH)</h4>
        <div class="of-tpl">
            <div class="of-tpl-head">
                <span class="of-tpl-title">Texto pronto pra copiar e colar no Gmail/Outlook</span>
                <span>
                    <button type="button" class="of-btn-copy" onclick="copiarTpl('tplEmail1')">📋 Copiar</button>
                </span>
            </div>
            <textarea id="tplEmail1" readonly></textarea>
        </div>
    </div>

    <div class="of-sec">
        <h4>✉️ E-mail — Modelo 2 (envio formal com dados bancários)</h4>
        <div class="of-tpl">
            <div class="of-tpl-head">
                <span class="of-tpl-title">Envie depois que o RH confirmar o e-mail do setor</span>
                <span>
                    <button type="button" class="of-btn-copy" onclick="copiarTpl('tplEmail2')">📋 Copiar</button>
                </span>
            </div>
            <textarea id="tplEmail2" readonly></textarea>
        </div>
    </div>

    <div class="of-sec">
        <h4>💬 Mensagem WhatsApp (caso o RH só atenda por WhatsApp)</h4>
        <div class="of-tpl">
            <div class="of-tpl-head">
                <span class="of-tpl-title">Texto curto pra abrir a conversa</span>
                <span>
                    <button type="button" class="of-btn-copy" onclick="copiarTpl('tplWa')">📋 Copiar</button>
                    <button type="button" class="of-btn-wa" onclick="enviarWa()">📱 Enviar via Hub</button>
                </span>
            </div>
            <textarea id="tplWa" readonly style="min-height:120px;"></textarea>
        </div>
    </div>

    <div class="of-sec">
        <h4>📝 Registro interno</h4>
        <div class="of-grid">
            <div><span class="of-lab">Data do envio</span><input type="date" name="data_envio" class="of-inp" value="<?= e($oficioExistente['data_envio'] ?? date('Y-m-d')) ?>"></div>
            <div><span class="of-lab">Plataforma</span><select name="plataforma" class="of-inp">
                <?php $_pl = $oficioExistente['plataforma'] ?? 'email'; ?>
                <option value="email" <?= $_pl === 'email' ? 'selected' : '' ?>>E-mail</option>
                <option value="whatsapp" <?= $_pl === 'whatsapp' ? 'selected' : '' ?>>WhatsApp</option>
                <option value="correio" <?= $_pl === 'correio' ? 'selected' : '' ?>>Correios</option>
                <option value="outro" <?= $_pl === 'outro' ? 'selected' : '' ?>>Outro</option>
            </select></div>
        </div>
        <div style="margin-top:.5rem;"><span class="of-lab">Observações</span><textarea name="observacoes" class="of-inp" rows="2"><?= e($oficioExistente['observacoes'] ?? '') ?></textarea></div>
    </div>

    <div style="display:flex;gap:.5rem;justify-content:flex-end;margin-top:1.5rem;padding-top:1rem;border-top:1px solid var(--border);">
        <a href="<?= $caseId ? module_url('operacional','caso_ver.php?id='.$caseId) : module_url('oficios') ?>" class="btn btn-outline btn-sm">Cancelar</a>
        <button type="submit" class="btn btn-primary btn-sm" style="background:#B87333;"><?= $oficioExistente ? '💾 Salvar alterações' : '✓ Registrar envio do ofício' ?></button>
    </div>
</form>

<script>
<?php
// Detecta gênero do usuário logado pra flexionar os templates corretamente
// Prioridade: coluna users.genero (se existir) → heurística (primeiro nome termina em 'a' = feminino)
try { $pdo->exec("ALTER TABLE users ADD COLUMN genero CHAR(1) DEFAULT NULL COMMENT 'M=masculino, F=feminino'"); } catch (Exception $e) {}
$_userRow = current_user();
$_userGenero = $_userRow['genero'] ?? null;
if (!$_userGenero) {
    $_primeiro = strtolower(explode(' ', trim($_userRow['name'] ?? ''))[0] ?? '');
    // Heurística simples: termina em 'a' ou sufixos comuns femininos → F; caso contrário M
    $_userGenero = (preg_match('/a$|ce$/', $_primeiro)) ? 'F' : 'M';
    // Exceções comuns masculinas terminadas em 'a'
    if (in_array($_primeiro, array('luca','joshua','jeremias','elias','tobias','matias','zacarias'), true)) $_userGenero = 'M';
}
?>
var userNome = <?= json_encode($_userRow['name'] ?? 'Amanda Ferreira') ?>;
var userGenero = <?= json_encode($_userGenero) ?>; // 'F' ou 'M'
var _T = userGenero === 'F'
    ? { advg:'advogada', inscr:'inscrita', atu:'atuando' }
    : { advg:'advogado', inscr:'inscrito', atu:'atuando' };
var userOAB = <?= json_encode(current_user()['oab'] ?? 'OAB-RJ 163.260') ?>;
var userTel = <?= json_encode(current_user()['phone'] ?? '(24) 99205-0096') ?>;
var casoInfo = <?= json_encode(array('client_name' => $cliente['name'] ?? '', 'phone' => $cliente['phone'] ?? '', 'client_id' => $cliente['id'] ?? 0)) ?>;

function atualizarPreviews() {
    var emp    = (document.getElementById('empregador').value || '').trim();
    var cnpj   = (document.getElementById('empresa_cnpj').value || '').trim();
    var func   = (document.getElementById('funcionario_nome').value || '[NOME DO COLABORADOR]').trim();
    var cargo  = (document.getElementById('funcionario_cargo').value || '[CARGO]').trim();
    var matr   = (document.getElementById('funcionario_matricula').value || '[MATRÍCULA]').trim();
    var banco  = (document.getElementById('conta_banco').value || '[BANCO]').trim();
    var ag     = (document.getElementById('conta_agencia').value || '[AGÊNCIA]').trim();
    var cc     = (document.getElementById('conta_numero').value || '[CONTA]').trim();
    var tit    = (document.getElementById('conta_titular').value || '[TITULAR]').trim();
    var cpf    = (document.getElementById('conta_cpf').value || '[CPF]').trim();
    var numProc = ((document.getElementById('numero_processo') || {}).value || '').trim();
    // Prefixo obrigatório do assunto: sempre começa com Ref: + nº do processo
    var refPref = 'Ref: processo nº ' + (numProc || '[Nº DO PROCESSO]') + ' — ';

    // Modelo 1 — solicitar contato RH
    var m1 = 'Assunto: ' + refPref + 'Pensão alimentícia — solicitação de contato do RH' + (emp ? ' — ' + emp : '') + '\n\n'
           + 'Prezado(a), boa tarde!\n\n'
           + 'Meu nome é ' + userNome + ', ' + _T.advg + ' ' + _T.inscr + ' na ' + userOAB + ', e estou atuando em processo de fixação de pensão alimentícia em que um(a) de seus colaboradores é genitor(a) da criança.\n\n'
           + 'Informo que há decisão judicial determinando o desconto da pensão alimentícia diretamente na folha de pagamento do(a) colaborador(a) ' + func
           + (cargo !== '[CARGO]' ? ', cargo ' + cargo : '') + (matr !== '[MATRÍCULA]' ? ', matrícula ' + matr : '') + '. '
           + 'Para formalizar a medida, necessito encaminhar o ofício diretamente ao setor de Recursos Humanos.\n\n'
           + 'Assim, solicito, gentilmente, que me informe o endereço de e-mail ou contato do setor responsável, a fim de enviar o referido ofício e cumprir a determinação judicial.\n\n'
           + 'Desde já, agradeço pela atenção e colaboração. Fico à disposição para quaisquer esclarecimentos.\n\n'
           + 'Atenciosamente,\n' + userNome + '\n' + userOAB + '\n' + userTel;

    // Modelo 2 — envio com dados bancários
    var m2 = 'Assunto: ' + refPref + 'Ofício — Desconto de pensão alimentícia em folha' + (func !== '[NOME DO COLABORADOR]' ? ' — ' + func : '') + '\n\n'
           + 'Prezada(o), bom dia!\n\n'
           + 'Meu nome é ' + userNome + ', ' + _T.advg + ' ' + _T.inscr + ' na ' + userOAB + ', e estou atuando em processo de fixação de pensão alimentícia.\n\n'
           + 'Envio, em anexo, a decisão judicial determinando o desconto da pensão alimentícia diretamente na folha de pagamento do(a) colaborador(a):\n\n'
           + '• Nome: ' + func + '\n'
           + '• Cargo: ' + cargo + '\n'
           + '• Matrícula: ' + matr + '\n\n'
           + 'DADOS PARA DEPÓSITO:\n'
           + '• Banco: ' + banco + '\n'
           + '• Agência: ' + ag + '\n'
           + '• Conta corrente: ' + cc + '\n'
           + '• Titular: ' + tit + '\n'
           + '• CPF do titular: ' + cpf + '\n\n'
           + 'Solicito, gentilmente, a confirmação do recebimento para que possamos informar ao juízo sobre o cumprimento da decisão.\n\n'
           + 'Desde já, agradeço pela atenção e colaboração. Fico à disposição para quaisquer esclarecimentos.\n\n'
           + 'Atenciosamente,\n' + userNome + '\n' + userOAB + '\n' + userTel;

    // WhatsApp — curto e respeitoso
    var wa = 'Olá! Boa tarde.\n\n'
           + 'Sou ' + userNome + ', ' + _T.advg + ' ' + userOAB + '. Estou tratando de um processo de pensão alimentícia envolvendo um(a) colaborador(a) de vocês'
           + (emp ? ' da ' + emp : '') + '.\n\n'
           + 'Preciso enviar um ofício ao setor de RH para desconto em folha de pagamento. Poderia me informar o e-mail do setor responsável?\n\n'
           + 'Agradeço desde já. 🙏';

    document.getElementById('tplEmail1').value = m1;
    document.getElementById('tplEmail2').value = m2;
    document.getElementById('tplWa').value = wa;
}
atualizarPreviews();

function copiarTpl(id) {
    var el = document.getElementById(id);
    el.select(); el.setSelectionRange(0, 99999);
    try {
        document.execCommand('copy');
        if (navigator.clipboard) navigator.clipboard.writeText(el.value);
        var btn = event.target;
        var orig = btn.textContent;
        btn.textContent = '✓ Copiado!';
        btn.style.background = '#059669';
        setTimeout(function(){ btn.textContent = orig; btn.style.background = '#052228'; }, 2000);
    } catch(e) { alert('Erro ao copiar: ' + e.message); }
}

function enviarWa() {
    var numero = (document.getElementById('rh_contato').value || '').replace(/\D/g, '');
    if (!numero) { alert('Informe o WhatsApp do RH no campo acima.'); return; }
    var msg = document.getElementById('tplWa').value;
    // Se tem waSenderOpen (Hub integrado), usa; senão abre wa.me
    if (window.waSenderOpen) {
        waSenderOpen({
            telefone: numero,
            nome: document.getElementById('empregador').value || 'RH',
            canal: '24',
            mensagem: msg
        });
    } else {
        window.open('https://wa.me/55' + numero + '?text=' + encodeURIComponent(msg), '_blank');
    }
}
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
