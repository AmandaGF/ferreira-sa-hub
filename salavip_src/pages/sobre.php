<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
$pdo = sv_db();
$user = salavip_current_user();
$clienteId = salavip_current_cliente_id();
$pageTitle = 'Ferreira & Sá Advocacia';
require_once __DIR__ . '/../includes/header.php';
?>

<!-- SECTION 1: Hero Card -->
<div class="sv-card" style="text-align:center;padding:2.5rem 2rem;margin-bottom:1.5rem;background:linear-gradient(135deg, var(--sv-bg-card), rgba(176,141,110,.08));border:1px solid var(--sv-border);">
    <div style="font-size:2.5rem;margin-bottom:.5rem;">&#9878;&#65039;</div>
    <h2 style="font-family:'Playfair Display',serif;color:var(--sv-accent);font-size:1.8rem;margin-bottom:.3rem;">Ferreira &amp; S&aacute; Advocacia</h2>
    <p style="color:var(--sv-text-muted);font-size:.95rem;font-style:italic;">Advocacia humanizada, estrat&eacute;gica e de resultados.</p>
    <p style="color:var(--sv-text-muted);font-size:.82rem;margin-top:.5rem;">Full Service &mdash; Atua&ccedil;&atilde;o em todas as &aacute;reas do Direito</p>
</div>

