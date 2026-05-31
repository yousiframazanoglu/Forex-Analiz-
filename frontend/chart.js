// ============================================================
// ForexAnaliz — Japon Mumu Chart Motoru
// Tamamen sıfırdan Canvas ile yazıldı
// ============================================================

class CandleChart {
    constructor(canvasId) {
        this.canvas  = document.getElementById(canvasId);
        this.ctx     = this.canvas.getContext("2d");
        this.data    = [];
        this.offset  = 0;   // kaydırma
        this.zoom    = 1;   // 1 = normal, < 1 = uzaklaştır
        this.isDragging = false;
        this.dragStartX = 0;
        this.dragStartOffset = 0;

        this._bindEvents();
        this._resize();
        window.addEventListener("resize", () => this._resize());
    }

    // ── Veri yükle ve çiz ─────────────────────────────────────
    load(candles) {
        this.data   = candles;
        this.offset = 0;
        this.draw();
    }

    // ── Ana çizim ─────────────────────────────────────────────
    draw() {
        const { ctx, canvas, data } = this;
        if (!data.length) return;

        const W = canvas.width;
        const H = canvas.height;
        const PAD = { top: 20, right: 60, bottom: 50, left: 10 };

        // Arka plan
        ctx.fillStyle = getComputedStyle(document.documentElement)
            .getPropertyValue("--surface") || "#fff";
        ctx.fillRect(0, 0, W, H);

        // Çizim alanı
        const chartW = W - PAD.left - PAD.right;
        const chartH = H - PAD.top  - PAD.bottom;

        // Görünür mum sayısı
        const BASE_CANDLE_W = 12;
        const candleW  = Math.max(4, BASE_CANDLE_W * this.zoom);
        const gap      = Math.max(1, candleW * 0.2);
        const visible  = Math.floor(chartW / (candleW + gap));

        const startIdx = Math.max(0, data.length - visible - Math.floor(this.offset));
        const endIdx   = Math.min(data.length, startIdx + visible);
        const slice    = data.slice(startIdx, endIdx);

        if (!slice.length) return;

        // Min/Max
        const highs  = slice.map(c => c.high);
        const lows   = slice.map(c => c.low);
        const maxH   = Math.max(...highs);
        const minL   = Math.min(...lows);
        const range  = maxH - minL || 0.001;
        const scaleY = v => PAD.top + chartH - ((v - minL) / range) * chartH;

        // Grid çizgileri
        ctx.strokeStyle = "#E2E2DE";
        ctx.lineWidth   = 0.5;
        const gridLines = 6;
        for (let i = 0; i <= gridLines; i++) {
            const y     = PAD.top + (chartH / gridLines) * i;
            const price = maxH - (range / gridLines) * i;
            ctx.beginPath();
            ctx.moveTo(PAD.left, y);
            ctx.lineTo(W - PAD.right, y);
            ctx.stroke();
            // Fiyat etiketi
            ctx.fillStyle  = "#AAAAAA";
            ctx.font       = "11px 'DM Mono', monospace";
            ctx.textAlign  = "left";
            ctx.fillText(price.toFixed(this._dp()), W - PAD.right + 4, y + 4);
        }

        // Mumları çiz
        slice.forEach((c, i) => {
            const x     = PAD.left + i * (candleW + gap) + candleW / 2;
            const isUp  = c.close >= c.open;
            const color = isUp ? "#2E8B57" : "#C0392B";

            ctx.strokeStyle = color;
            ctx.fillStyle   = isUp ? "#2E8B57" : "#C0392B";
            ctx.lineWidth   = 1;

            // Gölge (fitil)
            ctx.beginPath();
            ctx.moveTo(x, scaleY(c.high));
            ctx.lineTo(x, scaleY(c.low));
            ctx.stroke();

            // Gövde
            const bodyTop = scaleY(Math.max(c.open, c.close));
            const bodyBot = scaleY(Math.min(c.open, c.close));
            const bodyH   = Math.max(1, bodyBot - bodyTop);
            const bodyX   = PAD.left + i * (candleW + gap);

            if (isUp) {
                ctx.fillRect(bodyX, bodyTop, candleW, bodyH);
            } else {
                ctx.fillRect(bodyX, bodyTop, candleW, bodyH);
            }

            // Tarih etiketi (her 5'inci mum)
            if (i % Math.max(1, Math.floor(visible / 8)) === 0) {
                ctx.fillStyle = "#AAAAAA";
                ctx.font      = "10px 'DM Sans', sans-serif";
                ctx.textAlign = "center";
                const label   = c.time ? c.time.substring(5) : "";  // MM-DD
                ctx.fillText(label, x, H - PAD.bottom + 16);
            }
        });

        // Crosshair etiket (son fiyat çizgisi)
        if (slice.length) {
            const last  = slice[slice.length - 1];
            const y     = scaleY(last.close);
            const isUp  = last.close >= last.open;
            ctx.setLineDash([4, 4]);
            ctx.strokeStyle = isUp ? "#2E8B57" : "#C0392B";
            ctx.lineWidth   = 1;
            ctx.beginPath();
            ctx.moveTo(PAD.left, y);
            ctx.lineTo(W - PAD.right, y);
            ctx.stroke();
            ctx.setLineDash([]);

            // Fiyat badge
            ctx.fillStyle   = isUp ? "#2E8B57" : "#C0392B";
            ctx.fillRect(W - PAD.right + 2, y - 9, 56, 18);
            ctx.fillStyle   = "#fff";
            ctx.font        = "11px 'DM Mono', monospace";
            ctx.textAlign   = "left";
            ctx.fillText(last.close.toFixed(this._dp()), W - PAD.right + 5, y + 4);
        }

        // Hacim barları (alt %15)
        const volH = chartH * 0.12;
        const maxV = Math.max(...slice.map(c => c.volume || 0));
        if (maxV > 0) {
            slice.forEach((c, i) => {
                const isUp = c.close >= c.open;
                const bh   = ((c.volume || 0) / maxV) * volH;
                const bx   = PAD.left + i * (candleW + gap);
                const by   = H - PAD.bottom - bh;
                ctx.fillStyle = isUp ? "rgba(46,139,87,0.3)" : "rgba(192,57,43,0.3)";
                ctx.fillRect(bx, by, candleW, bh);
            });
        }
    }

