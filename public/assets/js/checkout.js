(() => {
  'use strict';

  const root = document.getElementById('cpCheckout');
  if (!root) return;

  const statusUrl = root.dataset.statusUrl;
  const pollEnabled = root.dataset.poll === '1';
  const currentStatus = root.dataset.status || '';
  const chip = document.getElementById('cpCheckoutChip');
  const messageEl = document.getElementById('cpCheckoutMessage');
  const pollNote = document.getElementById('cpPollNote');
  let checking = false;

  const checkStatus = async () => {
    if (!statusUrl || checking) return;
    checking = true;
    try {
      const response = await fetch(statusUrl, { headers: { Accept: 'application/json' }, cache: 'no-store' });
      if (!response.ok) throw new Error('HTTP ' + response.status);
      const data = await response.json();
      if (!data.ok) throw new Error(data.message || 'Status unavailable');
      if (data.status !== currentStatus || !data.poll) {
        window.location.reload();
        return;
      }
      if (messageEl && data.message) messageEl.textContent = data.message;
      if (chip && data.label) chip.textContent = data.label;
      if (pollNote) {
        pollNote.textContent = 'Checked ' + new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        pollNote.classList.remove('is-error');
      }
    } catch (error) {
      if (pollNote && !pollNote.dataset.original) {
        pollNote.dataset.original = pollNote.textContent || '';
      }
      if (pollNote) {
        pollNote.textContent = 'Could not check status — retrying…';
        pollNote.classList.add('is-error');
      }
    } finally {
      checking = false;
    }
  };

  if (pollEnabled && statusUrl) {
    window.setInterval(checkStatus, 6000);
  }
  document.querySelectorAll('[data-check-now]').forEach((button) => {
    button.addEventListener('click', checkStatus);
  });

  const countdown = document.getElementById('cpCountdown');
  if (countdown && countdown.dataset.expires) {
    const target = new Date(countdown.dataset.expires.replace(' ', 'T') + 'Z').getTime();
    let done = false;
    const tick = () => {
      const left = target - Date.now();
      if (left <= 0) {
        if (done) return;
        done = true;
        countdown.textContent = 'Expired — refreshing…';
        window.setTimeout(() => window.location.reload(), 1200);
        return;
      }
      const minutes = Math.floor(left / 60000);
      const seconds = Math.floor((left % 60000) / 1000);
      countdown.textContent = 'Expires in ' + minutes + ':' + String(seconds).padStart(2, '0');
    };
    tick();
    window.setInterval(tick, 1000);
  }

  document.querySelectorAll('form[data-pay-form]').forEach((form) => {
    form.addEventListener('submit', () => {
      const button = form.querySelector('button[type="submit"]');
      if (!button || button.disabled) return;
      button.disabled = true;
      button.innerHTML = '<span class="cp-spinner"></span> Sending prompt…';
    });
  });
})();