<!-- SECTION 2: Contato & Dados Oficiais -->
<div class="sobre-contact-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;margin-bottom:1.5rem;">

    <!-- Dados do Escritório -->
    <div class="sv-card" style="padding:1.5rem;">
        <h3 style="font-family:'Playfair Display',serif;color:var(--sv-accent);font-size:1.1rem;margin-bottom:1rem;padding-bottom:.5rem;border-bottom:1px solid var(--sv-border);">Dados do Escrit&oacute;rio</h3>
        <div style="display:flex;flex-direction:column;gap:.75rem;">
            <div style="display:flex;align-items:flex-start;gap:.6rem;">
                <span style="font-size:1.1rem;flex-shrink:0;">&#128196;</span>
                <div>
                    <div style="color:var(--sv-text-muted);font-size:.75rem;text-transform:uppercase;letter-spacing:.5px;">CNPJ</div>
                    <div style="color:var(--sv-text);font-size:.88rem;font-weight:600;">46.042.291/0001-50</div>
                </div>
            </div>
            <div style="display:flex;align-items:flex-start;gap:.6rem;">
                <span style="font-size:1.1rem;flex-shrink:0;">&#128101;</span>
                <div>
                    <div style="color:var(--sv-text-muted);font-size:.75rem;text-transform:uppercase;letter-spacing:.5px;">OAB/RJ</div>
                    <div style="color:var(--sv-text);font-size:.88rem;">Amanda Guedes Ferreira &mdash; <strong>OAB/RJ 223.389</strong></div>
                    <div style="color:var(--sv-text);font-size:.88rem;">Luiz Eduardo de S&aacute; &mdash; <strong>OAB/RJ 220.807</strong></div>
                </div>
            </div>
            <div style="display:flex;align-items:flex-start;gap:.6rem;">
                <span style="font-size:1.1rem;flex-shrink:0;">&#128205;</span>
                <div>
                    <div style="color:var(--sv-text-muted);font-size:.75rem;text-transform:uppercase;letter-spacing:.5px;">Endere&ccedil;o</div>
                    <div style="color:var(--sv-text);font-size:.88rem;">Rua Dr. Aldrovando de Oliveira, 140 &ndash; Ano Bom &ndash; Barra Mansa/RJ &ndash; CEP 27323-400</div>
                </div>
            </div>
            <div style="display:flex;align-items:flex-start;gap:.6rem;">
                <span style="font-size:1.1rem;flex-shrink:0;">&#128179;</span>
                <div>
                    <div style="color:var(--sv-text-muted);font-size:.75rem;text-transform:uppercase;letter-spacing:.5px;">Chave PIX</div>
                    <div style="color:var(--sv-text);font-size:.88rem;font-weight:600;">46.042.291/0001-50 <span style="color:var(--sv-text-muted);font-weight:400;">(CNPJ)</span></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Canais de Atendimento -->
    <div class="sv-card" style="padding:1.5rem;">
        <h3 style="font-family:'Playfair Display',serif;color:var(--sv-accent);font-size:1.1rem;margin-bottom:1rem;padding-bottom:.5rem;border-bottom:1px solid var(--sv-border);">Canais de Atendimento</h3>
        <div style="display:flex;flex-direction:column;gap:.75rem;">
            <div style="display:flex;align-items:center;gap:.6rem;">
                <span style="font-size:1.1rem;flex-shrink:0;">&#128172;</span>
                <div style="flex:1;">
                    <div style="color:var(--sv-text-muted);font-size:.75rem;text-transform:uppercase;letter-spacing:.5px;">WhatsApp</div>
                    <div style="color:var(--sv-text);font-size:.88rem;">(24) 99205-0096</div>
                </div>
                <a href="https://wa.me/5524992050096?text=Ol%C3%A1!" target="_blank" class="sv-btn" style="background:#25D366;color:#fff;font-size:.78rem;padding:6px 14px;border-radius:20px;text-decoration:none;display:inline-flex;align-items:center;gap:6px;white-space:nowrap;">
                    &#128172; Abrir WhatsApp
                </a>
            </div>
            <div style="display:flex;align-items:center;gap:.6rem;">
                <span style="font-size:1.1rem;flex-shrink:0;">&#128222;</span>
                <div>
                    <div style="color:var(--sv-text-muted);font-size:.75rem;text-transform:uppercase;letter-spacing:.5px;">Telefone</div>
                    <div style="color:var(--sv-text);font-size:.88rem;">(24) 99205-0096</div>
                </div>
            </div>
            <div style="display:flex;align-items:center;gap:.6rem;">
                <span style="font-size:1.1rem;flex-shrink:0;">&#9993;&#65039;</span>
                <div>
                    <div style="color:var(--sv-text-muted);font-size:.75rem;text-transform:uppercase;letter-spacing:.5px;">E-mail</div>
                    <div style="color:var(--sv-text);font-size:.88rem;"><a href="mailto:contato@ferreiraesa.com.br" style="color:var(--sv-accent);text-decoration:none;">contato@ferreiraesa.com.br</a></div>
                </div>
            </div>
            <div style="display:flex;align-items:center;gap:.6rem;">
                <span style="font-size:1.1rem;flex-shrink:0;">&#127760;</span>
                <div>
                    <div style="color:var(--sv-text-muted);font-size:.75rem;text-transform:uppercase;letter-spacing:.5px;">Site</div>
                    <div style="color:var(--sv-text);font-size:.88rem;"><a href="https://www.ferreiraesa.com.br" target="_blank" style="color:var(--sv-accent);text-decoration:none;">www.ferreiraesa.com.br</a></div>
                </div>
            </div>
            <div style="display:flex;align-items:center;gap:.6rem;">
                <span style="font-size:1.1rem;flex-shrink:0;">&#128247;</span>
                <div>
                    <div style="color:var(--sv-text-muted);font-size:.75rem;text-transform:uppercase;letter-spacing:.5px;">Instagram</div>
                    <div style="color:var(--sv-text);font-size:.88rem;"><a href="https://www.instagram.com/ferreiraesaadvocacia/" target="_blank" style="color:var(--sv-accent);text-decoration:none;">@ferreiraesaadvocacia</a></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- SECTION 3: Nossos Diferenciais -->
