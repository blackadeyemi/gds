// Lightweight show/hide toggle for the login password field (vanilla,
// replaces the legacy jQuery showPassword plugin).
document.addEventListener('DOMContentLoaded', function () {
    var pwd = document.getElementById('password');
    if (!pwd) return;

    var toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'toggle';
    toggle.textContent = 'Show';
    toggle.style.position = 'absolute';
    toggle.style.right = '8px';
    toggle.style.top = '50%';
    toggle.style.transform = 'translateY(-50%)';

    var wrap = pwd.parentElement;
    wrap.style.position = 'relative';
    wrap.appendChild(toggle);

    toggle.addEventListener('click', function () {
        var hidden = pwd.type === 'password';
        pwd.type = hidden ? 'text' : 'password';
        toggle.textContent = hidden ? 'Hide' : 'Show';
    });
});
