<?php
/**
 * Atualiza os formulários públicos para enviar dual-write ao Conecta.
 * Modifica submit.php do convivencia_form e gastos_pensao,
 * e index.html da calculadora.
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');

$dryRun = !isset($_GET['executar']);
echo "=== ATUALIZAR FORMULÁRIOS PÚBLICOS → CONECTA ===\n";
echo $dryRun ? ">>> MODO SIMULAÇÃO (adicione &executar) <<<\n\n" : ">>> EXECUTANDO <<<\n\n";

$publicHtml = dirname(__DIR__); // /home/ferre315/public_html
$conectaApiUrl = 'https://www.ferreiraesa.com.br/conecta/publico/api_form.php';

$atualizados = 0;
$erros = 0;

// ═══════════════════════════════════════════
// 1. CONVIVÊNCIA — submit.php
// ═══════════════════════════════════════════
echo "--- 1. Convivência (convivencia_form/submit.php) ---\n";
$convFile = $publicHtml . '/convivencia_form/submit.php';
if (!file_exists($convFile)) {
    echo "  ERRO: Arquivo não encontrado: $convFile\n\n";
    $erros++;
} else {
    $conv = file_get_contents($convFile);
    if (strpos($conv, 'api_form.php') !== false) {
        echo "  JÁ ATUALIZADO (contém api_form.php)\n\n";
    } else {
        // Inserir dual-write antes do redirect
        $marker = "header('Location: thankyou.php?p=' . urlencode(\$protocol));";
        if (strpos($conv, $marker) === false) {
            // Tentar variação
            $marker = "header('Location: thankyou.php?p='";
            if (strpos($conv, $marker) === false) {
                echo "  ERRO: Não encontrou o ponto de inserção no submit.php\n\n";
                $erros++;
            }
        }

        if (strpos($conv, $marker) !== false) {
            $dualWrite = <<<'PHP'

// ── Dual-write: enviar também para o Conecta ──
$conectaUrl = 'https://www.ferreiraesa.com.br/conecta/publico/api_form.php';
$conectaPayload = json_encode(array(
    'form_type'    => 'convivencia',
    'client_name'  => $client_name,
    'client_phone' => $client_phone,
    'client_email' => $client_email,
    'child_name'   => $child_name,
    'child_age'    => $child_age,
    'relationship_role' => $relationship_role,
    'answers'      => $data,
    'protocol_original' => $protocol,
), JSON_UNESCAPED_UNICODE);

$ch = curl_init($conectaUrl);
curl_setopt_array($ch, array(
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $conectaPayload,
    CURLOPT_HTTPHEADER     => array('Content-Type: application/json'),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 5,
    CURLOPT_SSL_VERIFYPEER => false,
));
curl_exec($ch);
curl_close($ch);

PHP;
            $conv = str_replace($marker, $dualWrite . $marker, $conv);

            if ($dryRun) {
                echo "  [SIMULAÇÃO] Adicionaria dual-write (" . strlen($dualWrite) . " bytes)\n\n";
            } else {
                file_put_contents($convFile, $conv);
                echo "  [OK] Dual-write adicionado!\n\n";
                $atualizados++;
            }
        }
    }
}

// ═══════════════════════════════════════════
// 2. GASTOS PENSÃO — submit.php
// ═══════════════════════════════════════════
echo "--- 2. Gastos Pensão (gastos_pensao/submit.php) ---\n";
$gastosFile = $publicHtml . '/gastos_pensao/submit.php';
if (!file_exists($gastosFile)) {
    echo "  ERRO: Arquivo não encontrado: $gastosFile\n\n";
    $erros++;
} else {
    $gastos = file_get_contents($gastosFile);
    if (strpos($gastos, 'api_form.php') !== false) {
        echo "  JÁ ATUALIZADO (contém api_form.php)\n\n";
    } else {
        $marker = "responder(true, 'Salvo com sucesso.'";
        if (strpos($gastos, $marker) !== false) {
            $dualWrite = <<<'PHP'

    // ── Dual-write: enviar também para o Conecta ──
    $conectaUrl = 'https://www.ferreiraesa.com.br/conecta/publico/api_form.php';
    $conectaData = $dados;
    $conectaData['form_type'] = 'gastos_pensao';
    $conectaData['client_name'] = $nome_responsavel;
    $conectaData['client_phone'] = $whatsapp;
    $conectaData['protocol_original'] = $protocolo;

    $ch = curl_init($conectaUrl);
    curl_setopt_array($ch, array(
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($conectaData, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => array('Content-Type: application/json'),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_SSL_VERIFYPEER => false,
    ));
    curl_exec($ch);
    curl_close($ch);

PHP;
            $gastos = str_replace($marker, $dualWrite . $marker, $gastos);

            if ($dryRun) {
                echo "  [SIMULAÇÃO] Adicionaria dual-write (" . strlen($dualWrite) . " bytes)\n\n";
            } else {
                file_put_contents($gastosFile, $gastos);
                echo "  [OK] Dual-write adicionado!\n\n";
                $atualizados++;
            }
        } else {
            echo "  ERRO: Não encontrou o ponto de inserção\n\n";
            $erros++;
        }
    }
}

// ═══════════════════════════════════════════
// 3. CALCULADORA — index.html (trocar Firebase → Conecta)
// ═══════════════════════════════════════════
echo "--- 3. Calculadora (calculadora/index.html) ---\n";
$calcFile = $publicHtml . '/calculadora/index.html';
if (!file_exists($calcFile)) {
    echo "  ERRO: Arquivo não encontrado: $calcFile\n\n";
    $erros++;
} else {
    $calc = file_get_contents($calcFile);
    if (strpos($calc, 'api_form.php') !== false) {
        echo "  JÁ ATUALIZADO (contém api_form.php)\n\n";
    } else {
        // Substituir o bloco Firebase por fetch ao Conecta
        $oldCode = 'db.collection("leads_calculadora").add(dados).then(() => {';
        if (strpos($calc, $oldCode) !== false) {
            // Encontrar o bloco completo até o fechamento });
            $startPos = strpos($calc, $oldCode);
            // Buscar o fechamento: });  (fim do .then())
            $searchFrom = $startPos + strlen($oldCode);
            // Contar as chaves para achar o fechamento correto
            $depth = 1;
            $pos = $searchFrom;
            $len = strlen($calc);
            while ($pos < $len && $depth > 0) {
                if ($calc[$pos] === '{') $depth++;
                if ($calc[$pos] === '}') $depth--;
                $pos++;
            }
            // Avançar até o ');' do .then()
            while ($pos < $len && ($calc[$pos] === ')' || $calc[$pos] === ';')) {
                $pos++;
            }

            $oldBlock = substr($calc, $startPos, $pos - $startPos);

            $newBlock = <<<'JS'
// Enviar para o Conecta (substitui Firebase)
        var conectaData = {
            form_type: 'calculadora_lead',
            client_name: dados.nome,
            client_phone: dados.whatsapp,
            nome: dados.nome,
            whatsapp: dados.whatsapp,
            idade_filhos: dados.idade_filhos,
            situacao: dados.situacao,
            porcentagem: dados.porcentagem,
            ano_referencia: dados.ano_referencia,
            data_envio: dados.data_envio.toISOString()
        };

        // Também salvar no Firebase como backup
        db.collection("leads_calculadora").add(dados).catch(function(){});

        fetch('https://www.ferreiraesa.com.br/conecta/publico/api_form.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(conectaData)
        }).catch(function(){}).finally(function() {
            var valorFinal = (salarioSelecionado * dados.porcentagem) / 100;
            var venc = calcularVencimento();
            var vStr = valorFinal.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });

            document.getElementById('valorFinal').innerText = vStr;
            document.getElementById('msgVencimento').innerHTML = 'O 5º dia útil de <strong>' + venc.m + '</strong> será no dia <strong>' + venc.f + '</strong>.';

            var msg = 'Olá, meu nome é ' + dados.nome + '. Calculei a pensão (' + dados.porcentagem + '%) e o valor deu ' + vStr + '. Gostaria de agendar uma consulta.';
            document.getElementById('linkWhats').href = 'https://wa.me/5521998626615?text=' + encodeURIComponent(msg);

            document.getElementById('pensionForm').style.display = 'none';
            document.getElementById('result').style.display = 'block';
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
JS;

            $calc = str_replace($oldBlock, $newBlock, $calc);

            if ($dryRun) {
                echo "  [SIMULAÇÃO] Substituiria Firebase por Conecta+Firebase backup\n\n";
            } else {
                file_put_contents($calcFile, $calc);
                echo "  [OK] Firebase → Conecta (com Firebase backup)!\n\n";
                $atualizados++;
            }
        } else {
            echo "  ERRO: Bloco Firebase não encontrado no formato esperado\n\n";
            $erros++;
        }
    }
}

echo "=== RESUMO ===\n";
echo "Atualizados: $atualizados\n";
echo "Erros: $erros\n";
if ($dryRun) echo "\n>>> Para executar: adicione &executar <<<\n";
echo "\n=== FIM ===\n";
