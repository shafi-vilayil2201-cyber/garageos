// Turns the existing printable invoice page into a real PDF file (via
// vendored html2canvas + jsPDF — no server-side PDF generation exists,
// see public/invoice-print.php) and hands it to the OS Share Sheet so
// WhatsApp can appear as a target with the file actually attached.
// navigator.share with files only works on mobile Chrome/Safari — on
// desktop there's no way to attach a file into a wa.me chat, so this
// falls back to downloading the PDF and opening the pre-filled chat
// for the file to be attached by hand.
//
// This deliberately does NOT use jsPDF's own .html() method. That
// method has real, confirmed rendering bugs independent of html2canvas
// itself: it drops border and conditional-color styling entirely (the
// "PAID" badge, table lines, the letterhead rule all vanish), it
// mis-places CSS Grid content (the "Billed to" / "Vehicle & job
// reference" two-column block gets torn out of order), and its
// "slice" page-break mode cuts straight through content sitting at a
// page boundary. Calling html2canvas directly and building the PDF
// page-by-page ourselves avoids all three — html2canvas alone renders
// the page faithfully; jsPDF is only used here as a thin image
// container.
document.addEventListener('DOMContentLoaded', () =>
{
    const button = document.getElementById('whatsapp-share-btn');

    if (!button) { return; }

    const originalLabel = button.innerHTML;

    function setBusy(label)
    {
        button.disabled = true;
        button.textContent = label;
    }

    function restore()
    {
        button.disabled = false;
        button.innerHTML = originalLabel;
    }

    // Matches print.css's .sheet { max-width: 210mm }, i.e. an A4 page
    // width at 96 CSS px/inch. Used as a fixed iframe/capture width
    // instead of measuring the DOM live — on at least one real device
    // (iOS Safari) a live scrollWidth measurement came back far too
    // small, blowing up the resulting scale factor.
    const A4_WIDTH_PX = 794;
    const RENDER_SCALE = 2;
    const PAGE_WIDTH_MM = 210;
    const PAGE_HEIGHT_MM = 297;

    async function buildInvoicePdf(printUrl)
    {
        const iframe = document.createElement('iframe');
        // Kept within normal viewport bounds (just visually hidden) rather
        // than flung far off-screen — an extreme negative offset is a
        // known trigger for WebKit/Safari to skip or botch layout for
        // content it treats as far outside any visible/composited area.
        iframe.style.position = 'fixed';
        iframe.style.top = '0';
        iframe.style.left = '0';
        iframe.style.width = A4_WIDTH_PX + 'px';
        iframe.style.height = '1200px';
        iframe.style.opacity = '0';
        iframe.style.pointerEvents = 'none';
        iframe.style.zIndex = '-1';
        document.body.appendChild(iframe);

        try {
            await new Promise((resolve, reject) => {
                iframe.addEventListener('load', resolve, { once: true });
                iframe.addEventListener('error', reject, { once: true });
                iframe.src = printUrl;
            });

            const sheet = iframe.contentDocument && iframe.contentDocument.querySelector('.sheet');

            if (!sheet) {
                throw new Error('Could not find the invoice content to convert.');
            }

            // html2canvas has a known bug rendering the ₹ glyph (it comes
            // out as a stray superscript "1"). This disposable iframe
            // clone is the only thing that goes through html2canvas — the
            // real on-screen page and the WhatsApp message text are
            // unaffected and keep the real ₹ symbol.
            sheet.innerHTML = sheet.innerHTML.replace(/₹/g, 'Rs. ');

            // Measure safe page-break points BEFORE rasterizing: the
            // bottom edge of every top-level section and every table
            // row. A page break is only ever placed at one of these, so
            // it never lands in the middle of a row or a line of text.
            const sheetTop = sheet.getBoundingClientRect().top;
            const breakOffsets = new Set([0]);
            Array.from(sheet.children).forEach(el => {
                breakOffsets.add(el.getBoundingClientRect().bottom - sheetTop);
            });
            sheet.querySelectorAll('tr').forEach(el => {
                breakOffsets.add(el.getBoundingClientRect().bottom - sheetTop);
            });
            const safeBreaksCss = Array.from(breakOffsets).sort((a, b) => a - b);

            const canvas = await window.html2canvas(sheet, {
                backgroundColor: '#ffffff',
                scale: RENDER_SCALE
            });

            const { jsPDF } = window.jspdf;
            const pdf = new jsPDF('p', 'mm', 'a4');
            const pxPerMm = canvas.width / PAGE_WIDTH_MM;
            const pageHeightPx = PAGE_HEIGHT_MM * pxPerMm;

            const safeBreaksPx = safeBreaksCss
                .map(px => px * RENDER_SCALE)
                .filter(px => px <= canvas.height);
            if (safeBreaksPx[safeBreaksPx.length - 1] !== canvas.height) {
                safeBreaksPx.push(canvas.height);
            }

            let cursor = 0;
            let isFirstPage = true;

            while (cursor < canvas.height) {
                const maxAllowed = cursor + pageHeightPx;
                const candidates = safeBreaksPx.filter(p => p > cursor && p <= maxAllowed);
                // No safe break fits this page at all (a single row taller
                // than a page) — fall back to a hard cut rather than loop
                // forever.
                const cut = candidates.length ? candidates[candidates.length - 1] : Math.min(maxAllowed, canvas.height);

                const sliceHeightPx = cut - cursor;
                const pageCanvas = document.createElement('canvas');
                pageCanvas.width = canvas.width;
                pageCanvas.height = sliceHeightPx;
                pageCanvas.getContext('2d').drawImage(
                    canvas, 0, cursor, canvas.width, sliceHeightPx, 0, 0, canvas.width, sliceHeightPx
                );

                const sliceImgData = pageCanvas.toDataURL('image/jpeg', 0.92);
                const sliceHeightMm = sliceHeightPx / pxPerMm;

                if (!isFirstPage) { pdf.addPage(); }
                pdf.addImage(sliceImgData, 'JPEG', 0, 0, PAGE_WIDTH_MM, sliceHeightMm);

                cursor = cut;
                isFirstPage = false;
            }

            return pdf.output('blob');

        } finally {
            document.body.removeChild(iframe);
        }
    }

    // Safari requires navigator.share() to run within a very short
    // window of the actual tap, with no meaningful async work first. PDF
    // generation (loading the iframe, running html2canvas) easily takes
    // longer than that window, so the very first share() call on iOS
    // reliably fails with NotAllowedError — not a real permissions
    // issue, just Safari deciding the user gesture has gone stale. When
    // that happens, the already-built file is cached here and the
    // button switches to a "tap again" state; that next tap is a fresh,
    // synchronous gesture with the file already in hand, which Safari
    // accepts.
    let pendingShare = null;

    async function shareNow(file, waMessage)
    {
        await navigator.share({ files: [file], text: waMessage });
    }

    button.addEventListener('click', async event => {

        event.preventDefault();

        if (pendingShare) {
            const { file, waMessage } = pendingShare;
            pendingShare = null;

            try {
                await shareNow(file, waMessage);
            } catch (error) {
                if (!(error && error.name === 'AbortError')) {
                    console.error(error);
                    window.showToast("Couldn't share the invoice PDF. Try Print Invoice instead.", 'error');
                }
            } finally {
                restore();
            }
            return;
        }

        const printUrl = button.dataset.invoiceUrl;
        const waNumber = button.dataset.waNumber;
        const waMessage = button.dataset.waMessage;
        const filename = button.dataset.filename;
        const waTextUrl = 'https://wa.me/' + waNumber + '?text=' + encodeURIComponent(waMessage);

        setBusy('Preparing PDF…');

        try {

            const blob = await buildInvoicePdf(printUrl);
            const file = new File([blob], filename, { type: 'application/pdf' });

            if (navigator.canShare && navigator.canShare({ files: [file] })) {
                try {
                    await shareNow(file, waMessage);
                } catch (shareError) {
                    if (shareError && shareError.name === 'NotAllowedError') {
                        pendingShare = { file, waMessage };
                        button.disabled = false;
                        button.textContent = 'Tap to send via WhatsApp';
                        return;
                    }
                    throw shareError;
                }
                restore();
                return;
            }

            const downloadUrl = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = downloadUrl;
            link.download = filename;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            URL.revokeObjectURL(downloadUrl);

            window.open(waTextUrl, '_blank', 'noopener');
            restore();

        } catch (error) {

            if (error && error.name === 'AbortError') {
                restore();
            } else {
                console.error(error);
                const detail = error && (error.message || error.name) ? ': ' + (error.message || error.name) : '';
                window.showToast("Couldn't prepare the invoice PDF" + detail + ". Try Print Invoice instead.", 'error');
                restore();
            }
        }
    });
});