<div class="sv-card" style="padding:1.5rem;margin-bottom:1.5rem;">
    <h3 style="font-family:'Playfair Display',serif;color:var(--sv-accent);font-size:1.2rem;margin-bottom:1.2rem;padding-bottom:.5rem;border-bottom:1px solid var(--sv-border);">Nossos Diferenciais</h3>
    <div class="sobre-diferenciais-grid" style="display:grid;grid-template-columns:repeat(3, 1fr);gap:1rem;">

        <div style="background:var(--sv-bg-card);border:1px solid var(--sv-border);border-radius:12px;padding:1.5rem;text-align:center;">
            <div style="font-size:2rem;margin-bottom:.5rem;">&#128105;&zwj;&#127891;</div>
            <h4 style="font-family:'Playfair Display',serif;color:var(--sv-accent);font-size:.95rem;margin-bottom:.5rem;">Professora Universit&aacute;ria</h4>
            <p style="color:var(--sv-text-muted);font-size:.82rem;line-height:1.5;">Nossa s&oacute;cia-fundadora, Dra. Amanda Guedes Ferreira, &eacute; professora universit&aacute;ria h&aacute; mais de 10 anos, unindo teoria jur&iacute;dica aprofundada &agrave; pr&aacute;tica cotidiana da advocacia.</p>
        </div>

        <div style="background:var(--sv-bg-card);border:1px solid var(--sv-border);border-radius:12px;padding:1.5rem;text-align:center;">
            <div style="font-size:2rem;margin-bottom:.5rem;">&#127963;&#65039;</div>
            <h4 style="font-family:'Playfair Display',serif;color:var(--sv-accent);font-size:.95rem;margin-bottom:.5rem;">Experi&ecirc;ncia na Defensoria P&uacute;blica</h4>
            <p style="color:var(--sv-text-muted);font-size:.82rem;line-height:1.5;">A Dra. Amanda atuou por mais de 7 anos e meio na Defensoria P&uacute;blica do Estado do Rio de Janeiro, adquirindo vasta experi&ecirc;ncia em causas de interesse social, fam&iacute;lia e direitos fundamentais.</p>
        </div>

        <div style="background:var(--sv-bg-card);border:1px solid var(--sv-border);border-radius:12px;padding:1.5rem;text-align:center;">
            <div style="font-size:2rem;margin-bottom:.5rem;">&#128188;</div>
            <h4 style="font-family:'Playfair Display',serif;color:var(--sv-accent);font-size:.95rem;margin-bottom:.5rem;">Voca&ccedil;&atilde;o pela Advocacia</h4>
            <p style="color:var(--sv-text-muted);font-size:.82rem;line-height:1.5;">Por amor &agrave; profiss&atilde;o, a Dra. Amanda pediu exonera&ccedil;&atilde;o de seu cargo p&uacute;blico est&aacute;vel para dedicar-se integralmente &agrave; advocacia privada &mdash; uma decis&atilde;o que reflete comprometimento e confian&ccedil;a em seu trabalho.</p>
        </div>

        <div style="background:var(--sv-bg-card);border:1px solid var(--sv-border);border-radius:12px;padding:1.5rem;text-align:center;">
            <div style="font-size:2rem;margin-bottom:.5rem;">&#129309;</div>
            <h4 style="font-family:'Playfair Display',serif;color:var(--sv-accent);font-size:.95rem;margin-bottom:.5rem;">Atendimento Humanizado</h4>
            <p style="color:var(--sv-text-muted);font-size:.82rem;line-height:1.5;">Cada cliente &eacute; tratado com empatia, respeito e aten&ccedil;&atilde;o individualizada. Acreditamos que por tr&aacute;s de cada processo existe uma hist&oacute;ria de vida que merece ser ouvida e cuidada.</p>
        </div>

        <div style="background:var(--sv-bg-card);border:1px solid var(--sv-border);border-radius:12px;padding:1.5rem;text-align:center;">
            <div style="font-size:2rem;margin-bottom:.5rem;">&#128203;</div>
            <h4 style="font-family:'Playfair Display',serif;color:var(--sv-accent);font-size:.95rem;margin-bottom:.5rem;">Transpar&ecirc;ncia Total</h4>
            <p style="color:var(--sv-text-muted);font-size:.82rem;line-height:1.5;">Atrav&eacute;s da Central VIP, nossos clientes acompanham cada passo do seu processo em tempo real. Sem surpresas, sem jarg&otilde;es &mdash; comunica&ccedil;&atilde;o clara e acess&iacute;vel.</p>
        </div>

        <div style="background:var(--sv-bg-card);border:1px solid var(--sv-border);border-radius:12px;padding:1.5rem;text-align:center;">
            <div style="font-size:2rem;margin-bottom:.5rem;">&#127919;</div>
            <h4 style="font-family:'Playfair Display',serif;color:var(--sv-accent);font-size:.95rem;margin-bottom:.5rem;">Estrat&eacute;gia e Resultados</h4>
            <p style="color:var(--sv-text-muted);font-size:.82rem;line-height:1.5;">Combinamos conhecimento acad&ecirc;mico, experi&ecirc;ncia institucional e dedica&ccedil;&atilde;o para tra&ccedil;ar a melhor estrat&eacute;gia jur&iacute;dica. Cada caso recebe aten&ccedil;&atilde;o personalizada para alcan&ccedil;ar o melhor resultado poss&iacute;vel.</p>
        </div>

    </div>
