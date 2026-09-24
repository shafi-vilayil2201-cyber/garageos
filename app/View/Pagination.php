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

// $extraParams preserves other active query-string filters (e.g. a
// "filter by staff member" dropdown) across Prev/Next — without it,
// paging away from page 1 would silently drop any such filter.
function render_pagination(int $page, int $totalRows, int $perPage = PAGINATION_PER_PAGE, array $extraParams = []): string
{
    $totalPages = max(1, (int) ceil($totalRows / $perPage));

    if ($totalPages <= 1) {
        return '';
    }

    $firstRow = ($page - 1) * $perPage + 1;
    $lastRow = min($page * $perPage, $totalRows);

    $buildUrl = function (int $targetPage) use ($extraParams): string {
        $params = array_merge($extraParams, ['page' => $targetPage]);
        return '?' . http_build_query($params);
    };

    $prev = $page > 1
        ? '<a href="' . htmlspecialchars($buildUrl($page - 1)) . '" class="button secondary sm">' . icon('chevron-left', 14) . ' Prev</a>'
        : '<span class="button secondary sm" aria-disabled="true">' . icon('chevron-left', 14) . ' Prev</span>';

    $next = $page < $totalPages
        ? '<a href="' . htmlspecialchars($buildUrl($page + 1)) . '" class="button secondary sm">Next ' . icon('chevron-right', 14) . '</a>'
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

// A compact numbered pager (1 2 3 ... 8) for a small widget — a
// dashboard card, not a full list page — where the total is always
// small enough to fit page-number buttons in one row instead of the
// "Page X of Y" shorthand above. $paramName lets more than one of
// these live on the same page without their query params colliding.
function render_numbered_pagination(int $page, int $totalPages, string $paramName, array $extraParams = []): string
{
    if ($totalPages <= 1) {
        return '';
    }

    $buildUrl = function (int $targetPage) use ($paramName, $extraParams): string {
        return '?' . http_build_query(array_merge($extraParams, [$paramName => $targetPage]));
    };

    // Always show the first and last page plus a window around the
    // current one, with an ellipsis filling any gap — so the control
    // never grows past a handful of buttons no matter how many pages
    // actually exist.
    $pagesToShow = array_unique(array_filter(
        [1, $page - 1, $page, $page + 1, $totalPages],
        fn(int $p): bool => $p >= 1 && $p <= $totalPages
    ));
    sort($pagesToShow);

    $buttons = '';
    $previousPage = 0;

    foreach ($pagesToShow as $p) {
        if ($previousPage && $p - $previousPage > 1) {
            $buttons .= '<span class="pagination-ellipsis">&hellip;</span>';
        }

        $buttons .= $p === $page
            ? '<span class="pagination-number active" aria-current="page">' . $p . '</span>'
            : '<a href="' . htmlspecialchars($buildUrl($p)) . '" class="pagination-number">' . $p . '</a>';

        $previousPage = $p;
    }

    $prev = $page > 1
        ? '<a href="' . htmlspecialchars($buildUrl($page - 1)) . '" class="pagination-arrow" aria-label="Previous page">' . icon('chevron-left', 14) . '</a>'
        : '<span class="pagination-arrow" aria-disabled="true">' . icon('chevron-left', 14) . '</span>';

    $next = $page < $totalPages
        ? '<a href="' . htmlspecialchars($buildUrl($page + 1)) . '" class="pagination-arrow" aria-label="Next page">' . icon('chevron-right', 14) . '</a>'
        : '<span class="pagination-arrow" aria-disabled="true">' . icon('chevron-right', 14) . '</span>';

    return '<div class="pagination pagination-compact">' . $prev . $buttons . $next . '</div>';
}
