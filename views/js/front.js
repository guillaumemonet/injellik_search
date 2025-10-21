document.addEventListener('DOMContentLoaded', function() {
  const input = document.getElementById('injellik-ai-input');
  const results = document.getElementById('injellik-ai-results');
  const container = document.getElementById('injellik-ai-search');
  if (!input || !container) return;
  const ajaxUrl = container.dataset.ajax;

  let timer = null;
  input.addEventListener('input', function(e) {
    clearTimeout(timer);
    const q = e.target.value;
    if (q.length < 2) { results.innerHTML = ''; return; }
    timer = setTimeout(() => {
      fetch(ajaxUrl + '?q=' + encodeURIComponent(q))
        .then(r => r.json())
        .then(data => {
          if (data.error) { results.innerHTML = '<div class="inj-error">'+data.error+'</div>'; return; }
          if (data.products && data.products.length > 0) {
            results.innerHTML = '<div class="inj-answer">'+(data.raw ? '<pre>'+escapeHtml(data.raw)+'</pre>' : '')+'</div>' +
                                data.products.map(id=>'<div class="inj-item"><a href="/index.php?id_product='+id+'">Produit #'+id+'</a></div>').join('');
          } else {
            results.innerHTML = '<div class="inj-answer">Aucun produit trouvé.</div>';
          }
        })
        .catch(err => { results.innerHTML = '<div class="inj-error">Erreur réseau</div>'; });
    }, 300);
  });

  function escapeHtml(unsafe) {
    return unsafe.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }
});
