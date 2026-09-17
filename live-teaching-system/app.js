// LiveTeach SA - Main JavaScript

document.addEventListener('DOMContentLoaded', function () {

    // Auto-hide alerts after 5 seconds
    setTimeout(function () {
        document.querySelectorAll('.alert').forEach(function (alert) {
            alert.style.transition = 'opacity 0.5s';
            alert.style.opacity = '0';
            setTimeout(function () { alert.remove(); }, 500);
        });
    }, 5000);

    // Mobile nav toggle
    const header = document.querySelector('.header-inner');
    const nav = document.querySelector('.nav-links');
    if (header && nav && window.innerWidth < 768) {
        const btn = document.createElement('button');
        btn.innerHTML = '☰';
        btn.className = 'btn btn-outline';
        btn.style.cssText = 'padding:6px 14px; font-size:1.2rem;';
        btn.addEventListener('click', function () {
            nav.style.display = nav.style.display === 'none' ? 'flex' : 'none';
        });
        nav.style.display = 'none';
        nav.style.flexDirection = 'column';
        nav.style.width = '100%';
        header.appendChild(btn);
    }

    // Confirm delete buttons
    document.querySelectorAll('[data-confirm]').forEach(function (el) {
        el.addEventListener('click', function (e) {
            if (!confirm(el.dataset.confirm)) e.preventDefault();
        });
    });

    // Auto-submit filter forms on select change (only if not already handled inline)
    document.querySelectorAll('select[data-autosubmit]').forEach(function (sel) {
        sel.addEventListener('change', function () { sel.closest('form').submit(); });
    });

});
