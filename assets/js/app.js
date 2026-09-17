/* SCH MEDICOS — UI behaviours */

function schInitIcons() {
  if (window.lucide && typeof window.lucide.createIcons === 'function') {
    window.lucide.createIcons();
  }
}

document.addEventListener('DOMContentLoaded', () => {
  schInitIcons();
  const isPublicSite = document.body.classList.contains('site-public');
  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* Dismissable flashes */
  document.querySelectorAll('[data-dismiss]').forEach((button) => {
    button.addEventListener('click', () => {
      const target = document.querySelector(button.dataset.dismiss);
      if (target) {
        target.style.transition = 'opacity .2s ease, transform .2s ease';
        target.style.opacity = '0';
        target.style.transform = 'translateY(-6px)';
        setTimeout(() => target.remove(), 200);
      }
    });
  });

  document.querySelectorAll('[data-copy]').forEach((button) => {
    button.addEventListener('click', async () => {
      const text = button.dataset.copy || '';
      try {
        if (navigator.clipboard && window.isSecureContext) {
          await navigator.clipboard.writeText(text);
        } else {
          const temp = document.createElement('textarea');
          temp.value = text;
          temp.style.position = 'fixed';
          temp.style.opacity = '0';
          document.body.appendChild(temp);
          temp.select();
          document.execCommand('copy');
          temp.remove();
        }
        if (window.crmToast) window.crmToast('Link copiado', 'copy-check');
      } catch (error) {
        if (window.crmToast) window.crmToast('No se pudo copiar el link', 'alert-triangle');
      }
    });
  });

  /* Sticky nav shadow + scroll progress bar */
  const nav = document.querySelector('.public-nav');
  const progress = document.querySelector('.scroll-progress');
  if (nav || progress) {
    const docEl = document.documentElement;
    const onScroll = () => {
      if (nav) nav.classList.toggle('is-stuck', window.scrollY > 8);
      if (progress) {
        const max = docEl.scrollHeight - docEl.clientHeight;
        progress.style.transform = 'scaleX(' + (max > 0 ? Math.min(1, window.scrollY / max) : 0) + ')';
      }
    };
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
    window.addEventListener('resize', onScroll, { passive: true });
  }

  if (isPublicSite) {
    document.querySelectorAll('.sch-solutions__grid, .sch-project-showcase__grid, .sch-project-grid, .sch-contact-stack').forEach((group) => {
      Array.from(group.children).forEach((child, index) => {
        if (!child.hasAttribute('data-reveal')) child.setAttribute('data-reveal', 'scale');
        if (!child.dataset.revealDelay) child.dataset.revealDelay = String((index % 6) * 55);
      });
    });
  }

  /* Scroll reveal */
  const revealables = document.querySelectorAll('[data-reveal]');
  if (revealables.length) {
    if (!('IntersectionObserver' in window) ||
        window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
      revealables.forEach((el) => el.classList.add('is-visible'));
    } else {
      const io = new IntersectionObserver((entries, obs) => {
        entries.forEach((entry) => {
          if (entry.isIntersecting) {
            const el = entry.target;
            const delay = el.dataset.revealDelay;
            if (delay) el.style.setProperty('--reveal-delay', delay + 'ms');
            el.classList.add('is-visible');
            obs.unobserve(el);
          }
        });
      }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });
      revealables.forEach((el) => io.observe(el));
    }
  }

  if (isPublicSite) {
    if (!reduceMotion) {
      document.querySelectorAll('.sch-solution-item, .sch-showcase-card, .sch-project-card, .sch-contact-card').forEach((card) => {
        card.addEventListener('pointermove', (event) => {
          const rect = card.getBoundingClientRect();
          card.style.setProperty('--mx', (event.clientX - rect.left) + 'px');
          card.style.setProperty('--my', (event.clientY - rect.top) + 'px');
        }, { passive: true });
      });

      const heroMedia = document.querySelector('.sch-home-hero__media');
      const heroImage = document.querySelector('.sch-hero-main-img');
      if (heroMedia && heroImage && window.matchMedia('(hover: hover) and (pointer: fine)').matches) {
        let targetX = 0;
        let targetY = 0;
        let currentX = 0;
        let currentY = 0;
        let raf = null;

        const tick = () => {
          currentX += (targetX - currentX) * 0.08;
          currentY += (targetY - currentY) * 0.08;
          heroImage.style.transform = `rotate(.75deg) translate3d(${currentX}px, ${currentY}px, 0)`;
          raf = Math.abs(targetX - currentX) > 0.05 || Math.abs(targetY - currentY) > 0.05
            ? requestAnimationFrame(tick)
            : null;
        };

        heroMedia.addEventListener('pointermove', (event) => {
          const rect = heroMedia.getBoundingClientRect();
          targetX = ((event.clientX - rect.left) / rect.width - 0.5) * 10;
          targetY = ((event.clientY - rect.top) / rect.height - 0.5) * 8;
          if (!raf) raf = requestAnimationFrame(tick);
        }, { passive: true });

        heroMedia.addEventListener('pointerleave', () => {
          targetX = 0;
          targetY = 0;
          if (!raf) raf = requestAnimationFrame(tick);
        }, { passive: true });
      }
    }
  }

  /* Animated counters (home redesign) */
  if (isPublicSite) {
    const counters = document.querySelectorAll('[data-count]');
    if (counters.length) {
      const fill = (el) => {
        const target = parseFloat(el.dataset.count) || 0;
        if (reduceMotion || !('requestAnimationFrame' in window)) {
          el.textContent = String(Math.round(target));
          return;
        }
        el.textContent = '0';
        const duration = 1400;
        const start = performance.now();
        const step = (now) => {
          const p = Math.min(1, (now - start) / duration);
          const eased = 1 - Math.pow(1 - p, 3);
          el.textContent = String(Math.round(target * eased));
          if (p < 1) requestAnimationFrame(step);
        };
        requestAnimationFrame(step);
      };
      if (!('IntersectionObserver' in window)) {
        counters.forEach(fill);
      } else {
        const cio = new IntersectionObserver((entries, obs) => {
          entries.forEach((entry) => {
            if (entry.isIntersecting) { fill(entry.target); obs.unobserve(entry.target); }
          });
        }, { threshold: 0.4 });
        counters.forEach((el) => cio.observe(el));
      }
    }
  }

  /* Hero parallax (home redesign) */
  if (isPublicSite && !reduceMotion) {
    const xMedia = document.querySelector('.schx-hero__media');
    const xImg = document.querySelector('.schx-hero__img');
    if (xMedia && xImg && window.matchMedia('(hover: hover) and (pointer: fine)').matches) {
      let tX = 0, tY = 0, cX = 0, cY = 0, raf = null;
      const tick = () => {
        cX += (tX - cX) * 0.08;
        cY += (tY - cY) * 0.08;
        xImg.style.transform = `scale(1.04) translate3d(${cX}px, ${cY}px, 0)`;
        raf = (Math.abs(tX - cX) > 0.05 || Math.abs(tY - cY) > 0.05) ? requestAnimationFrame(tick) : null;
      };
      xMedia.addEventListener('pointermove', (event) => {
        const rect = xMedia.getBoundingClientRect();
        tX = ((event.clientX - rect.left) / rect.width - 0.5) * 14;
        tY = ((event.clientY - rect.top) / rect.height - 0.5) * 12;
        if (!raf) raf = requestAnimationFrame(tick);
      }, { passive: true });
      xMedia.addEventListener('pointerleave', () => { tX = 0; tY = 0; if (!raf) raf = requestAnimationFrame(tick); }, { passive: true });
    }
  }

  if (isPublicSite) {
    document.querySelectorAll('.sch-public-form, .helpdesk-wizard').forEach((form) => {
      form.addEventListener('submit', (event) => {
        if (form.dataset.submitting === '1') {
          event.preventDefault();
          return;
        }
        if (typeof form.checkValidity === 'function' && !form.noValidate && !form.checkValidity()) {
          event.preventDefault();
          form.reportValidity();
          return;
        }
        const submit = form.querySelector('button[type="submit"]');
        if (!submit) return;
        form.dataset.submitting = '1';
        submit.dataset.originalText = submit.textContent.trim();
        submit.setAttribute('aria-busy', 'true');
        submit.disabled = true;
        const label = submit.dataset.loadingText || 'Enviando...';
        submit.innerHTML = '<span class="sch-submit-spinner" aria-hidden="true"></span>' + label;
      });
    });
  }

  /* Ctrl/Cmd + K focuses CRM search */
  const crmSearch = document.querySelector('.crm-search input');
  if (crmSearch) {
    window.addEventListener('keydown', (e) => {
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        crmSearch.focus();
      }
    });
  }
});

