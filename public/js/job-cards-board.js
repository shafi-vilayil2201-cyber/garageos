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


    // Column header total (see job-cards.php) — kept as a data-amount
    // integer on the element itself so repeated moves don't drift from
    // re-parsing a formatted "₹1,234" string.
    function updateTotal(status, delta)
    {
        const el = document.getElementById(`kanban-total-${status}`);

        if (!el)
        {
            return;
        }

        const next = Math.max(0, parseInt(el.dataset.amount || '0', 10) + delta);

        el.dataset.amount = String(next);
        el.textContent = `₹${next.toLocaleString('en-US')}`;
    }


    // Shared by the desktop mouse drop handler and the touch pointerup
    // handler below — one place that knows how to move a card in the DOM,
    // call the API, and revert on failure.
    async function moveCardToColumn(link, fromColumn, toColumn)
    {
        const fromStatus = link.dataset.status;
        const toStatus = toColumn.dataset.status;

        if (fromStatus === toStatus)
        {
            return;
        }

        const cardsHolder = toColumn.querySelector('.kanban-column-cards');
        const wrap = link.closest('.kanban-card-wrap') || link;

        cardsHolder.insertBefore(wrap, cardsHolder.firstChild);

        if (fromColumn !== toColumn)
        {
            const amount = parseInt(link.dataset.amount || '0', 10);

            updateCount(fromColumn.dataset.status, -1);
            updateCount(toStatus, 1);
            updateTotal(fromStatus, -amount);
            updateTotal(toStatus, amount);
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
            originalHolder.insertBefore(wrap, originalHolder.firstChild);

            if (fromColumn !== toColumn)
            {
                const amount = parseInt(link.dataset.amount || '0', 10);

                updateCount(fromColumn.dataset.status, 1);
                updateCount(toStatus, -1);
                updateTotal(fromStatus, amount);
                updateTotal(toStatus, -amount);
            }
        }
    }


    // Desktop mouse drag — HTML5 native drag-and-drop. Touch/pen never
    // fires these events, which is exactly why the pointer-based path
    // below exists separately.
    let draggedLink = null;
    let draggedFromColumn = null;

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

        col.addEventListener('drop', event =>
        {
            event.preventDefault();
            col.classList.remove('drag-over');

            if (!draggedLink || col.classList.contains('drop-disabled'))
            {
                return;
            }

            moveCardToColumn(draggedLink, draggedFromColumn, col);
        });
    });


    // Touch/pen drag — Pointer Events. Gated to non-mouse pointer types
    // so mouse users keep using the native HTML5 path above untouched.
    // A tap (no meaningful movement) is left alone entirely so the
    // card's normal link click still opens the job card; only once the
    // finger has moved past DRAG_THRESHOLD do we take over the gesture
    // (pointer capture + preventDefault), which is also what stops the
    // browser's long-press link callout/context menu from appearing.
    const DRAG_THRESHOLD = 10;
    const EDGE_SCROLL_ZONE = 60;
    const EDGE_SCROLL_SPEED = 12;

    let touchPointerId = null;
    let touchLink = null;
    let touchFromColumn = null;
    let touchStartX = 0;
    let touchStartY = 0;
    let touchIsDragging = false;
    let touchGhost = null;
    let touchHoverColumn = null;
    let touchJustDragged = false;
    let autoScrollFrame = null;
    let lastPointerY = 0;


    function createGhost(link)
    {
        const rect = link.getBoundingClientRect();
        const ghost = link.cloneNode(true);

        ghost.classList.add('kanban-card-ghost');
        ghost.removeAttribute('href');
        ghost.removeAttribute('draggable');
        ghost.style.width = `${rect.width}px`;
        ghost.style.left = `${rect.left}px`;
        ghost.style.top = `${rect.top}px`;

        document.body.appendChild(ghost);

        return ghost;
    }


    function startTouchDrag(link, x, y)
    {
        touchIsDragging = true;
        touchFromColumn = link.closest('.kanban-column');
        touchGhost = createGhost(link);

        link.classList.add('dragging');
        document.body.classList.add('kanban-touch-dragging');

        const fromRank = RANK[link.dataset.status] || 0;

        columns.forEach(col =>
        {
            const colRank = RANK[col.dataset.status] || 0;

            if (colRank < fromRank)
            {
                col.classList.add('drop-disabled');
            }
        });

        positionGhost(x, y);
    }


    function positionGhost(x, y)
    {
        if (!touchGhost)
        {
            return;
        }

        touchGhost.style.left = `${x - touchStartPointerOffsetX}px`;
        touchGhost.style.top = `${y - touchStartPointerOffsetY}px`;
    }


    let touchStartPointerOffsetX = 0;
    let touchStartPointerOffsetY = 0;


    function updateHoverColumn(x, y)
    {
        const under = document.elementFromPoint(x, y);
        const col = under ? under.closest('.kanban-column') : null;

        if (col === touchHoverColumn)
        {
            return;
        }

        if (touchHoverColumn)
        {
            touchHoverColumn.classList.remove('drag-over');
        }

        if (col && !col.classList.contains('drop-disabled'))
        {
            col.classList.add('drag-over');
        }

        touchHoverColumn = col;
    }


    function maybeAutoScroll(y)
    {
        lastPointerY = y;

        if (autoScrollFrame)
        {
            return;
        }

        function step()
        {
            if (!touchIsDragging)
            {
                autoScrollFrame = null;
                return;
            }

            if (lastPointerY < EDGE_SCROLL_ZONE)
            {
                window.scrollBy(0, -EDGE_SCROLL_SPEED);
            } else if (lastPointerY > window.innerHeight - EDGE_SCROLL_ZONE)
            {
                window.scrollBy(0, EDGE_SCROLL_SPEED);
            }

            autoScrollFrame = requestAnimationFrame(step);
        }

        autoScrollFrame = requestAnimationFrame(step);
    }


    function endTouchDrag(x, y)
    {
        document.body.classList.remove('kanban-touch-dragging');
        columns.forEach(col => col.classList.remove('drop-disabled', 'drag-over'));

        if (touchGhost)
        {
            touchGhost.remove();
            touchGhost = null;
        }

        if (autoScrollFrame)
        {
            cancelAnimationFrame(autoScrollFrame);
            autoScrollFrame = null;
        }

        const link = touchLink;
        const fromColumn = touchFromColumn;
        const targetColumn = touchHoverColumn;

        link.classList.remove('dragging');
        touchHoverColumn = null;

        if (targetColumn && !targetColumn.classList.contains('drop-disabled'))
        {
            moveCardToColumn(link, fromColumn, targetColumn);
        }

        touchIsDragging = false;
        touchLink = null;
        touchFromColumn = null;
    }


    // pointerdown/click stay per-card (need to know which card started the
    // gesture), but pointermove/pointerup/pointercancel are attached once
    // on the document instead of per-card. Pointer capture is attempted as
    // an optimization but isn't relied on — without document-level
    // listeners, moving off the original card element (which is exactly
    // what dragging does) would stop delivering events to a per-card
    // listener the moment capture didn't take.
    cardLinks.forEach(link =>
    {
        link.addEventListener('pointerdown', event =>
        {
            if (event.pointerType === 'mouse' || touchPointerId !== null)
            {
                return;
            }

            touchPointerId = event.pointerId;
            touchLink = link;
            touchStartX = event.clientX;
            touchStartY = event.clientY;
            touchIsDragging = false;

            const rect = link.getBoundingClientRect();
            touchStartPointerOffsetX = event.clientX - rect.left;
            touchStartPointerOffsetY = event.clientY - rect.top;

            try
            {
                link.setPointerCapture(event.pointerId);
            } catch (error)
            {
                // Non-fatal — the document-level listeners below still
                // track this pointer by id either way.
            }
        });

        // A touch drag ends with a synthetic "click" on the anchor right
        // after pointerup — without this, releasing a drag over a card
        // would also navigate to it.
        link.addEventListener('click', event =>
        {
            if (touchJustDragged)
            {
                event.preventDefault();
                touchJustDragged = false;
            }
        });
    });


    document.addEventListener('pointermove', event =>
    {
        if (event.pointerId !== touchPointerId || !touchLink)
        {
            return;
        }

        const dx = event.clientX - touchStartX;
        const dy = event.clientY - touchStartY;

        if (!touchIsDragging)
        {
            if (Math.hypot(dx, dy) < DRAG_THRESHOLD)
            {
                return;
            }

            startTouchDrag(touchLink, event.clientX, event.clientY);
        }

        event.preventDefault();

        positionGhost(event.clientX, event.clientY);
        updateHoverColumn(event.clientX, event.clientY);
        maybeAutoScroll(event.clientY);
    }, { passive: false });


    function finishPointer(event)
    {
        if (event.pointerId !== touchPointerId)
        {
            return;
        }

        if (touchIsDragging)
        {
            touchJustDragged = true;
            endTouchDrag(event.clientX, event.clientY);
        }

        touchPointerId = null;
        touchLink = null;
    }

    document.addEventListener('pointerup', finishPointer);
    document.addEventListener('pointercancel', finishPointer);


    // Per-card print menu — delegated on the board since every card has
    // its own trigger/menu pair; see user-menu.js for the single-instance
    // version of this same open/close/outside-click/Escape pattern.
    let openPrintMenu = null;

    function closePrintMenu()
    {
        if (openPrintMenu)
        {
            openPrintMenu.menu.hidden = true;
            openPrintMenu.trigger.setAttribute('aria-expanded', 'false');
            openPrintMenu = null;
        }
    }

    board.addEventListener('click', event =>
    {
        const trigger = event.target.closest('[data-print-trigger]');

        if (trigger)
        {
            event.preventDefault();
            event.stopPropagation();

            const menu = trigger.nextElementSibling;
            const wasOpen = openPrintMenu && openPrintMenu.trigger === trigger;

            closePrintMenu();

            if (!wasOpen)
            {
                menu.hidden = false;
                trigger.setAttribute('aria-expanded', 'true');
                openPrintMenu = { trigger, menu };
            }

            return;
        }

        closePrintMenu();
    });

    document.addEventListener('click', event =>
    {
        if (openPrintMenu && !board.contains(event.target))
        {
            closePrintMenu();
        }
    });

    document.addEventListener('keydown', event =>
    {
        if (event.key === 'Escape' && openPrintMenu)
        {
            closePrintMenu();
        }
    });


    // Quick-edit — one shared modal (see job-cards.php) populated from
    // whichever card's pencil icon was clicked, rather than rendering a
    // separate modal per card.
    board.addEventListener('click', event =>
    {
        const trigger = event.target.closest('[data-edit-trigger]');

        if (!trigger)
        {
            return;
        }

        document.getElementById('quick-edit-job-card-id').value = trigger.dataset.jobCardId;
        document.getElementById('quick-edit-job-no').textContent = trigger.dataset.jobNo;
        document.getElementById('quick-edit-odometer').value = trigger.dataset.odometerIn || '';
        document.getElementById('quick-edit-complaint').value = trigger.dataset.customerComplaint || '';
        document.getElementById('quick-edit-promised-at').value = trigger.dataset.promisedAt || '';

        openModal('quick-edit-modal');
    });
})();
