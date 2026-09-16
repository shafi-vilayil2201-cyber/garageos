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

    async function buildInvoicePdf(printUrl)
    {
        const iframe = document.createElement('iframe');
        iframe.style.position = 'fixed';
        iframe.style.left = '-10000px';
        iframe.style.top = '0';
        iframe.style.width = '900px';
        iframe.style.height = '1200px';
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

            const { jsPDF } = window.jspdf;
            const pdf = new jsPDF('p', 'mm', 'a4');

            await pdf.html(sheet, {
                x: 0,
                y: 0,
                width: 210,
                windowWidth: sheet.scrollWidth || 900
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
                alert("Couldn't prepare the invoice PDF. Try Print Invoice instead.");
            }

        } finally {
            restore();
        }
    });
});