</div>

<!-- SECTION 4: Advogados Responsáveis -->
<div class="sv-card" style="padding:1.5rem;margin-bottom:1.5rem;">
    <h3 style="font-family:'Playfair Display',serif;color:var(--sv-accent);font-size:1.2rem;margin-bottom:1.2rem;padding-bottom:.5rem;border-bottom:1px solid var(--sv-border);">Advogados Respons&aacute;veis</h3>
    <div class="sobre-advogados-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;">

        <!-- Dra. Amanda -->
        <div style="text-align:center;padding:1.5rem;background:var(--sv-bg-card);border:1px solid var(--sv-border);border-radius:12px;">
            <div style="width:80px;height:80px;border-radius:50%;background:linear-gradient(135deg, var(--sv-accent), rgba(176,141,110,.7));display:flex;align-items:center;justify-content:center;margin:0 auto .8rem;font-family:'Playfair Display',serif;color:#fff;font-size:1.5rem;font-weight:700;">AF</div>
            <h4 style="font-family:'Playfair Display',serif;color:var(--sv-accent);font-size:1.05rem;margin-bottom:.2rem;">Dra. Amanda Guedes Ferreira</h4>
            <p style="color:var(--sv-text-muted);font-size:.78rem;margin-bottom:.3rem;">OAB/RJ 223.389</p>
            <p style="color:var(--sv-accent);font-size:.82rem;font-weight:600;margin-bottom:.6rem;">S&oacute;cia-fundadora</p>
            <p style="color:var(--sv-text-muted);font-size:.82rem;line-height:1.5;">Professora universit&aacute;ria, ex-Defensora P&uacute;blica do Estado do Rio de Janeiro, especialista em Direito de Fam&iacute;lia, Sucess&otilde;es e Previdenci&aacute;rio.</p>
        </div>

        <!-- Dr. Luiz Eduardo -->
        <div style="text-align:center;padding:1.5rem;background:var(--sv-bg-card);border:1px solid var(--sv-border);border-radius:12px;">
            <div style="width:80px;height:80px;border-radius:50%;background:linear-gradient(135deg, var(--sv-accent), rgba(176,141,110,.7));display:flex;align-items:center;justify-content:center;margin:0 auto .8rem;font-family:'Playfair Display',serif;color:#fff;font-size:1.5rem;font-weight:700;">LS</div>
            <h4 style="font-family:'Playfair Display',serif;color:var(--sv-accent);font-size:1.05rem;margin-bottom:.2rem;">Dr. Luiz Eduardo de S&aacute;</h4>
            <p style="color:var(--sv-text-muted);font-size:.78rem;margin-bottom:.3rem;">OAB/RJ 220.807</p>
            <p style="color:var(--sv-accent);font-size:.82rem;font-weight:600;margin-bottom:.6rem;">S&oacute;cio</p>
            <p style="color:var(--sv-text-muted);font-size:.82rem;line-height:1.5;">Advogado com atua&ccedil;&atilde;o estrat&eacute;gica em Direito Civil, Fam&iacute;lia e Sucess&otilde;es.</p>
        </div>

    </div>
