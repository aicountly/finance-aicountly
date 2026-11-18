(function () {
    var notice = document.getElementById('aic-maintenance-20261118');
    var remaining = Date.parse('2026-11-18T19:30:00Z') - Date.now();
    if (!notice || remaining <= 0) return;
    try { if (sessionStorage.getItem(notice.id) === 'dismissed') return; } catch { /* Browser storage is optional. */ }
    notice.hidden = false;
    notice.querySelector('button').addEventListener('click', function () {
      notice.hidden = true;
      try { sessionStorage.setItem(notice.id, 'dismissed'); } catch { /* Browser storage is optional. */ }
    });
    window.setTimeout(function () { notice.hidden = true; }, remaining);
  })();
