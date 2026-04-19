<?php
/**
 * Ferreira & Sá Hub — Hub de Configurações do WhatsApp CRM
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
if (!has_min_role('gestao')) {
    flash_set('error', 'Acesso restrito.');
    redirect(url('modules/whatsapp/'));
}

$pageTitle = 'Configurações WhatsApp';
require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.cfg-grid { display:grid;grid-template-columns:repeat(auto-fit, minmax(260px, 1fr));gap:1rem;margin-top:1rem; }
.cfg-card { background:#fff;border:1px solid var(--border);border-radius:14px;padding:1.3rem;text-decoration:none;color:var(--text);transition:all .2s;display:flex;flex-direction:column;gap:.5rem; }
.cfg-card:hover { border-color:var(--rose);box-shadow:0 4px 12px rgba(215,171,144,.15);transform:translateY(-2px); }
.cfg-ico { font-size:2.2rem;line-height:1; }
.cfg-titulo { font-weight:700;color:var(--petrol-900);font-size:1.05rem;margin:0; }
.cfg-desc { font-size:.82rem;color:var(--text-muted);margin:0;line-height:1.4; }
.cfg-tag { display:inline-block;margin-top:auto;font-size:.7rem;background:#f3f4f6;color:#374151;padding:2px 8px;border-radius:10px;font-weight:600;text-transform:uppercase;letter-spacing:.3px;align-self:flex-start; }
.cfg-intro { background:#f9fafb;border:1px solid var(--border);border-radius:12px;padding:1rem 1.2rem;margin-bottom:.5rem; }
</style>

<div style="display:flex;align-items:center;gap:.5rem;margin-bottom:1rem;">
    <h1 style="margin:0;">⚙️ Configurações WhatsApp</h1>
    <div style="margin-left:auto;display:flex;gap:.4rem;">
        <a href="<?= url('modules/whatsapp/?canal=21') ?>" class="btn btn-outline btn-sm">← Voltar ao WhatsApp (21)</a>
        <a href="<?= url('modules/whatsapp/?canal=24') ?>" class="btn btn-outline btn-sm">← Voltar ao WhatsApp (24)</a>
    </div>
</div>

<div class="cfg-intro">
    <strong>Central de gestão do WhatsApp CRM.</strong> Aqui você configura templates (respostas rápidas), etiquetas, automações (bot IA, aniversário, fora do horário) e as credenciais Z-API.
</div>

<div class="cfg-grid">
    <a href="<?= module_url('whatsapp', 'templates.php') ?>" class="cfg-card">
        <div class="cfg-ico">📋</div>
        <h3 class="cfg-titulo">Templates / Respostas Rápidas</h3>
        <p class="cfg-desc">Textos prontos que aparecem no botão 📋 do chat. Também são usados pelas automações (fora do horário, boas-vindas, aniversário, etc).</p>
        <span class="cfg-tag">Respostas rápidas</span>
    </a>

    <a href="<?= module_url('whatsapp', 'etiquetas.php') ?>" class="cfg-card">
        <div class="cfg-ico">🏷</div>
        <h3 class="cfg-titulo">Etiquetas</h3>
        <p class="cfg-desc">Marcadores coloridos aplicados às conversas (Urgente, VIP, Aguardando Docs, Negociação, etc). Filtra o inbox.</p>
        <span class="cfg-tag">Organização</span>
    </a>

    <a href="<?= module_url('whatsapp', 'automacoes.php') ?>" class="cfg-card">
        <div class="cfg-ico">🤖</div>
        <h3 class="cfg-titulo">Automações</h3>
        <p class="cfg-desc">Horário de atendimento, bot IA (recepção), fora do horário, boas-vindas, confirmação de documento, aniversário e assinatura do atendente.</p>
        <span class="cfg-tag">Regras automáticas</span>
    </a>

    <a href="<?= module_url('whatsapp', 'configurar.php') ?>" class="cfg-card">
        <div class="cfg-ico">🔑</div>
        <h3 class="cfg-titulo">Credenciais Z-API</h3>
        <p class="cfg-desc">ID e token das instâncias 21 e 24, Client-Token da conta e Base URL. Webhook URL mostrada aqui para copiar.</p>
        <span class="cfg-tag">Integração</span>
    </a>
</div>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