</div>

<!-- SECTION 5: Áreas de Atuação -->
<div class="sv-card" style="padding:1.5rem;margin-bottom:1.5rem;">
    <h3 style="font-family:'Playfair Display',serif;color:var(--sv-accent);font-size:1.2rem;margin-bottom:1.2rem;padding-bottom:.5rem;border-bottom:1px solid var(--sv-border);">&Aacute;reas de Atua&ccedil;&atilde;o &mdash; Full Service</h3>
    <div style="text-align:center;padding:.5rem 0;">
        <span style="display:inline-block;padding:6px 14px;background:var(--sv-accent-bg);border:1px solid var(--sv-border);border-radius:20px;color:var(--sv-accent);font-size:.82rem;font-weight:600;margin:4px;">Direito de Fam&iacute;lia</span>
        <span style="display:inline-block;padding:6px 14px;background:var(--sv-accent-bg);border:1px solid var(--sv-border);border-radius:20px;color:var(--sv-accent);font-size:.82rem;font-weight:600;margin:4px;">Direito Previdenci&aacute;rio</span>
        <span style="display:inline-block;padding:6px 14px;background:var(--sv-accent-bg);border:1px solid var(--sv-border);border-radius:20px;color:var(--sv-accent);font-size:.82rem;font-weight:600;margin:4px;">Direito Civil</span>
        <span style="display:inline-block;padding:6px 14px;background:var(--sv-accent-bg);border:1px solid var(--sv-border);border-radius:20px;color:var(--sv-accent);font-size:.82rem;font-weight:600;margin:4px;">Direito do Consumidor</span>
        <span style="display:inline-block;padding:6px 14px;background:var(--sv-accent-bg);border:1px solid var(--sv-border);border-radius:20px;color:var(--sv-accent);font-size:.82rem;font-weight:600;margin:4px;">Direito Imobili&aacute;rio</span>
        <span style="display:inline-block;padding:6px 14px;background:var(--sv-accent-bg);border:1px solid var(--sv-border);border-radius:20px;color:var(--sv-accent);font-size:.82rem;font-weight:600;margin:4px;">Direito Trabalhista</span>
        <span style="display:inline-block;padding:6px 14px;background:var(--sv-accent-bg);border:1px solid var(--sv-border);border-radius:20px;color:var(--sv-accent);font-size:.82rem;font-weight:600;margin:4px;">Direito Sucess&oacute;rio (Invent&aacute;rios)</span>
        <span style="display:inline-block;padding:6px 14px;background:var(--sv-accent-bg);border:1px solid var(--sv-border);border-radius:20px;color:var(--sv-accent);font-size:.82rem;font-weight:600;margin:4px;">Contratos</span>
        <span style="display:inline-block;padding:6px 14px;background:var(--sv-accent-bg);border:1px solid var(--sv-border);border-radius:20px;color:var(--sv-accent);font-size:.82rem;font-weight:600;margin:4px;">Media&ccedil;&atilde;o e Concilia&ccedil;&atilde;o</span>
        <span style="display:inline-block;padding:6px 14px;background:var(--sv-accent-bg);border:1px solid var(--sv-border);border-radius:20px;color:var(--sv-accent);font-size:.82rem;font-weight:600;margin:4px;">Planejamento Patrimonial</span>
    </div>
</div>

