<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Levantamento de Despesas Mensais — Ferreira &amp; Sá Advocacia</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
/* ========== VARIABLES & RESET ========== */
:root{
  --g1:#052228;--g2:#173d46;--cobre:#6a3c2c;--nude:#d7ab90;
  --bg:#f5f0eb;--card:#fff;--text:#1e1e1e;--muted:#6b6b6b;
  --border:#e0d6cd;--radius:18px;--shadow:0 4px 24px rgba(0,0,0,.07);
  --ok:#27ae60;--err:#c0392b;--warn:#e67e22;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{scroll-behavior:smooth}
body{font-family:'Open Sans',sans-serif;background:var(--bg);color:var(--text);line-height:1.6;min-height:100vh}
a{color:var(--cobre);text-decoration:none}
a:hover{text-decoration:underline}

/* ========== HEADER ========== */
.header{background:linear-gradient(135deg,var(--g1),var(--g2));color:#fff;text-align:center;padding:32px 16px 24px}
.header h1{font-size:1.35rem;font-weight:700;margin-bottom:4px}
.header p{font-size:.85rem;opacity:.85;max-width:480px;margin:0 auto}

/* ========== PROGRESS ========== */
.progressWrap{background:#fff;border-bottom:1px solid var(--border);padding:10px 12px;position:sticky;top:0;z-index:100;overflow-x:auto;-webkit-overflow-scrolling:touch}
.progressBar{display:flex;align-items:center;gap:6px;flex-wrap:nowrap;min-width:max-content;padding-bottom:4px}
.pill{padding:6px 12px;border-radius:999px;border:1.5px solid var(--border);display:flex;align-items:center;gap:5px;font-size:.75rem;font-weight:500;color:var(--muted);cursor:pointer;transition:.2s;flex-shrink:0;background:#fff;white-space:nowrap}
.pill:hover{border-color:rgba(5,34,40,.3);transform:translateY(-1px)}
.pill.active{border-color:var(--cobre);color:var(--cobre);background:rgba(106,60,44,.08);font-weight:700}
.pill.done{border-color:var(--ok);color:var(--ok);background:rgba(39,174,96,.08);font-weight:600}
.pill .pillIcon{font-size:.9rem;line-height:1}
.pill .pillCheck{display:none;font-size:.7rem;margin-left:2px}
.pill.done .pillCheck{display:inline}

/* ========== CONTAINER ========== */
.container{max-width:680px;margin:0 auto;padding:16px}

/* ========== CARD ========== */
.card{background:var(--card);border-radius:var(--radius);box-shadow:var(--shadow);padding:28px 24px;margin-bottom:20px;display:none}
.card.visible{display:block;animation:fadeUp .35s ease}
@keyframes fadeUp{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:none}}
.card h2{font-size:1.1rem;color:var(--g1);margin-bottom:4px}
.card .stepSub{font-size:.8rem;color:var(--muted);margin-bottom:18px}

/* ========== FORM ELEMENTS ========== */
.row{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px}
.col-12{grid-column:1/-1}
.col-6{}
label{display:block;font-size:.82rem;font-weight:600;margin-bottom:4px;color:var(--g2)}
.requiredMark{color:var(--err)}
input[type="text"],input[type="tel"],input[type="email"],input[type="number"],select,textarea{
  width:100%;padding:10px 12px;border:1.5px solid var(--border);border-radius:10px;font-family:inherit;font-size:.9rem;
  background:#fafafa;transition:border .2s;outline:none
}
input:focus,select:focus,textarea:focus{border-color:var(--cobre)}
textarea{resize:vertical;min-height:70px}
.rowNote{font-size:.72rem;color:var(--muted);margin-top:2px}

/* ========== BUTTONS ========== */
.btnRow{display:flex;gap:10px;margin-top:22px;justify-content:flex-end}
.btn{padding:10px 28px;border:none;border-radius:10px;font-family:inherit;font-size:.9rem;font-weight:600;cursor:pointer;transition:.2s}
.btnPrimary{background:var(--cobre);color:#fff}
.btnPrimary:hover{background:#5a3022}
.btnSecondary{background:transparent;color:var(--cobre);border:1.5px solid var(--cobre)}
.btnSecondary:hover{background:rgba(106,60,44,.08)}
.btnSuccess{background:var(--ok);color:#fff}
.btnSuccess:hover{background:#219150}

/* ========== CHART ========== */
.chartWrap{background:var(--card);border-radius:var(--radius);box-shadow:var(--shadow);padding:20px;margin-bottom:20px}
.chartWrap h3{font-size:.95rem;color:var(--g1);margin-bottom:10px;text-align:center}
.chartWrap canvas{max-height:340px}

/* ========== REVIEW TABLE ========== */
.reviewTable{width:100%;border-collapse:collapse;font-size:.82rem;margin-bottom:14px}
.reviewTable th{text-align:left;padding:8px 6px;background:var(--g1);color:#fff;font-weight:600}
.reviewTable td{padding:7px 6px;border-bottom:1px solid var(--border)}
.reviewTable tr:nth-child(even) td{background:#f9f6f3}
.totalRow td{font-weight:700;background:rgba(106,60,44,.08)!important;border-top:2px solid var(--cobre)}
/* Ícone "compartilhado/rateado" — tooltip ao passar o mouse */
.shareTip{display:inline-flex;align-items:center;justify-content:center;width:16px;height:16px;border-radius:50%;background:#B87333;color:#fff;font-size:.7rem;font-weight:700;margin-left:6px;cursor:help;vertical-align:middle;line-height:1;}
.shareTip:hover{background:#8b5a26;}

/* ========== TOAST ========== */
.toast{position:fixed;bottom:24px;left:50%;transform:translateX(-50%);padding:12px 24px;border-radius:10px;font-size:.85rem;font-weight:600;color:#fff;z-index:999;opacity:0;transition:.3s;pointer-events:none}
.toast.show{opacity:1}
.toast.err{background:var(--err)}
.toast.ok{background:var(--ok)}
.toast.warn{background:var(--warn)}

/* ========== SUCCESS SCREEN ========== */
.successScreen{text-align:center;padding:40px 20px}
.successScreen .ico{font-size:64px;margin-bottom:12px}
.successScreen h2{color:var(--ok);margin-bottom:8px}
.successScreen .proto{font-size:1.3rem;font-weight:700;color:var(--cobre);margin:12px 0}
.successScreen p{font-size:.88rem;color:var(--muted);margin-bottom:6px}
.actionBtns{display:flex;gap:10px;justify-content:center;flex-wrap:wrap;margin-top:18px}
.actionBtns .btn{font-size:.82rem;padding:8px 18px}
.igLink{display:inline-flex;align-items:center;gap:6px;margin-top:16px;font-weight:600;color:var(--cobre);font-size:.88rem}

/* ========== RESPONSIVE ========== */
@media(max-width:600px){
  .row{grid-template-columns:1fr}
  .header h1{font-size:1.15rem}
  .card{padding:20px 16px}
  .pill{width:24px;height:24px;font-size:10px}
  .progLine{width:8px}
  .btnRow{flex-direction:column}
  .btn{width:100%;text-align:center}
}
/* divider helper */
.divider{border:none;border-top:1px solid var(--border);margin:18px 0}
/* auto-divide note */
.divideNote{background:rgba(39,174,96,.08);border-left:3px solid var(--ok);padding:8px 12px;border-radius:8px;font-size:.78rem;color:var(--g2);margin-bottom:14px}
/* sem filhos note */
.semFilhosNote{background:rgba(230,126,34,.1);border-left:3px solid var(--warn);padding:8px 12px;border-radius:8px;font-size:.8rem;color:var(--warn);margin-top:6px;display:none}
/* hide utility */
.hidden{display:none!important}

/* ===== PERSISTENT SUMMARY ===== */
.summaryWrap{background:var(--card);border-radius:var(--radius);box-shadow:var(--shadow);padding:16px 20px;margin-bottom:16px}
.summaryTop{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;margin-bottom:12px}
.summaryInfo .badge{display:inline-block;padding:5px 10px;border-radius:999px;background:rgba(215,171,144,.2);border:1px solid rgba(106,60,44,.2);color:var(--cobre);font-size:.75rem;font-weight:600}
.summaryInfo .helpText{font-size:.78rem;color:var(--muted);margin-top:6px;line-height:1.5}
.totalBox{border-radius:14px;padding:12px 16px;background:rgba(5,34,40,.06);border:1px solid rgba(5,34,40,.12);text-align:right;min-width:200px}
.totalBox strong{display:block;font-size:.78rem;color:var(--muted)}
.totalBox .totalVal{font-size:1.4rem;font-weight:800;color:var(--g1)}
.kpiGrid{display:grid;grid-template-columns:repeat(5,1fr);gap:8px;margin-bottom:14px}
@media(max-width:900px){.kpiGrid{grid-template-columns:repeat(3,1fr)}}
@media(max-width:600px){.kpiGrid{grid-template-columns:repeat(2,1fr)}}
.kpiCard{background:rgba(5,34,40,.03);border:1px solid rgba(5,34,40,.08);border-radius:12px;padding:10px 12px;display:flex;align-items:center;gap:8px}
.kpiIcon{font-size:1.2rem;flex-shrink:0}
.kpiLabel{font-size:.7rem;color:var(--muted);line-height:1.3}
.kpiVal{font-size:.95rem;font-weight:700;color:var(--g1)}
.summaryChart{margin-top:10px}
.summaryChart h3{font-size:.85rem;color:var(--g1);margin-bottom:8px;display:flex;align-items:center;gap:6px}
.chartActions{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px}
.chartActions button{font-size:.75rem;padding:6px 12px}
</style>
</head>
<body>

<!-- ====== HEADER ====== -->
<div class="header">
  <h1>Levantamento de Despesas Mensais</h1>
  <p>Preencha com calma. Ao final, os dados serão enviados ao escritório Ferreira &amp; Sá Advocacia.</p>
</div>

<!-- ====== ORIENTAÇÕES (banner colapsável) ====== -->
<div style="background:linear-gradient(135deg,rgba(184,115,51,.08),rgba(184,115,51,.04));border:1px solid rgba(184,115,51,.3);border-left:5px solid #B87333;border-radius:10px;padding:14px 18px;margin-bottom:18px;">
  <div onclick="document.getElementById('orientacoesBody').style.display=document.getElementById('orientacoesBody').style.display==='none'?'block':'none';this.querySelector('.orient-chevron').textContent=document.getElementById('orientacoesBody').style.display==='none'?'▸':'▾';" style="cursor:pointer;display:flex;justify-content:space-between;align-items:center;font-weight:700;color:#6a3c2c;font-size:.95rem;">
    <span>📌 Antes de começar — leia as orientações importantes</span>
    <span class="orient-chevron" style="font-size:.85rem;">▾</span>
  </div>
  <div id="orientacoesBody" style="margin-top:12px;font-size:.88rem;color:#4a2a1f;line-height:1.55;">
    <div style="margin-bottom:14px;">
      <strong>1. Tem mais de um filho?</strong> Preencha <u>uma planilha por filho</u>. Em cada uma, cadastre apenas os gastos relacionados àquele filho específico.
    </div>
    <div style="margin-bottom:14px;">
      <strong>2. Como ratear gastos compartilhados</strong> (plano de celular, água, luz, internet, IPTU, condomínio, supermercado, etc.):<br>
      Divida o valor pelo número de pessoas que usam. Ex.: plano familiar de R$ 200 com 4 linhas (você + 3 filhos) → coloque <strong>R$ 50</strong> em cada planilha de filho.<br>
      <em>Atenção:</em> em <strong>Moradia</strong>, basta preencher o valor real e informar o número de moradores — o sistema divide automaticamente. Demais categorias você divide manualmente.
    </div>
    <div style="margin-bottom:14px;">
      <strong>3. Filho que não mora com você (só visita)</strong>:<br>
      Preencha apenas os gastos que efetivamente acontecem com ele. Ex.: alimentação dos dias de visita (4 visitas × R$ 50 = R$ 200/mês), presentes, vestuário que você compra. <strong>NÃO</strong> preencha moradia, escola ou plano de saúde se ele não usa esses recursos com você. Use o campo <em>"Convivência"</em> abaixo pra indicar a frequência.
    </div>
    <div style="margin-bottom:14px;">
      <strong>4. Sua renda mensal</strong>:<br>
      Coloque sua renda <u>TOTAL real</u> (ex.: R$ 6.000) em <u>todas</u> as planilhas. <strong>Não divida</strong> pelo número de filhos. O campo é a sua receita verdadeira — a advogada precisa dessa informação em cada análise.
    </div>
    <div>
      <strong>5. Pensão recebida (se for o caso)</strong>: marque "Sim" no campo "Recebe pensão" e informe o valor. <u>Não some</u> a pensão à sua renda — são valores separados.
    </div>
  </div>
</div>

<!-- ====== PROGRESS BAR ====== -->
<div class="progressWrap">
  <div class="progressBar" id="progressBar"></div>
</div>

<!-- ====== MAIN CONTAINER ====== -->
<div class="container" id="formArea">

<!-- ===== PERSISTENT SUMMARY (visible on all steps) ===== -->
<div class="summaryWrap" id="summaryWrap">
  <div class="summaryTop">
    <div class="summaryInfo">
      <span class="badge">Resumo das despesas</span>
      <p class="helpText">Os valores são atualizados conforme você preenche. Em <strong>Moradia</strong>, o sistema divide automaticamente pelo número de moradores.</p>
    </div>
    <div class="totalBox">
      <strong>Total mensal estimado</strong>
      <div class="totalVal" id="totalGeral">R$ 0,00</div>
    </div>
  </div>

  <div class="kpiGrid">
    <div class="kpiCard"><span class="kpiIcon">🏠</span><div><div class="kpiLabel">Moradia (rateada)</div><div class="kpiVal" id="kpi_moradia">R$ 0,00</div></div></div>
    <div class="kpiCard"><span class="kpiIcon">🍽️</span><div><div class="kpiLabel">Alimentação</div><div class="kpiVal" id="kpi_alim">R$ 0,00</div></div></div>
    <div class="kpiCard"><span class="kpiIcon">❤️</span><div><div class="kpiLabel">Saúde</div><div class="kpiVal" id="kpi_saude">R$ 0,00</div></div></div>
    <div class="kpiCard"><span class="kpiIcon">📚</span><div><div class="kpiLabel">Educação</div><div class="kpiVal" id="kpi_edu">R$ 0,00</div></div></div>
    <div class="kpiCard"><span class="kpiIcon">🚗</span><div><div class="kpiLabel">Transporte</div><div class="kpiVal" id="kpi_transp">R$ 0,00</div></div></div>
    <div class="kpiCard"><span class="kpiIcon">👕</span><div><div class="kpiLabel">Vestuário</div><div class="kpiVal" id="kpi_vest">R$ 0,00</div></div></div>
    <div class="kpiCard"><span class="kpiIcon">🎮</span><div><div class="kpiLabel">Lazer</div><div class="kpiVal" id="kpi_lazer">R$ 0,00</div></div></div>
    <div class="kpiCard"><span class="kpiIcon">💻</span><div><div class="kpiLabel">Tecnologia</div><div class="kpiVal" id="kpi_tech">R$ 0,00</div></div></div>
    <div class="kpiCard"><span class="kpiIcon">🧸</span><div><div class="kpiLabel">Cuidados</div><div class="kpiVal" id="kpi_care">R$ 0,00</div></div></div>
    <div class="kpiCard"><span class="kpiIcon">📦</span><div><div class="kpiLabel">Outros</div><div class="kpiVal" id="kpi_outros">R$ 0,00</div></div></div>
  </div>

  <div class="summaryChart">
    <h3>📊 Gráfico das categorias</h3>
    <canvas id="summaryChartCanvas" style="max-height:260px;"></canvas>
    <div class="chartActions">
      <button class="btn btnSecondary" onclick="downloadChart()">Baixar gráfico (PNG)</button>
      <button class="btn btnSecondary" onclick="downloadCSV()">Baixar dados (CSV)</button>
    </div>
  </div>
</div>

<!-- ===== STEP 0 — Identificação ===== -->
<div class="card" data-step="0">
  <h2>Identificação</h2>
  <p class="stepSub">Dados básicos sobre você e sua família</p>

  <div class="row">
    <div class="col-6">
      <label>Seu nome completo <span class="requiredMark">*</span></label>
      <input type="text" name="nome_completo" class="requiredField" placeholder="Ex.: Maria Fernanda da Silva" data-store>
    </div>
    <div class="col-6">
      <label>CPF</label>
      <input type="text" name="cpf" placeholder="000.000.000-00" data-mask="cpf" data-store>
    </div>
  </div>

  <div class="row">
    <div class="col-6">
      <label>WhatsApp <span class="requiredMark">*</span></label>
      <input type="tel" name="whatsapp" class="requiredField" placeholder="(00) 00000-0000" data-mask="phone" data-store>
    </div>
    <div class="col-6">
      <label>Nome do filho(a) a que este formulário se refere <span class="requiredMark">*</span></label>
      <input type="text" name="nome_filho_referente" id="nomeFilhoInput" class="requiredField" placeholder="Ex.: Ana Clara" data-store>
      <div style="margin-top:8px;">
        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:13px;color:var(--text);">
          <input type="checkbox" id="semFilhosCheck" style="width:auto;"> Não tenho filhos
        </label>
      </div>
      <div class="semFilhosNote" id="semFilhosNote">Os gastos serão calculados como despesas pessoais</div>
      <div class="rowNote">Se houver mais de um filho e os gastos forem diferentes, preencha um formulário para cada.</div>
    </div>
  </div>

  <div class="row">
    <div class="col-6">
      <label>O filho(a) possui TEA ou necessidades especiais?</label>
      <select name="tea" data-store>
        <option value="">Selecione</option>
        <option value="sim">Sim</option>
        <option value="nao">Não</option>
      </select>
    </div>
    <div class="col-6">
      <label>Se sim, qual tratamento?</label>
      <input type="text" name="tratamento_tea" placeholder="Ex.: ABA, Fono, TO..." data-store>
    </div>
  </div>

  <div class="row">
    <div class="col-6">
      <label>Quantidade de filhos</label>
      <input type="number" name="qtd_filhos" min="0" max="20" placeholder="Ex.: 2" data-store>
    </div>
    <div class="col-6">
      <label>Os gastos são iguais para todos os filhos?</label>
      <select name="gastos_iguais" data-store>
        <option value="">Selecione</option>
        <option value="sim">Sim</option>
        <option value="nao">Não</option>
      </select>
    </div>
  </div>

  <div class="row">
    <div class="col-12">
      <label>Convivência deste filho com você</label>
      <select name="convivencia" data-store>
        <option value="">Selecione</option>
        <option value="mora_comigo">Mora comigo (convivência integral)</option>
        <option value="visita_semanal">Visita semanal (fim de semana / dias alternados)</option>
        <option value="visita_quinzenal">Visita quinzenal</option>
        <option value="visita_mensal">Visita mensal</option>
        <option value="visita_esporadica">Visita esporádica / nas férias</option>
        <option value="nao_convive">Não convive com este filho</option>
      </select>
      <small style="color:#6b7280;font-size:.72rem;">Importante: se o filho só visita, preencha apenas os gastos que efetivamente acontecem com ele (alimentação dos dias de visita, presentes, etc.). Não preencha moradia, escola ou plano de saúde se ele não usa esses recursos com você.</small>
    </div>
  </div>

  <div class="row">
    <div class="col-6">
      <label>Fonte de renda <span class="requiredMark">*</span></label>
      <select name="fonte_renda" class="requiredField" data-store>
        <option value="">Selecione</option>
        <option value="empregado_clt">Empregado(a) CLT</option>
        <option value="autonomo">Autônomo(a)</option>
        <option value="empresario">Empresário(a)</option>
        <option value="servidor_publico">Servidor(a) Público(a)</option>
        <option value="aposentado">Aposentado(a)</option>
        <option value="desempregado">Desempregado(a)</option>
        <option value="pensionista">Pensionista</option>
        <option value="outro">Outro</option>
      </select>
    </div>
    <div class="col-6">
      <label>Renda mensal aproximada</label>
      <input type="text" name="renda_mensal" class="money" placeholder="R$ 0,00" data-cents="0" data-store>
    </div>
  </div>

  <div class="row">
    <div class="col-6">
      <label>Quem paga a maior parte das despesas?</label>
      <select name="quem_paga" data-store>
        <option value="">Selecione</option>
        <option value="eu">Eu</option>
        <option value="outro_genitor">O outro genitor</option>
        <option value="dividimos">Dividimos igualmente</option>
        <option value="familia">Família ajuda</option>
        <option value="outro">Outro</option>
      </select>
    </div>
    <div class="col-6">
      <label>O outro genitor paga pensão atualmente?</label>
      <select name="recebe_pensao" data-store>
        <option value="">Selecione</option>
        <option value="sim">Sim</option>
        <option value="nao">Não</option>
        <option value="irregular">Sim, mas de forma irregular</option>
      </select>
    </div>
  </div>

  <div class="row">
    <div class="col-12">
      <label>Observações sobre a identificação</label>
      <textarea name="obs_identificacao" placeholder="Alguma informação relevante..." data-store></textarea>
    </div>
  </div>

  <div class="btnRow" style="justify-content:space-between">
    <button class="btn" onclick="apagarDados()" style="background:#dc2626;color:#fff;">Apagar dados preenchidos</button>
    <button class="btn btnPrimary" onclick="goStep(1)">Próximo &rarr;</button>
  </div>
</div>

<!-- ===== STEP 1 — Moradia ===== -->
<div class="card" data-step="1">
  <h2>Moradia</h2>
  <p class="stepSub">Despesas fixas com habitação</p>
  <div class="divideNote" id="divideNote1">
    <strong>Divisão automática:</strong> Informe quantas pessoas moram na residência para rateio proporcional.
    <div style="margin-top:6px">
      <label style="font-size:.8rem;font-weight:600;">Moradores na residência:</label>
      <input type="number" name="moradores" id="moradores" min="1" max="20" value="1" style="width:70px;padding:4px 8px;border-radius:6px;border:1.5px solid var(--border);font-size:.85rem" data-store>
    </div>
  </div>
  <div class="row">
    <div class="col-6"><label>Aluguel <span class="shareTip" title="Despesa de moradia: o sistema divide automaticamente pelo número de moradores informado acima.">i</span></label><input type="text" name="moradia_aluguel" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
    <div class="col-6"><label>Condomínio <span class="shareTip" title="Despesa de moradia: o sistema divide automaticamente pelo número de moradores informado acima.">i</span></label><input type="text" name="moradia_condominio" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
  </div>
  <div class="row">
    <div class="col-6"><label>IPTU (mensal) <span class="shareTip" title="Despesa de moradia: o sistema divide automaticamente pelo número de moradores.">i</span></label><input type="text" name="moradia_iptu" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
    <div class="col-6"><label>Água <span class="shareTip" title="Despesa de moradia: o sistema divide automaticamente pelo número de moradores.">i</span></label><input type="text" name="moradia_agua" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
  </div>
  <div class="row">
    <div class="col-6"><label>Luz <span class="shareTip" title="Despesa de moradia: o sistema divide automaticamente pelo número de moradores.">i</span></label><input type="text" name="moradia_luz" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
    <div class="col-6"><label>Gás <span class="shareTip" title="Despesa de moradia: o sistema divide automaticamente pelo número de moradores.">i</span></label><input type="text" name="moradia_gas" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
  </div>
  <div class="row">
    <div class="col-6"><label>Internet <span class="shareTip" title="Despesa de moradia: o sistema divide automaticamente pelo número de moradores.">i</span></label><input type="text" name="moradia_internet" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
    <div class="col-6"><label>Telefone fixo <span class="shareTip" title="Despesa de moradia: o sistema divide automaticamente pelo número de moradores.">i</span></label><input type="text" name="moradia_telefone" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
  </div>
  <div class="row">
    <div class="col-6"><label>TV por assinatura <span class="shareTip" title="Despesa de moradia: o sistema divide automaticamente pelo número de moradores.">i</span></label><input type="text" name="moradia_tv" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
    <div class="col-6"><label>Manutenção / Reparos <span class="shareTip" title="Despesa de moradia: o sistema divide automaticamente pelo número de moradores.">i</span></label><input type="text" name="moradia_manutencao" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
  </div>
  <div class="btnRow">
    <button class="btn btnSecondary" onclick="goStep(0)">&larr; Voltar</button>
    <button class="btn btnPrimary" onclick="goStep(2)">Próximo &rarr;</button>
  </div>
</div>

<!-- ===== STEP 2 — Alimentação ===== -->
<div class="card" data-step="2">
  <h2>Alimentação</h2>
  <p class="stepSub">Gastos mensais com alimentação</p>
  <div class="row">
    <div class="col-6"><label>Supermercado <span class="shareTip" title="Geralmente compartilhado: divida o valor proporcionalmente. Ex.: gasta R$ 1.200/mês, família de 4 → R$ 300 por pessoa. Coloque o que cabe a este filho.">i</span></label><input type="text" name="alim_supermercado" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
    <div class="col-6"><label>Feira / Hortifruti</label><input type="text" name="alim_feira" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
  </div>
  <div class="row">
    <div class="col-6"><label>Açougue / Carnes</label><input type="text" name="alim_carnes" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
    <div class="col-6"><label>Padaria <span class="shareTip" title="Geralmente compartilhado: divida o valor proporcionalmente entre os filhos.">i</span></label><input type="text" name="alim_padaria" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
  </div>
  <div class="row">
    <div class="col-6"><label>Lanche escolar</label><input type="text" name="alim_lanche_escolar" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
    <div class="col-6"><label>Refeições fora</label><input type="text" name="alim_refeicoes_fora" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
  </div>
  <div class="row">
    <div class="col-6"><label>Leite / Fórmula</label><input type="text" name="alim_leite_formula" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
    <div class="col-6"><label>Água mineral</label><input type="text" name="alim_agua_mineral" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
  </div>
  <div class="row">
    <div class="col-6"><label>Alimentação especial / dieta</label><input type="text" name="alim_especial" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
    <div class="col-6"><label>Suplementos</label><input type="text" name="alim_suplementos" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
  </div>
  <div class="row">
    <div class="col-12"><label>Outros (alimentação)</label><input type="text" name="alim_outros" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
  </div>
  <div class="btnRow">
    <button class="btn btnSecondary" onclick="goStep(1)">&larr; Voltar</button>
    <button class="btn btnPrimary" onclick="goStep(3)">Próximo &rarr;</button>
  </div>
</div>

<!-- ===== STEP 3 — Saúde ===== -->
<div class="card" data-step="3">
  <h2>Saúde</h2>
  <p class="stepSub">Gastos mensais com saúde</p>
  <div class="row">
    <div class="col-6"><label>Plano de saúde</label><input type="text" name="saude_plano" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
    <div class="col-6"><label>Coparticipação</label><input type="text" name="saude_coparticipacao" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
  </div>
  <div class="row">
    <div class="col-6"><label>Medicamentos</label><input type="text" name="saude_medicamentos" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
    <div class="col-6"><label>Consultas médicas</label><input type="text" name="saude_consultas" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
  </div>
  <div class="row">
    <div class="col-6"><label>Exames</label><input type="text" name="saude_exames" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
    <div class="col-6"><label>Odontologia</label><input type="text" name="saude_odontologia" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
  </div>
  <div class="row">
    <div class="col-6"><label>Psicólogo</label><input type="text" name="saude_psicologo" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
    <div class="col-6"><label>Fonoaudiólogo</label><input type="text" name="saude_fono" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
  </div>
  <div class="row">
    <div class="col-6"><label>Terapias (ABA, TO, etc.)</label><input type="text" name="saude_terapias" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
    <div class="col-6"><label>Fisioterapia</label><input type="text" name="saude_fisio" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
  </div>
  <div class="row">
    <div class="col-6"><label>Óculos / Lentes</label><input type="text" name="saude_oculos" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
    <div class="col-6"><label>Vacinas particulares</label><input type="text" name="saude_vacinas" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
  </div>
  <div class="row">
    <div class="col-12"><label>Outros (saúde)</label><input type="text" name="saude_outros" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
  </div>
  <div class="btnRow">
    <button class="btn btnSecondary" onclick="goStep(2)">&larr; Voltar</button>
    <button class="btn btnPrimary" onclick="goStep(4)">Próximo &rarr;</button>
  </div>
</div>

<!-- ===== STEP 4 — Educação ===== -->
<div class="card" data-step="4">
  <h2>Educação</h2>
  <p class="stepSub">Gastos mensais com educação</p>
  <div class="row">
    <div class="col-6"><label>Mensalidade escolar / creche</label><input type="text" name="educ_mensalidade" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
    <div class="col-6"><label>Matrícula (mensal)</label><input type="text" name="educ_matricula" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
  </div>
  <div class="row">
    <div class="col-6"><label>Transporte escolar</label><input type="text" name="educ_transporte" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
    <div class="col-6"><label>Material escolar</label><input type="text" name="educ_material" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
  </div>
  <div class="row">
    <div class="col-6"><label>Uniforme</label><input type="text" name="educ_uniforme" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
    <div class="col-6"><label>Livros / Apostilas</label><input type="text" name="educ_livros" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
  </div>
  <div class="row">
    <div class="col-6"><label>Reforço escolar</label><input type="text" name="educ_reforco" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
    <div class="col-6"><label>Curso de idiomas</label><input type="text" name="educ_idiomas" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
  </div>
  <div class="row">
    <div class="col-6"><label>Passeios escolares</label><input type="text" name="educ_passeios" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
    <div class="col-6"><label>Outros (educação)</label><input type="text" name="educ_outros" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
  </div>
  <div class="btnRow">
    <button class="btn btnSecondary" onclick="goStep(3)">&larr; Voltar</button>
    <button class="btn btnPrimary" onclick="goStep(5)">Próximo &rarr;</button>
  </div>
</div>

<!-- ===== STEP 5 — Transporte ===== -->
<div class="card" data-step="5">
  <h2>Transporte</h2>
  <p class="stepSub">Gastos mensais com transporte</p>
  <div class="row">
    <div class="col-6"><label>Transporte público</label><input type="text" name="transp_publico" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
    <div class="col-6"><label>Uber / Táxi / 99</label><input type="text" name="transp_uber" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
  </div>
  <div class="row">
    <div class="col-6"><label>Combustível</label><input type="text" name="transp_combustivel" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
    <div class="col-6"><label>Manutenção do veículo</label><input type="text" name="transp_manutencao" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
  </div>
  <div class="row">
    <div class="col-6"><label>Seguro do veículo (mensal)</label><input type="text" name="transp_seguro" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
    <div class="col-6"><label>IPVA (mensal)</label><input type="text" name="transp_ipva" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
  </div>
  <div class="row">
    <div class="col-6"><label>Estacionamento</label><input type="text" name="transp_estacionamento" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
    <div class="col-6"><label>Outros (transporte)</label><input type="text" name="transp_outros" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
  </div>
  <div class="btnRow">
    <button class="btn btnSecondary" onclick="goStep(4)">&larr; Voltar</button>
    <button class="btn btnPrimary" onclick="goStep(6)">Próximo &rarr;</button>
  </div>
</div>

<!-- ===== STEP 6 — Vestuário e Higiene ===== -->
<div class="card" data-step="6">
  <h2>Vestuário e Higiene</h2>
  <p class="stepSub">Gastos mensais com roupas e higiene pessoal</p>
  <div class="row">
    <div class="col-6"><label>Roupas</label><input type="text" name="vest_roupas" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
    <div class="col-6"><label>Calçados</label><input type="text" name="vest_calcados" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
  </div>
  <div class="row">
    <div class="col-6"><label>Produtos de higiene</label><input type="text" name="vest_higiene" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
    <div class="col-6"><label>Fraldas / Lenços</label><input type="text" name="vest_fraldas" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
  </div>
  <div class="row">
    <div class="col-6"><label>Corte de cabelo</label><input type="text" name="vest_cabelo" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
    <div class="col-6"><label>Dermatológicos / Protetor solar</label><input type="text" name="vest_dermatologicos" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
  </div>
  <div class="row">
    <div class="col-12"><label>Outros (vestuário/higiene)</label><input type="text" name="vest_outros" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
  </div>
  <div class="btnRow">
    <button class="btn btnSecondary" onclick="goStep(5)">&larr; Voltar</button>
    <button class="btn btnPrimary" onclick="goStep(7)">Próximo &rarr;</button>
  </div>
</div>

<!-- ===== STEP 7 — Lazer ===== -->
<div class="card" data-step="7">
  <h2>Lazer</h2>
  <p class="stepSub">Gastos mensais com lazer e entretenimento</p>
  <div class="row">
    <div class="col-6"><label>Esportes / Academia</label><input type="text" name="lazer_esportes" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
    <div class="col-6"><label>Atividades extracurriculares</label><input type="text" name="lazer_atividades" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
  </div>
  <div class="row">
    <div class="col-6"><label>Passeios / Cinema / Parques</label><input type="text" name="lazer_passeios" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
    <div class="col-6"><label>Festas de aniversário</label><input type="text" name="lazer_aniversarios" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
  </div>
  <div class="row">
    <div class="col-6"><label>Brinquedos / Jogos</label><input type="text" name="lazer_brinquedos" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
    <div class="col-6"><label>Streaming (Netflix, Disney+...) <span class="shareTip" title="Plano familiar — divida o valor pelo número de pessoas que usam. Ex.: R$ 60 com 4 pessoas → R$ 15 por planilha.">i</span></label><input type="text" name="lazer_streaming" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
  </div>
  <div class="row">
    <div class="col-12"><label>Outros (lazer)</label><input type="text" name="lazer_outros" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
  </div>
  <div class="btnRow">
    <button class="btn btnSecondary" onclick="goStep(6)">&larr; Voltar</button>
    <button class="btn btnPrimary" onclick="goStep(8)">Próximo &rarr;</button>
  </div>
</div>

<!-- ===== STEP 8 — Tecnologia ===== -->
<div class="card" data-step="8">
  <h2>Tecnologia</h2>
  <p class="stepSub">Gastos mensais com tecnologia e comunicação</p>
  <div class="row">
    <div class="col-6"><label>Plano de celular <span class="shareTip" title="Plano familiar — divida o valor pelo número de linhas. Ex.: R$ 200 com 4 linhas → R$ 50 em cada planilha de filho. Se cada um tem plano individual, coloque o valor real do filho.">i</span></label><input type="text" name="tech_celular" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
    <div class="col-6"><label>Aparelho celular (parcela)</label><input type="text" name="tech_aparelho" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
  </div>
  <div class="row">
    <div class="col-6"><label>Tablet / Notebook (parcela)</label><input type="text" name="tech_tablet" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
    <div class="col-6"><label>Apps e assinaturas <span class="shareTip" title="Se for assinatura familiar (Spotify Família, iCloud, etc), divida pelo número de usuários.">i</span></label><input type="text" name="tech_apps" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
  </div>
  <div class="row">
    <div class="col-6"><label>Internet para estudo</label><input type="text" name="tech_internet_estudo" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
    <div class="col-6"><label>Outros (tecnologia)</label><input type="text" name="tech_outros" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
  </div>
  <div class="btnRow">
    <button class="btn btnSecondary" onclick="goStep(7)">&larr; Voltar</button>
    <button class="btn btnPrimary" onclick="goStep(9)">Próximo &rarr;</button>
  </div>
</div>

<!-- ===== STEP 9 — Cuidados ===== -->
<div class="card" data-step="9">
  <h2>Cuidados</h2>
  <p class="stepSub">Gastos mensais com cuidadores e serviços domésticos</p>
  <div class="row">
    <div class="col-6"><label>Babá</label><input type="text" name="cuid_baba" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
    <div class="col-6"><label>Cuidador(a)</label><input type="text" name="cuid_cuidador" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
  </div>
  <div class="row">
    <div class="col-6"><label>Acompanhante terapêutico</label><input type="text" name="cuid_acompanhante" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
    <div class="col-6"><label>Diarista / Empregada</label><input type="text" name="cuid_diarista" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
  </div>
  <div class="row">
    <div class="col-12"><label>Outros (cuidados)</label><input type="text" name="cuid_outros" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
  </div>
  <div class="btnRow">
    <button class="btn btnSecondary" onclick="goStep(8)">&larr; Voltar</button>
    <button class="btn btnPrimary" onclick="goStep(10)">Próximo &rarr;</button>
  </div>
</div>

<!-- ===== STEP 10 — Gastos Eventuais / Anuais ===== -->
<div class="card" data-step="10">
  <h2>📌 Gastos Eventuais / Anuais</h2>
  <p class="stepSub">Despesas que não são mensais — informe o <strong>valor anual</strong>. O sistema divide automaticamente por 12 ao calcular o gasto mensal real.</p>
  <div class="row">
    <div class="col-6"><label>Uniforme escolar (anual)</label><input type="text" name="eventual_uniforme" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
    <div class="col-6"><label>Matrícula escolar (anual)</label><input type="text" name="eventual_matricula" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
  </div>
  <div class="row">
    <div class="col-6"><label>Material escolar (anual)</label><input type="text" name="eventual_material_escolar" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
    <div class="col-6"><label>IPVA (anual)</label><input type="text" name="eventual_ipva" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
  </div>
  <div class="row">
    <div class="col-6"><label>IPTU (anual)</label><input type="text" name="eventual_iptu" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
    <div class="col-6"><label>Presentes (Natal, aniversários — anual)</label><input type="text" name="eventual_presentes" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
  </div>
  <div class="row">
    <div class="col-6"><label>Viagens / férias (anual)</label><input type="text" name="eventual_viagens" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
    <div class="col-6"><label>Médico / exames esporádicos (anual)</label><input type="text" name="eventual_medico_esporadico" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
  </div>
  <div class="row">
    <div class="col-12"><label>Outros gastos eventuais (anual)</label><input type="text" name="eventual_outros" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
  </div>
  <div class="row">
    <div class="col-12">
      <label>Descreva os outros gastos eventuais</label>
      <textarea name="eventual_descricao" placeholder="Ex.: óculos novo, conserto carro, festa aniversário..." data-store></textarea>
    </div>
  </div>
  <div style="background:rgba(184,115,51,.08);border-left:4px solid #B87333;padding:10px 14px;border-radius:6px;margin-top:12px;font-size:.82rem;color:#6a3c2c;">
    💡 <strong>Como funciona:</strong> se você gastou R$ 600 de uniforme num ano, o sistema soma R$ 50/mês (600 ÷ 12) ao gasto mensal total — assim o orçamento real fica visível.
  </div>
  <div class="btnRow">
    <button class="btn btnSecondary" onclick="goStep(9)">&larr; Voltar</button>
    <button class="btn btnPrimary" onclick="goStep(11)">Próximo &rarr;</button>
  </div>
</div>

<!-- ===== STEP 11 — Outros Gastos ===== -->
<div class="card" data-step="11">
  <h2>Outros Gastos</h2>
  <p class="stepSub">Despesas mensais que não se encaixam nas categorias anteriores</p>
  <div class="row">
    <div class="col-12"><label>Outros gastos mensais</label><input type="text" name="outros_gastos" class="money" placeholder="R$ 0,00" data-cents="0" data-store></div>
  </div>
  <div class="row">
    <div class="col-12">
      <label>Descreva esses outros gastos</label>
      <textarea name="outros_descricao" placeholder="Detalhe aqui os gastos informados acima..." data-store></textarea>
    </div>
  </div>
  <div class="row">
    <div class="col-12">
      <label>Observações finais</label>
      <textarea name="obs_finais" placeholder="Algo mais que gostaria de informar ao escritório?" data-store></textarea>
    </div>
  </div>
  <div class="btnRow">
    <button class="btn btnSecondary" onclick="goStep(10)">&larr; Voltar</button>
    <button class="btn btnPrimary" onclick="goStep(12)">Revisar &rarr;</button>
  </div>
</div>

<!-- ===== STEP 12 — Revisão ===== -->
<div class="card" data-step="12">
  <h2>Revisão e Envio</h2>
  <p class="stepSub">Confira os valores antes de enviar</p>

  <div class="chartWrap">
    <h3>Despesas por Categoria</h3>
    <canvas id="chartCanvas"></canvas>
  </div>

  <div id="reviewContent"></div>

  <div class="btnRow" style="justify-content:space-between;flex-wrap:wrap;gap:10px">
    <button class="btn btnSecondary" onclick="goStep(11)">&larr; Voltar e corrigir</button>
    <button class="btn btnSuccess" id="submitBtn" onclick="submitForm()">Enviar ao escritório</button>
  </div>
</div>

<!-- ===== SUCCESS SCREEN ===== -->
<div class="card" data-step="success" style="display:none">
  <div class="successScreen">
    <div class="ico">&#10004;&#65039;</div>
    <h2>Formulário enviado com sucesso!</h2>
    <p class="proto" id="protoDisplay"></p>
    <p>Seus dados foram recebidos pelo escritório Ferreira &amp; Sá Advocacia.</p>
    <p>Entraremos em contato pelo WhatsApp informado.</p>

    <div style="background:rgba(5,34,40,.06);border:1px solid rgba(5,34,40,.12);border-radius:14px;padding:14px;margin:16px 0;text-align:left;">
      <p style="font-size:.85rem;color:var(--text);margin:0 0 8px;"><strong>Percebeu que algum valor está errado?</strong></p>
      <p style="font-size:.78rem;color:var(--muted);margin:0 0 10px;">Você pode revisar e alterar os valores. Ao enviar novamente, os dados serão atualizados automaticamente.</p>
      <button class="btn" onclick="voltarParaRevisao()" style="background:var(--cobre);width:100%;">Revisar e alterar valores</button>
    </div>

    <div class="actionBtns">
      <button class="btn btnSecondary" onclick="downloadCSV()">Baixar CSV</button>
      <button class="btn btnSecondary" onclick="downloadChart()">Baixar Gráfico PNG</button>
    </div>

    <a href="https://www.instagram.com/advocaciaferreiraesa/" target="_blank" class="igLink">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z"/></svg>
      @advocaciaferreiraesa
    </a>
  </div>
</div>

</div><!-- /container -->

<!-- ====== TOAST ====== -->
<div class="toast" id="toast"></div>

<script>
/* ==========================================================
   DESPESAS MENSAIS — Ferreira & Sá Advocacia
   Standalone form JS
   ========================================================== */

const STORE_KEY = 'despesas_mensais_form_v1';
const TOTAL_STEPS = 13; // 0..12 (Identificação..Enviar). Step 10 novo: Eventuais.
let currentStep = 0;
let chartInstance = null;
let summaryChartInstance = null;
let _protocoloSalvo = ''; // Para atualização

/* ---- Category map for grouping money fields ---- */
const CATEGORIES = [
  {key:'moradia',  label:'Moradia',      prefix:'moradia_'},
  {key:'alim',     label:'Alimentação',  prefix:'alim_'},
  {key:'saude',    label:'Saúde',        prefix:'saude_'},
  {key:'educ',     label:'Educação',     prefix:'educ_'},
  {key:'transp',   label:'Transporte',   prefix:'transp_'},
  {key:'vest',     label:'Vestuário',    prefix:'vest_'},
  {key:'lazer',    label:'Lazer',        prefix:'lazer_'},
  {key:'tech',     label:'Tecnologia',   prefix:'tech_'},
  {key:'cuid',     label:'Cuidados',     prefix:'cuid_'},
  {key:'eventual', label:'Eventuais (÷12)', prefix:'eventual_', mensalizar:12}, // Anuais → mensal: divide cents por 12
  {key:'outros',   label:'Outros',       prefix:'outros_'}
];

/* ========== INIT ========== */
document.addEventListener('DOMContentLoaded', () => {
  buildProgressBar();
  initMoneyMasks();
  initTextMasks();
  initSemFilhos();
  loadFromStorage();
  goStep(0);
  updateSummaryKPIs();
  autoSaveLoop();
});

/* ========== PROGRESS BAR ========== */
function buildProgressBar(){
  const bar = document.getElementById('progressBar');
  const steps = [
    {icon:'📋',label:'Identificação'},
    {icon:'🏠',label:'Moradia'},
    {icon:'🍽️',label:'Alimentação'},
    {icon:'❤️',label:'Saúde'},
    {icon:'📚',label:'Educação'},
    {icon:'🚗',label:'Transporte'},
    {icon:'👕',label:'Vestuário'},
    {icon:'🎮',label:'Lazer'},
    {icon:'💻',label:'Tecnologia'},
    {icon:'🧸',label:'Cuidados'},
    {icon:'📦',label:'Outros'},
    {icon:'✅',label:'Enviar'}
  ];
  for(let i=0;i<TOTAL_STEPS;i++){
    const p=document.createElement('div');
    p.className='pill';
    p.dataset.idx=i;
    p.innerHTML='<span class="pillIcon">'+steps[i].icon+'</span> '+(i+1)+'. '+steps[i].label+'<span class="pillCheck"> ✓</span>';
    p.onclick=()=>goStep(i);
    bar.appendChild(p);
  }
}

function updatePills(){
  document.querySelectorAll('.pill').forEach(p=>{
    const idx=+p.dataset.idx;
    p.classList.remove('active','done');
    if(idx===currentStep) p.classList.add('active');
    else if(isStepDone(idx)) p.classList.add('done');
  });
  // Scroll pill ativa para visível
  const activePill = document.querySelector('.pill.active');
  if(activePill) activePill.scrollIntoView({behavior:'smooth',block:'nearest',inline:'center'});
}

function isStepDone(idx){
  const card=document.querySelector(`.card[data-step="${idx}"]`);
  if(!card) return false;
  const moneys=[...card.querySelectorAll('.money')];
  if(moneys.length===0){
    // step 0: check required
    const reqs=[...card.querySelectorAll('.requiredField')];
    return reqs.length>0 && reqs.every(f=>f.value.trim()!=='');
  }
  return moneys.some(m=>+(m.dataset.cents||0)>0);
}

/* ========== NAVIGATION ========== */
function goStep(n){
  // validate required on current step before moving forward
  if(n>currentStep){
    const card=document.querySelector(`.card[data-step="${currentStep}"]`);
    const reqs=[...card.querySelectorAll('.requiredField')];
    for(const f of reqs){
      if(f.value.trim()===''){
        toast('Preencha os campos obrigatórios (*)','err');
        f.focus();
        return;
      }
    }
  }
  currentStep=n;
  document.querySelectorAll('.card').forEach(c=>{c.classList.remove('visible');c.style.display=''});
  const target=document.querySelector(`.card[data-step="${n}"]`);
  if(target) target.classList.add('visible');
  updatePills();
  window.scrollTo({top:0,behavior:'smooth'});
  saveToStorage();
  if(n===11) buildReview();
}

/* ========== MONEY MASK ========== */
function initMoneyMasks(){
  document.querySelectorAll('.money').forEach(inp=>{
    inp.inputMode='numeric';
    inp.addEventListener('input', handleMoneyInput);
    inp.addEventListener('focus', e=>{
      const v=e.target;
      setTimeout(()=>v.setSelectionRange(v.value.length,v.value.length),0);
    });
  });
}

function handleMoneyInput(e){
  let raw=e.target.value.replace(/\D/g,'');
  if(raw==='') raw='0';
  const cents=parseInt(raw,10);
  e.target.dataset.cents=cents;
  e.target.value=formatBRL(cents);
  updateSummaryKPIs();
}

function formatBRL(cents){
  const neg=cents<0;
  const abs=Math.abs(cents);
  const reais=Math.floor(abs/100);
  const cent=abs%100;
  const rStr=reais.toString().replace(/\B(?=(\d{3})+(?!\d))/g,'.');
  return (neg?'-':'')+`R$ ${rStr},${cent.toString().padStart(2,'0')}`;
}

/* ========== TEXT MASKS ========== */
function initTextMasks(){
  document.querySelectorAll('[data-mask="cpf"]').forEach(inp=>{
    inp.inputMode='numeric';
    inp.addEventListener('input',e=>{
      let v=e.target.value.replace(/\D/g,'').slice(0,11);
      if(v.length>9) v=v.replace(/(\d{3})(\d{3})(\d{3})(\d{1,2})/,'$1.$2.$3-$4');
      else if(v.length>6) v=v.replace(/(\d{3})(\d{3})(\d{1,3})/,'$1.$2.$3');
      else if(v.length>3) v=v.replace(/(\d{3})(\d{1,3})/,'$1.$2');
      e.target.value=v;
    });
  });
  document.querySelectorAll('[data-mask="phone"]').forEach(inp=>{
    inp.inputMode='numeric';
    inp.addEventListener('input',e=>{
      let v=e.target.value.replace(/\D/g,'').slice(0,11);
      if(v.length>6) v=v.replace(/(\d{2})(\d{5})(\d{1,4})/,'($1) $2-$3');
      else if(v.length>2) v=v.replace(/(\d{2})(\d{1,5})/,'($1) $2');
      e.target.value=v;
    });
  });
}

/* ========== SEM FILHOS CHECKBOX ========== */
function initSemFilhos(){
  const cb=document.getElementById('semFilhosCheck');
  const inp=document.getElementById('nomeFilhoInput');
  const note=document.getElementById('semFilhosNote');
  if(!cb||!inp) return;
  cb.addEventListener('change',()=>{
    if(cb.checked){
      inp.style.display='none';
      inp.classList.remove('requiredField');
      inp.value='';
      note.style.display='block';
    } else {
      inp.style.display='';
      inp.classList.add('requiredField');
      note.style.display='none';
    }
  });
}

/* ========== LOCAL STORAGE ========== */
function saveToStorage(){
  const data={};
  document.querySelectorAll('[data-store]').forEach(el=>{
    const key=el.name;
    if(!key) return;
    if(el.classList.contains('money')){
      data[key]={cents:+(el.dataset.cents||0),display:el.value};
    } else if(el.tagName==='SELECT'){
      data[key]=el.value;
    } else {
      data[key]=el.value;
    }
  });
  // save checkbox state
  const cb=document.getElementById('semFilhosCheck');
  if(cb) data.__semFilhos=cb.checked;
  data.__step=currentStep;
  try{localStorage.setItem(STORE_KEY,JSON.stringify(data))}catch(e){}
}

function loadFromStorage(){
  let data;
  try{data=JSON.parse(localStorage.getItem(STORE_KEY))}catch(e){return}
  if(!data) return;
  document.querySelectorAll('[data-store]').forEach(el=>{
    const key=el.name;
    if(!key||!(key in data)) return;
    const val=data[key];
    if(el.classList.contains('money')&&typeof val==='object'){
      el.dataset.cents=val.cents||0;
      el.value=val.display||formatBRL(val.cents||0);
    } else {
      el.value=val;
    }
  });
  // restore checkbox
  const cb=document.getElementById('semFilhosCheck');
  if(cb&&data.__semFilhos){
    cb.checked=true;
    cb.dispatchEvent(new Event('change'));
  }
  if(typeof data.__step==='number') currentStep=data.__step;
}

function autoSaveLoop(){
  setInterval(saveToStorage,15000);
}

/* ========== UPDATE PERSISTENT SUMMARY KPIs ========== */
function updateSummaryKPIs(){
  const moradores=Math.max(1,+(document.querySelector('[name="moradores"]')?.value||1));
  let grandTotal=0;
  const catTotals=[];
  const kpiIdMap={moradia:'kpi_moradia',alim:'kpi_alim',saude:'kpi_saude',educ:'kpi_edu',transp:'kpi_transp',vest:'kpi_vest',lazer:'kpi_lazer',tech:'kpi_tech',cuid:'kpi_care',outros:'kpi_outros'};

  CATEGORIES.forEach(cat=>{
    let total=0;
    document.querySelectorAll(`.money[name^="${cat.prefix}"]`).forEach(m=>{
      total+=+(m.dataset.cents||0);
    });
    // Categoria com mensalizar:N divide por N (ex: eventuais anuais ÷ 12)
    if (cat.mensalizar && cat.mensalizar > 1) {
      total = Math.round(total / cat.mensalizar);
    }
    const perPerson=(cat.key==='moradia')?Math.round(total/moradores):total;
    grandTotal+=perPerson;
    catTotals.push({label:cat.label,cents:perPerson});
    const el=document.getElementById(kpiIdMap[cat.key]);
    if(el) el.textContent=formatBRL(perPerson);
  });

  const totalEl=document.getElementById('totalGeral');
  if(totalEl) totalEl.textContent=formatBRL(grandTotal);

  // Update summary chart
  buildSummaryChart(catTotals);
}

function buildSummaryChart(catTotals){
  const filtered=catTotals.filter(c=>c.cents>0);
  const labels=filtered.map(c=>c.label);
  const values=filtered.map(c=>c.cents/100);
  const colors=['#052228','#173d46','#6a3c2c','#d7ab90','#27ae60','#e67e22','#2980b9','#8e44ad','#c0392b','#16a085'];

  const canvas=document.getElementById('summaryChartCanvas');
  if(!canvas) return;

  if(summaryChartInstance){summaryChartInstance.destroy()}
  const ctx=canvas.getContext('2d');
  if(labels.length===0) return;

  summaryChartInstance=new Chart(ctx,{
    type:'bar',
    data:{
      labels:labels,
      datasets:[{
        label:'Valor (R$)',
        data:values,
        backgroundColor:colors.slice(0,labels.length),
        borderRadius:8,
        maxBarThickness:48
      }]
    },
    options:{
      responsive:true,
      maintainAspectRatio:false,
      plugins:{
        legend:{display:false},
        tooltip:{
          callbacks:{
            label:ctx=>'R$ '+ctx.parsed.y.toLocaleString('pt-BR',{minimumFractionDigits:2})
          }
        }
      },
      scales:{
        y:{
          beginAtZero:true,
          ticks:{
            callback:v=>'R$ '+v.toLocaleString('pt-BR',{minimumFractionDigits:0})
          }
        }
      }
    }
  });
}

/* ========== BUILD REVIEW ========== */
function buildReview(){
  const moradores=Math.max(1,+(document.querySelector('[name="moradores"]')?.value||1));
  let html='<table class="reviewTable"><thead><tr><th>Categoria</th><th style="text-align:right">Valor total</th><th style="text-align:right">Por pessoa</th></tr></thead><tbody>';
  let grandTotal=0;
  const catTotals=[];

  CATEGORIES.forEach(cat=>{
    let totalBruto=0;
    document.querySelectorAll(`.money[name^="${cat.prefix}"]`).forEach(m=>{
      totalBruto+=+(m.dataset.cents||0);
    });
    // Eventuais (anuais): mostra total anual mas mensaliza pra somar no TOTAL MENSAL
    const total = (cat.mensalizar && cat.mensalizar > 1) ? Math.round(totalBruto / cat.mensalizar) : totalBruto;
    const perPerson=(cat.key==='moradia')?Math.round(total/moradores):total;
    grandTotal+=perPerson;
    catTotals.push({label:cat.label,cents:perPerson});
    if(totalBruto>0){
      const labelExtra = (cat.mensalizar && cat.mensalizar > 1) ? ` <small style="color:#888">(anual ${formatBRL(totalBruto)})</small>` : '';
      html+=`<tr><td>${cat.label}${labelExtra}</td><td style="text-align:right">${formatBRL(total)}</td><td style="text-align:right">${formatBRL(perPerson)}</td></tr>`;
    }
  });

  html+=`<tr class="totalRow"><td><strong>TOTAL MENSAL</strong></td><td style="text-align:right" colspan="2"><strong>${formatBRL(grandTotal)}</strong></td></tr>`;
  html+='</tbody></table>';

  document.getElementById('reviewContent').innerHTML=html;
  buildChart(catTotals);
}

/* ========== CHART ========== */
function buildChart(catTotals){
  const filtered=catTotals.filter(c=>c.cents>0);
  const labels=filtered.map(c=>c.label);
  const values=filtered.map(c=>c.cents/100);
  const colors=['#052228','#173d46','#6a3c2c','#d7ab90','#27ae60','#e67e22','#2980b9','#8e44ad','#c0392b','#16a085'];

  if(chartInstance){chartInstance.destroy()}
  const ctx=document.getElementById('chartCanvas').getContext('2d');
  chartInstance=new Chart(ctx,{
    type:'bar',
    data:{
      labels:labels,
      datasets:[{
        label:'Valor (R$)',
        data:values,
        backgroundColor:colors.slice(0,labels.length),
        borderRadius:8,
        maxBarThickness:48
      }]
    },
    options:{
      responsive:true,
      maintainAspectRatio:false,
      plugins:{
        legend:{display:false},
        tooltip:{
          callbacks:{
            label:ctx=>'R$ '+ctx.parsed.y.toLocaleString('pt-BR',{minimumFractionDigits:2})
          }
        }
      },
      scales:{
        y:{
          beginAtZero:true,
          ticks:{
            callback:v=>'R$ '+v.toLocaleString('pt-BR',{minimumFractionDigits:0})
          }
        }
      }
    }
  });
}

/* ========== BUILD PAYLOAD ========== */
function buildPayload(){
  const payload={};
  document.querySelectorAll('[data-store]').forEach(el=>{
    const key=el.name;
    if(!key) return;
    if(el.classList.contains('money')){
      payload[key]=+(el.dataset.cents||0);
    } else {
      payload[key]=el.value.trim();
    }
  });

  // sem filhos
  const cb=document.getElementById('semFilhosCheck');
  if(cb&&cb.checked){
    payload.sem_filhos='sim';
    payload.nome_filho_referente='';
  } else {
    payload.sem_filhos='nao';
  }

  // moradores for division
  payload.moradores=Math.max(1,+(document.querySelector('[name="moradores"]')?.value||1));

  // computed totals
  const moradores=payload.moradores;
  let grandTotal=0;
  CATEGORIES.forEach(cat=>{
    let totalBruto=0;
    document.querySelectorAll(`.money[name^="${cat.prefix}"]`).forEach(m=>{
      totalBruto+=+(m.dataset.cents||0);
    });
    // Eventuais (anuais): mensaliza pra somar no total geral, mas guarda o bruto também
    const total = (cat.mensalizar && cat.mensalizar > 1) ? Math.round(totalBruto / cat.mensalizar) : totalBruto;
    const perPerson=(cat.key==='moradia')?Math.round(total/moradores):total;
    payload[`total_${cat.key}`]=perPerson;
    if (cat.mensalizar && cat.mensalizar > 1) {
      payload[`total_${cat.key}_anual`]=totalBruto; // pra advogada/escritório consultar o anual original
    }
    grandTotal+=perPerson;
  });
  payload.total_geral=grandTotal;

  return payload;
}

/* ========== SUBMIT ========== */
async function submitForm(){
  const btn=document.getElementById('submitBtn');
  btn.disabled=true;
  btn.textContent='Enviando...';

  try{
    const payload=buildPayload();
    // Se já tem protocolo salvo, enviar para UPDATE
    if(_protocoloSalvo) payload._protocolo = _protocoloSalvo;
    const res=await fetch('submit.php',{
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body:JSON.stringify(payload)
    });
    const out=await res.json();
    if(out.ok){
      _protocoloSalvo = out.protocolo;
      // show success
      document.querySelectorAll('.card').forEach(c=>{c.classList.remove('visible');c.style.display='none'});
      const suc=document.querySelector('.card[data-step="success"]');
      suc.style.display='block';
      suc.classList.add('visible');
      document.getElementById('protoDisplay').textContent='Protocolo: '+out.protocolo;
      if(out.atualizado) document.getElementById('protoDisplay').textContent += ' (atualizado)';
      document.querySelector('.progressWrap').style.display='none';
      document.getElementById('summaryWrap').style.display='none';
      // NÃO remover localStorage — o cliente pode querer alterar de novo
      toast(out.atualizado ? 'Dados atualizados!' : 'Enviado com sucesso!','ok');
      window.scrollTo({top:0,behavior:'smooth'});
    } else {
      toast(out.erro||'Erro ao enviar. Tente novamente.','err');
      btn.disabled=false;
      btn.textContent='Enviar ao escritório';
    }
  }catch(err){
    toast('Erro de conexão. Verifique sua internet e tente novamente.','err');
    btn.disabled=false;
    btn.textContent='Enviar ao escritório';
  }
}

/* ========== DOWNLOAD CSV ========== */
function downloadCSV(){
  const payload=buildPayload();
  let csv='Campo;Valor\n';
  for(const[k,v] of Object.entries(payload)){
    const display=(typeof v==='number'&&k!=='moradores'&&k!=='qtd_filhos')?formatBRL(v):v;
    csv+=`"${k}";"${display}"\n`;
  }
  const blob=new Blob(['\uFEFF'+csv],{type:'text/csv;charset=utf-8;'});
  const url=URL.createObjectURL(blob);
  const a=document.createElement('a');
  a.href=url;a.download='despesas_mensais.csv';
  document.body.appendChild(a);a.click();document.body.removeChild(a);
  URL.revokeObjectURL(url);
}

/* ========== DOWNLOAD CHART ========== */
function downloadChart(){
  const canvas=document.getElementById('summaryChartCanvas')||document.getElementById('chartCanvas');
  if(!canvas) return;
  const a=document.createElement('a');
  a.href=canvas.toDataURL('image/png');
  a.download='grafico_despesas.png';
  document.body.appendChild(a);a.click();document.body.removeChild(a);
}

/* ========== TOAST ========== */
function toast(msg,type='ok'){
  const t=document.getElementById('toast');
  t.textContent=msg;
  t.className='toast '+type+' show';
  setTimeout(()=>t.classList.remove('show'),3500);
}

/* ========== APAGAR DADOS ========== */
function apagarDados(){
  if(!confirm('Tem certeza que deseja apagar TODOS os dados preenchidos?\n\nEsta ação não pode ser desfeita.')) return;
  localStorage.removeItem(STORE_KEY);
  _protocoloSalvo = '';
  window.location.reload();
}

/* ========== VOLTAR PARA REVISÃO (após envio) ========== */
function voltarParaRevisao(){
  // Esconder tela de sucesso
  document.querySelector('.card[data-step="success"]').style.display='none';
  // Mostrar progress bar e summary
  document.querySelector('.progressWrap').style.display='';
  document.getElementById('summaryWrap').style.display='';
  // Habilitar botão de enviar
  const btn=document.getElementById('submitBtn');
  if(btn){btn.disabled=false;btn.textContent='Atualizar e reenviar';}
  // Ir para a primeira etapa
  goStep(0);
  toast('Altere os valores e envie novamente. Os dados serão atualizados.','ok');
}
</script>
</body>
</html>