/* Re-create icons after Alpine swaps DOM (mobile menu, quote rows) */
document.addEventListener('alpine:initialized', () => setTimeout(schInitIcons, 0));

/* Toast notifications (CRM-wide) */
window.crmToast = function crmToast(message, icon) {
  let wrap = document.getElementById('crm-toasts');
  if (!wrap) {
    wrap = document.createElement('div');
    wrap.className = 'crm-toast-wrap';
    wrap.id = 'crm-toasts';
    document.body.appendChild(wrap);
  }
  const toast = document.createElement('div');
  toast.className = 'crm-toast';
  toast.setAttribute('role', 'status');
  const i = document.createElement('i');
  i.setAttribute('data-lucide', icon || 'check-circle-2');
  const span = document.createElement('span');
  span.textContent = message;
  toast.append(i, span);
  wrap.appendChild(toast);
  if (window.lucide) window.lucide.createIcons();
  setTimeout(() => {
    toast.classList.add('is-out');
    setTimeout(() => toast.remove(), 260);
  }, 2800);
};

/* Trigger a client-side file download (CSV / text) */
window.crmDownload = function crmDownload(filename, text, mime) {
  const blob = new Blob([text], { type: (mime || 'text/csv') + ';charset=utf-8;' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(a.href), 1000);
};

/* Reusable form-in-modal (native <dialog>) for CRM Add/Edit forms */
window.crmFormModal = function crmFormModal(defaults, autoEdit) {
  return {
    form: Object.assign({}, defaults),
    _def: defaults,
    init() { if (autoEdit) { this.openEdit(autoEdit); } },
    openNew() { this.form = Object.assign({}, this._def); this._show(); },
    openEdit(data) { this.form = Object.assign({}, this._def, data); this._show(); },
    _show() {
      const dlg = this.$refs.dlg;
      if (dlg && typeof dlg.showModal === 'function' && !dlg.open) dlg.showModal();
      this.$nextTick(() => { if (window.lucide) window.lucide.createIcons(); });
    },
    close() { const dlg = this.$refs.dlg; if (dlg && dlg.open) dlg.close(); }
  };
};

/* Public helpdesk wizard: 4 guided steps + review before sending the ticket.
   `config` = { storageKey, equipment: [{id, label}] } injected per client. */
window.publicTicketWizard = function publicTicketWizard(config) {
  const cfg = config || {};
  /* Tres pasos, no cuatro.
     Contacto eran cuatro campos cortos —y casi siempre ya rellenados con los
     del último reporte—, así que ocupaba un paso entero para pedir un clic.
     Va junto al equipo, que es la otra mitad de "quién y qué". Queda: quién y
     qué / qué pasa / revisar. Un clic menos y ningún paso a medio llenar. */
  const STEPS = 3;
  const CONTACT_KEYS = ['contact_name', 'email', 'phone', 'department'];

  return {
    step: 1,
    steps: STEPS,
    titles: ['Contacto y equipo', 'Qué está pasando', 'Revisión y envío'],
    /* Rótulos cortos para la barra de pasos: los largos se cortaban a la
       mitad («Prioridad y descri…») y un paso ilegible no orienta a nadie. */
    shortTitles: ['Contacto y equipo', 'La falla', 'Revisión'],
    hints: [
      'A quién buscar cuando el técnico tome el caso, y sobre qué equipo.',
      'Mientras mejor descrito, más rápido se resuelve.',
      'Revisa antes de enviar. Puedes editar cualquier dato.'
    ],
    error: '',
    errors: {},
    sending: false,
    remembered: false,
    equipment: Array.isArray(cfg.equipment) ? cfg.equipment : [],
    fields: {
      contact_name: '',
      email: '',
      phone: '',
      department: '',
      equipment_id: '',
      equipment_name: '',
      serial: '',
      area: '',
      impact: 'Media',
      subject: '',
      description: '',
      availability: ''
    },

    init() {
      this.restoreContact();
      this.$nextTick(() => schInitIcons());
    },

    /* The same person usually reports every case: prefill their contact data. */
    restoreContact() {
      if (!cfg.storageKey) return;
      try {
        const saved = JSON.parse(window.localStorage.getItem(cfg.storageKey) || 'null');
        if (!saved || !saved.contact_name) return;
        CONTACT_KEYS.forEach((k) => { if (saved[k]) this.fields[k] = saved[k]; });
        this.remembered = true;
      } catch (e) { /* storage blocked or corrupt: start empty */ }
    },
    rememberContact() {
      if (!cfg.storageKey) return;
      try {
        const data = {};
        CONTACT_KEYS.forEach((k) => { data[k] = this.fields[k]; });
        window.localStorage.setItem(cfg.storageKey, JSON.stringify(data));
      } catch (e) { /* ignore */ }
    },
    forget() {
      CONTACT_KEYS.forEach((k) => { this.fields[k] = ''; });
      this.remembered = false;
      try { window.localStorage.removeItem(cfg.storageKey); } catch (e) { /* ignore */ }
    },

    equipmentLabel() {
      const found = this.equipment.find((item) => String(item.id) === String(this.fields.equipment_id));
      return found ? found.label : '';
    },

    /* La descripción necesita 20 caracteres para pasar la validación. Antes eso
       solo se sabía al fallar, y el contador decía «0 caracteres», que no ayuda
       a nadie: nadie cuenta letras. Mejor decir cuánto falta y avisar cuándo ya
       alcanza. */
    DESC_MIN: 20,
    get descLen() { return this.fields.description.trim().length; },
    get descFaltan() { return Math.max(0, this.DESC_MIN - this.descLen); },
    get descOk() { return this.descLen >= this.DESC_MIN; },

    get review() {
      const f = this.fields;
      const asset = this.equipmentLabel() || [f.equipment_name, f.serial].filter(Boolean).join(' · ');
      const row = (label, value, step) => ({ label, value: value || 'Sin especificar', empty: !value, step });
      return [
        row('Contacto', f.contact_name, 1),
        row('Correo', f.email, 1),
        row('Teléfono', f.phone, 1),
        row('Área o departamento', f.department || f.area, 1),
        row('Equipo', asset, 1),
        row('Impacto', f.impact, 2),
        row('Asunto', f.subject, 2),
        row('Descripción', f.description, 2),
        row('Disponibilidad', f.availability, 2)
      ];
    },

    validEmail(value) {
      return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(value || '').trim());
    },
    clear(field) {
      if (this.errors[field]) {
        delete this.errors[field];
        this.errors = { ...this.errors };
      }
      if (!Object.keys(this.errors).length) this.error = '';
    },

    /* Validates one step and marks the offending fields. */
    validateStep(step) {
      const f = this.fields;
      const found = {};
      if (step === 1) {
        if (!f.contact_name.trim()) found.contact_name = 'Indica quién reporta el caso.';
        if (!this.validEmail(f.email)) found.email = 'Escribe un correo válido, ahí llega el seguimiento.';
      }
      if (step === 2) {
        if (!f.subject.trim()) found.subject = 'Resume el caso en una línea.';
        else if (f.subject.trim().length < 6) found.subject = 'El asunto es muy corto para identificar el caso.';
        if (!f.description.trim()) found.description = 'Describe la falla antes de enviar el ticket.';
        else if (f.description.trim().length < 20) found.description = 'Agrega un poco más de detalle: síntomas, alarmas u hora.';
      }
      this.errors = found;
      const keys = Object.keys(found);
      this.error = keys.length ? found[keys[0]] : '';
      if (keys.length) this.focusField(keys[0]);
      return keys.length === 0;
    },
    focusField(name) {
      this.$nextTick(() => {
        const el = this.$root.querySelector('[name="' + name + '"]');
        if (el) el.focus({ preventScroll: false });
      });
    },

    go(step) {
      this.step = Math.min(this.steps, Math.max(1, step));
      this.$nextTick(() => {
        schInitIcons();
        const top = this.$root.querySelector('.helpdesk-wizard');
        if (top && window.innerWidth <= 1099) top.scrollIntoView({ behavior: 'smooth', block: 'start' });
      });
    },
    next() {
      if (!this.validateStep(this.step)) return;
      if (this.step === 1) this.rememberContact();
      this.go(this.step + 1);
    },
    back() {
      this.error = '';
      this.errors = {};
      this.go(this.step - 1);
    },
    /* Progress chips: jump back freely, forward only through validation. */
    goTo(step) {
      if (step === this.step) return;
      if (step < this.step) { this.error = ''; this.errors = {}; this.go(step); return; }
      for (let s = this.step; s < step; s += 1) {
        if (!this.validateStep(s)) { this.go(s); return; }
      }
      this.go(step);
    },
    /* Enter must advance the wizard, never submit a half-filled ticket. */
    onEnter(event) {
      const tag = (event.target.tagName || '').toLowerCase();
      if (tag === 'textarea') return;
      event.preventDefault();
      if (this.step < this.steps) { this.next(); return; }
      const form = event.target.closest('form');
      if (form) form.requestSubmit();
    },
    /* Runs on the form's submit event, so disabling the button here is safe. */
    onSubmit(event) {
      for (let s = 1; s <= this.steps; s += 1) {
        if (!this.validateStep(s)) { event.preventDefault(); this.go(s); return; }
      }
      if (this.sending) { event.preventDefault(); return; }
      this.sending = true;
      this.rememberContact();
    }
  };
};

