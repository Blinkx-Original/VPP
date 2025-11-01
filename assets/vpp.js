(() => {
  const boot = () => {
    const carousels = document.querySelectorAll('[data-vpp-carousel]');
    if (!carousels.length) {
      return;
    }
    carousels.forEach((root) => {
      const frame = root.querySelector('[data-vpp-carousel-frame]');
      if (!frame) {
        return;
      }
      const items = Array.from(frame.querySelectorAll('[data-vpp-carousel-item]'));
      if (!items.length) {
        return;
      }
      let activeIndex = Math.max(0, items.findIndex((img) => img.classList.contains('is-active')));
      const prev = root.querySelector('[data-vpp-carousel-prev]');
      const next = root.querySelector('[data-vpp-carousel-next]');
      const thumbCandidates = root.parentElement ? root.parentElement.querySelectorAll('[data-vpp-carousel-thumb]') : [];
      const thumbs = Array.from(thumbCandidates);

      const applyState = (newIndex) => {
        const normalized = ((newIndex % items.length) + items.length) % items.length;
        items.forEach((img, idx) => {
          const isActive = idx === normalized;
          img.classList.toggle('is-active', isActive);
          if (isActive) {
            img.setAttribute('aria-current', 'true');
            img.removeAttribute('aria-hidden');
          } else {
            img.removeAttribute('aria-current');
            img.setAttribute('aria-hidden', 'true');
          }
        });
        thumbs.forEach((thumb, idx) => {
          const isThumbActive = idx === normalized;
          thumb.classList.toggle('is-active', isThumbActive);
          thumb.setAttribute('aria-pressed', isThumbActive ? 'true' : 'false');
        });
        activeIndex = normalized;
        if (prev) {
          prev.disabled = items.length <= 1;
        }
        if (next) {
          next.disabled = items.length <= 1;
        }
      };

      applyState(activeIndex);

      if (prev) {
        prev.addEventListener('click', () => {
          applyState(activeIndex - 1);
        });
      }
      if (next) {
        next.addEventListener('click', () => {
          applyState(activeIndex + 1);
        });
      }

      if (thumbs.length) {
        thumbs.forEach((thumb, idx) => {
          thumb.addEventListener('click', () => {
            applyState(idx);
          });
        });
      }

      let touchStartX = null;
      frame.addEventListener(
        'touchstart',
        (event) => {
          if (event.touches.length === 1) {
            touchStartX = event.touches[0].clientX;
          }
        },
        { passive: true }
      );

      frame.addEventListener(
        'touchend',
        (event) => {
          if (touchStartX === null || !event.changedTouches.length) {
            touchStartX = null;
            return;
          }
          const touchEndX = event.changedTouches[0].clientX;
          const delta = touchEndX - touchStartX;
          touchStartX = null;
          if (Math.abs(delta) < 30) {
            return;
          }
          if (delta > 0) {
            applyState(activeIndex - 1);
          } else {
            applyState(activeIndex + 1);
          }
        },
        { passive: true }
      );
    });
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
