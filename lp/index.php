<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ferreira & Sá Advocacia — Especialistas em Direito de Família</title>
    <meta name="description" content="Ferreira & Sá Advocacia — Escritório especializado em Direito de Família, Divórcio, Guarda, Pensão Alimentícia, Inventário. Atendimento humanizado em Resende, Volta Redonda, Barra Mansa, Rio de Janeiro e São Paulo.">
    <meta name="theme-color" content="#052228">
    <link rel="icon" type="image/png" href="../assets/img/logo.png">
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing:border-box; margin:0; padding:0; }
        :root {
            --petrol:#052228; --petrol-light:#173d46; --rose:#d7ab90; --rose-dark:#b8956e;
            --bg:#f8f9fa; --text:#1a1a1a; --text-muted:#6b7280;
        }
        body { font-family:'Open Sans',sans-serif; color:var(--text); background:var(--bg); }
        a { text-decoration:none; color:inherit; }
        img { max-width:100%; }

        /* NAV */
        .nav { position:fixed; top:0; left:0; right:0; z-index:100; background:rgba(5,34,40,.95); backdrop-filter:blur(10px); padding:.75rem 2rem; display:flex; align-items:center; justify-content:space-between; }
        .nav-logo img { height:40px; }
        .nav-links { display:flex; gap:1.5rem; align-items:center; }
        .nav-links a { color:rgba(255,255,255,.8); font-size:.82rem; font-weight:600; transition:color .2s; }
        .nav-links a:hover { color:var(--rose); }
        .nav-cta { background:var(--rose); color:var(--petrol) !important; padding:.5rem 1.25rem; border-radius:100px; font-weight:700 !important; }
        .nav-cta:hover { background:var(--rose-dark) !important; }

        /* HERO */
        .hero { min-height:100vh; background:linear-gradient(135deg, var(--petrol) 0%, var(--petrol-light) 100%); display:flex; align-items:center; justify-content:center; text-align:center; padding:6rem 2rem 4rem; position:relative; overflow:hidden; }
        .hero::after { content:''; position:absolute; bottom:0; left:0; right:0; height:120px; background:linear-gradient(to top, var(--bg), transparent); }
        .hero-content { position:relative; z-index:2; max-width:700px; }
        .hero h1 { font-size:2.8rem; font-weight:800; color:#fff; line-height:1.2; margin-bottom:1rem; }
        .hero h1 span { color:var(--rose); }
        .hero p { font-size:1.1rem; color:rgba(255,255,255,.75); line-height:1.7; margin-bottom:2rem; }
        .hero-btns { display:flex; gap:.75rem; justify-content:center; flex-wrap:wrap; }
        .btn-hero { padding:.85rem 2rem; border-radius:100px; font-weight:700; font-size:.95rem; font-family:inherit; border:none; cursor:pointer; transition:all .3s; display:inline-flex; align-items:center; gap:.5rem; }
        .btn-primary { background:var(--rose); color:var(--petrol); }
        .btn-primary:hover { background:var(--rose-dark); transform:translateY(-2px); box-shadow:0 8px 25px rgba(215,171,144,.4); }
        .btn-outline { background:transparent; color:#fff; border:2px solid rgba(255,255,255,.3); }
        .btn-outline:hover { border-color:var(--rose); color:var(--rose); }

        /* SEÇÕES */
        .section { padding:5rem 2rem; }
        .section-title { text-align:center; margin-bottom:3rem; }
        .section-title h2 { font-size:2rem; font-weight:800; color:var(--petrol); margin-bottom:.5rem; }
        .section-title p { font-size:1rem; color:var(--text-muted); max-width:600px; margin:0 auto; }
        .section-title .line { width:60px; height:4px; background:var(--rose); border-radius:2px; margin:1rem auto 0; }
        .container { max-width:1100px; margin:0 auto; }

        /* ÁREAS */
        .areas-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(240px, 1fr)); gap:1.25rem; }
        .area-card { background:#fff; border-radius:16px; padding:1.75rem; text-align:center; box-shadow:0 2px 15px rgba(0,0,0,.06); transition:all .3s; border-bottom:4px solid transparent; }
        .area-card:hover { transform:translateY(-5px); box-shadow:0 8px 30px rgba(0,0,0,.1); border-bottom-color:var(--rose); }
        .area-icon { font-size:2.5rem; margin-bottom:1rem; }
        .area-card h3 { font-size:.95rem; font-weight:700; color:var(--petrol); margin-bottom:.4rem; }
        .area-card p { font-size:.78rem; color:var(--text-muted); line-height:1.5; }

        /* DIFERENCIAIS */
        .dif-section { background:var(--petrol); color:#fff; }
        .dif-section .section-title h2 { color:#fff; }
        .dif-section .section-title p { color:rgba(255,255,255,.6); }
        .dif-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:1.5rem; }
        .dif-item { text-align:center; padding:1.5rem; }
        .dif-item .num { font-size:2.5rem; font-weight:800; color:var(--rose); }
        .dif-item h4 { font-size:.88rem; font-weight:700; margin:.5rem 0 .3rem; }
        .dif-item p { font-size:.75rem; color:rgba(255,255,255,.6); }

        /* EQUIPE */
        .team-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:1.5rem; }
        .team-card { background:#fff; border-radius:16px; padding:2rem; text-align:center; box-shadow:0 2px 15px rgba(0,0,0,.06); }
        .team-avatar { width:80px; height:80px; border-radius:50%; background:linear-gradient(135deg, var(--petrol), var(--petrol-light)); display:flex; align-items:center; justify-content:center; margin:0 auto 1rem; font-size:1.5rem; color:#fff; font-weight:800; }
        .team-card h3 { font-size:1rem; font-weight:700; color:var(--petrol); }
        .team-card .oab { font-size:.78rem; color:var(--rose-dark); font-weight:600; }
        .team-card p { font-size:.78rem; color:var(--text-muted); margin-top:.5rem; line-height:1.5; }

        /* CONTATO */
        .contact-section { background:linear-gradient(135deg, var(--petrol), var(--petrol-light)); color:#fff; text-align:center; }
        .contact-section .section-title h2 { color:#fff; }
        .contact-section .section-title p { color:rgba(255,255,255,.6); }
        .contact-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:1.5rem; margin-bottom:2rem; }
        .contact-item { background:rgba(255,255,255,.08); border-radius:12px; padding:1.5rem; }
        .contact-item .icon { font-size:1.5rem; margin-bottom:.5rem; }
        .contact-item h4 { font-size:.85rem; font-weight:700; margin-bottom:.25rem; }
        .contact-item p { font-size:.78rem; color:rgba(255,255,255,.7); }
        .contact-item a { color:var(--rose); font-weight:600; }

        /* FOOTER */
        .footer { background:#031518; color:rgba(255,255,255,.5); text-align:center; padding:2rem; font-size:.72rem; }
        .footer a { color:var(--rose); }

        /* WhatsApp flutuante */
        .wpp-float { position:fixed; bottom:1.5rem; right:1.5rem; z-index:200; background:#25D366; color:#fff; width:60px; height:60px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:1.8rem; box-shadow:0 4px 15px rgba(37,211,102,.4); transition:transform .3s; }
        .wpp-float:hover { transform:scale(1.1); }

        /* Responsivo */
        @media(max-width:768px) {
            .hero h1 { font-size:1.8rem; }
            .nav-links { display:none; }
            .section { padding:3rem 1.25rem; }
        }

        /* Scroll suave */
        html { scroll-behavior:smooth; }
    </style>
</head>
<body>

<!-- NAV -->
<nav class="nav">
    <a href="#" class="nav-logo"><img src="../assets/img/logo.png" alt="Ferreira &amp; Sá" onerror="this.outerHTML='<span style=color:#fff;font-weight:800;font-size:1rem>FERREIRA &amp; SÁ</span>'"></a>
    <div class="nav-links">
        <a href="#areas">Áreas</a>
        <a href="#diferenciais">Diferenciais</a>
        <a href="#equipe">Equipe</a>
        <a href="#contato">Contato</a>
        <a href="https://wa.me/5524992050096" target="_blank" class="nav-cta">Fale Conosco</a>
    </div>
</nav>

<!-- HERO -->
<section class="hero">
    <div class="hero-content">
        <h1>Advocacia especializada em <span>Direito de Família</span></h1>
        <p>Atuamos com dedicação, empatia e excelência técnica para proteger o que é mais importante: sua família. Atendimento humanizado em todo o Brasil.</p>
        <div class="hero-btns">
            <a href="https://wa.me/5524992050096?text=Ol%C3%A1%2C%20gostaria%20de%20agendar%20uma%20consulta." class="btn-hero btn-primary">💬 Agendar Consulta</a>
            <a href="#areas" class="btn-hero btn-outline">Conheça Nossas Áreas →</a>
        </div>
    </div>
</section>

<!-- ÁREAS -->
<section class="section" id="areas">
    <div class="container">
        <div class="section-title">
            <h2>Áreas de Atuação</h2>
            <p>Somos especialistas em Direito de Família e atuamos em todas as demandas relacionadas</p>
            <div class="line"></div>
        </div>
        <div class="areas-grid">
            <div class="area-card">
                <div class="area-icon">⚖️</div>
                <h3>Divórcio</h3>
                <p>Consensual ou litigioso, judicial ou extrajudicial. Partilha de bens e guarda dos filhos.</p>
            </div>
            <div class="area-card">
                <div class="area-icon">👶</div>
                <h3>Guarda e Convivência</h3>
                <p>Regulamentação de guarda, visitação e convivência familiar. Defesa dos direitos da criança.</p>
            </div>
            <div class="area-card">
                <div class="area-icon">💰</div>
                <h3>Pensão Alimentícia</h3>
                <p>Fixação, revisão e execução de alimentos. Defesa do alimentante e do alimentado.</p>
            </div>
            <div class="area-card">
                <div class="area-icon">📋</div>
                <h3>Inventário e Partilha</h3>
                <p>Inventário judicial e extrajudicial. Planejamento sucessório e partilha de bens.</p>
            </div>
            <div class="area-card">
                <div class="area-icon">🏠</div>
                <h3>União Estável</h3>
                <p>Reconhecimento, dissolução e efeitos patrimoniais da união estável.</p>
            </div>
            <div class="area-card">
                <div class="area-icon">🛡️</div>
                <h3>Medidas Protetivas</h3>
                <p>Medidas de urgência em situações de violência doméstica e familiar.</p>
            </div>
            <div class="area-card">
                <div class="area-icon">👨‍👩‍👧</div>
                <h3>Adoção</h3>
                <p>Assessoria completa em processos de adoção nacional.</p>
            </div>
            <div class="area-card">
                <div class="area-icon">📝</div>
                <h3>Direito do Consumidor</h3>
                <p>Ações de indenização por danos morais e materiais nas relações de consumo.</p>
            </div>
        </div>
    </div>
</section>

<!-- DIFERENCIAIS -->
<section class="section dif-section" id="diferenciais">
    <div class="container">
        <div class="section-title">
            <h2>Por que nos escolher?</h2>
            <p>Nosso compromisso é com resultados e com o acolhimento do cliente</p>
            <div class="line"></div>
        </div>
        <div class="dif-grid">
            <div class="dif-item">
                <div class="num">5+</div>
                <h4>Cidades Atendidas</h4>
                <p>Resende, Volta Redonda, Barra Mansa, Rio de Janeiro e São Paulo</p>
            </div>
            <div class="dif-item">
                <div class="num">100%</div>
                <h4>Atendimento Digital</h4>
                <p>Consultas online, acompanhamento pelo WhatsApp e documentos digitais</p>
            </div>
            <div class="dif-item">
                <div class="num">24h</div>
                <h4>Retorno Rápido</h4>
                <p>Respondemos todas as consultas em até 24 horas úteis</p>
            </div>
            <div class="dif-item">
                <div class="num">OAB/RJ</div>
                <h4>Registro Profissional</h4>
                <p>Sociedade registrada sob n. 5.987/2023 na OAB/RJ</p>
            </div>
        </div>
    </div>
</section>

<!-- EQUIPE -->
<section class="section" id="equipe">
    <div class="container">
        <div class="section-title">
            <h2>Nossa Equipe</h2>
            <p>Profissionais qualificados e comprometidos com a sua causa</p>
            <div class="line"></div>
        </div>
        <div class="team-grid">
            <div class="team-card">
                <div class="team-avatar">AG</div>
                <h3>Amanda Guedes Ferreira</h3>
                <div class="oab">OAB/RJ 163.260</div>
                <p>Sócia-administradora. Especialista em Direito de Família e Sucessões. Atendimento humanizado e dedicação integral às causas de família.</p>
            </div>
            <div class="team-card">
                <div class="team-avatar">LE</div>
                <h3>Luiz Eduardo de Sá Silva Marcelino</h3>
                <div class="oab">OAB/RJ 248.755</div>
                <p>Sócio-administrador. Atuação estratégica em demandas de família, consumidor e responsabilidade civil.</p>
            </div>
        </div>
    </div>
</section>

<!-- CONTATO -->
<section class="section contact-section" id="contato">
    <div class="container">
        <div class="section-title">
            <h2>Entre em Contato</h2>
            <p>Estamos prontos para atender você</p>
            <div class="line"></div>
        </div>
        <div class="contact-grid">
            <div class="contact-item">
                <div class="icon">📍</div>
                <h4>Escritório Principal</h4>
                <p>Rua Dr. Aldrovando de Oliveira, 140<br>Ano Bom — Barra Mansa/RJ</p>
            </div>
            <div class="contact-item">
                <div class="icon">📱</div>
                <h4>WhatsApp</h4>
                <p><a href="https://wa.me/5524992050096">(24) 99205-0096</a></p>
                <p><a href="https://wa.me/551121105438">(11) 2110-5438</a></p>
            </div>
            <div class="contact-item">
                <div class="icon">✉️</div>
                <h4>E-mail</h4>
                <p><a href="mailto:contato@ferreiraesa.com.br">contato@ferreiraesa.com.br</a></p>
            </div>
            <div class="contact-item">
                <div class="icon">🌐</div>
                <h4>Site</h4>
                <p><a href="https://www.ferreiraesa.com.br">www.ferreiraesa.com.br</a></p>
            </div>
        </div>
        <a href="https://wa.me/5524992050096?text=Ol%C3%A1%2C%20gostaria%20de%20agendar%20uma%20consulta." class="btn-hero btn-primary" style="font-size:1rem;">💬 Falar com um Advogado</a>
    </div>
</section>

<!-- FOOTER -->
<footer class="footer">
    <p>&copy; <?= date('Y') ?> Ferreira &amp; Sá Advocacia — CNPJ 51.294.223/0001-40 — OAB/RJ 5.987/2023</p>
    <p style="margin-top:.25rem;">Resende · Volta Redonda · Barra Mansa · Rio de Janeiro · São Paulo</p>
</footer>

<!-- WhatsApp Flutuante -->
<a href="https://wa.me/5524992050096?text=Ol%C3%A1%2C%20vim%20pelo%20site%20e%20gostaria%20de%20mais%20informa%C3%A7%C3%B5es." target="_blank" class="wpp-float" aria-label="WhatsApp">
    <svg width="30" height="30" viewBox="0 0 24 24" fill="#fff"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
</a>

</body>
</html>