/* PDF preview modal (iframe): quotes, invoices and payment reminders.
   `kind` names the document in the modal header ('Cotización' by default).
   `editUrl`, si viene, muestra «Editar estado de cuenta» junto a Descargar:
   quien ve el PDF y encuentra algo que cambiar lo tiene a un clic. */
window.crmPdfPreviewOpen = function crmPdfPreviewOpen(viewUrl, downloadUrl, title, kind, editUrl) {
  const dlg = document.getElementById('crm-pdf-modal');
  if (!dlg) { window.open(viewUrl, '_blank'); return; }
  const frame = dlg.querySelector('#crm-pdf-frame');
  const dl = dlg.querySelector('#crm-pdf-download');
  const ed = dlg.querySelector('#crm-pdf-edit');
  if (ed) {
    ed.style.display = editUrl ? '' : 'none';
    ed.href = editUrl || '#';
  }
  const open = dlg.querySelector('#crm-pdf-open');
  const tl = dlg.querySelector('#crm-pdf-title');
  if (frame) frame.src = viewUrl;
  if (dl) dl.href = downloadUrl;
  if (open) open.href = viewUrl;
  const label = kind || 'Cotización';
  if (tl) tl.textContent = title ? (label + ' ' + title) : ('Vista previa · ' + label);
  if (typeof dlg.showModal === 'function' && !dlg.open) dlg.showModal();
  if (window.lucide) window.lucide.createIcons();
};
window.crmPdfPreviewClose = function crmPdfPreviewClose() {
  const dlg = document.getElementById('crm-pdf-modal');
  if (!dlg) return;
  const frame = dlg.querySelector('#crm-pdf-frame');
  if (frame) frame.src = 'about:blank';
  if (dlg.open) dlg.close();
};

/* Collapse / expand the CRM sidebar (persisted) */
window.crmToggleNav = function crmToggleNav() {
  const collapsed = document.documentElement.classList.toggle('crm-collapsed');
  try { localStorage.setItem('crmNav', collapsed ? 'collapsed' : 'open'); } catch (e) {}
  if (window.lucide) window.lucide.createIcons();
};

