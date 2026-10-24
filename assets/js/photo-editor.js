/* ============================================================
 * AUCTION HUB GROUP — assets/js/photo-editor.js
 * Manual photo editor for Admin → AI Photos (no AI, no cost):
 * rotate, crop, blur an area (number plates, faces), brightness /
 * contrast / saturation, undo, reset. The vehicle itself is never
 * retouched — these are framing and light fixes only.
 *
 *   const ed = AHGPhotoEditor(containerEl, imageUrl);
 *   ed.toDataURL() → 'data:image/jpeg;base64,…' (null if not loaded)
 * ============================================================ */
window.AHGPhotoEditor = function (root, imageUrl) {
    root.innerHTML = `
      <div class="pe-bar">
        <button type="button" data-a="rotl" title="Rotate left"><i class="fas fa-rotate-left"></i></button>
        <button type="button" data-a="rotr" title="Rotate right"><i class="fas fa-rotate-right"></i></button>
        <button type="button" data-a="crop" title="Drag a box, then press Apply"><i class="fas fa-crop-simple"></i> Crop</button>
        <button type="button" data-a="blur" title="Drag a box over a number plate or face, then press Apply"><i class="fas fa-eye-slash"></i> Blur area</button>
        <button type="button" data-a="apply" class="pe-apply" hidden><i class="fas fa-check"></i> Apply</button>
        <span class="pe-sep"></span>
        <button type="button" data-a="undo" title="Undo"><i class="fas fa-arrow-rotate-left"></i> Undo</button>
        <button type="button" data-a="reset" title="Back to the original"><i class="fas fa-clock-rotate-left"></i> Reset</button>
      </div>
      <div class="pe-sliders">
        <label>Brightness <input type="range" data-f="brightness" min="60" max="160" value="100"></label>
        <label>Contrast <input type="range" data-f="contrast" min="60" max="160" value="100"></label>
        <label>Saturation <input type="range" data-f="saturate" min="40" max="180" value="100"></label>
      </div>
      <div class="pe-stage"><canvas></canvas><p class="pe-hint">Loading photo…</p></div>`;

    const view = root.querySelector('canvas'), vctx = view.getContext('2d');
    const hint = root.querySelector('.pe-hint'), applyBtn = root.querySelector('[data-a=apply]');
    let work = document.createElement('canvas');       // full-size working image
    let original = null, history = [], tool = null, sel = null, drag = null;
    const f = {brightness: 100, contrast: 100, saturate: 100};
    const filterStr = () => `brightness(${f.brightness}%) contrast(${f.contrast}%) saturate(${f.saturate}%)`;

    const img = new Image();
    img.crossOrigin = 'anonymous';                      // ImageKit sends CORS headers, so the canvas stays exportable
    img.onload = () => {
        work.width = img.naturalWidth; work.height = img.naturalHeight;
        work.getContext('2d').drawImage(img, 0, 0);
        original = clone(work);
        hint.textContent = '';
        draw();
    };
    img.onerror = () => { hint.textContent = 'Could not load this photo for editing.'; };
    img.src = imageUrl;

    function clone(c) { const n = document.createElement('canvas'); n.width = c.width; n.height = c.height; n.getContext('2d').drawImage(c, 0, 0); return n; }
    function push() { history.push(clone(work)); if (history.length > 15) history.shift(); }

    // Fit the working image into the stage
    let scale = 1;
    function draw() {
        if (!work.width) return;
        const maxW = root.querySelector('.pe-stage').clientWidth || 800, maxH = Math.min(560, window.innerHeight * 0.6);
        scale = Math.min(maxW / work.width, maxH / work.height, 1);
        view.width = Math.round(work.width * scale); view.height = Math.round(work.height * scale);
        vctx.filter = filterStr();
        vctx.drawImage(work, 0, 0, view.width, view.height);
        vctx.filter = 'none';
        if (sel) {
            vctx.save();
            vctx.strokeStyle = tool === 'blur' ? '#e5233b' : '#ffd54f';
            vctx.lineWidth = 2; vctx.setLineDash([6, 4]);
            vctx.strokeRect(sel.x, sel.y, sel.w, sel.h);
            if (tool === 'crop') {          // dim what will be cut away
                vctx.fillStyle = 'rgba(0,0,0,.45)';
                vctx.fillRect(0, 0, view.width, sel.y);
                vctx.fillRect(0, sel.y + sel.h, view.width, view.height - sel.y - sel.h);
                vctx.fillRect(0, sel.y, sel.x, sel.h);
                vctx.fillRect(sel.x + sel.w, sel.y, view.width - sel.x - sel.w, sel.h);
            }
            vctx.restore();
        }
    }
    window.addEventListener('resize', draw);

    // Drag to select (mouse + touch)
    const pos = e => { const r = view.getBoundingClientRect(), p = e.touches ? e.touches[0] : e;
        return {x: Math.max(0, Math.min(view.width, p.clientX - r.left)), y: Math.max(0, Math.min(view.height, p.clientY - r.top))}; };
    const start = e => { if (!tool) return; e.preventDefault(); drag = pos(e); sel = {x: drag.x, y: drag.y, w: 0, h: 0}; };
    const move = e => { if (!drag) return; e.preventDefault(); const p = pos(e);
        sel = {x: Math.min(drag.x, p.x), y: Math.min(drag.y, p.y), w: Math.abs(p.x - drag.x), h: Math.abs(p.y - drag.y)}; draw(); };
    const end = () => { drag = null; applyBtn.hidden = !(sel && sel.w > 8 && sel.h > 8); };
    view.addEventListener('mousedown', start); view.addEventListener('touchstart', start, {passive: false});
    window.addEventListener('mousemove', move); view.addEventListener('touchmove', move, {passive: false});
    window.addEventListener('mouseup', end); view.addEventListener('touchend', end);

    function setTool(t) {
        tool = tool === t ? null : t; sel = null; applyBtn.hidden = true;
        root.querySelectorAll('[data-a=crop],[data-a=blur]').forEach(b => b.classList.toggle('active', b.dataset.a === tool));
        view.style.cursor = tool ? 'crosshair' : 'default';
        draw();
    }

    function apply() {
        if (!sel) return;
        const s = {x: Math.round(sel.x / scale), y: Math.round(sel.y / scale), w: Math.round(sel.w / scale), h: Math.round(sel.h / scale)};
        push();
        if (tool === 'crop') {
            const n = document.createElement('canvas'); n.width = s.w; n.height = s.h;
            n.getContext('2d').drawImage(work, s.x, s.y, s.w, s.h, 0, 0, s.w, s.h);
            work = n;
        } else if (tool === 'blur') {
            // Pixelate hard, then soften — unreadable plates, no hard blocks
            const tiny = document.createElement('canvas'), k = Math.max(6, Math.round(Math.min(s.w, s.h) / 6));
            tiny.width = Math.max(1, Math.round(s.w / k)); tiny.height = Math.max(1, Math.round(s.h / k));
            tiny.getContext('2d').drawImage(work, s.x, s.y, s.w, s.h, 0, 0, tiny.width, tiny.height);
            const ctx = work.getContext('2d');
            ctx.save(); ctx.imageSmoothingEnabled = true; ctx.filter = `blur(${Math.round(k / 2)}px)`;
            ctx.drawImage(tiny, 0, 0, tiny.width, tiny.height, s.x, s.y, s.w, s.h);
            ctx.restore();
        }
        setTool(tool); draw();
    }

    function rotate(dir) {
        push();
        const n = document.createElement('canvas'); n.width = work.height; n.height = work.width;
        const c = n.getContext('2d');
        c.translate(n.width / 2, n.height / 2); c.rotate(dir * Math.PI / 2);
        c.drawImage(work, -work.width / 2, -work.height / 2);
        work = n; sel = null; draw();
    }

    root.querySelector('.pe-bar').addEventListener('click', e => {
        const b = e.target.closest('button'); if (!b || !work.width) return;
        const a = b.dataset.a;
        if (a === 'rotl') rotate(-1);
        else if (a === 'rotr') rotate(1);
        else if (a === 'crop' || a === 'blur') setTool(a);
        else if (a === 'apply') apply();
        else if (a === 'undo' && history.length) { work = history.pop(); sel = null; draw(); }
        else if (a === 'reset' && original) {
            push(); work = clone(original);
            root.querySelectorAll('.pe-sliders input').forEach(i => { i.value = 100; f[i.dataset.f] = 100; });
            sel = null; draw();
        }
    });
    root.querySelectorAll('.pe-sliders input').forEach(i => i.addEventListener('input', () => { f[i.dataset.f] = +i.value; draw(); }));

    return {
        /** Full-size result with the light settings baked in */
        toDataURL() {
            if (!work.width) return null;
            const out = document.createElement('canvas'); out.width = work.width; out.height = work.height;
            const c = out.getContext('2d'); c.filter = filterStr(); c.drawImage(work, 0, 0);
            return out.toDataURL('image/jpeg', 0.9);
        },
        changed() { return history.length > 0 || f.brightness !== 100 || f.contrast !== 100 || f.saturate !== 100; },
    };
};
