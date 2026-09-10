(function ()
{
    const RANK = window.JOB_CARD_STATUS_RANK || {};
    const CSRF = window.JOB_CARD_CSRF || '';

    const board = document.getElementById('kanban-board');

    if (!board)
    {
        return;
    }

    const columns = Array.from(board.querySelectorAll('.kanban-column'));
    const cardLinks = Array.from(board.querySelectorAll('.kanban-card-link[draggable="true"]'));

    let draggedLink = null;
    let draggedFromColumn = null;


    function showToast(message)
    {
        let toast = document.getElementById('kanban-toast');

        if (!toast)
        {
            toast = document.createElement('div');
            toast.id = 'kanban-toast';
            toast.className = 'kanban-toast';
            document.body.appendChild(toast);
        }

        toast.textContent = message;
        toast.classList.add('visible');

        clearTimeout(toast._hideTimer);
        toast._hideTimer = setTimeout(() =>
        {
            toast.classList.remove('visible');
        }, 3200);
    }


    function updateCount(status, delta)
    {
        const el = document.getElementById(`kanban-count-${status}`);

        if (el)
        {
            el.textContent = String(Math.max(0, parseInt(el.textContent, 10) + delta));
        }
    }


    cardLinks.forEach(link =>
    {
        link.addEventListener('dragstart', event =>
        {
            draggedLink = link;
            draggedFromColumn = link.closest('.kanban-column');

            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', link.dataset.jobCardId);

            link.classList.add('dragging');

            const fromRank = RANK[link.dataset.status] || 0;

            columns.forEach(col =>
            {
                const colRank = RANK[col.dataset.status] || 0;

                if (colRank < fromRank)
                {
                    col.classList.add('drop-disabled');
                }
            });
        });

        link.addEventListener('dragend', () =>
        {
            link.classList.remove('dragging');
            columns.forEach(col => col.classList.remove('drop-disabled', 'drag-over'));
            draggedLink = null;
            draggedFromColumn = null;
        });
    });


    columns.forEach(col =>
    {
        col.addEventListener('dragover', event =>
        {
            if (!draggedLink || col.classList.contains('drop-disabled'))
            {
                return;
            }

            event.preventDefault();
            event.dataTransfer.dropEffect = 'move';
            col.classList.add('drag-over');
        });

        col.addEventListener('dragleave', () =>
        {
            col.classList.remove('drag-over');
        });

        col.addEventListener('drop', async event =>
        {
            event.preventDefault();
            col.classList.remove('drag-over');

            if (!draggedLink || col.classList.contains('drop-disabled'))
            {
                return;
            }

            const link = draggedLink;
            const fromColumn = draggedFromColumn;
            const fromStatus = link.dataset.status;
            const toStatus = col.dataset.status;

            if (fromStatus === toStatus)
            {
                return;
            }

            const cardsHolder = col.querySelector('.kanban-column-cards');

            cardsHolder.insertBefore(link, cardsHolder.firstChild);

            if (fromColumn !== col)
            {
                updateCount(fromColumn.dataset.status, -1);
                updateCount(toStatus, 1);
            }

            try
            {
                const body = new URLSearchParams({
                    _csrf: CSRF,
                    job_card_id: link.dataset.jobCardId,
                    status: toStatus
                });

                const response = await fetch('/api/job-cards/update-status.php', {
                    method: 'POST',
                    body
                });

                const data = await response.json();

                if (!response.ok)
                {
                    throw new Error(data.error || 'Could not update that job card.');
                }

                link.dataset.status = toStatus;

                const cardEl = link.querySelector('.kanban-card');
                cardEl.className = cardEl.className.replace(/\bcard-\S+/, `card-${toStatus}`);

                if (toStatus === 'delivered')
                {
                    link.removeAttribute('draggable');
                }

            } catch (error)
            {
                console.error(error);
                showToast(error.message || 'Could not move that job card. Reverting.');

                const originalHolder = fromColumn.querySelector('.kanban-column-cards');
                originalHolder.insertBefore(link, originalHolder.firstChild);

                if (fromColumn !== col)
                {
                    updateCount(fromColumn.dataset.status, 1);
                    updateCount(toStatus, -1);
                }
            }
        });
    });
})();
