<?php
/**
 * Ferreira & Sá Hub — Treinamento da Equipe
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();

$pageTitle = 'Treinamento';
$pdo = db();

// Buscar usuários ativos (exceto admin) para os chips
$activeUsers = $pdo->query("SELECT name FROM users WHERE is_active = 1 ORDER BY name")->fetchAll();

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
:root {
  --primario: #052228;
  --cobre: #B87333;
  --cobre-claro: #D7AB90;
  --cobre-suave: #F5EDE3;
  --texto: #1A1A1A;
  --cinza: #F4F4F4;
  --cinza-medio: #E8E8E8;
  --branco: #FFFFFF;
  --verde-ok: #2D7A4F;
  --vermelho: #CC0000;
}
.page-content { max-width:none !important; padding:0 !important; }
.hero { background: linear-gradient(135deg, var(--primario) 0%, #0a3d47 100%); padding: 48px 32px 40px; text-align: center; position: relative; overflow: hidden; border-radius: 0 0 16px 16px; }
.hero::before { content: ''; position: absolute; top: -60px; right: -60px; width: 300px; height: 300px; border: 1px solid rgba(184,115,51,0.2); border-radius: 50%; }
.hero-eyebrow { font-size: 12px; font-weight: 600; letter-spacing: 0.2em; text-transform: uppercase; color: var(--cobre); margin-bottom: 12px; }
.hero h1 { font-size: clamp(24px, 4vw, 38px); color: #fff; line-height: 1.2; margin-bottom: 14px; font-weight: 800; }
.hero-sub { font-size: 15px; color: rgba(255,255,255,0.7); max-width: 480px; margin: 0 auto 24px; }
.hero-chips { display: flex; flex-wrap: wrap; gap: 8px; justify-content: center; }
.chip { background: rgba(255,255,255,0.08); border: 1px solid rgba(184,115,51,0.35); color: var(--cobre-claro); font-size: 12px; padding: 5px 14px; border-radius: 20px; }
.hero-logo { position: absolute; top: 20px; right: 32px; }
.hero-logo img { width: 48px; height: 48px; border-radius: 10px; opacity: 0.8; }

.nav-modulos { background: var(--branco); border-bottom: 1px solid var(--cinza-medio); padding: 0 16px; display: flex; gap: 0; justify-content: center; overflow-x: auto; position: sticky; top: 60px; z-index: 50; }
.nav-btn { background: none; border: none; border-bottom: 3px solid transparent; padding: 14px 16px; font-size: 13px; font-weight: 500; color: #888; cursor: pointer; white-space: nowrap; transition: all 0.2s; font-family: inherit; }
.nav-btn:hover { color: var(--primario); }
.nav-btn.ativo { color: var(--primario); border-bottom-color: var(--cobre); font-weight: 600; }

.tr-main { max-width: 1000px; margin: 0 auto; padding: 32px 24px; }
.modulo { display: none; animation: fadeIn 0.3s ease; }
.modulo.ativo { display: block; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

.modulo-header { display: flex; align-items: center; gap: 14px; margin-bottom: 28px; }
.modulo-icon { width: 48px; height: 48px; background: var(--primario); border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0; }
.modulo-titulo h2 { font-size: 22px; color: var(--primario); font-weight: 800; }
.modulo-titulo p { font-size: 13px; color: #666; margin-top: 2px; }

.secao { background: var(--branco); border-radius: 14px; padding: 28px; margin-bottom: 20px; border: 1px solid var(--cinza-medio); }
.secao-titulo { font-size: 12px; font-weight: 700; letter-spacing: 0.12em; text-transform: uppercase; color: var(--cobre); margin-bottom: 18px; display: flex; align-items: center; gap: 10px; }
.secao-titulo::after { content: ''; flex: 1; height: 1px; background: var(--cinza-medio); }

.passos { display: flex; flex-direction: column; gap: 0; }
.passo { display: flex; gap: 16px; padding-bottom: 20px; position: relative; }
.passo:not(:last-child)::before { content: ''; position: absolute; left: 17px; top: 38px; bottom: 0; width: 2px; background: var(--cinza-medio); }
.passo-num { width: 36px; height: 36px; background: var(--primario); color: #fff; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 700; flex-shrink: 0; position: relative; z-index: 1; }
.passo-conteudo h4 { font-size: 14px; font-weight: 600; color: var(--primario); margin-bottom: 4px; padding-top: 6px; }
.passo-conteudo p { font-size: 13px; color: #555; line-height: 1.6; }

.alerta { border-radius: 10px; padding: 12px 16px; font-size: 13px; margin: 12px 0; display: flex; gap: 10px; align-items: flex-start; }
.alerta-ok { background: #EBF7F1; border-left: 4px solid var(--verde-ok); color: #1a4a2e; }
.alerta-atencao { background: #FFF8EC; border-left: 4px solid var(--cobre); color: #5a3a00; }
.alerta-erro { background: #FFF0F0; border-left: 4px solid var(--vermelho); color: #5a0000; }
.alerta-icone { font-size: 16px; flex-shrink: 0; }

.kanban-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 10px; margin: 14px 0; }
.kanban-coluna { background: var(--cinza); border-radius: 10px; padding: 12px; border: 1px solid var(--cinza-medio); }
.kanban-coluna-num { font-size: 10px; font-weight: 700; color: var(--cobre); letter-spacing: 0.1em; margin-bottom: 3px; }
.kanban-coluna-nome { font-size: 12px; font-weight: 600; color: var(--primario); margin-bottom: 4px; }
.kanban-coluna-resp { font-size: 10px; color: #888; }
.kanban-coluna-auto { margin-top: 6px; font-size: 10px; background: #EBF7F1; color: var(--verde-ok); padding: 2px 8px; border-radius: 20px; display: inline-block; font-weight: 600; }
.kanban-coluna-manual { margin-top: 6px; font-size: 10px; background: #EBF2FF; color: #1a3a7a; padding: 2px 8px; border-radius: 20px; display: inline-block; font-weight: 600; }

.regra { display: flex; gap: 14px; padding: 14px; background: var(--cinza); border-radius: 10px; margin-bottom: 8px; align-items: flex-start; }
.regra-emoji { font-size: 18px; flex-shrink: 0; }
.regra-texto h4 { font-size: 13px; font-weight: 600; color: var(--primario); margin-bottom: 3px; }
.regra-texto p { font-size: 12px; color: #555; }

.tabela-treino { width: 100%; border-collapse: collapse; font-size: 13px; }
.tabela-treino th { background: var(--primario); color: #fff; padding: 10px 14px; text-align: left; font-weight: 600; font-size: 11px; letter-spacing: 0.05em; }
.tabela-treino th:first-child { border-radius: 8px 0 0 0; }
.tabela-treino th:last-child { border-radius: 0 8px 0 0; }
.tabela-treino td { padding: 10px 14px; border-bottom: 1px solid var(--cinza-medio); vertical-align: top; }
.tabela-treino tr:nth-child(even) td { background: var(--cinza); }

.badge { display: inline-block; padding: 2px 8px; border-radius: 20px; font-size: 11px; font-weight: 600; }
.badge-comercial { background: #E8F0FF; color: #1a3a7a; }
.badge-cx { background: #F0E8FF; color: #3a1a7a; }
.badge-operacional { background: #EBF7F1; color: var(--verde-ok); }
.badge-admin { background: #FFEEE8; color: #7a3a1a; }

.dica { background: var(--cobre-suave); border: 1px solid rgba(184,115,51,0.2); border-radius: 10px; padding: 14px 18px; margin: 14px 0; }
.dica-titulo { font-size: 11px; font-weight: 700; letter-spacing: 0.1em; text-transform: uppercase; color: var(--cobre); margin-bottom: 6px; }
.dica p { font-size: 13px; color: #5a3a00; }

.tr-footer { background: var(--primario); color: rgba(255,255,255,0.5); text-align: center; padding: 20px; font-size: 12px; margin-top: 40px; border-radius: 12px; }
.tr-footer strong { color: var(--cobre); }

@media (max-width: 768px) { .tr-main { padding: 16px 12px; } .secao { padding: 18px; } .kanban-grid { grid-template-columns: 1fr 1fr; } }
</style>

<div class="hero">
  <div class="hero-logo"><img src="<?= url('assets/img/logo-sidebar.png') ?>" alt="Logo" onerror="this.style.display='none'"></div>
  <div class="hero-eyebrow">Material Interno — Equipe</div>
  <h1>Bem-vindas ao<br>Ferreira & Sá Hub</h1>
  <p class="hero-sub">Guia completo para usar o portal interno do escritório. Leia com atenção e consulte sempre que tiver dúvidas.</p>
  <div class="hero-chips">
    <?php foreach ($activeUsers as $u):
        $firstName = explode(' ', trim($u['name']))[0];
    ?>
        <span class="chip"><?= e($firstName) ?></span>
    <?php endforeach; ?>
  </div>
</div>

<nav class="nav-modulos">
  <button class="nav-btn ativo" onclick="mostrar('kanban-comercial', this)">📋 Kanban Comercial</button>
  <button class="nav-btn" onclick="mostrar('kanban-operacional', this)">⚙️ Kanban Operacional</button>
  <button class="nav-btn" onclick="mostrar('documentos', this)">📄 Documentos</button>
  <button class="nav-btn" onclick="mostrar('notificacoes', this)">💬 Notificações</button>
  <button class="nav-btn" onclick="mostrar('portal-links', this)">🔗 Portal de Links</button>
  <button class="nav-btn" onclick="mostrar('procuracao', this)">✍️ Regras de Procuração</button>
</nav>

<div class="tr-main">

<!-- ═══ KANBAN COMERCIAL ═══ -->
<div id="kanban-comercial" class="modulo ativo">
  <div class="modulo-header">
    <div class="modulo-icon">📋</div>
    <div class="modulo-titulo"><h2>Kanban Comercial</h2><p>Acompanhamento do cliente desde o cadastro até a pasta apta</p></div>
  </div>
  <div class="secao">
    <div class="secao-titulo">As 10 colunas do Pipeline</div>
    <div class="kanban-grid">
      <div class="kanban-coluna"><div class="kanban-coluna-num">01</div><div class="kanban-coluna-nome">Cadastro Preenchido</div><div class="kanban-coluna-resp">Sistema</div><div class="kanban-coluna-auto">Automático</div></div>
      <div class="kanban-coluna"><div class="kanban-coluna-num">02</div><div class="kanban-coluna-nome">Elaboração Procuração / Contrato</div><div class="kanban-coluna-resp">Comercial</div><div class="kanban-coluna-manual">Manual</div></div>
      <div class="kanban-coluna"><div class="kanban-coluna-num">03</div><div class="kanban-coluna-nome">Link Enviado</div><div class="kanban-coluna-resp">Comercial</div><div class="kanban-coluna-manual">Manual</div></div>
      <div class="kanban-coluna"><div class="kanban-coluna-num">04</div><div class="kanban-coluna-nome">Contrato Assinado</div><div class="kanban-coluna-resp">Comercial</div><div class="kanban-coluna-auto">Gatilho Drive + Op.</div></div>
      <div class="kanban-coluna"><div class="kanban-coluna-num">05</div><div class="kanban-coluna-nome">Agendado + Docs Solicitados</div><div class="kanban-coluna-resp">CX</div><div class="kanban-coluna-manual">Manual</div></div>
      <div class="kanban-coluna"><div class="kanban-coluna-num">06</div><div class="kanban-coluna-nome">Reunião / Cobrando Docs</div><div class="kanban-coluna-resp">CX</div><div class="kanban-coluna-manual">Manual</div></div>
      <div class="kanban-coluna"><div class="kanban-coluna-num">07</div><div class="kanban-coluna-nome">Documento Faltante</div><div class="kanban-coluna-resp">Sistema</div><div class="kanban-coluna-auto">Espelho Operacional</div></div>
      <div class="kanban-coluna"><div class="kanban-coluna-num">08</div><div class="kanban-coluna-nome">Pasta Apta</div><div class="kanban-coluna-resp">CX</div><div class="kanban-coluna-auto">Some quando Op. inicia</div></div>
      <div class="kanban-coluna"><div class="kanban-coluna-num">09</div><div class="kanban-coluna-nome">Cancelado</div><div class="kanban-coluna-resp">Admin</div><div class="kanban-coluna-manual">Somente Admin</div></div>
      <div class="kanban-coluna"><div class="kanban-coluna-num">10</div><div class="kanban-coluna-nome">Suspenso</div><div class="kanban-coluna-resp">Admin</div><div class="kanban-coluna-manual">Somente Admin</div></div>
    </div>
  </div>
  <div class="secao">
    <div class="secao-titulo">Fluxo passo a passo</div>
    <div class="passos">
      <div class="passo"><div class="passo-num">1</div><div class="passo-conteudo"><h4>Cliente preenche o formulário de cadastro</h4><p>O card é criado automaticamente na coluna <strong>Cadastro Preenchido</strong>. Vocês não precisam fazer nada — ele já aparece com todos os dados que o cliente informou.</p></div></div>
      <div class="passo"><div class="passo-num">2</div><div class="passo-conteudo"><h4>Elaborar procuração e contrato</h4><p>Mova o card para <strong>Elaboração Procuração / Contrato</strong>. Clique no card, vá em Documentos e gere a procuração e o contrato conforme o tipo de ação.</p></div></div>
      <div class="passo"><div class="passo-num">3</div><div class="passo-conteudo"><h4>Enviar link para assinatura</h4><p>Faça o download do documento, suba no <strong>ZapSign</strong> e envie o link para o cliente. Mova o card para <strong>Link Enviado</strong>.</p></div></div>
      <div class="passo"><div class="passo-num">4</div><div class="passo-conteudo"><h4>Confirmar assinatura do contrato</h4><p>Quando o cliente assinar, mova para <strong>Contrato Assinado</strong>. O sistema vai pedir o <strong>nome da pasta</strong> no formato <em>Nome do Cliente x Tipo de Ação</em>. Ao confirmar, a pasta é criada no Drive e o caso aparece no Kanban Operacional.</p><div class="alerta alerta-atencao"><span class="alerta-icone">⚠️</span><span>O nome da pasta no formato correto é essencial. Exemplo: <strong>Wendel Magno x Alimentos</strong></span></div></div></div>
      <div class="passo"><div class="passo-num">5</div><div class="passo-conteudo"><h4>Agendar onboarding e solicitar documentos</h4><p>Mova para <strong>Agendado + Docs Solicitados</strong>. Agende a reunião de onboarding com o cliente e solicite os documentos necessários.</p></div></div>
      <div class="passo"><div class="passo-num">6</div><div class="passo-conteudo"><h4>Cobrar documentos após a reunião</h4><p>Mova para <strong>Reunião / Cobrando Docs</strong>. O CX acompanha o recebimento da documentação.</p></div></div>
      <div class="passo"><div class="passo-num">7</div><div class="passo-conteudo"><h4>Pasta apta — tudo certo!</h4><p>Quando toda a documentação estiver recebida, mova para <strong>Pasta Apta</strong>. O Operacional será notificado. O card some do Pipeline quando o Operacional iniciar a execução.</p><div class="alerta alerta-ok"><span class="alerta-icone">✅</span><span>Após a Pasta Apta, o comercial não precisa mais fazer nada neste caso.</span></div></div></div>
    </div>
  </div>
  <div class="secao">
    <div class="secao-titulo">Regras importantes</div>
    <div class="regra"><div class="regra-emoji">🔄</div><div class="regra-texto"><h4>Documento Faltante — vem do Operacional</h4><p>Se o Operacional sinalizar que falta um documento, o card vai automaticamente para <strong>Documento Faltante</strong>. O CX precisa cobrar o cliente.</p></div></div>
    <div class="regra"><div class="regra-emoji">🚫</div><div class="regra-texto"><h4>Cancelado e Suspenso — somente Admin</h4><p>Apenas a Amanda pode mover um card para Cancelado ou Suspenso. Cancela nos dois Kanbans automaticamente.</p></div></div>
    <div class="regra"><div class="regra-emoji">👁️</div><div class="regra-texto"><h4>Visão Kanban vs. Tabela</h4><p>Alterne entre Kanban (cards) e Tabela (Excel) no topo. Na tabela, edite campos direto na célula.</p></div></div>
    <div class="regra"><div class="regra-emoji">📅</div><div class="regra-texto"><h4>Filtro por mês</h4><p>Use o filtro de mês no topo para ver só os clientes de um período específico.</p></div></div>
  </div>
  <div class="secao">
    <div class="secao-titulo">Quem pode fazer o quê</div>
    <table class="tabela-treino">
      <tr><th>Ação</th><th>Quem pode</th></tr>
      <tr><td>Criar novo lead manualmente</td><td><span class="badge badge-comercial">Comercial</span> <span class="badge badge-admin">Admin</span></td></tr>
      <tr><td>Mover cards colunas 1–4</td><td><span class="badge badge-comercial">Comercial</span> <span class="badge badge-admin">Admin</span></td></tr>
      <tr><td>Mover cards colunas 5–8</td><td><span class="badge badge-cx">CX</span> <span class="badge badge-admin">Admin</span></td></tr>
      <tr><td>Cancelar ou suspender</td><td><span class="badge badge-admin">Admin apenas</span></td></tr>
      <tr><td>Ver dados financeiros</td><td><span class="badge badge-comercial">Comercial</span> <span class="badge badge-admin">Admin</span></td></tr>
    </table>
  </div>
</div>

<!-- ═══ KANBAN OPERACIONAL ═══ -->
<div id="kanban-operacional" class="modulo">
  <div class="modulo-header"><div class="modulo-icon">⚙️</div><div class="modulo-titulo"><h2>Kanban Operacional</h2><p>Execução dos processos — do recebimento à distribuição</p></div></div>
  <div class="secao">
    <div class="secao-titulo">As 8 colunas do Operacional</div>
    <div class="kanban-grid">
      <div class="kanban-coluna"><div class="kanban-coluna-num">01</div><div class="kanban-coluna-nome">Contrato — Aguardando Docs</div><div class="kanban-coluna-resp">Sistema</div><div class="kanban-coluna-auto">Vem do Pipeline</div></div>
      <div class="kanban-coluna"><div class="kanban-coluna-num">02</div><div class="kanban-coluna-nome">Pasta Apta</div><div class="kanban-coluna-resp">Sistema</div><div class="kanban-coluna-auto">Vem do Pipeline</div></div>
      <div class="kanban-coluna"><div class="kanban-coluna-num">03</div><div class="kanban-coluna-nome">Em Execução</div><div class="kanban-coluna-resp">Operacional</div><div class="kanban-coluna-manual">Manual</div></div>
      <div class="kanban-coluna"><div class="kanban-coluna-num">04</div><div class="kanban-coluna-nome">Documento Faltante</div><div class="kanban-coluna-resp">Operacional</div><div class="kanban-coluna-auto">Notifica CX</div></div>
      <div class="kanban-coluna"><div class="kanban-coluna-num">05</div><div class="kanban-coluna-nome">Aguardando Distribuição</div><div class="kanban-coluna-resp">Operacional</div><div class="kanban-coluna-manual">Manual</div></div>
      <div class="kanban-coluna"><div class="kanban-coluna-num">06</div><div class="kanban-coluna-nome">Processo Distribuído</div><div class="kanban-coluna-resp">Operacional</div><div class="kanban-coluna-auto">Modal com nº</div></div>
      <div class="kanban-coluna"><div class="kanban-coluna-num">07</div><div class="kanban-coluna-nome">Parceria</div><div class="kanban-coluna-resp">Operacional</div><div class="kanban-coluna-manual">Manual</div></div>
      <div class="kanban-coluna"><div class="kanban-coluna-num">08</div><div class="kanban-coluna-nome">Cancelado</div><div class="kanban-coluna-resp">Admin</div><div class="kanban-coluna-manual">Somente Admin</div></div>
    </div>
  </div>
  <div class="secao">
    <div class="secao-titulo">Fluxo passo a passo</div>
    <div class="passos">
      <div class="passo"><div class="passo-num">1</div><div class="passo-conteudo"><h4>Novo caso chega em Aguardando Docs</h4><p>Quando o Comercial move para <strong>Contrato Assinado</strong>, o caso aparece automaticamente aqui.</p></div></div>
      <div class="passo"><div class="passo-num">2</div><div class="passo-conteudo"><h4>Pasta Apta — pode começar!</h4><p>Quando o CX confirma que toda a documentação chegou, o caso move para <strong>Pasta Apta</strong>.</p></div></div>
      <div class="passo"><div class="passo-num">3</div><div class="passo-conteudo"><h4>Iniciar a execução</h4><p>Mova para <strong>Em Execução</strong>. O card some do Pipeline automaticamente.</p><div class="alerta alerta-ok"><span class="alerta-icone">✅</span><span>Quando mover para Em Execução, o card some do Pipeline. Não é bug.</span></div></div></div>
      <div class="passo"><div class="passo-num">4</div><div class="passo-conteudo"><h4>Documento Faltante</h4><p>Se faltar documento, mova para <strong>Documento Faltante</strong>. O CX é notificado automaticamente.</p><div class="alerta alerta-atencao"><span class="alerta-icone">⚠️</span><span>Preencha a descrição com cuidado — vai aparecer para o cliente.</span></div></div></div>
      <div class="passo"><div class="passo-num">5</div><div class="passo-conteudo"><h4>Aguardando Distribuição</h4><p>Quando a petição estiver pronta mas ainda não distribuída.</p></div></div>
      <div class="passo"><div class="passo-num">6</div><div class="passo-conteudo"><h4>Processo Distribuído</h4><p>Preencha: nº do processo, vara/juízo, tipo e data. Ou selecione <strong>Extrajudicial</strong> se for o caso.</p></div></div>
      <div class="passo"><div class="passo-num">7</div><div class="passo-conteudo"><h4>Parceria</h4><p>Para casos com advogados parceiros externos. Selecione o parceiro da lista.</p></div></div>
    </div>
  </div>
  <div class="secao">
    <div class="secao-titulo">Alertas automáticos</div>
    <div class="regra"><div class="regra-emoji">🔔</div><div class="regra-texto"><h4>Pasta Apta há mais de 5 dias</h4><p>Alerta automático se não mover para Em Execução.</p></div></div>
    <div class="regra"><div class="regra-emoji">🔔</div><div class="regra-texto"><h4>Documento Faltante há mais de 3 dias</h4><p>Alerta automático para o CX responsável.</p></div></div>
    <div class="regra"><div class="regra-emoji">🔔</div><div class="regra-texto"><h4>Processo sem número há mais de 2 dias</h4><p>Alerta se distribuído sem número cadastrado.</p></div></div>
  </div>
</div>

<!-- ═══ DOCUMENTOS ═══ -->
<div id="documentos" class="modulo">
  <div class="modulo-header"><div class="modulo-icon">📄</div><div class="modulo-titulo"><h2>Geração de Documentos</h2><p>Como gerar procuração, contrato e outros documentos pelo portal</p></div></div>
  <div class="secao">
    <div class="secao-titulo">Passo a passo</div>
    <div class="passos">
      <div class="passo"><div class="passo-num">1</div><div class="passo-conteudo"><h4>Abra o card do cliente</h4><p>No Kanban Comercial, clique no card do cliente.</p></div></div>
      <div class="passo"><div class="passo-num">2</div><div class="passo-conteudo"><h4>Clique em Elaborar Documento</h4><p>Dentro do card, clique no botão <strong>📜 Elaborar Documento</strong>.</p></div></div>
      <div class="passo"><div class="passo-num">3</div><div class="passo-conteudo"><h4>Escolha o modelo</h4><p>Selecione o tipo de documento e o tipo de ação do cliente.</p></div></div>
      <div class="passo"><div class="passo-num">4</div><div class="passo-conteudo"><h4>Revise e gere</h4><p>O sistema preenche automaticamente com os dados do cliente. Revise e clique em <strong>Gerar Documento</strong>.</p></div></div>
      <div class="passo"><div class="passo-num">5</div><div class="passo-conteudo"><h4>Enviar para assinatura</h4><p>Download, suba no <strong>ZapSign</strong> e envie o link pelo WhatsApp.</p></div></div>
    </div>
  </div>
  <div class="secao">
    <div class="secao-titulo">Documentos disponíveis</div>
    <table class="tabela-treino">
      <tr><th>Documento</th><th>Quem usa</th><th>Observação</th></tr>
      <tr><td>Procuração — Alimentos / Execução / Revisional</td><td><span class="badge badge-comercial">Comercial</span></td><td>No nome da criança</td></tr>
      <tr><td>Procuração — Guarda / Convivência / Divórcio</td><td><span class="badge badge-comercial">Comercial</span></td><td>No nome do pai ou mãe</td></tr>
      <tr><td>Contrato de Honorários</td><td><span class="badge badge-comercial">Comercial</span></td><td>Fixo ou risco</td></tr>
      <tr><td>Declaração de Residência</td><td><span class="badge badge-cx">CX</span></td><td>Enviar pelo WhatsApp</td></tr>
      <tr><td>Petição de Juntada</td><td><span class="badge badge-operacional">Operacional</span></td><td>Vincula ao nº do processo</td></tr>
    </table>
    <div class="dica"><div class="dica-titulo">💡 Dica</div><p>Para alterar o conteúdo do texto, baixe em <strong>Word (.docx)</strong> e edite antes de subir no ZapSign.</p></div>
  </div>
</div>

<!-- ═══ NOTIFICAÇÕES ═══ -->
<div id="notificacoes" class="modulo">
  <div class="modulo-header"><div class="modulo-icon">💬</div><div class="modulo-titulo"><h2>Notificações e Mensagens</h2><p>Como enviar mensagens para clientes pelo portal</p></div></div>
  <div class="secao">
    <div class="secao-titulo">Mensagens automáticas</div>
    <p style="font-size:13px;color:#555;margin-bottom:16px;">Enviadas automaticamente quando o caso muda de coluna:</p>
    <table class="tabela-treino">
      <tr><th>Quando</th><th>Mensagem</th><th>Canal</th></tr>
      <tr><td>Contrato assinado</td><td>Boas-vindas ao escritório</td><td>WhatsApp + E-mail</td></tr>
      <tr><td>Pasta Apta</td><td>Confirmação de recebimento dos documentos</td><td>WhatsApp + E-mail</td></tr>
      <tr><td>Processo Distribuído</td><td>Número do processo</td><td>WhatsApp + E-mail</td></tr>
      <tr><td>Documento Faltante</td><td>Pedido do documento</td><td>WhatsApp + E-mail</td></tr>
    </table>
  </div>
  <div class="secao">
    <div class="secao-titulo">Enviar mensagem manual</div>
    <div class="passos">
      <div class="passo"><div class="passo-num">1</div><div class="passo-conteudo"><h4>Acesse Mensagens no menu lateral</h4><p>Clique em <strong>Mensagens</strong> e escolha o modelo de mensagem.</p></div></div>
      <div class="passo"><div class="passo-num">2</div><div class="passo-conteudo"><h4>Clique em WhatsApp</h4><p>Informe o número do cliente. Vai abrir o WhatsApp Web com a mensagem pronta.</p></div></div>
      <div class="passo"><div class="passo-num">3</div><div class="passo-conteudo"><h4>Marque como enviado</h4><p>Volte ao portal e marque como <strong>Enviado</strong> na aba de notificações.</p><div class="alerta alerta-atencao"><span class="alerta-icone">⚠️</span><span>O WhatsApp Web precisa estar conectado no computador.</span></div></div></div>
    </div>
  </div>
  <div class="secao">
    <div class="secao-titulo">Aniversários</div>
    <div class="regra"><div class="regra-emoji">🎂</div><div class="regra-texto"><h4>Lista de aniversariantes</h4><p>Em <strong>Datas Especiais</strong>, veja aniversariantes do dia. Envie mensagem pelo WhatsApp e marque como enviado.</p></div></div>
  </div>
</div>

<!-- ═══ PORTAL DE LINKS ═══ -->
<div id="portal-links" class="modulo">
  <div class="modulo-header"><div class="modulo-icon">🔗</div><div class="modulo-titulo"><h2>Portal de Links</h2><p>Todos os links úteis do escritório em um só lugar</p></div></div>
  <div class="secao">
    <div class="secao-titulo">O que você encontra aqui</div>
    <div class="regra"><div class="regra-emoji">📋</div><div class="regra-texto"><h4>Formulários de cadastro</h4><p>Links para enviar aos clientes.</p></div></div>
    <div class="regra"><div class="regra-emoji">📄</div><div class="regra-texto"><h4>Modelos de mensagem</h4><p>Textos prontos para enviar ao cliente.</p></div></div>
    <div class="regra"><div class="regra-emoji">🔗</div><div class="regra-texto"><h4>Links dos sistemas</h4><p>PJe, TJRJ, JusBrasil, Receita Federal, etc.</p></div></div>
    <div class="dica"><div class="dica-titulo">💡 Em construção</div><p>Se precisar de algum link que não está lá, avise a Amanda para adicionar.</p></div>
  </div>
</div>

<!-- ═══ REGRAS DE PROCURAÇÃO ═══ -->
<div id="procuracao" class="modulo">
  <div class="modulo-header"><div class="modulo-icon">✍️</div><div class="modulo-titulo"><h2>Regras de Procuração</h2><p>Quem deve outorgar poderes em cada tipo de ação</p></div></div>
  <div class="secao">
    <div class="secao-titulo">Regra geral</div>
    <div class="alerta alerta-atencao"><span class="alerta-icone">⚠️</span><div><strong>Atenção:</strong> correção identificada no treinamento. Algumas procurações estavam com o nome errado. Leia com atenção.</div></div>
  </div>
  <div class="secao">
    <div class="secao-titulo">Procuração no nome da criança (filho/a)</div>
    <p style="font-size:13px;color:#555;margin-bottom:14px;">Nestas ações, quem está pedindo é a criança — ela outorga poderes representada pelo pai ou mãe.</p>
    <table class="tabela-treino">
      <tr><th>Tipo de Ação</th><th>Quem outorga</th><th>Representado por</th></tr>
      <tr><td>Pensão Alimentícia</td><td>A criança</td><td>Pai ou mãe responsável</td></tr>
      <tr><td>Revisional de Alimentos</td><td>A criança</td><td>Pai ou mãe responsável</td></tr>
      <tr><td>Execução de Alimentos</td><td>A criança</td><td>Pai ou mãe responsável</td></tr>
    </table>
    <div class="alerta alerta-ok" style="margin-top:14px;"><span class="alerta-icone">✅</span><span><strong>Exemplo:</strong> "HENRIQUE GABRIEL, representado por sua genitora THAIS CAROLINE..."</span></div>
  </div>
  <div class="secao">
    <div class="secao-titulo">Procuração no nome do pai ou mãe</div>
    <p style="font-size:13px;color:#555;margin-bottom:14px;">Nestas ações, quem pede é o adulto contratante.</p>
    <table class="tabela-treino">
      <tr><th>Tipo de Ação</th><th>Quem outorga</th></tr>
      <tr><td>Guarda Unilateral ou Compartilhada</td><td>O pai ou mãe contratante</td></tr>
      <tr><td>Regulamentação de Convivência</td><td>O pai ou mãe contratante</td></tr>
      <tr><td>Divórcio</td><td>O cônjuge contratante</td></tr>
      <tr><td>Investigação de Paternidade</td><td>O pai ou mãe contratante</td></tr>
      <tr><td>Inventário</td><td>O herdeiro contratante</td></tr>
    </table>
    <div class="alerta alerta-erro" style="margin-top:14px;"><span class="alerta-icone">❌</span><span><strong>Erro comum:</strong> gerar procuração de guarda com o nome das crianças. Deve ser o nome do adulto.</span></div>
  </div>
  <div class="secao">
    <div class="secao-titulo">Quando há alimentos E convivência juntos</div>
    <div class="regra"><div class="regra-emoji">📋</div><div class="regra-texto"><h4>Gere duas procurações separadas</h4><p>Uma no nome da criança (alimentos) e outra no nome do pai/mãe (convivência).</p></div></div>
    <div class="dica"><div class="dica-titulo">💡 Dica</div><p>No portal, escolha o modelo correto para cada ação. O sistema puxa o nome certo.</p></div>
  </div>
</div>

</div>

<div class="tr-footer">
  <strong>Ferreira & Sá Advocacia Especializada</strong> — Material interno de treinamento<br>
  Dúvidas? Fale com a Amanda. Atualizado em Abril/2026.
</div>

<script>
function mostrar(id, btn) {
  document.querySelectorAll('.modulo').forEach(function(m) { m.classList.remove('ativo'); });
  document.querySelectorAll('.nav-btn').forEach(function(b) { b.classList.remove('ativo'); });
  document.getElementById(id).classList.add('ativo');
  btn.classList.add('ativo');
  window.scrollTo({ top: document.querySelector('.nav-modulos').offsetTop - 60, behavior: 'smooth' });
}
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
