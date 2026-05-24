<?php
/**
 * Helper compartilhado pra visual (emoji + label curto + cor hex) de cada
 * tipo de ação do escritório. Usado no Painel de Temperatura, tela de
 * Clientes em Risco, e qualquer outra UI que queira pintar/marcar casos
 * pelo tipo de ação.
 *
 * Mantenha o $mapa abaixo atualizado conforme novos tipos surgirem.
 * Tipos não mapeados caem num fallback que tenta detectar por palavra-chave.
 */

if (!function_exists('caso_tipo_visual')) {
    /**
     * Retorna [emoji, label_curto, cor_hex] pra um case_type.
     * Sempre devolve um array — fallback "📁 Outro" cinza se não souber.
     */
    function caso_tipo_visual($tipo) {
        $t = strtolower(trim((string)$tipo));
        static $mapa = array(
            // ── Família — verde / roxo / vermelho / rosa
            'alimentos'                  => array('🍼', 'Alimentos', '#059669'),
            'pensao_alimenticia'         => array('🍼', 'Pensão Aliment.', '#059669'),
            'revisional_alimentos'       => array('🔁', 'Rev. Aliment.', '#0d9488'),
            'execucao_alimentos'         => array('⚖️', 'Exec. Aliment.', '#047857'),
            'guarda'                     => array('🧒', 'Guarda', '#7c3aed'),
            'guarda_unilateral'          => array('🧒', 'Guarda Unilat.', '#7c3aed'),
            'guarda_compartilhada'       => array('🧒', 'Guarda Comp.', '#7c3aed'),
            'guarda_unilateral_convivencia' => array('🧒', 'Guarda + Conv.', '#7c3aed'),
            'regulamentacao_convivencia' => array('📅', 'Reg. Convivência', '#a78bfa'),
            'divorcio'                   => array('💔', 'Divórcio', '#dc2626'),
            'divorcio_consensual'        => array('💔', 'Div. Consensual', '#dc2626'),
            'divorcio_litigioso'         => array('💔', 'Div. Litigioso', '#b91c1c'),
            'averbacao_divorcio'         => array('💔', 'Averbação Div.', '#b91c1c'),
            'uniao_estavel'              => array('💞', 'União Estável', '#db2777'),
            'reconhecimento_dissol_ue'   => array('💔', 'Dissol. União', '#be185d'),
            'investigacao_paternidade'   => array('🧬', 'Invest. Patern.', '#7e22ce'),
            'reconhecimento_paternidade' => array('🧬', 'Rec. Patern.', '#7e22ce'),
            'medida_protetiva'           => array('🛡️', 'Med. Protetiva', '#9f1239'),
            'tutela'                     => array('🤝', 'Tutela', '#0891b2'),
            'curatela'                   => array('🤝', 'Curatela', '#0891b2'),
            // ── Sucessões — marrom
            'inventario'                 => array('📜', 'Inventário', '#92400e'),
            'arrolamento'                => array('📜', 'Arrolamento', '#92400e'),
            'partilha'                   => array('📊', 'Partilha', '#a16207'),
            // ── Imobiliário — azul claro
            'usucapiao'                  => array('🏞️', 'Usucapião', '#15803d'),
            'imobiliario'                => array('🏠', 'Imobiliário', '#0ea5e9'),
            // ── Consumidor / Cível — amarelo / azul escuro
            'consumidor'                 => array('🛍️', 'Consumidor', '#f59e0b'),
            'indenizatoria'              => array('💰', 'Indenização', '#ca8a04'),
            'civil'                      => array('🏛️', 'Cível', '#0e7490'),
            // ── Golpe PIX e fraudes bancárias — vermelho-laranja (alerta)
            'gpx'                        => array('🚨', 'Golpe PIX', '#ea580c'),
            'golpe_pix'                  => array('🚨', 'Golpe PIX', '#ea580c'),
            'golpe pix'                  => array('🚨', 'Golpe PIX', '#ea580c'),
            'fraude_bancaria'            => array('🚨', 'Fraude Banc.', '#ea580c'),
            // ── Previdenciário — azul
            'previdenciario'             => array('🏛️', 'Previdência', '#1e40af'),
            'inss'                       => array('🏛️', 'INSS', '#1e40af'),
            'aposentadoria'              => array('🏛️', 'Aposentadoria', '#1e40af'),
            'aposentadoria_idade'        => array('🏛️', 'Apos. Idade', '#1e40af'),
            'aposentadoria_tempo'        => array('🏛️', 'Apos. Tempo', '#1e40af'),
            'aposentadoria_invalidez'    => array('🏛️', 'Apos. Invalidez', '#1e3a8a'),
            'auxilio_doenca'             => array('🏥', 'Auxílio Doença', '#0369a1'),
            'auxilio doença'             => array('🏥', 'Auxílio Doença', '#0369a1'),
            'auxilio_acidente'           => array('🏥', 'Aux. Acidente', '#0369a1'),
            'bpc'                        => array('💙', 'BPC/LOAS', '#3b82f6'),
            'loas'                       => array('💙', 'BPC/LOAS', '#3b82f6'),
            'pensao_morte'               => array('🕊️', 'Pensão Morte', '#475569'),
            'conversao_ait'              => array('🔁', 'Conv. AIT', '#1d4ed8'),
            'conversao para ait'         => array('🔁', 'Conv. AIT', '#1d4ed8'),
            'revisao_aposentadoria'      => array('🔁', 'Rev. Aposent.', '#1d4ed8'),
            // ── Trabalhista — laranja
            'trabalhista'                => array('👷', 'Trabalhista', '#ea580c'),
            // ── Criminal — vermelho-vinho
            'criminal'                   => array('⚠️', 'Criminal', '#991b1b'),
            // ── Outros
            'contrato'                   => array('📃', 'Contrato', '#475569'),
            'outro'                      => array('📁', 'Outro', '#6b7280'),
        );

        if (isset($mapa[$t])) return $mapa[$t];

        // Fallback por palavra-chave — tenta inferir
        if (strpos($t, 'aliment')  !== false) return array('🍼', 'Alimentos', '#059669');
        if (strpos($t, 'guarda')   !== false) return array('🧒', 'Guarda', '#7c3aed');
        if (strpos($t, 'divorc')   !== false || strpos($t, 'divór') !== false) return array('💔', 'Divórcio', '#dc2626');
        if (strpos($t, 'invent')   !== false) return array('📜', 'Inventário', '#92400e');
        if (strpos($t, 'consum')   !== false) return array('🛍️', 'Consumidor', '#f59e0b');
        if (strpos($t, 'imobil')   !== false) return array('🏠', 'Imobiliário', '#0ea5e9');
        if (strpos($t, 'previd')   !== false || strpos($t, 'inss')    !== false) return array('🏛️', 'Previdência', '#1e40af');
        if (strpos($t, 'aposent')  !== false) return array('🏛️', 'Aposentadoria', '#1e40af');
        if (strpos($t, 'auxíl')    !== false || strpos($t, 'auxil')   !== false) return array('🏥', 'Auxílio', '#0369a1');
        if (strpos($t, 'golpe')    !== false || strpos($t, 'fraude')  !== false || strpos($t, 'pix') !== false) return array('🚨', 'Golpe PIX', '#ea580c');
        if (strpos($t, 'contrat')  !== false) return array('📃', 'Contrato', '#475569');
        if (strpos($t, 'trabalh')  !== false) return array('👷', 'Trabalhista', '#ea580c');
        if (strpos($t, 'crim')     !== false) return array('⚠️', 'Criminal', '#991b1b');
        if (strpos($t, 'usucap')   !== false) return array('🏞️', 'Usucapião', '#15803d');
        if (strpos($t, 'protetiv') !== false) return array('🛡️', 'Med. Protetiva', '#9f1239');
        if (strpos($t, 'patern')   !== false) return array('🧬', 'Paternidade', '#7e22ce');

        // Capitaliza primeira letra pro fallback ficar legível
        $label = $t !== '' ? mb_strtoupper(mb_substr($t, 0, 1)) . mb_substr($t, 1) : '—';
        return array('📁', $label, '#6b7280');
    }
}
