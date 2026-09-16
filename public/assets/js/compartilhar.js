/**
 * Compartilhamento de tabelas/cards como IMAGEM no WhatsApp.
 *
 * Uso: um botão com os atributos abaixo dispara a captura do elemento alvo
 * (via html2canvas) e compartilha o resultado.
 *
 *   <button data-compartilhar-imagem="#seletor-do-card"
 *           data-arquivo="nome-do-arquivo">
 *       Compartilhar
 *   </button>
 *
 * Fluxo:
 *  1. Em celulares (Web Share API com suporte a arquivo) abre a folha nativa
 *     de compartilhamento SÓ com o arquivo — sem título/texto junto, pra não
 *     virar um card de link/texto que esconde a imagem no WhatsApp.
 *  2. Em desktop (sem suporte a compartilhar arquivo) só baixa o PNG, pra o
 *     usuário anexar o arquivo manualmente na conversa.
 *
 * Por que compartilhar um PDF em vez do PNG direto:
 *  O PNG gerado já sai perfeito (dá pra conferir baixando o arquivo). O
 *  problema é que, quando uma imagem é enviada pelo WhatsApp como "foto", o
 *  próprio WhatsApp recomprime/redimensiona o arquivo — e como nossa tabela
 *  é bem alta (muitas linhas numa imagem só), essa recompressão amassa o
 *  conteúdo e sai ilegível. Enviando como "documento" (PDF de página única
 *  com a mesma imagem dentro) o WhatsApp NÃO recomprime, e o resultado chega
 *  idêntico ao PNG original — exatamente como no download.
 *  Único efeito colateral: quem recebe vê um ícone de documento/PDF em vez
 *  de a foto já aparecer em miniatura no chat, precisando abrir pra ver.
 *
 * Importante: o card usa flexbox responsivo (as colunas quebram/empilham em
 * telas estreitas). Se capturássemos exatamente a largura real do celular,
 * cada linha "quebraria" em vários pedaços e a imagem saía espremida e
 * ilegível. Por isso a captura sempre simula uma janela larga (estilo
 * desktop) pro html2canvas, não importa o tamanho real da tela de quem tá
 * compartilhando.
 */
