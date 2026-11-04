(() => {
  'use strict';

  // Relógio local calculado a partir do mesmo timestamp registrado no PostgreSQL.
  const timer = document.querySelector('[data-countdown]');
  if (timer) {
    const startedAt = Number(timer.dataset.startedAt || 0);
    const duration = Number(timer.dataset.duration || 0);
    const serverNow = Number(timer.dataset.serverNow || 0);
    const clientAtRender = Date.now() / 1000;
    const display = timer.querySelector('[data-time-display]');
    let timeoutReloadScheduled = false;
    const update = () => {
      const serverNowEstimated = serverNow + (Date.now() / 1000 - clientAtRender);
      const remaining = Math.max(0, Math.ceil(startedAt + duration - serverNowEstimated));
      const minutes = String(Math.floor(remaining / 60)).padStart(2, '0');
      const seconds = String(remaining % 60).padStart(2, '0');
      if (display) display.textContent = `${minutes}:${seconds}`;
      timer.classList.toggle('timer-warning', remaining <= 60);
      if (remaining <= 0 && display) {
        display.setAttribute('aria-label', 'Tempo esgotado. A partida será atualizada.');
        if (!timeoutReloadScheduled) {
          timeoutReloadScheduled = true;
          window.setTimeout(() => window.location.reload(), 1200);
        }
      }
    };
    update();
    window.setInterval(update, 1000);
  }

  // A lanterna é apenas uma máscara visual. O conteúdo continua acessível a leitores de tela.
  const stage = document.querySelector('[data-lantern-stage]');
  const content = document.querySelector('[data-lantern-content]');
  if (stage && content) {
    const setLight = (clientX, clientY) => {
      const rect = stage.getBoundingClientRect();
      const x = Math.max(0, Math.min(rect.width, clientX - rect.left));
      const y = Math.max(0, Math.min(rect.height, clientY - rect.top));
      content.style.setProperty('--light-x', `${x}px`);
      content.style.setProperty('--light-y', `${y}px`);
    };

    stage.addEventListener('pointermove', (event) => setLight(event.clientX, event.clientY));
    stage.addEventListener('pointerdown', (event) => setLight(event.clientX, event.clientY));
    stage.addEventListener('focusin', (event) => {
      const target = event.target.closest('[data-light-target]') || event.target;
      const rect = target.getBoundingClientRect();
      setLight(rect.left + rect.width / 2, rect.top + rect.height / 2);
    });

    const toggle = document.querySelector('[data-accessible-toggle]');
    if (toggle) {
      toggle.addEventListener('click', () => {
        const enabled = stage.classList.toggle('read-mode');
        toggle.textContent = enabled ? 'Desativar modo leitura' : 'Modo leitura acessível';
        toggle.setAttribute('aria-pressed', enabled ? 'true' : 'false');
      });
    }

    // Controle opcional da lanterna pelo teclado para exploração sem mouse.
    let keyboardX = 0.5;
    let keyboardY = 0.5;
    stage.addEventListener('keydown', (event) => {
      const step = 36;
      const rect = stage.getBoundingClientRect();
      let handled = true;
      if (event.key === 'ArrowLeft') keyboardX -= step / rect.width;
      else if (event.key === 'ArrowRight') keyboardX += step / rect.width;
      else if (event.key === 'ArrowUp') keyboardY -= step / rect.height;
      else if (event.key === 'ArrowDown') keyboardY += step / rect.height;
      else handled = false;
      if (!handled) return;
      event.preventDefault();
      keyboardX = Math.max(0.02, Math.min(0.98, keyboardX));
      keyboardY = Math.max(0.02, Math.min(0.98, keyboardY));
      setLight(rect.left + rect.width * keyboardX, rect.top + rect.height * keyboardY);
    });
  }

  // No Render, acompanha mudanças do lobby e da partida sem exigir F5.
  // A assinatura vem do banco; a cada mudança, a tela recarrega com o estado oficial.
  const syncMarker = document.querySelector('[data-sync-stamp]');
  if (syncMarker) {
    const initialStamp = syncMarker.dataset.syncStamp || '';
    let requestInFlight = false;
    const syncRoom = async () => {
      if (requestInFlight || document.hidden) return;
      requestInFlight = true;
      try {
        const endpoint = new URL(window.location.href);
        endpoint.searchParams.set('sync', '1');
        const response = await fetch(endpoint.toString(), {
          method: 'GET',
          cache: 'no-store',
          credentials: 'same-origin',
          headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        });
        if (!response.ok) return;
        const state = await response.json();
        if (state.stamp && state.stamp !== initialStamp) {
          window.location.reload();
        }
      } catch (error) {
        // Uma falha temporária de rede não interrompe a partida nem o cronômetro local.
      } finally {
        requestInFlight = false;
      }
    };
    window.setInterval(syncRoom, 3500);
  }

  // Normaliza o código de sala sem depender de bibliotecas externas.
  document.querySelectorAll('.room-code-input').forEach((input) => {
    input.addEventListener('input', () => {
      input.value = input.value.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 6);
    });
  });
})();
