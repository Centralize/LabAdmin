// Apply the saved theme before first paint (avoids a light/dark flash).
(function () {
    var t = null;
    try { t = localStorage.getItem('labadmin.theme'); } catch (e) { /* storage unavailable */ }
    if (t === 'light' || t === 'dark') document.documentElement.setAttribute('data-theme', t);
})();