(function () {
    'use strict';

    const LARGURA_CAPTURA = 960; // largura "virtual" usada pro layout não quebrar/empilhar

    function comEstadoCarregando(botao, callback) {
        if (!botao) return callback();
        const htmlOriginal = botao.innerHTML;
        const larguraOriginal = botao.style.minWidth;
        botao.style.minWidth = botao.offsetWidth + 'px';
        botao.disabled = true;
        botao.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Gerando imagem...';

        const restaurar = () => {
            botao.disabled = false;
            botao.innerHTML = htmlOriginal;
            botao.style.minWidth = larguraOriginal;
        };

        Promise.resolve(callback()).finally(restaurar);
    }

    async function gerarCanvas(alvo, corFundo) {
        const escala = Math.min(window.devicePixelRatio || 1, 2) * 1.6;

        // Enquanto captura, aplica a classe "modo-captura" (fontes/ícones
        // maiores, só nessa cópia renderizada) pra imagem sair legível
        // mesmo em telas pequenas de celular.
        alvo.classList.add('modo-captura');

        try {
            // Simula uma janela larga (windowWidth) pra layout usar a versão
            // "desktop" do card, sem os pontos/nomes empilhando em várias
            // linhas como acontece na largura real de um celular.
            return await html2canvas(alvo, {
                backgroundColor: corFundo || '#ffffff',
                scale: escala,
                useCORS: true,
                logging: false,
                windowWidth: LARGURA_CAPTURA,
                windowHeight: Math.max(window.innerHeight, alvo.scrollHeight + 200),
            });
        } finally {
            alvo.classList.remove('modo-captura');
        }
    }

    function canvasParaBlobPng(canvas) {
        return new Promise((resolve, reject) => {
            canvas.toBlob((blob) => {
                if (blob) resolve(blob);
                else reject(new Error('Não foi possível gerar a imagem.'));
            }, 'image/png');
        });
    }

    /**
     * Embrulha o canvas num PDF de página única (mesmo tamanho da imagem,
     * sem margens) pra poder compartilhar como "documento" — o WhatsApp não
     * recomprime documentos, então a qualidade chega idêntica ao PNG.
     */
    function canvasParaBlobPdf(canvas) {
        const { jsPDF } = window.jspdf || {};
        if (!jsPDF) return null;

        const orientacao = canvas.width >= canvas.height ? 'l' : 'p';
        const doc = new jsPDF({
            orientation: orientacao,
            unit: 'px',
            format: [canvas.width, canvas.height],
            compress: true,
        });

        const dataUrl = canvas.toDataURL('image/jpeg', 0.95);
        doc.addImage(dataUrl, 'JPEG', 0, 0, canvas.width, canvas.height);

        return doc.output('blob');
    }

    function baixarArquivo(blob, nomeArquivo, extensao) {
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = nomeArquivo + '.' + extensao;
        document.body.appendChild(link);
        link.click();
        link.remove();
        setTimeout(() => URL.revokeObjectURL(url), 2000);
    }

    async function compartilharComoImagem(seletorAlvo, opcoes) {
        const alvo = document.querySelector(seletorAlvo);
        if (!alvo) {
            console.error('Elemento alvo não encontrado para compartilhar:', seletorAlvo);
            return;
        }

        const nomeArquivo = opcoes.arquivo || 'cartoleirao';

        let canvas;
        try {
            canvas = await gerarCanvas(alvo, opcoes.corFundo);
        } catch (erro) {
            console.error(erro);
            alert('Não foi possível gerar a imagem agora. Tente novamente.');
            return;
        }

        // Pro WhatsApp: tenta compartilhar como PDF (documento, sem
        // recompressão). Só a imagem, sem título/texto — evita que o
        // WhatsApp monte um card de texto/link por cima do arquivo.
        const blobPdf = canvasParaBlobPdf(canvas);
        if (blobPdf) {
            const arquivoPdf = new File([blobPdf], nomeArquivo + '.pdf', { type: 'application/pdf' });
            if (navigator.canShare && navigator.canShare({ files: [arquivoPdf] })) {
                try {
                    await navigator.share({ files: [arquivoPdf] });
                    return;
                } catch (erro) {
                    if (erro && erro.name === 'AbortError') return; // usuário cancelou
                    // sem suporte real apesar do canShare: cai no fallback abaixo
                }
            }
        }

        // Fallback: gera o PNG (mesma imagem, sem conversão) — usado quando
        // o navegador não sabe compartilhar arquivo (principalmente
        // desktop), ou não tem suporte a gerar o PDF.
        let blobPng;
        try {
            blobPng = await canvasParaBlobPng(canvas);
        } catch (erro) {
            console.error(erro);
            alert('Não foi possível gerar a imagem agora. Tente novamente.');
            return;
        }

        const arquivoPng = new File([blobPng], nomeArquivo + '.png', { type: 'image/png' });
        if (!blobPdf && navigator.canShare && navigator.canShare({ files: [arquivoPng] })) {
            try {
                await navigator.share({ files: [arquivoPng] });
                return;
            } catch (erro) {
                if (erro && erro.name === 'AbortError') return;
            }
        }

        baixarArquivo(blobPng, nomeArquivo, 'png');
        window.setTimeout(() => {
            alert('Imagem baixada! Anexe o arquivo na conversa do WhatsApp.');
        }, 300);
    }

    document.addEventListener('click', function (evento) {
        const botao = evento.target.closest('[data-compartilhar-imagem]');
        if (!botao) return;
        evento.preventDefault();

        comEstadoCarregando(botao, () =>
            compartilharComoImagem(botao.getAttribute('data-compartilhar-imagem'), {
                arquivo: botao.getAttribute('data-arquivo'),
                corFundo: botao.getAttribute('data-bg'),
            })
        );
    });
})();
