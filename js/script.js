(function() {
  // Highlight suggestion chips when clicked.
  const quoteSpans = document.querySelectorAll('.message-suggestions span, .suggestions span');
  quoteSpans.forEach(el => {
    el.addEventListener('click', function() {
      this.style.background = '#f5c842';
      this.style.color = '#0b0b0b';
      this.style.borderColor = '#f5c842';
      setTimeout(() => {
        this.style.background = 'white';
        this.style.color = '#1e1e1e';
        this.style.borderColor = '#ccc';
      }, 500);
    });
  });

  // "Book" buttons on provider cards only (not auth forms).
  // Only attach to .btn-book inside a .provider-card so login/signup buttons are untouched.
  const bookBtns = document.querySelectorAll('.provider-card .btn-book');
  bookBtns.forEach(btn => {
    btn.addEventListener('click', function(e) {
      e.preventDefault();
      e.stopPropagation();
      const original = this.innerHTML;
      this.innerHTML = '<i class="fas fa-check"></i> Request sent!';
      this.style.background = '#2a9d8f';
      this.style.color = 'white';
      setTimeout(() => {
        this.innerHTML = original;
        this.style.background = '#0b0b0b';
        this.style.color = 'white';
      }, 2000);
    });
  });

  // Location filter toast.
  const locSelect = document.querySelector('.location-box select');
  if (locSelect) {
    locSelect.addEventListener('change', function() {
      const val = this.options[this.selectedIndex].text;
      const feedback = document.createElement('div');
      feedback.style.cssText = 'position:fixed;bottom:30px;right:30px;background:#0b0b0b;color:#f5c842;'
        + 'padding:0.8rem 2rem;border-radius:60px;font-weight:500;box-shadow:0 6px 18px rgba(0,0,0,0.2);z-index:999;';
      feedback.innerHTML = `<i class="fas fa-location-dot"></i> Showing services in ${val}`;
      document.body.appendChild(feedback);
      setTimeout(() => feedback.remove(), 2500);
    });
  }

  // Provider card click-to-view (browse page).
  const providerCards = document.querySelectorAll('.provider-card');
  providerCards.forEach(card => {
    card.addEventListener('click', function(e) {
      if (e.target.closest('.btn-book')) return;
      const name = this.querySelector('h3')?.innerText || 'Provider';
      alert(`Services by ${name}:\n• View full profile for details\n• Skills, portfolio & testimonials available`);
    });
  });

  console.log('QuickGanyu frontend ready – Malawi marketplace');
})();
