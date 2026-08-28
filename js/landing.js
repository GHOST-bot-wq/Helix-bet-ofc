// ─── Landing Page ─────────────────────────────────────────────────────────────
function renderLanding(el) {
  el.innerHTML = `
    <!-- ── Navbar ────────────────────────────────────────────────────── -->
    <nav class="lnd-nav">
      <div class="lnd-nav-brand brand-logo-wrap" onclick="navigate('#landing')">
        <img class="brand-logo-img" src="" alt="logo" style="display:none;height:50px;object-fit:contain"/>
        <div class="brand-logo-icon" style="font-size:22px;margin-right:8px">🌀</div>
        <span class="brand-name">HelixWin</span>
      </div>
      <div class="lnd-nav-menu">
        <button class="lnd-nav-link" onclick="navigate('#login')">Entrar</button>
        <button class="lnd-cta-sm" onclick="navigate('#cadastro')">Cadastrar</button>
      </div>
    </nav>

    <!-- ── Hero ──────────────────────────────────────────────────────── -->
    <div class="lnd-hero">
      <canvas id="lnd-particles" aria-hidden="true"></canvas>

      <div class="lnd-hero-main-wrap">
        <div class="lnd-hero-inner">
          <!-- Coluna Esquerda: Copy & CTA -->
          <div class="lnd-hero-left anim-slide">
            <div class="lnd-live-badge">
              <span class="lnd-badge-icon">⚡</span>
              <span>ENTRE NO JOGO</span>
            </div>

            <h1 class="lnd-title">
              DINHEIRO<br><span class="yellow-text">FÁCIL</span>
            </h1>

            <div class="lnd-sub-headline">
              JOGUE. <span class="yellow-text">GANHE.</span> SAQUE.
            </div>

            <p class="lnd-sub">
              Faça sua jogada no <span class="purple-text">Helix Jump</span><br>
              e, se ganhar, saque via <span class="green-text">PIX.</span>
            </p>

            <div class="lnd-actions-block">
              <button class="lnd-cta-btn" id="btn-jogar-gratis" onclick="iniciarDemoJogo()">
                <svg viewBox="0 0 24 24" fill="currentColor" width="22" height="22"><polygon points="6 3 20 12 6 21 6 3"/></svg>
                <span id="btn-jogar-label">JOGAR AGORA</span>
              </button>
              
              <div class="lnd-microcopy">
                <span class="micro-item"><span class="micro-diamond">❖</span> Depósito via <strong>PIX</strong></span>
                <span class="micro-sep">|</span>
                <span class="micro-item"><span class="micro-diamond">❖</span> Saque via <strong>PIX</strong></span>
              </div>
            </div>
          </div>

          <!-- Coluna Direita: Widget ÚLTIMOS GANHOS -->
          <div class="lnd-hero-right anim-slide">
            <div class="lnd-wins-widget">
              <div class="lnd-wins-header">
                <span class="wins-trophy">🏆</span>
                <span>ÚLTIMOS GANHOS</span>
              </div>
              <div class="lnd-wins-list">
                <div class="lnd-win-item">
                  <div class="lnd-win-avatar av-purple">C</div>
                  <div class="lnd-win-details">
                    <span class="lnd-win-user">Carlos ganhou</span>
                    <span class="lnd-win-val">R$ 70,00</span>
                    <span class="lnd-win-time">há 2 minutos</span>
                  </div>
                  <div class="lnd-win-star">★</div>
                </div>
                <div class="lnd-win-item">
                  <div class="lnd-win-avatar av-blue">A</div>
                  <div class="lnd-win-details">
                    <span class="lnd-win-user">Ana ganhou</span>
                    <span class="lnd-win-val">R$ 45,00</span>
                    <span class="lnd-win-time">há 5 minutos</span>
                  </div>
                  <div class="lnd-win-star">★</div>
                </div>
                <div class="lnd-win-item">
                  <div class="lnd-win-avatar av-orange">L</div>
                  <div class="lnd-win-details">
                    <span class="lnd-win-user">Lucas ganhou</span>
                    <span class="lnd-win-val">R$ 14,00</span>
                    <span class="lnd-win-time">há 8 minutos</span>
                  </div>
                  <div class="lnd-win-star">★</div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Barra Inferior de Benefícios e Segurança (4 cards) -->
        <div class="lnd-bottom-bar">
          <div class="lnd-bottom-card">
            <div class="lnd-bcard-icon icon-purple">
              <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
            </div>
            <div class="lnd-bcard-text">
              <div class="bcard-title">COMECE AGORA</div>
              <div class="bcard-desc">Rápido e simples</div>
            </div>
          </div>

          <div class="lnd-bottom-card">
            <div class="lnd-bcard-icon icon-purple">
              <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
            </div>
            <div class="lnd-bcard-text">
              <div class="bcard-title">DEPÓSITO VIA <span class="green-text">PIX</span></div>
              <div class="bcard-desc">Liberação imediata</div>
            </div>
          </div>

          <div class="lnd-bottom-card">
            <div class="lnd-bcard-icon icon-green">
              <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
            </div>
            <div class="lnd-bcard-text">
              <div class="bcard-title">SAQUE VIA <span class="green-text">PIX</span></div>
              <div class="bcard-desc">Quando quiser</div>
            </div>
          </div>

          <div class="lnd-bottom-card lnd-bcard-security">
            <div class="lnd-bcard-icon icon-purple">
              <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            </div>
            <div class="lnd-bcard-text">
              <div class="bcard-title">Ambiente seguro, rápido e justo.</div>
              <div class="bcard-desc">Seu jogo. Suas regras. <span class="yellow-text" style="font-weight:800;">Seus ganhos.</span></div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ── Stats ──────────────────────────────────────────────────────── -->
    <div class="lnd-stats">
      <div class="lnd-stat">
        <div class="lnd-stat-val" id="stat-online">0</div>
        <div class="lnd-stat-lbl">JOGADORES NA ARENA</div>
      </div>
      <div class="lnd-stat">
        <div class="lnd-stat-val" id="stat-pago">R$ 0</div>
        <div class="lnd-stat-lbl">PRÊMIOS HOJE</div>
      </div>
      <div class="lnd-stat">
        <div class="lnd-stat-val" id="stat-maior">R$ 0</div>
        <div class="lnd-stat-lbl">MAIOR PRÊMIO HOJE</div>
      </div>
    </div>

    <!-- ── Como funciona ──────────────────────────────────────────────── -->
    <section class="lnd-how">
      <div class="lnd-container">
        <div class="lnd-section-head">
          <h2>Como funciona?</h2>
          <p>Simples, rápido e transparente</p>
        </div>
        <div class="lnd-how-grid">
          <div class="lnd-how-card anim-slide" style="animation-delay:.1s">
            <div class="lnd-how-num">01</div>
            <div class="lnd-how-icon">
              <svg viewBox="0 0 24 24" fill="none" stroke="#e8c547" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="28" height="28">
                <line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>
              </svg>
            </div>
            <h3>Defina sua aposta</h3>
            <p>Escolha de R$1,00 a R$100,00 e decida o quanto quer arriscar por partida.</p>
          </div>
          <div class="lnd-how-card anim-slide" style="animation-delay:.2s">
            <div class="lnd-how-num">02</div>
            <div class="lnd-how-icon">
              <svg viewBox="0 0 24 24" fill="none" stroke="#e8c547" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="28" height="28">
                <circle cx="12" cy="12" r="10"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/><line x1="2" y1="12" x2="22" y2="12"/>
              </svg>
            </div>
            <h3>Jogue Helix Jump</h3>
            <p>Gire a hélice e guie a bolinha. Evite as peças pretas e alcance 14 plataformas.</p>
          </div>
          <div class="lnd-how-card anim-slide" style="animation-delay:.3s">
            <div class="lnd-how-num">03</div>
            <div class="lnd-how-icon">
              <svg viewBox="0 0 24 24" fill="none" stroke="#00C97A" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="28" height="28">
                <polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/>
              </svg>
            </div>
            <h3>Ganhe 7x</h3>
            <p>Alcance a meta e receba 7x o valor apostado no seu saldo. Saque via PIX na hora.</p>
          </div>
        </div>
      </div>
    </section>

    <!-- ── Depoimentos ────────────────────────────────────────────────── -->
    <section class="lnd-test">
      <div class="lnd-container">
        <div class="lnd-section-head">
          <h2>O que dizem nossos jogadores</h2>
          <p>Resultados reais de jogadores reais</p>
        </div>
        <div class="lnd-test-grid">
          <div class="lnd-test-card anim-slide">
            <div class="lnd-test-stars">★★★★★</div>
            <p class="lnd-test-text">"Comecei com R$10 e em 3 partidas já tinha R$70. O jogo é justo e o PIX cai na hora. Recomendo muito!"</p>
            <div class="lnd-test-author">
              <div class="lnd-test-avatar" style="background:linear-gradient(135deg,#e8c547,#FF8CC8)">A</div>
              <div>
                <div class="lnd-test-name">Ana Paula S.</div>
                <div class="lnd-test-since">Jogadora desde janeiro</div>
              </div>
            </div>
          </div>
          <div class="lnd-test-card anim-slide" style="animation-delay:.15s">
            <div class="lnd-test-stars">★★★★★</div>
            <p class="lnd-test-text">"Sou viciado em Helix Jump de qualquer forma, agora ainda ganho dinheiro jogando. Já saquei mais de R$300 esse mês!"</p>
            <div class="lnd-test-author">
              <div class="lnd-test-avatar" style="background:linear-gradient(135deg,#4D9EFF,#00C97A)">C</div>
              <div>
                <div class="lnd-test-name">Carlos M.</div>
                <div class="lnd-test-since">Jogador profissional</div>
              </div>
            </div>
          </div>
          <div class="lnd-test-card anim-slide" style="animation-delay:.3s">
            <div class="lnd-test-stars">★★★★★</div>
            <p class="lnd-test-text">"Sistema de indicação é incrível. Já ganhei R$20 só indicando amigos e nem precisei jogar. Transparente e confiável."</p>
            <div class="lnd-test-author">
              <div class="lnd-test-avatar" style="background:linear-gradient(135deg,#FFB800,#FF8C42)">L</div>
              <div>
                <div class="lnd-test-name">Lucas R.</div>
                <div class="lnd-test-since">4 amigos indicados</div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- ── CTA final ──────────────────────────────────────────────────── -->
    <section class="lnd-cta-sec">
      <div class="lnd-orbs" aria-hidden="true">
        <div class="lnd-orb" style="width:380px;height:380px;top:-100px;right:8%;background:rgba(168,85,247,0.20)"></div>
        <div class="lnd-orb" style="width:250px;height:250px;bottom:-60px;left:5%;background:rgba(255,107,157,0.14);animation-delay:3s"></div>
      </div>
      <h2>Pronto para girar e ganhar?</h2>
      <p>Crie sua conta grátis, deposite a partir de R$10 e comece a ganhar agora.</p>
      <button class="lnd-cta-btn" onclick="navigate('#cadastro')">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" width="20" height="20">
          <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/>
          <line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/>
        </svg>
        CRIAR CONTA GRÁTIS
      </button>
    </section>

    <!-- ── Footer ─────────────────────────────────────────────────────── -->
    <footer class="lnd-footer">
      <div class="lnd-nav-brand brand-logo-wrap" style="display:flex; justify-content:center; align-items:center;">
    <img class="brand-logo-img" src="" alt="logo" 
    style="display:block; height:50px; object-fit:contain; margin:0 auto; margin-top:-40px;">
</div>
      <div class="lnd-footer-links">
        <a href="#" onclick="navigate('#landing');return false">Início</a>
        <a href="#" onclick="navigate('#cadastro');return false">Cadastrar</a>
        <a href="#" onclick="navigate('#login');return false">Entrar</a>
        <a href="#">Termos de uso</a>
        <a href="#" data-suporte-href>Suporte</a>
      </div>
      <div class="lnd-footer-warning">
        ⚠️ Jogo de entretenimento com apostas. Jogue com responsabilidade.
        Proibido para menores de 18 anos. Se sentir que o jogo está afetando sua vida,
        procure ajuda em <strong>jrc.org.br</strong>.
      </div>
      <p class="lnd-footer-copy">© 2026 <span class="brand-name"></span>. Todos os direitos reservados. — Sistema desenvolvido por <strong style="color:rgba(255,255,255,.45)">@waveplay_igaming</strong></p>
    </footer>
  `;

  // Animar stats com números simulados
  setTimeout(() => {
    const elOnline = document.getElementById('stat-online');
    const elPago   = document.getElementById('stat-pago');
    const elMaior  = document.getElementById('stat-maior');
    if (elOnline) animateNumber(elOnline, 0, 1847 + Math.floor(Math.random() * 200));
    if (elPago)   animateNumber(elPago,   0, 8420, 1400, v => 'R$ ' + Math.round(v).toLocaleString('pt-BR'));
    if (elMaior)  animateNumber(elMaior,  0, 700,  1000, v => 'R$ ' + Math.round(v).toLocaleString('pt-BR'));
  }, 300);

  // Preencher ref via URL
  const urlParams = new URLSearchParams(window.location.search);
  const ref = urlParams.get('ref');
  if (ref) sessionStorage.setItem('ref_code', ref);

  // ── Carregar config pública e aplicar regras ──────────────────────────────
  fetch('/api/public/config?_=' + Date.now())
    .then(r => r.json())
    .catch(() => ({}))
    .then(cfg => {
      // Nome da plataforma
      const nome = cfg.site_nome || 'HelixWin';
      document.querySelectorAll('.brand-name').forEach(el => el.textContent = nome);
      document.title = nome + ' - Gire e ganhe333';

      // Depósito mínimo
      const depMin = document.getElementById('lnd-dep-min');
      if (depMin && cfg.deposito_minimo !== undefined) {
        depMin.textContent = 'R$' + parseFloat(cfg.deposito_minimo)
          .toLocaleString('pt-BR', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
      }

      // Registro fechado → esconde botões de cadastro
      if (cfg.registro_aberto === false || cfg.registro_aberto === '0') {
        document.querySelectorAll('[onclick*="cadastro"], [onclick*="register"]').forEach(el => {
          el.style.display = 'none';
        });
      }

      // Modo manutenção → exibe overlay
      if (cfg.manutencao === true || cfg.manutencao === '1') {
        if (typeof showManutencao === 'function') {
          showManutencao(cfg.site_nome || 'HelixWin', '');
        }
      }
    });

  // ── Função para Iniciar Jogo Demo ──────────────────────────────────────────
  function iniciarDemoJogo() {
    sessionStorage.setItem('partida_atual', JSON.stringify({
      partida_id:           'demo',
      valor_entrada:        5,
      valor_meta:           20,
      valor_por_plataforma: 1,
      dificuldade:          'demo',
      modo_demo:            true,
    }));
    navigate('#jogo');
  }
  window.iniciarDemoJogo = iniciarDemoJogo;

  const btnJogar = document.getElementById('btn-jogar-gratis');
  if (btnJogar) {
    btnJogar.onclick = iniciarDemoJogo;
  }

  // ── Partículas de bolinhas no fundo ────────────────────────────────────────
  (function initParticles() {
    const canvas = document.getElementById('lnd-particles');
    if (!canvas) return;
    const ctx = canvas.getContext('2d');

    const COLORS = [
      'rgba(255,210,0,VAL)',
      'rgba(0,220,130,VAL)',
      'rgba(80,160,255,VAL)',
      'rgba(255,100,180,VAL)',
      'rgba(255,255,255,VAL)',
    ];

    let W, H, particles = [];

    function resize() {
      const hero = canvas.parentElement;
      W = canvas.width  = hero.offsetWidth;
      H = canvas.height = hero.offsetHeight;
    }

    function randomBetween(a, b) { return a + Math.random() * (b - a); }

    function createParticle() {
      const color = COLORS[Math.floor(Math.random() * COLORS.length)];
      const radius = randomBetween(2, 7);
      return {
        x:       randomBetween(0, W),
        y:       randomBetween(0, H),
        r:       radius,
        baseR:   radius,
        vx:      randomBetween(-0.35, 0.35),
        vy:      randomBetween(-0.6, -0.15),
        alpha:   randomBetween(0.15, 0.55),
        alphaDir: Math.random() > 0.5 ? 1 : -1,
        color,
        pulse:   randomBetween(0, Math.PI * 2),
        pulseSpd: randomBetween(0.01, 0.03),
        glowing: Math.random() > 0.6,
      };
    }

    function init() {
      resize();
      const count = Math.floor((W * H) / 9000);
      particles = Array.from({ length: Math.min(count, 90) }, createParticle);
    }

    function draw() {
      ctx.clearRect(0, 0, W, H);

      for (const p of particles) {
        // Movimento
        p.x += p.vx;
        p.y += p.vy;
        p.pulse += p.pulseSpd;

        // Pulso de tamanho
        p.r = p.baseR + Math.sin(p.pulse) * (p.baseR * 0.35);

        // Pulso de alpha
        p.alpha += p.alphaDir * 0.003;
        if (p.alpha >= 0.58 || p.alpha <= 0.08) p.alphaDir *= -1;

        // Recicla quando sai da tela
        if (p.y < -p.r * 2) { p.y = H + p.r; p.x = randomBetween(0, W); }
        if (p.x < -p.r * 2) p.x = W + p.r;
        if (p.x > W + p.r * 2) p.x = -p.r;

        const fillColor = p.color.replace('VAL', p.alpha.toFixed(2));

        ctx.beginPath();
        ctx.arc(p.x, p.y, p.r, 0, Math.PI * 2);

        if (p.glowing) {
          // Brilho externo
          const grd = ctx.createRadialGradient(p.x, p.y, 0, p.x, p.y, p.r * 3.2);
          const glowColor = p.color.replace('VAL', (p.alpha * 0.35).toFixed(2));
          const transparent = p.color.replace('VAL', '0');
          grd.addColorStop(0, fillColor);
          grd.addColorStop(0.4, glowColor);
          grd.addColorStop(1, transparent);
          ctx.shadowBlur = 0;
          ctx.fillStyle = fillColor;
          ctx.fill();
          // Halo
          ctx.beginPath();
          ctx.arc(p.x, p.y, p.r * 3.2, 0, Math.PI * 2);
          ctx.fillStyle = grd;
          ctx.fill();
        } else {
          ctx.fillStyle = fillColor;
          ctx.fill();
        }

        // Reflexo interno
        ctx.beginPath();
        ctx.arc(p.x - p.r * 0.28, p.y - p.r * 0.28, p.r * 0.38, 0, Math.PI * 2);
        ctx.fillStyle = `rgba(255,255,255,${(p.alpha * 0.5).toFixed(2)})`;
        ctx.fill();
      }

      requestAnimationFrame(draw);
    }

    window.addEventListener('resize', () => { resize(); });
    init();
    draw();
  })();
}