<!-- SECTION 6: Instagram -->
<div class="sv-card" style="padding:1.5rem;margin-bottom:1.5rem;">
    <h3 style="font-family:'Playfair Display',serif;color:var(--sv-accent);font-size:1.2rem;margin-bottom:1rem;padding-bottom:.5rem;border-bottom:1px solid var(--sv-border);">Siga-nos no Instagram</h3>
    <div style="display:flex;gap:1rem;overflow-x:auto;padding:1rem 0;scroll-snap-type:x mandatory;">
        <div style="min-width:200px;height:200px;background:var(--sv-accent-bg);border:1px solid var(--sv-border);border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0;scroll-snap-align:start;">
            <div style="text-align:center;color:var(--sv-text-muted);font-size:.8rem;">
                <div style="font-size:2rem;margin-bottom:.3rem;">&#128248;</div>
                Siga @ferreiraesaadvocacia<br>no Instagram
            </div>
        </div>
        <div style="min-width:200px;height:200px;background:var(--sv-accent-bg);border:1px solid var(--sv-border);border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0;scroll-snap-align:start;">
            <div style="text-align:center;color:var(--sv-text-muted);font-size:.8rem;">
                <div style="font-size:2rem;margin-bottom:.3rem;">&#128248;</div>
                Siga @ferreiraesaadvocacia<br>no Instagram
            </div>
        </div>
        <div style="min-width:200px;height:200px;background:var(--sv-accent-bg);border:1px solid var(--sv-border);border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0;scroll-snap-align:start;">
            <div style="text-align:center;color:var(--sv-text-muted);font-size:.8rem;">
                <div style="font-size:2rem;margin-bottom:.3rem;">&#128248;</div>
                Siga @ferreiraesaadvocacia<br>no Instagram
            </div>
        </div>
        <div style="min-width:200px;height:200px;background:var(--sv-accent-bg);border:1px solid var(--sv-border);border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0;scroll-snap-align:start;">
            <div style="text-align:center;color:var(--sv-text-muted);font-size:.8rem;">
                <div style="font-size:2rem;margin-bottom:.3rem;">&#128248;</div>
                Siga @ferreiraesaadvocacia<br>no Instagram
            </div>
        </div>
        <div style="min-width:200px;height:200px;background:var(--sv-accent-bg);border:1px solid var(--sv-border);border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0;scroll-snap-align:start;">
            <div style="text-align:center;color:var(--sv-text-muted);font-size:.8rem;">
                <div style="font-size:2rem;margin-bottom:.3rem;">&#128248;</div>
                Siga @ferreiraesaadvocacia<br>no Instagram
            </div>
        </div>
        <div style="min-width:200px;height:200px;background:var(--sv-accent-bg);border:1px solid var(--sv-border);border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0;scroll-snap-align:start;">
            <div style="text-align:center;color:var(--sv-text-muted);font-size:.8rem;">
                <div style="font-size:2rem;margin-bottom:.3rem;">&#128248;</div>
                Siga @ferreiraesaadvocacia<br>no Instagram
            </div>
        </div>
    </div>
    <div style="text-align:center;margin:1rem 0;">
        <a href="https://www.instagram.com/ferreiraesaadvocacia/" target="_blank" class="sv-btn sv-btn-outline" style="gap:8px;">
            <span style="font-size:1.2rem;">&#128247;</span> Seguir @ferreiraesaadvocacia no Instagram
        </a>
    </div>
</div>

<!-- Responsive Styles -->
<style>
@media (max-width: 900px) {
    .sobre-diferenciais-grid {
        grid-template-columns: repeat(2, 1fr) !important;
    }
}
@media (max-width: 600px) {
    .sobre-contact-grid {
        grid-template-columns: 1fr !important;
    }
    .sobre-diferenciais-grid {
        grid-template-columns: 1fr !important;
    }
    .sobre-advogados-grid {
        grid-template-columns: 1fr !important;
    }
}
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
