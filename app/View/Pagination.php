<?php

// Offset-based pagination for list pages. Each page runs its own COUNT
// query (the WHERE clause differs per page), then hands the total row
// count to paginate_page()/paginate_offset() to get a clamped current
// page and the SQL OFFSET, and to render_pagination() for the control.
// A page number past the last page clamps to the last page rather than
// showing an empty table with live-looking Prev/Next controls.

const PAGINATION_PER_PAGE = 20;

function paginate_page(int $totalRows, int $perPage = PAGINATION_PER_PAGE): int
{
    $totalPages = max(1, (int) ceil($totalRows / $perPage));
    $requested = max(1, (int) ($_GET['page'] ?? 1));

    return min($requested, $totalPages);
}

function paginate_offset(int $page, int $perPage = PAGINATION_PER_PAGE): int
{
    return ($page - 1) * $perPage;
}

function render_pagination(int $page, int $totalRows, int $perPage = PAGINATION_PER_PAGE): string
{
    $totalPages = max(1, (int) ceil($totalRows / $perPage));

    if ($totalPages <= 1) {
        return '';
    }

    $firstRow = ($page - 1) * $perPage + 1;
    $lastRow = min($page * $perPage, $totalRows);

    $prev = $page > 1
        ? '<a href="?page=' . ($page - 1) . '" class="button secondary sm">' . icon('chevron-left', 14) . ' Prev</a>'
        : '<span class="button secondary sm" aria-disabled="true">' . icon('chevron-left', 14) . ' Prev</span>';

    $next = $page < $totalPages
        ? '<a href="?page=' . ($page + 1) . '" class="button secondary sm">Next ' . icon('chevron-right', 14) . '</a>'
        : '<span class="button secondary sm" aria-disabled="true">Next ' . icon('chevron-right', 14) . '</span>';

    return '
        <div class="pagination">
            <span class="pagination-summary">Showing ' . $firstRow . '&ndash;' . $lastRow . ' of ' . $totalRows . '</span>
            <div class="pagination-controls">
                ' . $prev . '
                <span class="pagination-page">Page ' . $page . ' of ' . $totalPages . '</span>
                ' . $next . '
            </div>
        </div>
    ';
}
