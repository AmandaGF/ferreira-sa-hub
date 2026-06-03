<?php
/** Política de Privacidade (LGPD) — Ferreira & Sá. Usa lp/site.css. */
$wpp = '5524992050096';
$ano = date('Y');
$atualizado = '16/05/2026';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Política de Privacidade — Ferreira &amp; Sá Advocacia</title>
<meta name="description" content="Política de Privacidade e tratamento de dados pessoais (LGPD) do site da Ferreira & Sá Advocacia.">
<meta name="robots" content="noindex,follow">
<meta name="theme-color" content="#052228">
<link rel="icon" type="image/png" href="../assets/img/logo.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="site.css?v=2026060312">
<style>
.doc{max-width:820px;margin:0 auto;padding:8rem 1.5rem 5rem}
.doc h1{font-family:var(--serif);font-size:clamp(2rem,4vw,2.8rem);color:var(--petrol);font-weight:600;margin-bottom:.4rem}
.doc .upd{font-size:.8rem;color:var(--muted);margin-bottom:2.4rem}
.doc h2{font-family:var(--serif);font-size:1.4rem;color:var(--petrol);font-weight:600;margin:2.2rem 0 .7rem}
.doc p,.doc li{color:var(--ink);font-size:.95rem;line-height:1.75}
.doc ul{margin:.5rem 0 0 1.2rem;display:grid;gap:.4rem}
.doc a{color:var(--rose-2);font-weight:600}
.doc .back{display:inline-block;margin-bottom:2rem;color:var(--rose-2);font-weight:600;font-size:.85rem}
</style>
</head>
<body>
<div class="doc">
  <a href="v2.php" class="back">← Voltar ao site</a>
  <h1>Política de Privacidade</h1>
  <div class="upd">Última atualização: <?= $atualizado ?></div>

  <p>A <strong>Ferreira &amp; Sá Sociedade de Advogados</strong> (CNPJ 51.294.223/0001-40 — OAB/RJ 5.987/2023), com sede na Rua Dr. Aldrovando de Oliveira, 140, Ano Bom, Barra Mansa/RJ, respeita a sua privacidade e trata seus dados pessoais em conformidade com a Lei nº 13.709/2018 (LGPD).</p>

  <h2>1. Quais dados coletamos</h2>
  <p>Coletamos apenas os dados que você nos fornece voluntariamente pelos formulários e canais de contato do site: <strong>nome, telefone/WhatsApp, e-mail</strong> e as <strong>informações que você decidir relatar sobre o seu caso</strong>. Não utilizamos cookies de rastreamento publicitário neste site.</p>

  <h2>2. Para que usamos</h2>
  <ul>
    <li>Entrar em contato e prestar atendimento jurídico solicitado por você;</li>
    <li>Avaliar a viabilidade da sua demanda e apresentar proposta;</li>
    <li>Cumprir obrigações legais, regulatórias e do Código de Ética da OAB.</li>
  </ul>
  <p>A base legal é o seu consentimento e/ou os procedimentos preliminares relacionados a contrato do qual você é parte (art. 7º, I e V, LGPD).</p>

  <h2>3. Compartilhamento</h2>
  <p>Seus dados <strong>não são vendidos nem cedidos</strong> a terceiros para fins de marketing. Podem ser tratados por ferramentas estritamente necessárias ao atendimento (ex.: comunicação por WhatsApp e e-mail) e divulgados quando exigido por lei ou ordem judicial.</p>

  <h2>4. Sigilo profissional</h2>
  <p>As informações relacionadas ao seu caso são protegidas pelo <strong>sigilo profissional do advogado</strong>, dever ético e legal, com a máxima discrição — inclusive em processos que correm em segredo de justiça.</p>

  <h2>5. Armazenamento e segurança</h2>
  <p>Os dados são armazenados em ambiente controlado, com medidas técnicas e administrativas de segurança, e mantidos pelo tempo necessário ao atendimento e ao cumprimento de obrigações legais.</p>

  <h2>6. Seus direitos</h2>
  <p>Você pode, a qualquer momento, solicitar confirmação do tratamento, acesso, correção, anonimização, portabilidade ou eliminação dos seus dados, bem como revogar o consentimento, nos termos do art. 18 da LGPD.</p>

  <h2>7. Contato do Encarregado (DPO)</h2>
  <p>Para exercer seus direitos ou tirar dúvidas sobre privacidade, fale com a gente:
  <a href="mailto:contato@ferreiraesa.com.br">contato@ferreiraesa.com.br</a> ·
  <a href="https://wa.me/<?= $wpp ?>" target="_blank" rel="noopener">WhatsApp (24) 99205-0096</a>.</p>

  <p style="margin-top:2.4rem;font-size:.82rem;color:var(--muted)">Esta política pode ser atualizada periodicamente. A versão vigente está sempre disponível nesta página.</p>
  <a href="v2.php" class="back" style="margin:2rem 0 0">← Voltar ao site</a>
</div>
</body>
</html>