    _dp() {
        // JPY ve TRY için 2, diğerleri için 4-5 decimal
        const sym = this.canvas.dataset.symbol || "";
        return (sym.includes("JPY") || sym.includes("TRY")) ? 2 : 5;
    }

    // ── Mouse/Touch olayları ──────────────────────────────────
    _bindEvents() {
        const c = this.canvas;

        // Drag (kaydırma)
        c.addEventListener("mousedown", e => {
            this.isDragging    = true;
            this.dragStartX    = e.clientX;
            this.dragStartOffset = this.offset;
        });
        c.addEventListener("mousemove", e => {
            if (!this.isDragging) return;
            const dx = e.clientX - this.dragStartX;
            this.offset = this.dragStartOffset + dx / 14;
            this.offset = Math.max(0, Math.min(this.offset, this.data.length - 10));
            this.draw();
        });
        c.addEventListener("mouseup",   () => { this.isDragging = false; });
        c.addEventListener("mouseleave",() => { this.isDragging = false; });

        // Zoom (scroll)
        c.addEventListener("wheel", e => {
            e.preventDefault();
            this.zoom += e.deltaY > 0 ? -0.1 : 0.1;
            this.zoom  = Math.max(0.3, Math.min(this.zoom, 4));
            this.draw();
        }, { passive: false });

        // Touch
        let lastTouch = 0;
        c.addEventListener("touchstart", e => {
            if (e.touches.length === 1) {
                this.isDragging    = true;
                this.dragStartX    = e.touches[0].clientX;
                this.dragStartOffset = this.offset;
            }
        });
        c.addEventListener("touchmove", e => {
            if (!this.isDragging || e.touches.length !== 1) return;
            e.preventDefault();
            const dx = e.touches[0].clientX - this.dragStartX;
            this.offset = this.dragStartOffset + dx / 14;
            this.offset = Math.max(0, Math.min(this.offset, this.data.length - 10));
            this.draw();
        }, { passive: false });
        c.addEventListener("touchend", () => { this.isDragging = false; });
    }

    _resize() {
        const parent = this.canvas.parentElement;
        this.canvas.width  = parent.clientWidth  || 800;
        this.canvas.height = parent.clientHeight || 420;
        this.draw();
    }
}
