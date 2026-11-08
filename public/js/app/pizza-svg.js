(function () {
/**
 * Desenho da pizza de vários sabores, compartilhado pelo cardápio público e
 * pelos painéis (garçom e Atender Pedido). Funções puras: recebem os dados e
 * devolvem uma string SVG para x-html. A ordem dos sabores é a da linha do
 * carrinho. Redonda: fatias em leque; quadrada: faixas (2×2 com 4 sabores).
 */

const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]));

/** Foto real (o placeholder antigo "Sem Imagem" não conta como foto). */
const temFoto = (url) => !!url && !/Sem(%20| )Imagem/.test(url);

const svgPizzaRedonda = (n, sabores) => {
    const c = 64, r = 60;
    let defs = '', fatias = '', linhas = '';
    for (let i = 0; i < n; i++) {
        const sabor = sabores[i];
        if (temFoto(sabor?.foto)) {
            defs += `<pattern id="fatia-${i}" patternUnits="userSpaceOnUse" width="128" height="128"><image href="${esc(sabor.foto)}" x="0" y="0" width="128" height="128" preserveAspectRatio="xMidYMid slice"/></pattern>`;
        }
        const fill = sabor ? (temFoto(sabor.foto) ? `url(#fatia-${i})` : '#5a4636') : '#2A221C';
        if (n === 1) {
            fatias += `<circle cx="${c}" cy="${c}" r="${r}" fill="${fill}"/>`;
            continue;
        }
        const a0 = (-90 + i * 360 / n) * Math.PI / 180;
        const a1 = (-90 + (i + 1) * 360 / n) * Math.PI / 180;
        const x0 = c + r * Math.cos(a0), y0 = c + r * Math.sin(a0);
        const x1 = c + r * Math.cos(a1), y1 = c + r * Math.sin(a1);
        const grande = 360 / n > 180 ? 1 : 0;
        fatias += `<path d="M${c} ${c} L${x0.toFixed(2)} ${y0.toFixed(2)} A${r} ${r} 0 ${grande} 1 ${x1.toFixed(2)} ${y1.toFixed(2)} Z" fill="${fill}"/>`;
        linhas += `<line x1="${c}" y1="${c}" x2="${x0.toFixed(2)}" y2="${y0.toFixed(2)}" stroke="#14100D" stroke-width="3"/>`;
    }
    return `<svg viewBox="0 0 128 128" class="size-full" aria-hidden="true"><defs>${defs}</defs><circle cx="${c}" cy="${c}" r="${r + 2}" fill="#3a2a1c"/>${fatias}${linhas}<circle cx="${c}" cy="${c}" r="${r}" fill="none" stroke="#F2B33D" stroke-width="3"/></svg>`;
};

const svgPizzaQuadrada = (n, sabores) => {
    const m = 4, lado = 120, raio = 14;
    // Grade das partes: 4 sabores = 2×2; os demais, faixas verticais.
    const colunas = n === 4 ? 2 : n;
    const linhas = n === 4 ? 2 : 1;
    const w = lado / colunas, h = lado / linhas;
    let defs = `<clipPath id="pizza-quadrada"><rect x="${m}" y="${m}" width="${lado}" height="${lado}" rx="${raio}"/></clipPath>`;
    let partes = '', divisas = '';
    for (let i = 0; i < n; i++) {
        const sabor = sabores[i];
        const x = m + (i % colunas) * w, y = m + Math.floor(i / colunas) * h;
        if (temFoto(sabor?.foto)) {
            // As fotos são de pizzas redondas: cada parte mostra o miolo
            // da foto (ampliada e centrada nela), sem a borda curva.
            const zoom = Math.max(w, h) * 1.45;
            const ix = x + w / 2 - zoom / 2, iy = y + h / 2 - zoom / 2;
            defs += `<pattern id="fatia-${i}" patternUnits="userSpaceOnUse" width="128" height="128"><image href="${esc(sabor.foto)}" x="${ix.toFixed(2)}" y="${iy.toFixed(2)}" width="${zoom.toFixed(2)}" height="${zoom.toFixed(2)}" preserveAspectRatio="xMidYMid slice"/></pattern>`;
        }
        const fill = sabor ? (temFoto(sabor.foto) ? `url(#fatia-${i})` : '#5a4636') : '#2A221C';
        partes += `<rect x="${x.toFixed(2)}" y="${y.toFixed(2)}" width="${w.toFixed(2)}" height="${h.toFixed(2)}" fill="${fill}"/>`;
    }
    for (let c = 1; c < colunas; c++) {
        const x = (m + c * w).toFixed(2);
        divisas += `<line x1="${x}" y1="${m}" x2="${x}" y2="${m + lado}" stroke="#14100D" stroke-width="3"/>`;
    }
    for (let l = 1; l < linhas; l++) {
        const y = (m + l * h).toFixed(2);
        divisas += `<line x1="${m}" y1="${y}" x2="${m + lado}" y2="${y}" stroke="#14100D" stroke-width="3"/>`;
    }
    return `<svg viewBox="0 0 128 128" class="size-full" aria-hidden="true"><defs>${defs}</defs><rect x="${m - 2}" y="${m - 2}" width="${lado + 4}" height="${lado + 4}" rx="${raio + 2}" fill="#3a2a1c"/><g clip-path="url(#pizza-quadrada)">${partes}${divisas}</g><rect x="${m}" y="${m}" width="${lado}" height="${lado}" rx="${raio}" fill="none" stroke="#F2B33D" stroke-width="3"/></svg>`;
};

/**
 * @param {{ formato?: 'redonda'|'quadrada', quantidade: number, sabores: Array<{ foto?: string, nome?: string }> }} dados
 * @returns {string} SVG
 */
function desenharPizza({ formato = 'redonda', quantidade, sabores = [] }) {
    const n = Math.max(1, quantidade);

    return formato === 'quadrada' ? svgPizzaQuadrada(n, sabores) : svgPizzaRedonda(n, sabores);
}

window.desenharPizza = desenharPizza;
})();
