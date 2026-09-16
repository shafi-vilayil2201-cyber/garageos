// Turns the existing printable invoice page into a real PDF file (via
// vendored jsPDF + html2canvas — no server-side PDF generation exists,
// see public/invoice-print.php) and hands it to the OS Share Sheet so
// WhatsApp can appear as a target with the file actually attached.
// navigator.share with files only works on mobile Chrome/Safari — on
// desktop there's no way to attach a file into a wa.me chat, so this
// falls back to downloading the PDF and opening the pre-filled chat
// for the file to be attached by hand.
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
    // width at 96 CSS px/inch. Passed to jsPDF as a fixed windowWidth
    // instead of measuring sheet.scrollWidth live — on at least one real
    // device (iOS Safari) that live measurement came back far too small,
    // making jsPDF compute a huge scale (width ÷ windowWidth) and blow
    // the invoice up into oversized text spread across many pages.
    const A4_WIDTH_PX = 794;

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

            // jsPDF's html() renderer doesn't correctly place CSS Grid
            // content — .info-grid (the only display:grid in print.css,
            // see public/css/print.css) gets torn out of normal flow and
            // dumped near the bottom of the page, after the totals and
            // signature, instead of staying under the letterhead. Force
            // it to a plain stacked block layout, which the renderer
            // handles correctly, purely for this capture clone.
            const infoGrid = sheet.querySelector('.info-grid');
            if (infoGrid) {
                infoGrid.style.display = 'block';
                infoGrid.querySelectorAll('.info-block').forEach(block => {
                    block.style.marginBottom = '16px';
                });
            }

            const { jsPDF } = window.jspdf;
            const pdf = new jsPDF('p', 'mm', 'a4');

            await pdf.html(sheet, {
                x: 0,
                y: 0,
                width: 210,
                windowWidth: A4_WIDTH_PX,
                // jsPDF's default page-break mode ("slice") cuts straight
                // through whatever content happens to sit at the page
                // boundary — on any invoice long enough to need a second
                // page, a table row or totals line gets sliced in half
                // and duplicated across both pages. "text" mode breaks
                // between text runs instead, avoiding that.
                autoPaging: 'text'
            });

            return pdf.output('blob');

        } finally {
            document.body.removeChild(iframe);
        }
    }

    button.addEventListener('click', async event => {

        event.preventDefault();

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
                await navigator.share({ files: [file], text: waMessage });
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

        } catch (error) {

            if (error && error.name === 'AbortError') {
                // User cancelled the native share sheet — not a failure.
            } else {
                console.error(error);
                const detail = error && (error.message || error.name) ? ': ' + (error.message || error.name) : '';
                alert("Couldn't prepare the invoice PDF" + detail + ". Try Print Invoice instead.");
            }

        } finally {
            restore();
        }
    });
});
