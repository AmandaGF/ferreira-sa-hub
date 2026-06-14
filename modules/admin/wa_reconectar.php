<?php
/**
 * Reconexão de instância Z-API (WhatsApp) dentro do Hub.
 * Mostra o QR Code ao vivo e o status da conexão. Use quando o número
 * cair / deslogar ("not connected"). Padrão: ?ddd=24 (CX/Operacional) ou ?ddd=21.
 *
 * Endpoint AJAX no mesmo arquivo (?ajax=status|qr|restart) devolve JSON.
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
require_role('admin');
require_once __DIR__ . '/../../core/functions_zapi.php';

$ddd = preg_replace('/\D/', '', $_GET['ddd'] ?? '24');
if ($ddd !== '21' && $ddd !== '24') $ddd = '24';

function _wa_call($url, $clientToken) {
    $headers = array('Content-Type: application/json');
    if ($clientToken) $headers[] = 'Client-Token: ' . $clientToken;
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => false,
    ));
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return array('code' => $code, 'data' => json_decode($resp, true), 'raw' => $resp);
}

// ─── AJAX ────────────────────────────────────────────────────────
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    $inst = zapi_get_instancia($ddd);
    $cfg  = zapi_get_config();
    if (!$inst || !$inst['instancia_id'] || !$inst['token']) {
        echo json_encode(array('erro' => 'Instância DDD ' . $ddd . ' sem credenciais.'));
        exit;
    }
    $base = rtrim($cfg['base_url'], '/') . '/' . $inst['instancia_id'] . '/token/' . $inst['token'];
    $action = $_GET['ajax'];

    if ($action === 'status') {
        $r = _wa_call($base . '/status', $cfg['client_token']);
        $connected = !empty($r['data']['connected']);
        // atualiza DB pra refletir no resto do Hub
        try { db()->prepare("UPDATE zapi_instancias SET conectado=?, ultima_verificacao=NOW() WHERE ddd=?")
                  ->execute(array($connected ? 1 : 0, $ddd)); } catch (Exception $e) {}
        echo json_encode(array('connected' => $connected, 'detail' => $r['data']));
        exit;
    }
    if ($action === 'qr') {
        // /qr-code/image devolve {"value":"data:image/png;base64,..."}
        $r = _wa_call($base . '/qr-code/image', $cfg['client_token']);
        $val = is_array($r['data']) ? ($r['data']['value'] ?? null) : null;
        echo json_encode(array('qr' => $val, 'detail' => $r['data']));
        exit;
    }
    if ($action === 'restart') {
        $r = _wa_call($base . '/restart', $cfg['client_token']);
        echo json_encode(array('ok' => ($r['code'] >= 200 && $r['code'] < 300), 'detail' => $r['data']));
        exit;
    }
    if ($action === 'disconnect') {
        $r = _wa_call($base . '/disconnect', $cfg['client_token']);
        echo json_encode(array('ok' => ($r['code'] >= 200 && $r['code'] < 300), 'detail' => $r['data']));
        exit;
    }
    echo json_encode(array('erro' => 'ação inválida'));
    exit;
}

$inst = zapi_get_instancia($ddd);
$nome = $inst ? $inst['nome'] : '(não configurada)';
$pageTitle = 'Reconectar WhatsApp';
include __DIR__ . '/../../templates/layout_start.php';
?>
<style>
.wa-rc{max-width:560px;margin:1rem auto;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:1.5rem;text-align:center;}
.wa-rc h1{color:#052228;font-size:1.15rem;margin:.2rem 0;}
.wa-rc .sub{color:#6b7280;font-size:.85rem;margin-bottom:1rem;}
.wa-rc .tabs{display:flex;gap:.5rem;justify-content:center;margin-bottom:1rem;}
.wa-rc .tabs a{padding:.4rem .9rem;border-radius:8px;text-decoration:none;font-size:.85rem;border:1px solid #e5e7eb;color:#374151;}
.wa-rc .tabs a.on{background:#052228;color:#fff;border-color:#052228;}
.wa-st{padding:.7rem 1rem;border-radius:8px;font-weight:600;font-size:.9rem;margin-bottom:1rem;}
.wa-st.ok{background:#d1fae5;color:#065f46;}
.wa-st.no{background:#fee2e2;color:#991b1b;}
.wa-st.load{background:#f1f5f9;color:#475569;}
#qrBox{min-height:280px;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:.5rem;}
#qrBox img{width:280px;height:280px;border:1px solid #e5e7eb;border-radius:8px;}
.wa-rc .btns{margin-top:1rem;display:flex;gap:.6rem;justify-content:center;flex-wrap:wrap;}
.wa-rc button{padding:.5rem 1rem;border-radius:8px;border:none;cursor:pointer;font-size:.85rem;font-weight:600;}
.btn-rs{background:#B87333;color:#fff;}
.btn-dc{background:#fee2e2;color:#991b1b;}
.wa-hint{font-size:.8rem;color:#6b7280;margin-top:1rem;line-height:1.5;text-align:left;background:#f8fafc;padding:.8rem;border-radius:8px;}
</style>
<div class="wa-rc">
  <div class="tabs">
    <a href="?ddd=21" class="<?= $ddd==='21'?'on':'' ?>">DDD 21 — Comercial</a>
    <a href="?ddd=24" class="<?= $ddd==='24'?'on':'' ?>">DDD 24 — CX/Operacional</a>
  </div>
  <h1>📱 Reconectar WhatsApp</h1>
  <div class="sub">Instância <strong>DDD <?= e($ddd) ?></strong> — <?= e($nome) ?></div>

  <div id="waStatus" class="wa-st load">Verificando status…</div>
  <div id="qrBox"></div>

  <div class="btns">
    <button class="btn-rs" onclick="waRestart()">🔄 Reiniciar instância</button>
    <button class="btn-dc" onclick="waDisconnect()">⏏️ Desconectar (forçar novo QR)</button>
  </div>

  <div class="wa-hint">
    <strong>Como reconectar:</strong><br>
    1. No celular do número <?= e($ddd) ?>, abra o WhatsApp → <em>Configurações → Aparelhos conectados → Conectar um aparelho</em>.<br>
    2. Aponte a câmera para o QR Code acima.<br>
    3. Quando aparecer <strong>“Conectado ✓”</strong> em verde, pode fechar — o WhatsApp do Hub volta a funcionar.<br>
    <em>Se o QR não aparecer, clique em “Desconectar” e depois “Reiniciar instância”.</em>
  </div>
</div>

<script>
var DDD = <?= json_encode($ddd) ?>;
var base = 'wa_reconectar.php?ddd=' + DDD + '&ajax=';
var pollTimer = null, connected = false;

function setStatus(cls, txt){
  var el = document.getElementById('waStatus');
  el.className = 'wa-st ' + cls;
  el.textContent = txt;
}

function loadQr(){
  if (connected) return;
  fetch(base + 'qr', {headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(function(r){return r.json();})
    .then(function(d){
      if (connected) return;
      var box = document.getElementById('qrBox');
      if (d.qr) {
        box.innerHTML = '<img src="' + d.qr + '" alt="QR Code"><div style="font-size:.78rem;color:#6b7280">Escaneie com o celular do número ' + DDD + '</div>';
      } else {
        box.innerHTML = '<div style="color:#6b7280;font-size:.85rem">QR indisponível no momento. Se já está conectado, ignore. Senão clique em “Desconectar”.</div>';
      }
    }).catch(function(){});
}

function checkStatus(){
  fetch(base + 'status', {headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(function(r){return r.json();})
    .then(function(d){
      if (d.erro){ setStatus('no', '⚠ ' + d.erro); return; }
      if (d.connected){
        connected = true;
        setStatus('ok', '✓ Conectado! O WhatsApp do Hub está funcionando.');
        document.getElementById('qrBox').innerHTML = '<div style="font-size:3rem">✅</div>';
        if (pollTimer) clearInterval(pollTimer);
      } else {
        connected = false;
        setStatus('no', '✕ Desconectado — escaneie o QR Code abaixo.');
        loadQr();
      }
    }).catch(function(){ setStatus('load','Erro de rede ao checar status…'); });
}

function waRestart(){
  setStatus('load','Reiniciando…');
  fetch(base + 'restart', {headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(function(r){return r.json();}).then(function(){ setTimeout(checkStatus, 3000); });
}
function waDisconnect(){
  if(!confirm('Desconectar a instância DDD ' + DDD + '? Vai gerar um novo QR pra reparear.')) return;
  setStatus('load','Desconectando…');
  fetch(base + 'disconnect', {headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(function(r){return r.json();}).then(function(){ connected=false; setTimeout(checkStatus, 2000); });
}

checkStatus();
pollTimer = setInterval(checkStatus, 6000); // re-checa status + atualiza QR a cada 6s
</script>
<?php include __DIR__ . '/../../templates/layout_end.php'; ?>
