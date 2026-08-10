(function() {
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

  const bookBtns = document.querySelectorAll('.btn-book');
  bookBtns.forEach(btn => {
    btn.addEventListener('click', function(e) {
      e.preventDefault();
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

  const locSelect = document.querySelector('.location-box select');
  if (locSelect) {
    locSelect.addEventListener('change', function() {
      const val = this.options[this.selectedIndex].text;
      const feedback = document.createElement('div');
      feedback.style.position = 'fixed';
      feedback.style.bottom = '30px';
      feedback.style.right = '30px';
      feedback.style.background = '#0b0b0b';
      feedback.style.color = '#f5c842';
      feedback.style.padding = '0.8rem 2rem';
      feedback.style.borderRadius = '60px';
      feedback.style.fontWeight = '500';
      feedback.style.boxShadow = '0 6px 18px rgba(0,0,0,0.2)';
      feedback.style.zIndex = '999';
      feedback.innerHTML = `<i class="fas fa-location-dot"></i> Showing services in ${val}`;
      document.body.appendChild(feedback);
      setTimeout(() => feedback.remove(), 2500);
    });
  }

  const providerCards = document.querySelectorAll('.provider-card');
  providerCards.forEach(card => {
    card.addEventListener('click', function(e) {
      if (e.target.closest('.btn-book')) return;
      const name = this.querySelector('h3')?.innerText || 'Provider';
      alert(`🔍 Services by ${name}:\n• Plumbing, repairs, electrical\n• Portfolio with images/videos\n• Skills: certified, 5+ years\n• Location & testimonials available`);
    });
  });

  console.log('QuickGanyu frontend ready – Malawi marketplace');
})();
