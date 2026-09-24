// A small, stackable toast — the app-wide replacement for both the
// old kanban-only toast and any blocking alert(). Available everywhere
// via window.showToast(message, type), since this loads on every
// authenticated page from app/View/sidebar.php.
(function ()
{
    function ensureStack()
    {
        let stack = document.getElementById('toast-stack');

        if (!stack)
        {
            stack = document.createElement('div');
            stack.id = 'toast-stack';
            stack.className = 'toast-stack';
            document.body.appendChild(stack);
        }

        return stack;
    }

    window.showToast = function (message, type)
    {
        if (!message)
        {
            return;
        }

        const stack = ensureStack();
        const toast = document.createElement('div');

        toast.className = 'toast toast-' + (type === 'error' ? 'error' : 'success');
        toast.textContent = message;
        stack.appendChild(toast);

        requestAnimationFrame(() => toast.classList.add('visible'));

        setTimeout(() =>
        {
            toast.classList.remove('visible');
            setTimeout(() => toast.remove(), 220);
        }, 3200);
    };

    document.addEventListener('DOMContentLoaded', () =>
    {
        const flash = document.getElementById('flash-toast');

        if (flash)
        {
            window.showToast(flash.dataset.message, flash.dataset.type);
            flash.remove();
        }
    });
})();
