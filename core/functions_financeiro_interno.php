<?php
/**
 * Ferreira & Sá Hub — Setor Financeiro Interno
 * Finanças do escritório (contas a pagar/receber, fluxo de caixa, despesas fixas).
 * Restrito a Amanda (1) e Luiz Eduardo (6) via can_access_financeiro_interno().
 *
 * Auto-cria as tabelas (self-heal) pra não depender de migração manual.
 * Amanda 05/07/2026.
 */

/** Garante que as tabelas existem. Idempotente — roda em toda carga. */
function fin_int_ensure_schema($pdo)
{
    static $ok = false;
    if ($ok) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS fin_lancamentos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tipo ENUM('entrada','saida') NOT NULL DEFAULT 'saida',
        categoria VARCHAR(60) NOT NULL DEFAULT 'Outros',
        descricao VARCHAR(255) NOT NULL,
        valor_cents INT NOT NULL DEFAULT 0,
        vencimento DATE NULL,
        pago TINYINT(1) NOT NULL DEFAULT 0,
        pago_em DATE NULL,
        recorrente_id INT NULL,
        observacao TEXT NULL,
        criado_por INT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_venc (vencimento),
        KEY idx_tipo (tipo),
        KEY idx_rec (recorrente_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS fin_recorrentes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tipo ENUM('entrada','saida') NOT NULL DEFAULT 'saida',
        categoria VARCHAR(60) NOT NULL DEFAULT 'Outros',
        descricao VARCHAR(255) NOT NULL,
        valor_cents INT NOT NULL DEFAULT 0,
        dia_vencimento TINYINT NOT NULL DEFAULT 5,
        ativo TINYINT(1) NOT NULL DEFAULT 1,
        ultimo_mes_gerado VARCHAR(7) NULL,
        criado_por INT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY idx_ativo (ativo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Upgrade: colunas de importação de extrato bancário (OFX). Idempotente.
    $alters = array(
        "ALTER TABLE fin_lancamentos ADD COLUMN fitid VARCHAR(120) NULL",
        "ALTER TABLE fin_lancamentos ADD COLUMN origem VARCHAR(20) NOT NULL DEFAULT 'manual'",
        "ALTER TABLE fin_lancamentos ADD INDEX idx_fitid (fitid)",
    );
    foreach ($alters as $sql) { try { $pdo->exec($sql); } catch (Exception $e) {} }
    $ok = true;
}

/** Converte "1.234,56" (ou "1234.56", "1234") em centavos (int). */
function fin_int_parse_valor_cents($s)
{
    $s = trim((string)$s);
    if ($s === '') return 0;
    $s = preg_replace('/[^\d,.\-]/', '', $s);
    if ($s === '' || $s === '-') return 0;
    if (strpos($s, ',') !== false) {
        // formato BR: ponto é milhar, vírgula é decimal
        $s = str_replace('.', '', $s);
        $s = str_replace(',', '.', $s);
    }
    return (int) round(((float)$s) * 100);
}

/** Formata centavos como "R$ 1.234,56". */
function fin_int_fmt($cents)
{
    return 'R$ ' . number_format(((int)$cents) / 100, 2, ',', '.');
}

/**
 * Gera os lançamentos das despesas/receitas fixas do MÊS ATUAL (real), uma vez.
 * Cada recorrente ativa que ainda não foi gerada pro mês corrente vira um
 * lançamento (não pago). Idempotente via ultimo_mes_gerado.
 */
function fin_int_gerar_recorrentes($pdo, $uid)
{
    fin_int_ensure_schema($pdo);
    $mesAtual = date('Y-m');
    $st = $pdo->prepare("SELECT * FROM fin_recorrentes
        WHERE ativo = 1 AND (ultimo_mes_gerado IS NULL OR ultimo_mes_gerado < ?)");
    $st->execute(array($mesAtual));
    $recs = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$recs) return 0;

    $diasNoMes = (int) date('t', strtotime($mesAtual . '-01'));
    $ins = $pdo->prepare("INSERT INTO fin_lancamentos
        (tipo, categoria, descricao, valor_cents, vencimento, pago, recorrente_id, criado_por)
        VALUES (?,?,?,?,?,0,?,?)");
    $upd = $pdo->prepare("UPDATE fin_recorrentes SET ultimo_mes_gerado = ? WHERE id = ?");
    $n = 0;
    foreach ($recs as $r) {
        // Proteção extra: já existe lançamento dessa recorrente nesse mês?
        $chk = $pdo->prepare("SELECT COUNT(*) FROM fin_lancamentos
            WHERE recorrente_id = ? AND DATE_FORMAT(vencimento,'%Y-%m') = ?");
        $chk->execute(array($r['id'], $mesAtual));
        if ((int)$chk->fetchColumn() === 0) {
            $dia = (int)$r['dia_vencimento'];
            if ($dia < 1) $dia = 1;
            if ($dia > $diasNoMes) $dia = $diasNoMes;
            $venc = $mesAtual . '-' . sprintf('%02d', $dia);
            $ins->execute(array(
                $r['tipo'], $r['categoria'], $r['descricao'],
                (int)$r['valor_cents'], $venc, $r['id'], $uid
            ));
            $n++;
        }
        $upd->execute(array($mesAtual, $r['id']));
    }
    return $n;
}

/** Extrai o valor de uma tag OFX (SGML — normalmente sem tag de fechamento). */
function _fin_ofx_tag($block, $tag)
{
    if (preg_match('/<' . $tag . '>([^<\r\n]*)/i', $block, $m)) return trim($m[1]);
    return '';
}

/**
 * Faz o parse de um extrato bancário OFX e devolve as transações normalizadas:
 * cada uma com fitid, data (Y-m-d), tipo (entrada/saida), valor_cents (abs),
 * descricao. Aguenta OFX em UTF-8 ou Latin-1.
 */
function fin_int_parse_ofx($raw)
{
    if ($raw === '' || $raw === null) return array();
    // Normaliza encoding pra UTF-8
    if (!mb_check_encoding($raw, 'UTF-8')) {
        $raw = mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1');
    }
    $raw = str_replace(array("\r\n", "\r"), "\n", $raw);

    $txs = array();
    if (!preg_match_all('/<STMTTRN>(.*?)<\/STMTTRN>/is', $raw, $blocks)) return $txs;
    foreach ($blocks[1] as $b) {
        $amtRaw = _fin_ofx_tag($b, 'TRNAMT');
        $dt     = preg_replace('/[^0-9]/', '', _fin_ofx_tag($b, 'DTPOSTED'));
        $fitid  = _fin_ofx_tag($b, 'FITID');
        $memo   = _fin_ofx_tag($b, 'MEMO');
        $name   = _fin_ofx_tag($b, 'NAME');
        if ($amtRaw === '' || strlen($dt) < 8) continue;

        // Valor: OFX usa ponto decimal; sinal indica entrada(+)/saída(-)
        $amt   = (float) str_replace(',', '.', preg_replace('/[^\d,.\-]/', '', $amtRaw));
        $cents = (int) round(abs($amt) * 100);
        if ($cents === 0) continue;

        $desc = trim($memo !== '' ? $memo : $name);
        if ($desc === '') $desc = 'Movimentação bancária';

        $txs[] = array(
            'fitid'      => $fitid !== '' ? $fitid : md5($dt . $amtRaw . $desc),
            'data'       => substr($dt, 0, 4) . '-' . substr($dt, 4, 2) . '-' . substr($dt, 6, 2),
            'tipo'       => ($amt < 0) ? 'saida' : 'entrada',
            'valor_cents' => $cents,
            'descricao'  => mb_substr($desc, 0, 255),
        );
    }
    return $txs;
}

/**
 * Procura um lançamento em aberto (não pago) que combine com uma transação do
 * extrato — mesmo tipo, mesmo valor, vencimento perto da data (±7 dias).
 * Retorna o lançamento candidato (ou null) pra sugerir conciliação.
 */
function fin_int_sugerir_conciliacao($pdo, $tipo, $valorCents, $data)
{
    $st = $pdo->prepare("SELECT id, descricao, vencimento, categoria FROM fin_lancamentos
        WHERE pago = 0 AND tipo = ? AND valor_cents = ?
          AND vencimento BETWEEN DATE_SUB(?, INTERVAL 7 DAY) AND DATE_ADD(?, INTERVAL 7 DAY)
        ORDER BY ABS(DATEDIFF(vencimento, ?)) ASC, id ASC LIMIT 1");
    $st->execute(array($tipo, $valorCents, $data, $data, $data));
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}
