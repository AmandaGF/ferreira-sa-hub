<?php
/**
 * Cadastra/testa a chave da Places API e aquece o cache de avaliações.
 *
 * Uso (no navegador da Amanda):
 *   /conecta/set_google_reviews.php?key=fsa-hub-deploy-2026&v=SUA_API_KEY
 * Opcionais:
 *   &place_id=ChIJ...   força um place_id específico
 *   &q=Texto da busca   força resolver o local por outro texto
 *   &refresh=1          só recarrega o cache (sem mudar a chave)
 */
ini_set('display_errors', '1');
error_reporting(E_ALL);
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/google_reviews.php';

if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
header('Content-Type: text/plain; charset=utf-8');

$v   = trim($_GET['v'] ?? '');
$pid = trim($_GET['place_id'] ?? '');
$q   = trim($_GET['q'] ?? '');

if ($v !== '') {
    _grev_cfg_set('google_places_api_key', $v);
    echo "✓ Chave salva em configuracoes.google_places_api_key (" . substr($v, 0, 6) . "…" . substr($v, -4) . ")\n";
}
if ($pid !== '') {
    _grev_cfg_set('google_place_id', $pid);
    echo "✓ place_id forçado: {$pid}\n";
}

$temChave = _grev_cfg('google_places_api_key');
if ($temChave === '') { exit("\n✗ Nenhuma chave cadastrada ainda. Rode com &v=SUA_API_KEY\n"); }

echo "\nResolvendo local e buscando avaliações...\n";
$dados = google_reviews_refresh($q !== '' ? $q : null);

if (!$dados) {
    echo "\n✗ Falhou. Possíveis causas:\n";
    echo "  - Places API não habilitada no projeto do Google Cloud\n";
    echo "  - Chave restrita demais (restrinja por API = Places API; aplicação = Nenhuma ou IP do servidor)\n";
    echo "  - Faturamento não ativado no Google Cloud (Places exige billing, mas tem cota grátis)\n";
    echo "  - O texto da busca não achou o local — tente &q=Ferreira e Sá Advocacia <bairro/cidade>\n";
    echo "\nplace_id atual em configuracoes: " . (_grev_cfg('google_place_id') ?: '(vazio)') . "\n";
    exit;
}

echo "\n=== OK! Avaliações carregadas ===\n";
echo "Nota: " . ($dados['rating'] ?? '?') . "  |  Total de avaliações: " . ($dados['total'] ?? '?') . "\n";
echo "place_id: " . _grev_cfg('google_place_id') . "\n";
echo "URL Google: " . ($dados['url'] ?: '(sem)') . "\n";
echo "Reviews retornadas pela API: " . count($dados['reviews']) . "\n\n";
foreach ($dados['reviews'] as $i => $rv) {
    echo "  [" . ($i + 1) . "] " . $rv['author'] . " — " . $rv['rating'] . "★ (" . $rv['relative'] . ")\n";
    echo "      " . mb_substr(str_replace("\n", ' ', $rv['text']), 0, 160) . "\n";
}
echo "\nO site (lp/v2.php) já vai mostrar essas avaliações. Cache de 6h em files/google_reviews.json.\n";
echo "Obs: a Places API devolve no máximo ~5 reviews (as mais relevantes/recentes) — limitação do Google, não dá pra puxar todas.\n";