/* Anexo fotográfico: resumen de la selección y aviso antes de chocar con el
   límite del servidor, que descartaría el POST entero sin explicación. */
window.crmAnnexPick = function crmAnnexPick(input, inModal) {
  var files = Array.prototype.slice.call(input.files || []);
  var hint = input.closest('.annex-drop').querySelector('[data-hint]');
  // En el modal el botón envía toda la cotización, así que sólo se bloquea si la
  // selección es imposible; en el gestor sirve únicamente para subir fotos.
  var button = input.form.querySelector('.annex-upload__go')
    || input.form.querySelector('.crm-modal__foot button[type="submit"]');
  var limit = Number(input.dataset.limit) || 0;
  var total = files.reduce(function (s, f) { return s + f.size; }, 0);
  var mb = function (n) { return (n / 1048576).toFixed(1) + ' MB'; };
  var idle = inModal
    ? 'Salen numeradas en una hoja final del PDF · JPG, PNG o WEBP'
    : 'JPG, PNG o WEBP · puedes seleccionar varias';

  hint.style.color = '';
  if (button) { button.disabled = !inModal; }

  if (!files.length) {
    hint.textContent = idle;
    return;
  }
  var tooBig = limit > 0 && total > limit;
  hint.textContent = files.length + (files.length === 1 ? ' foto · ' : ' fotos · ') + mb(total)
    + (tooBig ? ' · excede el máximo de ' + mb(limit) + ': selecciona menos fotos' : '');
  if (tooBig) { hint.style.color = '#b91c1c'; }
  if (button) { button.disabled = tooBig; }
};

/* Lee un importe tecleado a mano tolerando el formato con el que la app lo
   imprime ("1,601.70") y los descuidos habituales al escribirlo. Los campos de
   dinero son <input type="text"> justamente para que quepan esos separadores:
   un type="number" los rechaza según el idioma del navegador y el usuario
   terminaba guardando otro monto. Mismas reglas que amount_parse() en PHP. */
