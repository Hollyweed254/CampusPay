document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.alert-autodismiss').forEach((el) => {
    window.setTimeout(() => {
      if (window.bootstrap && bootstrap.Alert) {
        bootstrap.Alert.getOrCreateInstance(el).close();
      }
    }, 4000);
  });

  const burger = document.getElementById('cpBurger');
  const sidebar = document.getElementById('cpSidebar');
  if (burger && sidebar) {
    burger.addEventListener('click', () => sidebar.classList.toggle('is-open'));
    document.addEventListener('click', (event) => {
      if (sidebar.classList.contains('is-open') && !sidebar.contains(event.target) && event.target !== burger && !burger.contains(event.target)) {
        sidebar.classList.remove('is-open');
      }
    });
  }

  const publicNav = document.getElementById('cpPublicNav');
  if (publicNav && window.bootstrap) {
    publicNav.querySelectorAll('a.nav-link').forEach((link) => {
      link.addEventListener('click', () => {
        if (publicNav.classList.contains('show')) {
          bootstrap.Collapse.getOrCreateInstance(publicNav).hide();
        }
      });
    });
  }

  document.querySelectorAll('img.cp-photo').forEach((img) => {
    const frame = img.closest('.cp-photo-frame');
    const fallback = frame ? frame.querySelector('.cp-photo-fallback') : null;
    const showFallback = () => {
      const picture = img.closest('picture');
      if (picture) {
        picture.hidden = true;
      }
      if (fallback) {
        fallback.hidden = false;
      }
    };
    img.addEventListener('error', showFallback);
    if (img.complete && img.naturalWidth === 0) {
      showFallback();
    }
  });

  document.querySelectorAll('form[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (event) => {
      if (!window.confirm(form.dataset.confirm || 'Continue?')) {
        event.preventDefault();
      }
    });
  });

  document.querySelectorAll('[data-copy-target]').forEach((button) => {
    button.addEventListener('click', async () => {
      const target = document.querySelector(button.dataset.copyTarget);
      if (!target) return;
      const text = target.value !== undefined ? target.value : target.textContent.trim();
      try {
        await navigator.clipboard.writeText(text);
      } catch (error) {
        if (typeof target.select === 'function') {
          target.select();
          document.execCommand('copy');
        }
      }
      const original = button.innerHTML;
      button.innerHTML = '<i class="bi bi-check2"></i> Copied';
      button.classList.add('is-copied');
      window.setTimeout(() => {
        button.innerHTML = original;
        button.classList.remove('is-copied');
      }, 1600);
    });
  });

  initActivityChart();
});

function initActivityChart() {
  const canvas = document.getElementById('cpActivityChart');
  if (!canvas || !canvas.getContext) {
    return;
  }

  let series = [];
  try {
    series = JSON.parse(canvas.dataset.series || '[]');
  } catch (error) {
    series = [];
  }
  if (!Array.isArray(series) || series.length === 0) {
    return;
  }

  const draw = () => {
    const width = canvas.clientWidth || 720;
    const height = 240;
    const dpr = window.devicePixelRatio || 1;
    canvas.width = Math.round(width * dpr);
    canvas.height = Math.round(height * dpr);

    const ctx = canvas.getContext('2d');
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.clearRect(0, 0, width, height);

    const pad = { top: 16, right: 6, bottom: 26, left: 30 };
    const plotW = width - pad.left - pad.right;
    const plotH = height - pad.top - pad.bottom;
    const counts = series.map((item) => Number(item.count) || 0);
    const peak = Math.max(1, ...counts);
    const step = peak <= 4 ? 1 : Math.ceil(peak / 4);
    const maxY = step * Math.ceil(peak / step);

    ctx.font = '11px Inter, system-ui, sans-serif';
    ctx.textBaseline = 'middle';

    for (let value = 0; value <= maxY; value += step) {
      const y = pad.top + plotH - (value / maxY) * plotH;
      ctx.strokeStyle = value === 0 ? '#D8E0EA' : '#EDF1F6';
      ctx.lineWidth = 1;
      ctx.beginPath();
      ctx.moveTo(pad.left, y + 0.5);
      ctx.lineTo(width - pad.right, y + 0.5);
      ctx.stroke();
      ctx.fillStyle = '#8A99AB';
      ctx.textAlign = 'right';
      ctx.fillText(String(value), pad.left - 6, y);
    }

    const slot = plotW / series.length;
    const barW = Math.max(6, Math.min(28, slot * 0.52));
    const labelEvery = width < 480 ? 3 : 2;

    let lastLabeled = -99;
    series.forEach((item, index) => {
      const count = Number(item.count) || 0;
      const x = pad.left + slot * index + (slot - barW) / 2;
      const h = count === 0 ? 0 : Math.max(3, (count / maxY) * plotH);
      const y = pad.top + plotH - h;
      if (h > 0) {
        ctx.fillStyle = index === series.length - 1 ? '#0E2238' : '#1E9E5A';
        const radius = Math.min(5, barW / 2, h);
        ctx.beginPath();
        if (ctx.roundRect) {
          ctx.roundRect(x, y, barW, h, [radius, radius, 0, 0]);
        } else {
          ctx.rect(x, y, barW, h);
        }
        ctx.fill();
      }
      if (count > 0 && slot > 26) {
        ctx.fillStyle = '#33465C';
        ctx.textAlign = 'center';
        ctx.font = '600 10px Inter, system-ui, sans-serif';
        ctx.fillText(String(count), x + barW / 2, y - 8);
        ctx.font = '11px Inter, system-ui, sans-serif';
      }
      if (index % labelEvery === 0) {
        ctx.fillStyle = '#8A99AB';
        ctx.textAlign = 'center';
        ctx.fillText(String(item.label || ''), x + barW / 2, height - pad.bottom / 2);
        lastLabeled = index;
      }
    });

    const lastIndex = series.length - 1;
    if (lastIndex - lastLabeled >= 2 && slot * (lastIndex - lastLabeled) > 38) {
      const x = pad.left + slot * lastIndex + (slot - barW) / 2;
      ctx.fillStyle = '#8A99AB';
      ctx.textAlign = 'center';
      ctx.fillText(String(series[lastIndex].label || ''), x + barW / 2, height - pad.bottom / 2);
    }
  };

  draw();
  let timer = null;
  window.addEventListener('resize', () => {
    window.clearTimeout(timer);
    timer = window.setTimeout(draw, 120);
  });
}
