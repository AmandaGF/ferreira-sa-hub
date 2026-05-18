(() => {
  const WHATS_NUMBER = "5521998626615"; // altere se precisar (DDI+DDD+numero)
  const WHATS_MSG = encodeURIComponent("Olá! Vim pela página de Curatela e quero orientação sobre documentos e próximos passos.");
  const whatsLink = `https://wa.me/${WHATS_NUMBER}?text=${WHATS_MSG}`;

  // Apply WhatsApp links
  ["whatsHero","whatsCta","whatsFooter","whatsFab"].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.href = whatsLink;
  });

  // Footer year
  const y = document.getElementById("year");
  if (y) y.textContent = new Date().getFullYear();

  // ===== Menu mobile (hambúrguer) =====
  const hamburger = document.getElementById("hamburger");
  const siteNav = document.getElementById("siteNav");
  const setMenu = (open) => {
    if (!hamburger || !siteNav) return;
    hamburger.setAttribute("aria-expanded", open ? "true" : "false");
    siteNav.classList.toggle("open", open);
  };
  if (hamburger && siteNav) {
    hamburger.addEventListener("click", () => {
      setMenu(siteNav.classList.contains("open") ? false : true);
    });
    // Fecha ao clicar num link do menu
    siteNav.querySelectorAll("a").forEach(a => a.addEventListener("click", () => setMenu(false)));
    // Fecha ao tocar fora
    document.addEventListener("click", (e) => {
      if (!siteNav.classList.contains("open")) return;
      if (siteNav.contains(e.target) || hamburger.contains(e.target)) return;
      setMenu(false);
    });
    // Fecha no Esc
    document.addEventListener("keydown", (e) => { if (e.key === "Escape") setMenu(false); });
  }

  // Checklist persistence
  const STORAGE_KEY = "curatela_checklist_v2";
  const boxes = Array.from(document.querySelectorAll('input[type="checkbox"][data-key]'));
  const progressText = document.getElementById("progressText");
  const progressBar = document.getElementById("progressBar");
  const resetBtn = document.getElementById("resetChecklist");

  const loadState = () => {
    try { return JSON.parse(localStorage.getItem(STORAGE_KEY) || "{}"); }
    catch { return {}; }
  };

  const saveState = (state) => {
    try { localStorage.setItem(STORAGE_KEY, JSON.stringify(state)); } catch {}
  };

  const updateProgress = () => {
    const total = boxes.length || 1;
    const done = boxes.filter(b => b.checked).length;
    const pct = Math.round((done / total) * 100);
    if (progressText) progressText.textContent = `${pct}% concluído`;
    if (progressBar) progressBar.style.width = `${pct}%`;
  };

  const state = loadState();
  boxes.forEach(b => {
    const key = b.dataset.key;
    if (key && typeof state[key] === "boolean") b.checked = state[key];
    b.addEventListener("change", () => {
      state[key] = b.checked;
      saveState(state);
      updateProgress();
    });
  });
  updateProgress();

  if (resetBtn) {
    resetBtn.addEventListener("click", () => {
      boxes.forEach(b => (b.checked = false));
      boxes.forEach(b => (state[b.dataset.key] = false));
      saveState(state);
      updateProgress();
    });
  }

  // Guide carousel thumbnails
  const pages = [
    "assets/page-01.png","assets/page-02.png","assets/page-03.png","assets/page-04.png","assets/page-05.png",
    "assets/page-06.png","assets/page-07.png","assets/page-08.png","assets/page-09.png","assets/page-10.png","assets/page-11.png"
  ];

  const carousel = document.getElementById("guideCarousel");
  if (carousel) {
    pages.forEach((src, idx) => {
      const btn = document.createElement("button");
      btn.className = "thumb";
      btn.type = "button";
      btn.setAttribute("aria-label", `Abrir página ${idx + 1}`);
      btn.innerHTML = `
        <img src="${src}" alt="Prévia da página ${idx + 1}" loading="lazy" />
        <div class="cap">Página ${idx + 1}</div>
      `;
      btn.addEventListener("click", () => openModal(idx));
      carousel.appendChild(btn);
    });
  }

  // Setas do carrossel (desktop)
  const carPrev = document.getElementById("carPrev");
  const carNext = document.getElementById("carNext");
  const scrollCarousel = (dir) => {
    if (!carousel) return;
    carousel.scrollBy({ left: dir * Math.max(320, carousel.clientWidth * 0.8), behavior: "smooth" });
  };
  if (carPrev) carPrev.addEventListener("click", () => scrollCarousel(-1));
  if (carNext) carNext.addEventListener("click", () => scrollCarousel(1));

  // Modal viewer
  const modal = document.getElementById("modal");
  const modalImg = document.getElementById("modalImg");
  const modalCount = document.getElementById("modalCount");
  const modalPrev = document.getElementById("modalPrev");
  const modalNext = document.getElementById("modalNext");
  let currentIndex = 0;

  const setModalImage = (i) => {
    currentIndex = (i + pages.length) % pages.length;
    if (modalImg) modalImg.src = pages[currentIndex];
    if (modalCount) modalCount.textContent = `Página ${currentIndex + 1}/${pages.length}`;
  };

  const openModal = (i) => {
    if (!modal) return;
    modal.setAttribute("aria-hidden", "false");
    document.body.style.overflow = "hidden";
    setModalImage(i);
  };

  const closeModal = () => {
    if (!modal) return;
    modal.setAttribute("aria-hidden", "true");
    document.body.style.overflow = "";
  };

  if (modalPrev) modalPrev.addEventListener("click", () => setModalImage(currentIndex - 1));
  if (modalNext) modalNext.addEventListener("click", () => setModalImage(currentIndex + 1));

  if (modal) {
    modal.addEventListener("click", (e) => {
      const t = e.target;
      if (t && t.dataset && t.dataset.close) closeModal();
    });
  }

  document.addEventListener("keydown", (e) => {
    if (!modal || modal.getAttribute("aria-hidden") !== "false") return;
    if (e.key === "Escape") closeModal();
    if (e.key === "ArrowLeft") setModalImage(currentIndex - 1);
    if (e.key === "ArrowRight") setModalImage(currentIndex + 1);
  });

})();
