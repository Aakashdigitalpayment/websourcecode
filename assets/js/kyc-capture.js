if (window._kycCaptureInited) { window._kycCaptureSkipped = true; } else {
window._kycCaptureInited = true;
/* ===========================================================
   KYC Capture v10.9
   - Live camera (getUserMedia) सहित pan/zoom/rotate crop
   - Signature pad (canvas, finger/pen)
   - Fingerprint photo capture with line-density quality check
   - Outputs base64 (PNG/JPEG) into hidden inputs
   =========================================================== */
(function () {
  'use strict';

  /* ------------------------------------------------------------------
     1. CAMERA + CROP CONTROLLER
  ------------------------------------------------------------------ */
  const CamCrop = {
    stream: null,
    video: null,
    facing: 'environment',
    targetField: null,    // {hidden, preview, options}
    cropImage: null,      // HTMLImageElement source
    state: { x: 0, y: 0, scale: 1, rot: 0 },
    drag: null,
    canvas: null, ctx: null,
    mode: 'camera',       // 'camera' or 'crop'

    modalEl: null,
    init() {
      if (this.modalEl) return;
      this.buildModal();
    },

    buildModal() {
      const html = `
        <div class="kyc-modal" id="kycCamModal">
          <div class="kyc-modal-header">
            <div class="kyc-modal-title"><i class="fas fa-camera"></i><span id="kycCamTitle">क्यामेरा</span></div>
            <button type="button" class="kyc-modal-close" id="kycCamClose"><i class="fas fa-times"></i></button>
          </div>
          <div class="kyc-modal-body" id="kycCamBody">
            <video class="kyc-cam-video" id="kycCamVideo" autoplay playsinline muted></video>
            <canvas class="kyc-crop-canvas" id="kycCropCanvas" style="display:none;"></canvas>
            <div class="kyc-cap-hint" id="kycCamHint">क्यामेरा अगाडि राखी फोटो खिच्नुहोस्</div>
          </div>
          <div class="kyc-modal-footer" id="kycCamFooter">
            <!-- camera mode: shutter -->
            <div class="kyc-shutter-row" id="kycShutterRow">
              <button type="button" class="kyc-flip-btn" id="kycFlipCam" title="क्यामेरा फेर्नुहोस्"><i class="fas fa-rotate"></i></button>
              <button type="button" class="kyc-modal-btn shutter" id="kycShutter"><i class="fas fa-camera"></i></button>
              <button type="button" class="kyc-flip-btn" id="kycPickGallery" title="Gallery"><i class="fas fa-images"></i></button>
            </div>
            <!-- crop mode: zoom + rotate + confirm -->
            <div id="kycCropControls" style="display:none;">
              <div class="kyc-zoom-row">
                <label><i class="fas fa-search-plus"></i> Zoom</label>
                <input type="range" id="kycZoom" min="0.5" max="4" step="0.05" value="1">
              </div>
              <div class="kyc-rotate-row">
                <button type="button" class="kyc-flip-btn" id="kycRotL" title="Left"><i class="fas fa-rotate-left"></i></button>
                <button type="button" class="kyc-flip-btn" id="kycRotR" title="Right"><i class="fas fa-rotate-right"></i></button>
                <button type="button" class="kyc-flip-btn" id="kycReset" title="Reset"><i class="fas fa-undo"></i></button>
              </div>
              <div class="kyc-modal-btns">
                <button type="button" class="kyc-modal-btn cancel" id="kycRetake"><i class="fas fa-redo me-1"></i>फेरि खिच्नुहोस्</button>
                <button type="button" class="kyc-modal-btn confirm" id="kycConfirm"><i class="fas fa-check me-1"></i>स्वीकार</button>
              </div>
            </div>
          </div>
          <input type="file" id="kycGalleryInput" accept=".jpg,.jpeg,image/jpeg" style="display:none;">
        </div>
      `;
      const div = document.createElement('div');
      div.innerHTML = html;
      document.body.appendChild(div.firstElementChild);

      this.modalEl = document.getElementById('kycCamModal');
      this.video = document.getElementById('kycCamVideo');
      this.canvas = document.getElementById('kycCropCanvas');
      this.ctx = this.canvas.getContext('2d');

      document.getElementById('kycCamClose').addEventListener('click', () => this.close());
      document.getElementById('kycShutter').addEventListener('click', () => this.snap());
      document.getElementById('kycFlipCam').addEventListener('click', () => this.flip());
      document.getElementById('kycPickGallery').addEventListener('click', () => document.getElementById('kycGalleryInput').click());
      document.getElementById('kycGalleryInput').addEventListener('change', (e) => this.fromGallery(e));
      document.getElementById('kycRetake').addEventListener('click', () => this.retake());
      document.getElementById('kycConfirm').addEventListener('click', () => this.confirm());
      document.getElementById('kycZoom').addEventListener('input', (e) => { this.state.scale = parseFloat(e.target.value); this.draw(); });
      document.getElementById('kycRotL').addEventListener('click', () => { this.state.rot -= 90; this.draw(); });
      document.getElementById('kycRotR').addEventListener('click', () => { this.state.rot += 90; this.draw(); });
      document.getElementById('kycReset').addEventListener('click', () => { this.state = { x: 0, y: 0, scale: 1, rot: 0 }; document.getElementById('kycZoom').value = 1; this.draw(); });

      // Pan handlers
      this.attachPan();
    },

    open(field) {
      this.targetField = field;
      this.init();
      document.getElementById('kycCamTitle').textContent = field.options.title || 'क्यामेरा';
      this.modalEl.classList.add('active');
      this.startCamera();
    },

    async startCamera() {
      this.mode = 'camera';
      this.video.style.display = '';
      this.canvas.style.display = 'none';
      document.getElementById('kycShutterRow').style.display = '';
      document.getElementById('kycCropControls').style.display = 'none';
      document.getElementById('kycCamHint').style.display = '';
      try {
        if (this.stream) this.stopStream();
        this.stream = await navigator.mediaDevices.getUserMedia({
          video: { facingMode: this.facing, width: { ideal: 1920 }, height: { ideal: 1080 } },
          audio: false
        });
        this.video.srcObject = this.stream;
      } catch (e) {
        alert('क्यामेरा खोल्न सकिएन: ' + (e && e.message ? e.message : 'Permission denied'));
        // fallback: open gallery
        document.getElementById('kycGalleryInput').click();
      }
    },

    stopStream() {
      if (this.stream) {
        this.stream.getTracks().forEach(t => t.stop());
        this.stream = null;
      }
    },

    flip() {
      this.facing = this.facing === 'environment' ? 'user' : 'environment';
      this.startCamera();
    },

    snap() {
      const v = this.video;
      const w = v.videoWidth || 1280, h = v.videoHeight || 720;
      const tmp = document.createElement('canvas');
      tmp.width = w; tmp.height = h;
      tmp.getContext('2d').drawImage(v, 0, 0, w, h);
      this.cropImage = new Image();
      this.cropImage.onload = () => this.enterCrop();
      this.cropImage.src = tmp.toDataURL('image/jpeg', 0.92);
    },

    fromGallery(e) {
      const f = e.target.files && e.target.files[0];
      if (!f) return;
      const name = (f.name || '').toLowerCase();
      const okExt = name.endsWith('.jpg') || name.endsWith('.jpeg');
      const mime = (f.type || '').toLowerCase();
      if (mime.startsWith('image/') && mime !== 'image/jpeg') {
        alert('कृपया JPG वा JPEG फाइल मात्र छान्नुहोस्। PDF/Word/अन्य स्वीकार्य छैन।');
        e.target.value = '';
        this.close();
        return;
      }
      if (!okExt && mime !== 'image/jpeg') {
        alert('कृपया JPG वा JPEG फाइल मात्र छान्नुहोस्। PDF/Word/अन्य स्वीकार्य छैन।');
        e.target.value = '';
        this.close();
        return;
      }
      if (mime === 'application/octet-stream' && !okExt) {
        alert('कृपया JPG वा JPEG फाइल मात्र छान्नुहोस्। PDF/Word/अन्य स्वीकार्य छैन।');
        e.target.value = '';
        this.close();
        return;
      }
      const reader = new FileReader();
      reader.onload = (ev) => {
        this.cropImage = new Image();
        this.cropImage.onload = () => this.enterCrop();
        this.cropImage.src = ev.target.result;
      };
      reader.readAsDataURL(f);
      e.target.value = '';
    },

    enterCrop() {
      this.mode = 'crop';
      this.stopStream();
      this.video.style.display = 'none';
      this.canvas.style.display = '';
      document.getElementById('kycShutterRow').style.display = 'none';
      document.getElementById('kycCropControls').style.display = '';
      document.getElementById('kycCamHint').style.display = 'none';
      document.getElementById('kycZoom').value = 1;
      this.state = { x: 0, y: 0, scale: 1, rot: 0 };

      // Choose canvas aspect ratio per field
      const opts = this.targetField.options;
      const ratio = opts.aspect || (4 / 3);
      const body = document.getElementById('kycCamBody');
      const maxW = body.clientWidth - 16;
      const maxH = body.clientHeight - 16;
      let cw = maxW, ch = cw / ratio;
      if (ch > maxH) { ch = maxH; cw = ch * ratio; }
      this.canvas.width = cw;
      this.canvas.height = ch;
      this.canvas.style.width = cw + 'px';
      this.canvas.style.height = ch + 'px';
      this.draw();
    },

    draw() {
      const { ctx, canvas, cropImage, state } = this;
      if (!cropImage) return;
      ctx.fillStyle = '#000';
      ctx.fillRect(0, 0, canvas.width, canvas.height);

      // Compute base scale to "cover" canvas
      const cw = canvas.width, ch = canvas.height;
      const iw = cropImage.naturalWidth, ih = cropImage.naturalHeight;
      const baseScale = Math.max(cw / iw, ch / ih);
      const s = baseScale * state.scale;

      ctx.save();
      ctx.translate(cw / 2 + state.x, ch / 2 + state.y);
      ctx.rotate((state.rot * Math.PI) / 180);
      ctx.drawImage(cropImage, -iw * s / 2, -ih * s / 2, iw * s, ih * s);
      ctx.restore();

      // Crop frame guideline
      ctx.strokeStyle = 'rgba(255,255,255,.6)';
      ctx.lineWidth = 1;
      ctx.setLineDash([5, 5]);
      ctx.strokeRect(0.5, 0.5, cw - 1, ch - 1);
      ctx.setLineDash([]);
    },

    attachPan() {
      const c = this.canvas;
      const pos = (e) => {
        const r = c.getBoundingClientRect();
        const t = e.touches ? e.touches[0] : e;
        return { x: t.clientX - r.left, y: t.clientY - r.top };
      };
      const onStart = (e) => {
        if (this.mode !== 'crop') return;
        e.preventDefault();
        const p = pos(e);
        if (e.touches && e.touches.length === 2) {
          // pinch start
          const dx = e.touches[0].clientX - e.touches[1].clientX;
          const dy = e.touches[0].clientY - e.touches[1].clientY;
          this.drag = { pinch: true, dist: Math.hypot(dx, dy), startScale: this.state.scale };
        } else {
          this.drag = { x: p.x - this.state.x, y: p.y - this.state.y };
        }
      };
      const onMove = (e) => {
        if (!this.drag || this.mode !== 'crop') return;
        e.preventDefault();
        if (this.drag.pinch && e.touches && e.touches.length === 2) {
          const dx = e.touches[0].clientX - e.touches[1].clientX;
          const dy = e.touches[0].clientY - e.touches[1].clientY;
          const newDist = Math.hypot(dx, dy);
          const ratio = newDist / this.drag.dist;
          this.state.scale = Math.max(0.5, Math.min(4, this.drag.startScale * ratio));
          document.getElementById('kycZoom').value = this.state.scale;
        } else {
          const p = pos(e);
          this.state.x = p.x - this.drag.x;
          this.state.y = p.y - this.drag.y;
        }
        this.draw();
      };
      const onEnd = () => { this.drag = null; };

      c.addEventListener('mousedown', onStart);
      c.addEventListener('mousemove', onMove);
      window.addEventListener('mouseup', onEnd);
      c.addEventListener('touchstart', onStart, { passive: false });
      c.addEventListener('touchmove', onMove, { passive: false });
      c.addEventListener('touchend', onEnd);

      // wheel zoom (desktop)
      c.addEventListener('wheel', (e) => {
        if (this.mode !== 'crop') return;
        e.preventDefault();
        const dz = e.deltaY < 0 ? 1.06 : 0.94;
        this.state.scale = Math.max(0.5, Math.min(4, this.state.scale * dz));
        document.getElementById('kycZoom').value = this.state.scale;
        this.draw();
      }, { passive: false });
    },

    retake() {
      if (this.targetField.options.gallery) {
        document.getElementById('kycGalleryInput').click();
      } else {
        this.startCamera();
      }
    },

    confirm() {
      // Export the visible cropped canvas at higher resolution
      const opts = this.targetField.options;
      const outW = opts.outWidth || 800;
      const ratio = opts.aspect || (4 / 3);
      const outH = Math.round(outW / ratio);
      const out = document.createElement('canvas');
      out.width = outW; out.height = outH;
      const octx = out.getContext('2d');

      // Render same transform but to output dimensions
      const cropImage = this.cropImage;
      const iw = cropImage.naturalWidth, ih = cropImage.naturalHeight;
      const baseScale = Math.max(outW / iw, outH / ih);
      const s = baseScale * this.state.scale;

      // Map pan from canvas-pixel space to output-pixel space
      const sx = outW / this.canvas.width;
      const sy = outH / this.canvas.height;

      octx.fillStyle = '#fff';
      octx.fillRect(0, 0, outW, outH);
      octx.save();
      octx.translate(outW / 2 + this.state.x * sx, outH / 2 + this.state.y * sy);
      octx.rotate((this.state.rot * Math.PI) / 180);
      octx.drawImage(cropImage, -iw * s / 2, -ih * s / 2, iw * s, ih * s);
      octx.restore();

      const dataUrl = out.toDataURL('image/jpeg', 0.88);
      this.targetField.hidden.value = dataUrl;
      this.renderPreview(this.targetField, dataUrl);

      // Field-specific post-process (e.g., fingerprint quality check)
      if (opts.afterConfirm) {
        try { opts.afterConfirm(dataUrl, this.targetField); } catch (e) { if (window.console) console.warn('KYC post-process skipped', e); }
      }
      this.close();
    },

    renderPreview(field, dataUrl) {
      const el = field.preview;
      el.innerHTML = `
        <div class="kyc-cap-preview">
          <img src="${dataUrl}" alt="preview">
          <div class="kyc-cap-status"><i class="fas fa-check-circle"></i> ${field.options.label || 'क्याप्चर भयो'}</div>
          <div class="kyc-cap-actions">
            <button type="button" class="kyc-cap-btn" data-act="recap"><i class="fas fa-redo"></i> फेरि</button>
            <button type="button" class="kyc-cap-btn danger" data-act="clear"><i class="fas fa-trash"></i> हटाउनुहोस्</button>
          </div>
          <div class="kyc-cap-extra"></div>
        </div>
      `;
      field.fieldEl.classList.add('has-image');
      el.querySelector('[data-act=recap]').addEventListener('click', () => CamCrop.open(field));
      el.querySelector('[data-act=clear]').addEventListener('click', () => {
        field.hidden.value = '';
        if (field.hidden && field.hidden.name === 'photo') {
          ['photo_quality_score', 'profile_photo_quality_score'].forEach((nm) => {
            const qh = document.querySelector(`input[name="${nm}"]`);
            if (qh) qh.value = '';
          });
        }
        field.fieldEl.classList.remove('has-image');
        renderEmpty(field);
      });
    },

    close() {
      this.stopStream();
      this.modalEl.classList.remove('active');
    }
  };

  /* ------------------------------------------------------------------
     2. PUBLIC API: setup capture fields from data-attributes
        Markup pattern:
        <div class="kyc-cap-field" data-kyc-cap="passport" data-name="photo">
          <span class="kyc-cap-label">पासपोर्ट साइज फोटो <span class="req">*</span></span>
          <div class="kyc-cap-content"></div>
          <input type="hidden" name="photo">
        </div>
  ------------------------------------------------------------------ */

  const PROFILES = {
    passport: { title: 'पासपोर्ट साइज फोटो', label: 'फोटो', aspect: 3 / 4, outWidth: 600,
      afterConfirm: (dataUrl, field) => analyzePassportQuality(dataUrl, field)
    },
    citizen_front: { title: 'नागरिकता अगाडि', label: 'नागरिकता अगाडि', aspect: 1.586, outWidth: 1000,
      afterConfirm: (dataUrl, field) => analyzeDocumentTextLikelihood(dataUrl, field, 'नागरिकता अगाडि')
    },
    citizen_back: { title: 'नागरिकता पछाडि', label: 'नागरिकता पछाडि', aspect: 1.586, outWidth: 1000,
      afterConfirm: (dataUrl, field) => analyzeDocumentTextLikelihood(dataUrl, field, 'नागरिकता पछाडि')
    },
    national_id: { title: 'National ID कार्ड', label: 'NID कार्ड', aspect: 1.586, outWidth: 1000 },
    thumb: { title: 'औंठा छाप', label: 'औंठा छाप', aspect: 1, outWidth: 600,
      afterConfirm: (dataUrl, field) => analyzeFingerprint(dataUrl, field)
    },
    document: { title: 'कागजात', label: 'कागजात', aspect: 1.414, outWidth: 1200, gallery: true }
  };

  function renderEmpty(field) {
    const opts = field.options;
    field.preview.innerHTML = `
      <div class="kyc-cap-empty">
        <i class="fas fa-camera"></i>
        <div class="kyc-cap-empty-title">${opts.label || 'क्याप्चर'} खिच्नुहोस्</div>
        <div class="kyc-cap-empty-sub">मोबाइलमा क्यामेरा खुल्छ — Zoom र Crop गरेर मात्र अपलोड हुन्छ</div>
        <div class="kyc-cap-actions">
          <button type="button" class="kyc-cap-btn primary"><i class="fas fa-camera"></i> क्यामेरा</button>
          <button type="button" class="kyc-cap-btn" data-gallery="1"><i class="fas fa-images"></i> Gallery</button>
        </div>
      </div>
    `;
    field.preview.querySelector('.kyc-cap-btn.primary').addEventListener('click', () => CamCrop.open(field));
    field.preview.querySelector('[data-gallery]').addEventListener('click', () => {
      CamCrop.targetField = field;
      CamCrop.init();
      document.getElementById('kycGalleryInput').click();
      // open modal in crop mode after image loads
      CamCrop.modalEl.classList.add('active');
      document.getElementById('kycCamHint').style.display = 'none';
    });
  }

  function pickCaptureHidden(container) {
    const skip = new Set(['photo_quality_score', 'profile_photo_quality_score']);
    let found = null;
    container.querySelectorAll('input[type=hidden]').forEach((h) => {
      const n = h.getAttribute('name') || '';
      if (skip.has(n)) return;
      found = h;
    });
    return found || container.querySelector('input[type="hidden"]');
  }

  function setupCaptureFields() {
    document.querySelectorAll('.kyc-cap-field[data-kyc-cap]').forEach((el) => {
      const kind = el.dataset.kycCap;
      const opts = Object.assign({}, PROFILES[kind] || PROFILES.document);
      const hidden = pickCaptureHidden(el);
      let preview = el.querySelector('.kyc-cap-content');
      if (!preview) {
        preview = document.createElement('div');
        preview.className = 'kyc-cap-content';
        el.appendChild(preview);
      }
      const field = { fieldEl: el, hidden: hidden, preview: preview, options: opts };
      const existing = (hidden && hidden.value) ? String(hidden.value).trim() : '';
      if (existing) CamCrop.renderPreview(field, existing);
      else renderEmpty(field);
    });
  }

  /* ------------------------------------------------------------------
     3. SIGNATURE PAD
        <div class="kyc-sig-wrap" data-kyc-signature data-name="signature">
          <canvas></canvas>
          <input type="hidden" name="signature">
        </div>
  ------------------------------------------------------------------ */
  function setupSignaturePads() {
    document.querySelectorAll('[data-kyc-signature]').forEach((wrap) => {
      const hidden = wrap.querySelector('input[type=hidden]');
      const canvas = document.createElement('canvas');
      canvas.className = 'kyc-sig-canvas';
      const baseline = document.createElement('div');
      baseline.className = 'kyc-sig-baseline';
      const toolbar = document.createElement('div');
      toolbar.className = 'kyc-sig-toolbar';
      toolbar.innerHTML = `
        <div class="kyc-sig-tools">
          <button type="button" class="kyc-sig-tool active" data-w="2"><i class="fas fa-pen-fancy"></i> पातलो</button>
          <button type="button" class="kyc-sig-tool" data-w="4"><i class="fas fa-pen"></i> बाक्लो</button>
          <button type="button" class="kyc-sig-tool" data-clear="1"><i class="fas fa-eraser"></i> मेट्नुहोस्</button>
        </div>
        <div class="kyc-sig-stat">तल हस्ताक्षर गर्नुहोस्</div>
      `;
      wrap.innerHTML = '';
      wrap.appendChild(canvas);
      wrap.appendChild(baseline);
      wrap.appendChild(toolbar);
      wrap.appendChild(hidden);

      const ctx = canvas.getContext('2d');
      function resize() {
        const r = wrap.getBoundingClientRect();
        const dpr = window.devicePixelRatio || 1;
        canvas.width = r.width * dpr;
        canvas.height = 200 * dpr;
        canvas.style.width = r.width + 'px';
        canvas.style.height = '200px';
        ctx.scale(dpr, dpr);
        ctx.lineCap = 'round'; ctx.lineJoin = 'round';
        ctx.strokeStyle = '#0f172a';
      }
      resize();
      window.addEventListener('resize', resize);

      let drawing = false, last = null, width = 2, hasInk = false;
      const stat = toolbar.querySelector('.kyc-sig-stat');

      function pos(e) {
        const r = canvas.getBoundingClientRect();
        const t = e.touches ? e.touches[0] : e;
        return { x: t.clientX - r.left, y: t.clientY - r.top };
      }
      function start(e) { e.preventDefault(); drawing = true; last = pos(e); }
      function move(e) {
        if (!drawing) return;
        e.preventDefault();
        const p = pos(e);
        ctx.lineWidth = width;
        ctx.beginPath();
        ctx.moveTo(last.x, last.y);
        ctx.lineTo(p.x, p.y);
        ctx.stroke();
        last = p;
        hasInk = true;
        save();
      }
      function end() { drawing = false; }

      function save() {
        if (!hasInk) { hidden.value = ''; return; }
        // Trim to remove huge whitespace? Keep simple — full canvas
        hidden.value = canvas.toDataURL('image/png');
        stat.textContent = '✓ हस्ताक्षर सुरक्षित';
        stat.style.color = '#16a34a';
      }

      function clear() {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        hidden.value = '';
        hasInk = false;
        stat.textContent = 'तल हस्ताक्षर गर्नुहोस्';
        stat.style.color = '';
      }

      canvas.addEventListener('mousedown', start);
      canvas.addEventListener('mousemove', move);
      window.addEventListener('mouseup', end);
      canvas.addEventListener('touchstart', start, { passive: false });
      canvas.addEventListener('touchmove', move, { passive: false });
      canvas.addEventListener('touchend', end);

      toolbar.querySelectorAll('.kyc-sig-tool').forEach((b) => {
        b.addEventListener('click', () => {
          if (b.dataset.clear) { clear(); return; }
          width = parseFloat(b.dataset.w);
          toolbar.querySelectorAll('.kyc-sig-tool').forEach(x => x.classList.remove('active'));
          b.classList.add('active');
        });
      });
    });
  }

  /* ------------------------------------------------------------------
     4. FINGERPRINT QUALITY CHECK
        Heuristic: edge density (Sobel-like). Requires enough ridges.
  ------------------------------------------------------------------ */
  function analyzeFingerprint(dataUrl, field) {
    const img = new Image();
    img.onload = () => {
      const c = document.createElement('canvas');
      const w = 200, h = 200;
      c.width = w; c.height = h;
      const cx = c.getContext('2d');
      cx.drawImage(img, 0, 0, w, h);
      const data = cx.getImageData(0, 0, w, h).data;

      // Convert to luminance + count edges
      let edges = 0, contrast = 0, mean = 0;
      const lum = new Uint8ClampedArray(w * h);
      for (let i = 0, p = 0; i < data.length; i += 4, p++) {
        const v = (data[i] * 0.299 + data[i + 1] * 0.587 + data[i + 2] * 0.114) | 0;
        lum[p] = v;
        mean += v;
      }
      mean /= (w * h);

      for (let y = 1; y < h - 1; y++) {
        for (let x = 1; x < w - 1; x++) {
          const idx = y * w + x;
          const gx = lum[idx + 1] - lum[idx - 1];
          const gy = lum[idx + w] - lum[idx - w];
          const g = Math.abs(gx) + Math.abs(gy);
          if (g > 30) edges++;
          contrast += g;
        }
      }
      contrast /= (w * h);
      const edgeRatio = edges / (w * h);

      // Decide quality
      let label, cls;
      if (edgeRatio < 0.05 || contrast < 8) {
        label = 'गुणस्तर कम — स्पष्ट लाइन देखिएन, फेरि खिच्नुहोस्';
        cls = 'bad';
      } else if (edgeRatio < 0.12) {
        label = 'मध्यम गुणस्तर — सकेसम्म फेरि खिच्नुहोस्';
        cls = 'ok';
      } else {
        label = 'राम्रो गुणस्तर — स्पष्ट लाइन भेटियो';
        cls = 'good';
      }

      const extra = field.preview.querySelector('.kyc-cap-extra');
      if (extra) {
        extra.innerHTML = `<span class="kyc-fp-quality ${cls}"><i class="fas fa-fingerprint"></i> ${label}</span>`;
      }
      // If bad, mark hidden empty so server-side validation can re-prompt? — keep value but show warning
      field.fieldEl.dataset.fpQuality = cls;
    };
    img.src = dataUrl;
  }

  function analyzePassportQuality(dataUrl, field) {
    const img = new Image();
    img.onload = () => {
      const c = document.createElement('canvas');
      const w = 220, h = 300;
      c.width = w; c.height = h;
      const cx = c.getContext('2d');
      cx.drawImage(img, 0, 0, w, h);
      const data = cx.getImageData(0, 0, w, h).data;

      let sum = 0, sumSq = 0;
      const lum = new Float32Array(w * h);
      for (let i = 0, p = 0; i < data.length; i += 4, p++) {
        const v = data[i] * 0.299 + data[i + 1] * 0.587 + data[i + 2] * 0.114;
        lum[p] = v;
        sum += v;
        sumSq += v * v;
      }
      const n = w * h;
      const mean = sum / n;
      const variance = Math.max(0, (sumSq / n) - (mean * mean));
      const std = Math.sqrt(variance);

      let grad = 0;
      for (let y = 1; y < h - 1; y++) {
        for (let x = 1; x < w - 1; x++) {
          const idx = y * w + x;
          const gx = lum[idx + 1] - lum[idx - 1];
          const gy = lum[idx + w] - lum[idx - w];
          grad += Math.abs(gx) + Math.abs(gy);
        }
      }
      const gradAvg = grad / n;

      const brightScore = mean < 65 ? (mean / 65) * 30 : (mean > 205 ? Math.max(10, (255 - mean) / 50 * 30) : 30);
      const contrastScore = Math.min(35, (std / 52) * 35);
      const sharpScore = Math.min(35, (gradAvg / 28) * 35);
      const score = Math.max(0, Math.min(100, Math.round(brightScore + contrastScore + sharpScore)));

      ['photo_quality_score', 'profile_photo_quality_score'].forEach((nm) => {
        const qh = document.querySelector(`input[name="${nm}"]`);
        if (qh) qh.value = String(score);
      });

      let label = 'राम्रो — यो फोटो प्रयोग गर्न सकिन्छ';
      let cls = 'good';
      if (score < 50) {
        label = 'गुणस्तर कम (' + score + '/100) — फेरि खिच्नुहोस्';
        cls = 'bad';
      } else if (score < 70) {
        label = 'ठिकै (' + score + '/100) — अझ स्पष्ट फोटो राख्नुहोस्';
        cls = 'ok';
      } else {
        label = 'राम्रो (' + score + '/100) — दुवै आँखा र कान स्पष्ट भए confirm गर्नुहोस्';
      }

      const extra = field.preview.querySelector('.kyc-cap-extra');
      if (extra) {
        extra.innerHTML = `<span class="kyc-fp-quality ${cls}"><i class="fas fa-user-check"></i> फोटो गुणस्तर स्कोर: ${score}/100 — ${label}</span>`;
      }
    };
    img.src = dataUrl;
  }

  function analyzeDocumentTextLikelihood(dataUrl, field, label) {
    const img = new Image();
    img.onload = () => {
      const c = document.createElement('canvas');
      const w = 320, h = 200;
      c.width = w; c.height = h;
      const cx = c.getContext('2d');
      cx.drawImage(img, 0, 0, w, h);
      const data = cx.getImageData(0, 0, w, h).data;

      const lum = new Float32Array(w * h);
      let mean = 0;
      for (let i = 0, p = 0; i < data.length; i += 4, p++) {
        const v = data[i] * 0.299 + data[i + 1] * 0.587 + data[i + 2] * 0.114;
        lum[p] = v;
        mean += v;
      }
      mean /= (w * h);

      let edgeCount = 0;
      let strongEdge = 0;
      for (let y = 1; y < h - 1; y++) {
        for (let x = 1; x < w - 1; x++) {
          const idx = y * w + x;
          const gx = lum[idx + 1] - lum[idx - 1];
          const gy = lum[idx + w] - lum[idx - w];
          const g = Math.abs(gx) + Math.abs(gy);
          if (g > 18) edgeCount++;
          if (g > 45) strongEdge++;
        }
      }
      const edgeRatio = edgeCount / (w * h);
      const strongEdgeRatio = strongEdge / (w * h);
      const textLikely = edgeRatio > 0.09 && strongEdgeRatio > 0.02 && mean > 35 && mean < 225;

      const extra = field.preview.querySelector('.kyc-cap-extra');
      if (extra) {
        if (textLikely) {
          extra.innerHTML = '<span class="kyc-fp-quality good"><i class="fas fa-check-circle"></i> डकुमेन्ट स्पष्ट देखियो</span>';
        } else {
          extra.innerHTML = '<span class="kyc-fp-quality bad"><i class="fas fa-triangle-exclamation"></i> यो फोटो कागजात जस्तो देखिएन। फेरि खिच्नुहोस्।</span>';
        }
      }

      if (!textLikely) {
        field.hidden.value = '';
        const fieldName = field.hidden ? (field.hidden.name || '') : '';
        let warnMsg = '';
        if (fieldName === 'citizenship_front' || fieldName === 'citizenship_back') {
          warnMsg = '⚠️ यो फोटो नागरिकता जस्तो देखिएन।\n\n'
            + '✅ सही: असली नेपाली नागरिकता पत्रको फोटो\n'
            + '❌ गलत: राष्ट्रिय परिचयपत्र, Voter ID, अन्य कार्ड, वा अनुहारको फोटो\n\n'
            + 'कृपया असली नागरिकता पत्रको स्पष्ट फोटो फेरि लिनुहोस्।';
        } else if (fieldName === 'national_id_card') {
          warnMsg = '⚠️ यो फोटो राष्ट्रिय परिचयपत्र जस्तो देखिएन।\n\nकृपया असली NID/Smart Card को स्पष्ट फोटो लिनुहोस्।';
        } else {
          warnMsg = (label || 'कागजात') + ' अस्पष्ट/गलत देखियो।\nकागजातको टेक्स्ट/किनारा स्पष्ट नभएमा Admin ले Reject गर्नेछन्।\nकृपया फेरि स्पष्ट फोटो लिनुहोस्।';
        }
        setTimeout(() => { alert(warnMsg); }, 50);
      }
    };
    img.src = dataUrl;
  }

  /* ------------------------------------------------------------------
     5. ADDRESS CASCADING DROPDOWNS (Province → District → Municipality → Ward)
  ------------------------------------------------------------------ */
  function setupAddressDropdowns() {
    if (typeof window.NEPAL_ADDRESS === 'undefined') return;
    const data = window.NEPAL_ADDRESS;

    document.querySelectorAll('[data-kyc-address]').forEach((wrap) => {
      if (wrap.dataset.kycAddressBound === '1') return;
      const prefix = wrap.dataset.kycAddress; // 'permanent' or 'temporary'
      const provSel = wrap.querySelector(`[name="${prefix}_province"]`);
      const distSel = wrap.querySelector(`[name="${prefix}_district"]`);
      const muniSel = wrap.querySelector(`[name="${prefix}_municipality"]`);
      const wardSel = wrap.querySelector(`[name="${prefix}_ward"]`);
      if (!provSel || !distSel || !muniSel || !wardSel) return;

      wrap.dataset.kycAddressBound = '1';

      const previousProvince = provSel.value;
      provSel.innerHTML = '<option value="">— छान्नुहोस् —</option>';

      // Populate provinces once only; initAllKYCCapture can run many times when wizard steps change.
      Object.keys(data).forEach((prov) => {
        const o = document.createElement('option');
        o.value = prov; o.textContent = prov;
        provSel.appendChild(o);
      });
      if (previousProvince && data[previousProvince]) provSel.value = previousProvince;

      provSel.addEventListener('change', () => {
        distSel.innerHTML = '<option value="">— जिल्ला छान्नुहोस् —</option>';
        muniSel.innerHTML = '<option value="">— नगरपालिका छान्नुहोस् —</option>';
        wardSel.innerHTML = '<option value="">— वडा —</option>';
        const districts = data[provSel.value] || {};
        Object.keys(districts).forEach((d) => {
          const o = document.createElement('option'); o.value = d; o.textContent = d;
          distSel.appendChild(o);
        });
      });
      distSel.addEventListener('change', () => {
        muniSel.innerHTML = '<option value="">— नगरपालिका छान्नुहोस् —</option>';
        wardSel.innerHTML = '<option value="">— वडा —</option>';
        const munis = (data[provSel.value] || {})[distSel.value] || [];
        munis.forEach((m) => {
          const o = document.createElement('option'); o.value = m.name; o.textContent = m.name;
          o.dataset.wards = m.wards;
          muniSel.appendChild(o);
        });
      });
      muniSel.addEventListener('change', () => {
        wardSel.innerHTML = '<option value="">— वडा —</option>';
        const opt = muniSel.options[muniSel.selectedIndex];
        const wc = opt && opt.dataset.wards ? parseInt(opt.dataset.wards, 10) : 35;
        for (let i = 1; i <= wc; i++) {
          const o = document.createElement('option');
          o.value = i; o.textContent = i;
          wardSel.appendChild(o);
        }
      });
    });

    // "Same as permanent" toggle
    const sameToggle = document.getElementById('kycSameAddress');
    if (sameToggle && sameToggle.dataset.kycSameBound !== '1') {
      sameToggle.dataset.kycSameBound = '1';
      const tempWrap = document.querySelector('[data-kyc-address="temporary"]');
      const permWrap = document.querySelector('[data-kyc-address="permanent"]');
      const syncWhenSame = () => sameToggle.checked && syncSameAddress(permWrap, tempWrap);
      sameToggle.addEventListener('change', () => {
        if (sameToggle.checked) {
          tempWrap.style.opacity = '.5';
          tempWrap.querySelectorAll('select,input').forEach(el => el.disabled = true);
          // Mirror values via hidden inputs in form submission
          syncSameAddress(permWrap, tempWrap);
        } else {
          tempWrap.style.opacity = '';
          tempWrap.querySelectorAll('select,input').forEach(el => el.disabled = false);
        }
      });
      if (permWrap) permWrap.addEventListener('change', syncWhenSame);
    }
  }

  function syncSameAddress(perm, temp) {
    if (!perm || !temp) return;
    ['province', 'district', 'municipality', 'ward', 'tole'].forEach((k) => {
      const p = perm.querySelector(`[name="permanent_${k}"]`);
      const t = temp.querySelector(`[name="temporary_${k}"]`);
      if (p && t) t.value = p.value;
    });
  }

  /* ------------------------------------------------------------------
     6. INIT
  ------------------------------------------------------------------ */
  function bindSubmitValidation() {
    document.querySelectorAll('form.kyc-form').forEach((form) => {
      if (form.dataset.kycCaptureBound === '1') return;
      form.dataset.kycCaptureBound = '1';
      form.addEventListener('submit', (e) => {
        // Re-enable disabled "same as permanent" inputs so they POST
        form.querySelectorAll('[data-kyc-address="temporary"] select, [data-kyc-address="temporary"] input').forEach(el => el.disabled = false);

        let missing = [];
        form.querySelectorAll('.kyc-cap-field[data-required] input[type=hidden]').forEach((h) => {
          if (!h.value) missing.push(h.closest('.kyc-cap-field').querySelector('.kyc-cap-label').textContent.trim());
        });
        form.querySelectorAll('[data-kyc-signature][data-required] input[type=hidden]').forEach((h) => {
          if (!h.value) missing.push('हस्ताक्षर');
        });
        if (missing.length) {
          e.preventDefault();
          alert('कृपया यी फाँटहरू पूरा गर्नुहोस्:\n• ' + missing.join('\n• '));
          window.scrollTo({ top: 200, behavior: 'smooth' });
        }
      });
    });
  }

  function initAllKYCCapture() {
    try { setupCaptureFields(); } catch (e) { if (window.console) console.warn('KYC capture init skipped', e); }
    try { setupSignaturePads(); } catch (e) { if (window.console) console.warn('KYC signature init skipped', e); }
    try { setupAddressDropdowns(); } catch (e) { if (window.console) console.warn('KYC address init skipped', e); }
    try { bindSubmitValidation(); } catch (e) { if (window.console) console.warn('KYC validation init skipped', e); }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAllKYCCapture);
  } else {
    initAllKYCCapture();
  }
  // Fallback: some pages/forms appear after initial paint
  window.addEventListener('load', function () {
    setTimeout(initAllKYCCapture, 120);
  });

  // expose for debugging
  window.KYCCapture = { CamCrop, setupCaptureFields, setupSignaturePads, initAllKYCCapture };
})();

}