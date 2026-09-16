/**
 * Sharing tables/cards as an IMAGE on WhatsApp.
 *
 * Usage: a button with the attributes below triggers a capture of the
 * target element (via html2canvas) and shares the result.
 *
 *   <button data-compartilhar-imagem="#card-selector"
 *           data-arquivo="file-name">
 *       Compartilhar
 *   </button>
 *
 * Flow:
 *  1. On mobile (Web Share API with file support) opens the native share
 *     sheet with ONLY the file — no title/text alongside it, so it doesn't
 *     turn into a link/text card that hides the image in WhatsApp.
 *  2. On desktop (no file-sharing support) it just downloads the PNG, so
 *     the user can manually attach the file to the conversation.
 *
 * Why share a PDF instead of the PNG directly:
 *  The generated PNG already comes out perfect (you can check by
 *  downloading the file). The problem is that, when an image is sent
 *  through WhatsApp as a "photo", WhatsApp itself recompresses/resizes the
 *  file — and since our table is quite tall (many rows in a single image),
 *  that recompression crushes the content and it becomes illegible.
 *  Sending it as a "document" (a single-page PDF with the same image
 *  inside) means WhatsApp does NOT recompress it, and the result arrives
 *  identical to the original PNG — exactly like the download.
 *  Only side effect: the recipient sees a document/PDF icon instead of the
 *  photo already showing as a thumbnail in the chat, needing to open it to see it.
 *
 * Important: the card uses responsive flexbox (columns wrap/stack on
 * narrow screens). If we captured the actual width of the phone, each row
 * would "wrap" into several pieces and the image would come out cramped
 * and illegible. That's why the capture always simulates a wide window
 * (desktop-style) for html2canvas, regardless of the actual screen size of
 * whoever is sharing.
 */
(function () {
    'use strict';

    const CAPTURE_WIDTH = 960; // "virtual" width used so the layout doesn't wrap/stack

    function withLoadingState(button, callback) {
        if (!button) return callback();
        const originalHtml = button.innerHTML;
        const originalWidth = button.style.minWidth;
        button.style.minWidth = button.offsetWidth + 'px';
        button.disabled = true;
        button.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Gerando imagem...';

        const restore = () => {
            button.disabled = false;
            button.innerHTML = originalHtml;
            button.style.minWidth = originalWidth;
        };

        Promise.resolve(callback()).finally(restore);
    }

    async function generateCanvas(target, backgroundColor) {
        const scale = Math.min(window.devicePixelRatio || 1, 2) * 1.6;

        // While capturing, applies the "modo-captura" class (larger
        // fonts/icons, only on this rendered copy) so the image comes out
        // legible even on small phone screens.
        target.classList.add('modo-captura');

        try {
            // Simulates a wide window (windowWidth) so the layout uses the
            // "desktop" version of the card, without the points/names
            // stacking into several lines like it does at a phone's real width.
            return await html2canvas(target, {
                backgroundColor: backgroundColor || '#ffffff',
                scale: scale,
                useCORS: true,
                logging: false,
                windowWidth: CAPTURE_WIDTH,
                windowHeight: Math.max(window.innerHeight, target.scrollHeight + 200),
            });
        } finally {
            target.classList.remove('modo-captura');
        }
    }

    function canvasToPngBlob(canvas) {
        return new Promise((resolve, reject) => {
            canvas.toBlob((blob) => {
                if (blob) resolve(blob);
                else reject(new Error('Não foi possível gerar a imagem.'));
            }, 'image/png');
        });
    }

    /**
     * Wraps the canvas into a single-page PDF (same size as the image, no
     * margins) so it can be shared as a "document" — WhatsApp doesn't
     * recompress documents, so the quality arrives identical to the PNG.
     */
    function canvasToPdfBlob(canvas) {
        const { jsPDF } = window.jspdf || {};
        if (!jsPDF) return null;

        const orientation = canvas.width >= canvas.height ? 'l' : 'p';
        const doc = new jsPDF({
            orientation: orientation,
            unit: 'px',
            format: [canvas.width, canvas.height],
            compress: true,
        });

        const dataUrl = canvas.toDataURL('image/jpeg', 0.95);
        doc.addImage(dataUrl, 'JPEG', 0, 0, canvas.width, canvas.height);

        return doc.output('blob');
    }

    function downloadFile(blob, fileName, extension) {
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = fileName + '.' + extension;
        document.body.appendChild(link);
        link.click();
        link.remove();
        setTimeout(() => URL.revokeObjectURL(url), 2000);
    }

    async function shareAsImage(targetSelector, options) {
        const target = document.querySelector(targetSelector);
        if (!target) {
            console.error('Elemento alvo não encontrado para compartilhar:', targetSelector);
            return;
        }

        const fileName = options.arquivo || 'cartoleirao';

        let canvas;
        try {
            canvas = await generateCanvas(target, options.corFundo);
        } catch (error) {
            console.error(error);
            alert('Não foi possível gerar a imagem agora. Tente novamente.');
            return;
        }

        // For WhatsApp: tries to share as a PDF (document, no
        // recompression). Just the image, no title/text — avoids WhatsApp
        // building a text/link card on top of the file.
        const pdfBlob = canvasToPdfBlob(canvas);
        if (pdfBlob) {
            const pdfFile = new File([pdfBlob], fileName + '.pdf', { type: 'application/pdf' });
            if (navigator.canShare && navigator.canShare({ files: [pdfFile] })) {
                try {
                    await navigator.share({ files: [pdfFile] });
                    return;
                } catch (error) {
                    if (error && error.name === 'AbortError') return; // user cancelled
                    // no real support despite canShare: falls through to the fallback below
                }
            }
        }

        // Fallback: generates the PNG (same image, no conversion) — used
        // when the browser can't share a file (mostly desktop), or has no
        // support for generating the PDF.
        let pngBlob;
        try {
            pngBlob = await canvasToPngBlob(canvas);
        } catch (error) {
            console.error(error);
            alert('Não foi possível gerar a imagem agora. Tente novamente.');
            return;
        }

        const pngFile = new File([pngBlob], fileName + '.png', { type: 'image/png' });
        if (!pdfBlob && navigator.canShare && navigator.canShare({ files: [pngFile] })) {
            try {
                await navigator.share({ files: [pngFile] });
                return;
            } catch (error) {
                if (error && error.name === 'AbortError') return;
            }
        }

        downloadFile(pngBlob, fileName, 'png');
        window.setTimeout(() => {
            alert('Imagem baixada! Anexe o arquivo na conversa do WhatsApp.');
        }, 300);
    }

    document.addEventListener('click', function (event) {
        const button = event.target.closest('[data-compartilhar-imagem]');
        if (!button) return;
        event.preventDefault();

        withLoadingState(button, () =>
            shareAsImage(button.getAttribute('data-compartilhar-imagem'), {
                arquivo: button.getAttribute('data-arquivo'),
                corFundo: button.getAttribute('data-bg'),
            })
        );
    });
})();
