-- ================================================================
-- FERREIRA & SA — Script de Correcao de Kanban Cards
-- Problema: 217 clientes estavam como 'pasta_apta' no Comercial
-- mas ja tinham processo distribuido no Operacional.
-- Este script corrige a coluna desses cards para 'processo_distribuido'
-- para que nao aparecam no Kanban ativo.
-- ================================================================

SET NAMES utf8mb4;

-- VERIFICACAO ANTES DA CORRECAO:
-- SELECT coluna_atual, COUNT(*) FROM kanban_cards WHERE kanban='comercial_cx' GROUP BY coluna_atual;

-- ================================================================
-- PASSO 1: Mover cards 'pasta_apta' do Comercial para 'processo_distribuido'
-- quando o cliente ja tem processo distribuido no Operacional
-- ================================================================

UPDATE kanban_cards k_com
SET 
  k_com.coluna_anterior = k_com.coluna_atual,
  k_com.coluna_atual    = 'processo_distribuido',
  k_com.observacao      = CONCAT(
    IFNULL(k_com.observacao,''), 
    ' | Corrigido automaticamente: processo ja distribuido no Operacional'
  )
WHERE k_com.kanban      = 'comercial_cx'
  AND k_com.coluna_atual = 'pasta_apta'
  AND EXISTS (
    SELECT 1
    FROM kanban_cards k_op
    WHERE k_op.kanban       = 'operacional'
      AND k_op.coluna_atual = 'processo_distribuido'
      AND k_op.cliente_id   = k_com.cliente_id
  );

-- ================================================================
-- PASSO 2: Mesma correcao via nome_pasta_drive (para cards sem cliente_id)
-- ================================================================

UPDATE kanban_cards k_com
JOIN clientes c ON c.id = k_com.cliente_id
SET
  k_com.coluna_anterior = k_com.coluna_atual,
  k_com.coluna_atual    = 'processo_distribuido',
  k_com.observacao      = CONCAT(
    IFNULL(k_com.observacao,''),
    ' | Corrigido via nome_pasta_drive'
  )
WHERE k_com.kanban       = 'comercial_cx'
  AND k_com.coluna_atual = 'pasta_apta'
  AND EXISTS (
    SELECT 1
    FROM processos p
    JOIN kanban_cards k_op ON k_op.cliente_id = (
      SELECT c2.id FROM clientes c2
      WHERE c2.nome_pasta_drive = p.nome_pasta LIMIT 1
    )
    WHERE p.nome_pasta        LIKE CONCAT(SUBSTRING(c.nome_completo,1,15),'%')
      AND k_op.kanban         = 'operacional'
      AND k_op.coluna_atual   = 'processo_distribuido'
  );

-- ================================================================
-- PASSO 3: Registrar correcao no log de auditoria
-- ================================================================

INSERT INTO log_auditoria (acao, kanban, coluna_origem, coluna_destino, gatilhos)
SELECT
  'CORRECAO_PASTA_APTA_DUPLICADA',
  'comercial_cx',
  'pasta_apta',
  'processo_distribuido',
  CONCAT('Correcao automatica em ', NOW(), ' — processo ja distribuido no Operacional')
FROM dual
WHERE EXISTS (
  SELECT 1 FROM kanban_cards
  WHERE kanban = 'comercial_cx'
    AND coluna_anterior = 'pasta_apta'
    AND coluna_atual = 'processo_distribuido'
);

-- ================================================================
-- VERIFICACAO APOS A CORRECAO:
-- Execute estas queries para confirmar:
-- ================================================================
-- SELECT coluna_atual, COUNT(*) as qtd
--   FROM kanban_cards WHERE kanban='comercial_cx'
--   GROUP BY coluna_atual ORDER BY qtd DESC;
--
-- Esperado apos correcao:
-- pasta_apta           ~168  (101 ativos + 25 multiplos + 42 sem processo)
-- processo_distribuido ~217  (os que ja foram executados)
-- elaboracao_procuracao ~112
-- cancelado            ~108
-- reuniao_cobrando_docs ~17
-- suspenso               ~6
-- ================================================================