window.crmParseAmount = function crmParseAmount(value) {
  if (typeof value === 'number') { return isFinite(value) ? value : 0; }
  var raw = String(value === null || value === undefined ? '' : value)
    .replace(/[\s  ']/g, '');
  var negative = raw.indexOf('-') !== -1;
  var s = raw.replace(/[^0-9.,]/g, '');
  if (!/[0-9]/.test(s)) { return 0; }

  var dot = s.lastIndexOf('.');
  var comma = s.lastIndexOf(',');
  if (dot !== -1 && comma !== -1) {
    // Manda como decimal el separador que esté más a la derecha.
    var decimal = dot > comma ? '.' : ',';
    s = s.split(decimal === '.' ? ',' : '.').join('');
    s = s.split(decimal).join('.');
  } else if (dot !== -1 || comma !== -1) {
    var sep = dot !== -1 ? '.' : ',';
    var at = s.lastIndexOf(sep);
    var tail = s.length - at - 1;
    // Repetido siempre es de miles; una coma sola con 3 dígitos detrás también.
    var thousands = s.split(sep).length - 1 > 1 || (sep === ',' && tail === 3 && at > 0);
    s = thousands ? s.split(sep).join('') : s.split(sep).join('.');
  }

  var n = parseFloat(s);
  if (!isFinite(n)) { return 0; }
  return negative ? -n : n;
};

/* Base común de los editores de cotización y factura: lectura de importes con
   separadores, formateo al salir del campo y el descuento único del documento
   (en % o en monto), que se reparte entre las partidas al guardar. */
function crmDocMoneyMixin() {
  return {
    disc: '',
    discMode: 'pct',
    num(v) { return window.crmParseAmount(v); },
    nf(n) { return (Number(n) || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
    /* Cantidades y porcentajes: hasta 2 decimales, sin ceros de relleno. */
    nq(n) { return (Number(n) || 0).toLocaleString('en-US', { maximumFractionDigits: 2 }); },
    /* Al salir del campo el valor se reescribe ya ordenado ("1601.7" → "1,601.70"),
       así el usuario ve de inmediato cómo se interpretó lo que escribió. */
    fixNum(v) { return String(v).trim() === '' ? '' : this.nf(this.num(v)); },
    fixQty(v) { return String(v).trim() === '' ? '' : this.nq(this.num(v)); },
    fixDisc(v) { return this.discMode === 'pct' ? this.fixQty(v) : this.fixNum(v); },
    sym() { return this.currency === 'USD' ? 'US$' : 'RD$'; },
    fmt(n) { return this.sym() + ' ' + this.nf(n); },
    r2(n) { return Math.round((n + Number.EPSILON) * 100) / 100; },
    lineGross(item) { return this.num(item.q) * this.num(item.p); },
    subtotalGross() { return this.items.reduce((s, i) => s + this.lineGross(i), 0); },
    /* Descuento del documento, con los mismos topes que distribute_discount()
       en el servidor: nunca supera el bruto ni el 100%. */
    discountRaw() {
      const gross = this.subtotalGross();
      const typed = Math.max(0, this.num(this.disc));
      if (gross <= 0 || typed <= 0) { return 0; }
      return this.discMode === 'pct' ? gross * Math.min(100, typed) / 100 : Math.min(gross, typed);
    },
    /* Reparto por partida, centavo a centavo igual que distribute_discount(): el
       resumen del modal tiene que dar exactamente lo que se guardará después. */
    lineDiscounts() {
      const gross = this.subtotalGross();
      const amount = this.r2(this.discountRaw());
      const out = this.items.map(() => 0);
      if (gross <= 0 || amount <= 0) { return out; }
      let last = -1;
      this.items.forEach((it, i) => { if (this.lineGross(it) > 0) { last = i; } });
      let assigned = 0;
      this.items.forEach((it, i) => {
        const lineGross = this.r2(this.lineGross(it));
        const share = i === last ? this.r2(amount - assigned) : this.r2(amount * lineGross / gross);
        out[i] = Math.max(0, Math.min(lineGross, share));
        assigned += out[i];
      });
      return out;
    },
    lineNets() {
      const discounts = this.lineDiscounts();
      return this.items.map((it, i) => this.r2(this.r2(this.lineGross(it)) - discounts[i]));
    },
    discountTotal() { return this.r2(this.lineDiscounts().reduce((s, d) => s + d, 0)); },
    loadDiscount(d) {
      this.discMode = (d && d.discount_mode) === 'amount' ? 'amount' : 'pct';
      const v = d ? this.num(d.discount_value) : 0;
      this.disc = v > 0 ? this.fixDisc(v) : '';
    },

    /* ---------------- Catálogo de productos y costo por partida ----------------
       El costo viaja con la línea y se congela en el documento al guardar. Aquí
       solo sirve para que quien cotiza vea el margen mientras arma el documento. */
    products: [],
    pickProduct: '',
    /* '' y null significan «costo desconocido». Se respetan tal cual: un 0 se
       leería más tarde como margen del 100%, que es peor que no saber. */
    costIn(v) {
      return (v === null || v === undefined || v === '') ? '' : this.fixNum(v);
    },
    productById(id) {
      const s = String(id || '');
      return this.products.find((p) => String(p.id) === s) || null;
    },
    lineFromProduct(p) {
      return {
        d: p.desc || p.name || '',
        q: '1',
        p: this.fixNum(p.price),
        c: this.costIn(p.cost),
        pid: String(p.id),
        exempt: !!p.exempt,
      };
    },
    /* Inserta la ficha elegida. Si la única línea está vacía la rellena, en vez
       de dejar una fila en blanco colgando arriba. */
    addFromProduct() {
      const p = this.productById(this.pickProduct);
      this.pickProduct = '';
      if (!p) { return; }
      const line = this.lineFromProduct(p);
      const first = this.items[0];
      if (this.items.length === 1 && !String(first.d || '').trim() && !this.num(first.p)) {
        this.items[0] = line;
      } else {
        this.items.push(line);
      }
      this.$nextTick(() => { if (window.lucide) window.lucide.createIcons(); });
    },
    lineHasCost(it) { return !(it.c === '' || it.c === null || it.c === undefined); },
    /* Margen bruto de la línea: importe menos costo × cantidad. */
    lineMargin(it) {
      return this.lineHasCost(it) ? this.r2(this.lineGross(it) - this.num(it.c) * this.num(it.q)) : null;
    },
    /* Margen de las líneas cuyo costo se conoce. null si no se conoce ninguno. */
    marginTotal() {
      let sum = 0;
      let any = false;
      this.items.forEach((it) => {
        const m = this.lineMargin(it);
        if (m !== null) { sum += m; any = true; }
      });
      return any ? this.r2(sum) : null;
    },
    /* ¿Hay alguna línea con importe y sin costo? Entonces el margen es parcial. */
    marginPartial() {
      return this.items.some((it) => !this.lineHasCost(it) && (this.num(it.p) > 0 || String(it.d || '').trim() !== ''));
    },
    marginPct() {
      const m = this.marginTotal();
      const base = this.items.reduce((s, it) => s + (this.lineHasCost(it) ? this.r2(this.lineGross(it)) : 0), 0);
      return (m === null || base <= 0.009) ? null : Math.round(m / base * 1000) / 10;
    },
  };
}

/* Quote editor inside a modal (cotizaciones) — create + edit with line items */
window.crmQuoteModal = function crmQuoteModal(opts) {
  opts = opts || {};
  var defaults = opts.defaults || {};
  var blankLine = function () { return { d: '', q: '1', p: '', c: '', pid: '' }; };
  return Object.assign(crmDocMoneyMixin(), {
    products: opts.products || [],
    form: {},
    items: [blankLine()],
    tax: '18',
    currency: 'DOP',
    rate: Number(defaults.rate) > 0 ? String(defaults.rate) : '60',
    reset() {
      this.form = { id: 0, client_id: '', title: '', category: '', status: 'Borrador', valid_until: defaults.validUntil || '', notes: '', terms: defaults.terms || '' };
      this.items = [blankLine()];
      this.tax = this.fixQty((defaults.tax !== undefined && Number(defaults.tax) >= 0) ? defaults.tax : 18);
      this.currency = 'DOP';
      this.rate = this.fixNum(Number(defaults.rate) > 0 ? defaults.rate : 60);
      this.loadDiscount(null);
    },
    subtotal() { return this.lineNets().reduce((s, n) => s + n, 0); },
    taxAmount() { return this.subtotal() * this.num(this.tax) / 100; },
    total() { return this.subtotal() + this.taxAmount(); },
    addLine() { this.items.push(blankLine()); this.$nextTick(() => { if (window.lucide) window.lucide.createIcons(); }); },
    removeLine(index) { if (this.items.length > 1) this.items.splice(index, 1); },
    openNew() { this.reset(); this.open(); },
    openEdit(d) {
      d = d || {};
      this.form = {
        id: d.id || 0, client_id: d.client_id || '', title: d.title || '', category: d.category || '',
        status: d.status || 'Borrador', valid_until: d.valid_until || (defaults.validUntil || ''),
        notes: d.notes || '', terms: (d.terms && d.terms.length) ? d.terms : (defaults.terms || '')
      };
      this.currency = d.currency === 'USD' ? 'USD' : 'DOP';
      this.items = (d.items && d.items.length)
        ? d.items.map((it) => ({ d: it.d || '', q: this.fixQty(it.q), p: this.fixNum(it.p), c: this.costIn(it.c), pid: it.pid ? String(it.pid) : '' }))
        : [blankLine()];
      this.tax = this.fixQty(d.tax_rate !== undefined && d.tax_rate !== '' && Number(d.tax_rate) >= 0 ? d.tax_rate : 18);
      this.rate = this.fixNum(Number(d.exchange_rate) > 0 ? d.exchange_rate : (Number(defaults.rate) > 0 ? defaults.rate : 60));
      this.loadDiscount(d);
      this.open();
    },
    init() {
      this.reset();
      if (opts.autoEdit) { this.openEdit(opts.autoEdit); }
      else if (opts.autoOpen) { this.open(); }
    },
    open() { const d = this.$refs.dlg; if (d && !d.open) d.showModal(); this.$nextTick(() => { if (window.lucide) window.lucide.createIcons(); }); },
    close() { const d = this.$refs.dlg; if (d && d.open) d.close(); }
  });
};

/* Fiscal invoice editor inside a modal (facturas) — NCF, ITBIS, exempt lines, retentions */
window.crmInvoiceModal = function crmInvoiceModal(opts) {
  opts = opts || {};
  var defaults = opts.defaults || {};
  var types = opts.types || [];
  var pairs = opts.pairs || {};
  var sequences = opts.sequences || {};   // 'B01' => {next, remaining, expiration}
  var clientRncMap = opts.clientRnc || {};
  var blankLine = function () { return { d: '', q: '1', p: '', c: '', pid: '', exempt: false }; };
  return Object.assign(crmDocMoneyMixin(), {
    products: opts.products || [],
    form: {},
    items: [blankLine()],
    tax: '18',
    isc: '',
    itbisRet: '',
    isrRet: '',
    currency: 'DOP',
    rate: Number(defaults.rate) > 0 ? String(defaults.rate) : '60',
    /* Serie válida para el <select>: B (fiscal), E (e-CF) o P (proforma sin NCF). */
    normSeries(v) { return v === 'E' ? 'E' : (v === 'P' ? 'P' : 'B'); },
    reset() {
      this.form = {
        id: 0, client_id: '', title: '', ncf_type: defaults.type || '01', ncf_prefix: this.normSeries(defaults.prefix),
        payment_condition: defaults.condition || 'Contado', payment_method: '',
        issue_date: defaults.issueDate || '', due_date: defaults.dueDate || '', modifies_ncf: '',
        notes: '', terms: defaults.terms || ''
      };
      this.items = [blankLine()];
      this.tax = this.fixQty(Number(defaults.tax) >= 0 ? defaults.tax : 18);
      this.isc = ''; this.itbisRet = ''; this.isrRet = '';
      this.currency = 'DOP';
      this.rate = this.fixNum(Number(defaults.rate) > 0 ? defaults.rate : 60);
      this.loadDiscount(null);
    },
    /* La proforma no lleva comprobante fiscal: ni tipo, ni NCF, ni RNC obligatorio. */
    isProforma() { return this.form.ncf_prefix === 'P'; },
    availableTypes() { var p = this.form.ncf_prefix === 'E' ? 'E' : 'B'; return types.filter(function (t) { return t.series === p; }); },
    syncType() {
      if (this.isProforma()) { return; }
      var want = this.form.ncf_prefix === 'E' ? 'E' : 'B';
      var cur = this.form.ncf_type;
      var t = types.find(function (x) { return x.code === cur; });
      if (t && t.series === want) { return; }
      var next;
      if (want === 'E') { next = pairs[cur]; }
      else { var inv = {}; Object.keys(pairs).forEach(function (b) { inv[pairs[b]] = b; }); next = inv[cur]; }
      if (!next || !types.find(function (x) { return x.code === next && x.series === want; })) { next = want === 'E' ? '31' : '01'; }
      this.form.ncf_type = next;
    },
    requiresRnc() { if (this.isProforma()) { return false; } var t = types.find((x) => x.code === this.form.ncf_type); return !!(t && t.rnc); },
    /* Rango vigente para la serie+tipo elegidos: mismo que consumirá la emisión. */
    seqInfo() { if (this.isProforma()) { return null; } return sequences[(this.form.ncf_prefix || 'B') + (this.form.ncf_type || '')] || null; },
    /* RNC del cliente seleccionado ('' si la ficha no lo tiene). */
    clientRnc() { return clientRncMap[String(this.form.client_id || '')] || ''; },
    /* Bases gravada y exenta: cada partida aporta su neto ya descontado, así el
       descuento del documento se reparte entre ambas por su peso en el bruto. */
    subtotalTaxed() { return this.lineNets().reduce((s, n, i) => s + (this.items[i].exempt ? 0 : n), 0); },
    subtotalExempt() { return this.lineNets().reduce((s, n, i) => s + (this.items[i].exempt ? n : 0), 0); },
    taxAmount() { return this.subtotalTaxed() * this.num(this.tax) / 100; },
    total() { return this.subtotalTaxed() + this.subtotalExempt() + this.taxAmount() + this.num(this.isc); },
    netReceivable() { return this.total() - this.num(this.itbisRet) - this.num(this.isrRet); },
    addLine() { this.items.push(blankLine()); this.$nextTick(() => { if (window.lucide) window.lucide.createIcons(); }); },
    removeLine(index) { if (this.items.length > 1) this.items.splice(index, 1); },
    openNew() { this.reset(); this.open(); },
    loadLines(d) {
      this.items = (d.items && d.items.length)
        ? d.items.map((it) => ({ d: it.d || '', q: this.fixQty(it.q), p: this.fixNum(it.p), c: this.costIn(it.c), pid: it.pid ? String(it.pid) : '', exempt: !!it.exempt }))
        : [blankLine()];
    },
    openEdit(d) {
      d = d || {};
      this.form = {
        id: d.id || 0, client_id: d.client_id || '', title: d.title || '',
        ncf_type: d.ncf_type || defaults.type || '01', ncf_prefix: this.normSeries(d.ncf_prefix),
        payment_condition: d.payment_condition || defaults.condition || 'Contado', payment_method: d.payment_method || '',
        issue_date: d.issue_date || defaults.issueDate || '', due_date: d.due_date || defaults.dueDate || '',
        modifies_ncf: d.modifies_ncf || '', notes: d.notes || '', terms: (d.terms && d.terms.length) ? d.terms : (defaults.terms || '')
      };
      this.currency = d.currency === 'USD' ? 'USD' : 'DOP';
      this.loadLines(d);
      this.tax = this.fixQty((d.tax_rate !== undefined && d.tax_rate !== '' && Number(d.tax_rate) >= 0) ? d.tax_rate : (Number(defaults.tax) >= 0 ? defaults.tax : 18));
      this.isc = Number(d.isc_amount) > 0 ? this.fixNum(d.isc_amount) : '';
      this.itbisRet = Number(d.itbis_retained) > 0 ? this.fixNum(d.itbis_retained) : '';
      this.isrRet = Number(d.isr_retained) > 0 ? this.fixNum(d.isr_retained) : '';
      this.rate = this.fixNum(Number(d.exchange_rate) > 0 ? d.exchange_rate : (Number(defaults.rate) > 0 ? defaults.rate : 60));
      this.loadDiscount(d);
      this.open();
    },
    applyPrefill(d) {
      this.reset();
      this.form.client_id = d.client_id || '';
      this.form.title = d.title || '';
      this.form.notes = d.notes || '';
      // De qué cotización sale: el servidor lo guarda y así los anticipos
      // cobrados sobre ella se aplican solos al emitir.
      this.form.quote_id = d.quote_id || '';
      this.currency = d.currency === 'USD' ? 'USD' : 'DOP';
      this.loadLines(d);
      if (d.tax_rate !== undefined && Number(d.tax_rate) >= 0) { this.tax = this.fixQty(d.tax_rate); }
      if (Number(d.exchange_rate) > 0) { this.rate = this.fixNum(d.exchange_rate); }
      this.loadDiscount(d);
    },
    init() {
      this.reset();
      if (opts.autoEdit) { this.openEdit(opts.autoEdit); }
      else if (opts.prefill) { this.applyPrefill(opts.prefill); this.open(); }
      else if (opts.autoOpen) { this.open(); }
    },
    open() { const d = this.$refs.dlg; if (d && !d.open) d.showModal(); this.$nextTick(() => { if (window.lucide) window.lucide.createIcons(); }); },
    close() { const d = this.$refs.dlg; if (d && d.open) d.close(); }
  });
};

/* =========================================================================
   Tema claro / oscuro
   =========================================================================
   El tema lo aplica un script en línea del <head>, antes del primer pintado.
   Aquí solo vive el cambio manual: escribe la preferencia y avisa a quien
   necesite repintarse, como los gráficos, que llevan sus colores en JS y no
   se enteran de un cambio de CSS. */
window.schTema = function () {
  var d = document.documentElement;
  var oscuro = d.getAttribute('data-tema') === 'oscuro';
  if (oscuro) { d.removeAttribute('data-tema'); } else { d.setAttribute('data-tema', 'oscuro'); }
  try { localStorage.setItem('schTema', oscuro ? 'claro' : 'oscuro'); } catch (e) {}
  window.dispatchEvent(new CustomEvent('sch:tema', { detail: { oscuro: !oscuro } }));
};

/* Si el usuario nunca eligió, el CRM sigue al sistema — también si lo cambia
   con la pestaña abierta. */
(function () {
  if (!window.matchMedia) { return; }
  var mq = window.matchMedia('(prefers-color-scheme: dark)');
  var alSistema = function (e) {
    var guardado = null;
    try { guardado = localStorage.getItem('schTema'); } catch (x) {}
    if (guardado) { return; }
    var d = document.documentElement;
    if (e.matches) { d.setAttribute('data-tema', 'oscuro'); } else { d.removeAttribute('data-tema'); }
    window.dispatchEvent(new CustomEvent('sch:tema', { detail: { oscuro: e.matches } }));
  };
  if (mq.addEventListener) { mq.addEventListener('change', alSistema); }
  else if (mq.addListener) { mq.addListener(alSistema); }
})();

/* =========================================================================
   Recibos de ingreso: corregir y anular
   ========================================================================= */

/* Formato de dinero igual que money_cur() en PHP, para que lo que se ve en el
   diálogo coincida con lo que luego imprime el recibo. */
function schDinero(v, moneda) {
  var sym = String(moneda).toUpperCase() === 'USD' ? 'US$' : 'RD$';
  var n = Number(v) || 0;
  return sym + ' ' + n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

/* Lee un importe tecleado como lo lee amount_parse() en el servidor. Delega en
   crmParseAmount, que sigue sus mismas reglas: antes esta función tomaba la coma
   sola como decimal y leía «60,000» como 60, mientras el servidor guardaba
   60,000 — el diálogo mostraba una cifra y se grababa otra. */
function schImporte(raw) {
  return window.crmParseAmount(raw);
}

/* Anticipos de una cotización: el mismo diálogo registra uno nuevo o corrige
   uno que todavía no se aplicó. El servidor revalida todo. */
window.anticipoEditor = function anticipoEditor(cfg) {
  cfg = cfg || {};
  var vacio = function () { return { id: 0, receipt: '', amount: '', paid_at: cfg.hoy || '', method: 'Transferencia', reference: '', note: '' }; };
  return {
    hoy: cfg.hoy || '',
    a: vacio(),
    anular: { id: 0, receipt: '', amount: '', paid_at: '' },
    motivo: '',
    enviando: false,
    abrir(id) {
      this.motivo = '';
      this.enviando = false;
      var dlg = document.getElementById(id);
      if (dlg && !dlg.open) { dlg.showModal(); }
      this.$nextTick(function () { if (window.schInitIcons) { schInitIcons(); } });
    },
    nuevo() { this.a = vacio(); this.abrir('ant-edit'); },
    editar(d) { this.a = Object.assign(vacio(), d || {}); this.abrir('ant-edit'); },
    pedirAnular(d) { this.anular = Object.assign({ id: 0, receipt: '', amount: '', paid_at: '' }, d || {}); this.abrir('ant-void'); },
    valido() {
      if (schImporte(this.a.amount) <= 0 || !this.a.paid_at) { return false; }
      if (this.hoy && this.a.paid_at > this.hoy) { return false; }
      return !this.a.id || this.motivo.trim() !== '';
    }
  };
};

window.reciboEditor = function reciboEditor(moneda) {
  return {
    moneda: moneda || 'DOP',
    r: { id: 0, receipt: '', paid_at: '', method: '', reference: '', note: '', lines: [] },
    motivo: '',
    enviando: false,

    /* Copia profunda: si se editara el objeto del evento, cancelar el diálogo
       dejaría los cambios pegados al botón y reaparecerían al reabrirlo. */
    abrir(datos, id) {
      this.r = JSON.parse(JSON.stringify(datos || {}));
      this.r.lines = Array.isArray(this.r.lines) ? this.r.lines : [];
      var self = this;
      this.r.lines.forEach(function (l) { self.prepararCifras(l); });
      this.motivo = '';
      this.enviando = false;
      var dlg = document.getElementById(id || 'rec-edit');
      if (dlg && !dlg.open) { dlg.showModal(); }
      this.$nextTick(function () { if (window.schInitIcons) { schInitIcons(); } });
    },
    total() {
      return this.r.lines.reduce(function (s, l) { return s + schImporte(l.amount); }, 0);
    },
    dinero(v) { return schDinero(v, this.moneda); },

    /* ---- Cifras del recibo ------------------------------------------------
       Neto = total − retenciones − ajuste − notas de crédito.
       Saldo antes = neto − lo cobrado antes − abono anterior (el que falta
       registrar). Saldo pendiente = saldo antes − este pago.
       Se guarda el abono y no el saldo: así, cambiar una retención no mueve
       el abono que la persona ya había indicado. Mismo cálculo que el PDF. */
    redondo(n) { return Math.round((Number(n) || 0) * 100) / 100; },
    // Lo que se ve lleva separador de miles, como el recibo impreso; lo que se
    // envía va plano. amount_parse() lee los dos igual.
    fijo(n) { return this.redondo(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
    importe(v) { return this.redondo(schImporte(v)).toFixed(2); },
    prepararCifras(l) {
      ['total', 'itbis', 'base', 'credito', 'ret_itbis', 'ret_isr', 'ajuste', 'previo'].forEach(function (k) {
        l[k] = Number(l[k]) || 0;
      });
      var c = {
        retI: this.fijo(l.ret_itbis),
        retS: this.fijo(l.ret_isr),
        adj: this.fijo(l.ajuste),
        abonoN: 0,
        abonoFecha: this.diaAntes(this.r.paid_at),
        abonoMetodo: 'Transferencia',
        abonoRef: '',
        // El detalle del neto se abre solo si ya tiene algo que explicar.
        detalle: l.ret_itbis > 0 || l.ret_isr > 0 || l.ajuste > 0 || l.credito > 0
      };
      l.c = c;
      l.amount = this.fijo(schImporte(l.amount));
      this.recalcularDesdeAbono(l);
    },
    neto(l) {
      return this.redondo(l.total - schImporte(l.c.retI) - schImporte(l.c.retS)
        - (l.ajuste_ok ? schImporte(l.c.adj) : 0) - l.credito);
    },
    abono(l) { return this.redondo(l.c.abonoN); },
    recalcularDesdeAbono(l) {
      var antes = this.neto(l) - l.previo - l.c.abonoN;
      l.c.antes = this.fijo(antes);
      l.c.pend = this.fijo(antes - schImporte(l.amount));
    },
    alDeducir(l) { this.recalcularDesdeAbono(l); },
    alAntes(l) {
      l.c.abonoN = this.redondo(this.neto(l) - l.previo - schImporte(l.c.antes));
      l.c.pend = this.fijo(schImporte(l.c.antes) - schImporte(l.amount));
    },
    alMonto(l) {
      l.c.pend = this.fijo(schImporte(l.c.antes) - schImporte(l.amount));
    },
    // Tocar el pendiente mueve el saldo anterior, no el pago: lo que entró en
    // este recibo es un hecho; lo que se debía antes es lo que se corrige.
    alPendiente(l) {
      var antes = schImporte(l.c.pend) + schImporte(l.amount);
      l.c.antes = this.fijo(antes);
      l.c.abonoN = this.redondo(this.neto(l) - l.previo - antes);
    },
    diaAntes(fecha) {
      var p = String(fecha || '').split('-').map(Number);
      if (p.length !== 3 || !p[0]) { return ''; }
      var d = new Date(Date.UTC(p[0], p[1] - 1, p[2] - 1));
      return d.toISOString().slice(0, 10);
    },
    errores(l) {
      var e = [];
      var retI = schImporte(l.c.retI), retS = schImporte(l.c.retS), adj = l.ajuste_ok ? schImporte(l.c.adj) : 0;
      if (retI < 0 || retS < 0 || adj < 0) { e.push('Las retenciones y el ajuste no pueden ser negativos.'); }
      if (retI > l.itbis + 0.004) { e.push('La retención de ITBIS no puede pasar del ITBIS facturado (' + this.dinero(l.itbis) + ').'); }
      if (retS > l.base + 0.004) { e.push('La retención de ISR no puede pasar del monto sin ITBIS (' + this.dinero(l.base) + ').'); }
      if (this.neto(l) < -0.004) { e.push('Con esas retenciones y ese ajuste la factura valdría menos de cero.'); }
      if (this.abono(l) < -0.004) {
        e.push(l.previo > 0.004
          ? 'El saldo antes de este pago no puede subir por encima de ' + this.dinero(this.neto(l) - l.previo) + ': para subirlo, corrige o anula los recibos anteriores de esta factura.'
          : 'El saldo antes de este pago no puede pasar del neto del comprobante (' + this.dinero(this.neto(l)) + ').');
      }
      if (schImporte(l.amount) < 0) { e.push('Este pago no puede ser negativo.'); }
      if (schImporte(l.c.pend) < -0.004) { e.push('Este pago supera el saldo antes de este pago: baja el pago o sube el saldo anterior.'); }
      if (this.abono(l) > 0.004) {
        if (!l.c.abonoFecha) {
          e.push('Indica la fecha en que el cliente hizo el abono anterior.');
        } else if (this.r.paid_at && l.c.abonoFecha >= this.r.paid_at) {
          e.push('El abono anterior tiene que ser de antes de la fecha de este recibo.');
        }
      }
      return e;
    },
    bloqueado() {
      var self = this;
      return this.r.lines.some(function (l) { return l.c && self.errores(l).length > 0; });
    }
  };
};

/* =========================================================================
   Plan de cuotas
   ========================================================================= */

window.planCuotas = function planCuotas(cfg) {
  cfg = cfg || {};
  return {
    saldo: Number(cfg.saldo) || 0,
    moneda: cfg.moneda || 'DOP',
    filas: Array.isArray(cfg.filas) && cfg.filas.length ? cfg.filas : [],
    n: Array.isArray(cfg.filas) && cfg.filas.length ? cfg.filas.length : 3,
    primera: Array.isArray(cfg.filas) && cfg.filas.length ? cfg.filas[0].due : '',
    frecuencia: 'mensual',
    enviando: false,

    /* Reparto en partes iguales, con el redondeo en la ÚLTIMA cuota para que la
       suma dé exacta al centavo. Misma regla que installments_propose(). */
    generar() {
      var n = Math.max(2, Math.min(36, parseInt(this.n, 10) || 2));
      this.n = n;
      var cuota = Math.floor((this.saldo / n) * 100) / 100;
      var fecha = this.primera || cfg.hoy || new Date().toISOString().slice(0, 10);
      var filas = [];
      for (var i = 1; i <= n; i++) {
        var imp = i === n ? Math.round((this.saldo - cuota * (n - 1)) * 100) / 100 : cuota;
        filas.push({ due: fecha, amt: imp.toFixed(2) });
        fecha = this.siguiente(fecha);
      }
      this.filas = filas;
    },

    /* Un mes después sin saltarse meses: del 31 de enero al 28/29 de febrero. */
    siguiente(fecha) {
      var p = String(fecha).split('-').map(Number);
      if (p.length !== 3 || !p[0]) { return fecha; }
      var y = p[0], m = p[1], d = p[2];
      var pad = function (x) { return (x < 10 ? '0' : '') + x; };
      if (this.frecuencia === 'mensual') {
        m += 1; if (m > 12) { m = 1; y += 1; }
        var ultimo = new Date(y, m, 0).getDate();
        return y + '-' + pad(m) + '-' + pad(Math.min(d, ultimo));
      }
      var dias = this.frecuencia === 'quincenal' ? 15 : 7;
      var dt = new Date(Date.UTC(p[0], p[1] - 1, p[2] + dias));
      return dt.getUTCFullYear() + '-' + pad(dt.getUTCMonth() + 1) + '-' + pad(dt.getUTCDate());
    },

    suma() {
      return Math.round(this.filas.reduce(function (s, f) { return s + schImporte(f.amt); }, 0) * 100) / 100;
    },
    cuadra() {
      return this.filas.length >= 2 && Math.abs(this.suma() - this.saldo) <= 0.01
        && this.filas.every(function (f) { return schImporte(f.amt) > 0.009 && f.due; });
    },
    diferencia() {
      var d = Math.round((this.saldo - this.suma()) * 100) / 100;
      return d > 0 ? 'Faltan ' + schDinero(d, this.moneda) : 'Sobran ' + schDinero(-d, this.moneda);
    },
    fechasDesordenadas() {
      for (var i = 1; i < this.filas.length; i++) {
        if (this.filas[i].due && this.filas[i - 1].due && this.filas[i].due <= this.filas[i - 1].due) { return true; }
      }
      return false;
    },
    errorFila(i) {
      var f = this.filas[i];
      if (!f) { return false; }
      if (schImporte(f.amt) <= 0.009) { return true; }
      return i > 0 && f.due && this.filas[i - 1].due && f.due <= this.filas[i - 1].due;
    },
    dinero(v) { return schDinero(v, this.moneda); }
  };
};
