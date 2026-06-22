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

window.publicTicketWizard = function publicTicketWizard() {
  return {
    step: 1,
    titles: ['Contacto del reporte', 'Activo afectado', 'Prioridad y descripcion'],
    error: '',
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
      this.$nextTick(() => schInitIcons());
    },
    validEmail(value) {
      return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(value || '').trim());
    },
    validateCurrent() {
      this.error = '';
      if (this.step === 1) {
        if (!this.fields.contact_name.trim()) this.error = 'Indica el nombre del contacto que reporta el caso.';
        else if (!this.validEmail(this.fields.email)) this.error = 'Indica un correo institucional valido.';
      }
      if (this.step === 3) {
        if (!this.fields.subject.trim()) this.error = 'Escribe un asunto claro para el ticket.';
        else if (!this.fields.description.trim()) this.error = 'Describe el problema antes de enviar el ticket.';
      }
      return this.error === '';
    },
    next() {
      if (!this.validateCurrent()) return;
      this.step = Math.min(3, this.step + 1);
      this.$nextTick(() => schInitIcons());
    },
    back() {
      this.error = '';
      this.step = Math.max(1, this.step - 1);
      this.$nextTick(() => schInitIcons());
    },
    validateFinal(event) {
      if (!this.validateCurrent()) event.preventDefault();
    }
  };
};

/* PDF preview modal (iframe) for quotes */
window.crmPdfPreviewOpen = function crmPdfPreviewOpen(viewUrl, downloadUrl, title) {
  const dlg = document.getElementById('crm-pdf-modal');
  if (!dlg) { window.open(viewUrl, '_blank'); return; }
  const frame = dlg.querySelector('#crm-pdf-frame');
  const dl = dlg.querySelector('#crm-pdf-download');
  const open = dlg.querySelector('#crm-pdf-open');
  const tl = dlg.querySelector('#crm-pdf-title');
  if (frame) frame.src = viewUrl;
  if (dl) dl.href = downloadUrl;
  if (open) open.href = viewUrl;
  if (tl) tl.textContent = title ? ('Cotización ' + title) : 'Vista previa de cotización';
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

/* Quote editor inside a modal (cotizaciones) — create + edit with line items */
window.crmQuoteModal = function crmQuoteModal(opts) {
  opts = opts || {};
  var defaults = opts.defaults || {};
  return {
    form: {},
    items: [{ d: '', q: 1, p: 0 }],
    tax: 18,
    currency: 'DOP',
    rate: Number(defaults.rate) > 0 ? Number(defaults.rate) : 60,
    reset() {
      this.form = { id: 0, client_id: '', title: '', category: '', status: 'Borrador', valid_until: defaults.validUntil || '', notes: '', terms: defaults.terms || '' };
      this.items = [{ d: '', q: 1, p: 0 }];
      this.tax = (defaults.tax !== undefined && Number(defaults.tax) >= 0) ? Number(defaults.tax) : 18;
      this.currency = 'DOP';
      this.rate = Number(defaults.rate) > 0 ? Number(defaults.rate) : 60;
    },
    sym() { return this.currency === 'USD' ? 'US$' : 'RD$'; },
    altSym() { return this.currency === 'USD' ? 'RD$' : 'US$'; },
    nf(n) { return (Number(n) || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
    fmt(n) { return this.sym() + ' ' + this.nf(n); },
    altFmt(n) { return this.altSym() + ' ' + this.nf(n); },
    subtotal() { return this.items.reduce((s, i) => s + (Number(i.q) || 0) * (Number(i.p) || 0), 0); },
    taxAmount() { return this.subtotal() * (Number(this.tax) || 0) / 100; },
    total() { return this.subtotal() + this.taxAmount(); },
    altTotal() {
      const r = Number(this.rate) || 0;
      if (this.currency === 'USD') return this.total() * r;
      return r > 0 ? this.total() / r : 0;
    },
    addLine() { this.items.push({ d: '', q: 1, p: 0 }); this.$nextTick(() => { if (window.lucide) window.lucide.createIcons(); }); },
    removeLine(index) { if (this.items.length > 1) this.items.splice(index, 1); },
    openNew() { this.reset(); this.open(); },
    openEdit(d) {
      d = d || {};
      this.form = {
        id: d.id || 0, client_id: d.client_id || '', title: d.title || '', category: d.category || '',
        status: d.status || 'Borrador', valid_until: d.valid_until || (defaults.validUntil || ''),
        notes: d.notes || '', terms: (d.terms && d.terms.length) ? d.terms : (defaults.terms || '')
      };
      this.items = (d.items && d.items.length) ? d.items.map(function (it) { return { d: it.d || '', q: Number(it.q) || 0, p: Number(it.p) || 0 }; }) : [{ d: '', q: 1, p: 0 }];
      this.tax = Number(d.tax_rate) >= 0 && d.tax_rate !== undefined && d.tax_rate !== '' ? Number(d.tax_rate) : 18;
      this.currency = d.currency === 'USD' ? 'USD' : 'DOP';
      this.rate = Number(d.exchange_rate) > 0 ? Number(d.exchange_rate) : (Number(defaults.rate) > 0 ? Number(defaults.rate) : 60);
      this.open();
    },
    init() {
      this.reset();
      if (opts.autoEdit) { this.openEdit(opts.autoEdit); }
      else if (opts.autoOpen) { this.open(); }
    },
    open() { const d = this.$refs.dlg; if (d && !d.open) d.showModal(); this.$nextTick(() => { if (window.lucide) window.lucide.createIcons(); }); },
    close() { const d = this.$refs.dlg; if (d && d.open) d.close(); }
  };
};

/* Fiscal invoice editor inside a modal (facturas) — NCF, ITBIS, exempt lines, retentions */
window.crmInvoiceModal = function crmInvoiceModal(opts) {
  opts = opts || {};
  var defaults = opts.defaults || {};
  var types = opts.types || [];
  var pairs = opts.pairs || {};
  return {
    form: {},
    items: [{ d: '', q: 1, p: 0, disc: 0, exempt: false }],
    tax: Number(defaults.tax) >= 0 ? Number(defaults.tax) : 18,
    isc: 0,
    itbisRet: 0,
    isrRet: 0,
    currency: 'DOP',
    rate: Number(defaults.rate) > 0 ? Number(defaults.rate) : 60,
    reset() {
      this.form = {
        id: 0, client_id: '', title: '', ncf_type: defaults.type || '01', ncf_prefix: defaults.prefix === 'E' ? 'E' : 'B',
        payment_condition: defaults.condition || 'Contado', payment_method: '',
        issue_date: defaults.issueDate || '', due_date: defaults.dueDate || '', modifies_ncf: '',
        notes: '', terms: defaults.terms || ''
      };
      this.items = [{ d: '', q: 1, p: 0, disc: 0, exempt: false }];
      this.tax = Number(defaults.tax) >= 0 ? Number(defaults.tax) : 18;
      this.isc = 0; this.itbisRet = 0; this.isrRet = 0;
      this.currency = 'DOP';
      this.rate = Number(defaults.rate) > 0 ? Number(defaults.rate) : 60;
    },
    availableTypes() { var p = this.form.ncf_prefix === 'E' ? 'E' : 'B'; return types.filter(function (t) { return t.series === p; }); },
    syncType() {
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
    requiresRnc() { var t = types.find((x) => x.code === this.form.ncf_type); return !!(t && t.rnc); },
    sym() { return this.currency === 'USD' ? 'US$' : 'RD$'; },
    altSym() { return this.currency === 'USD' ? 'RD$' : 'US$'; },
    nf(n) { return (Number(n) || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
    fmt(n) { return this.sym() + ' ' + this.nf(n); },
    altFmt(n) { return this.altSym() + ' ' + this.nf(n); },
    lineNet(item) { return Math.max(0, (Number(item.q) || 0) * (Number(item.p) || 0) - (Number(item.disc) || 0)); },
    subtotalTaxed() { return this.items.reduce((s, i) => s + (i.exempt ? 0 : this.lineNet(i)), 0); },
    subtotalExempt() { return this.items.reduce((s, i) => s + (i.exempt ? this.lineNet(i) : 0), 0); },
    discountTotal() { return this.items.reduce((s, i) => s + (Number(i.disc) || 0), 0); },
    taxAmount() { return this.subtotalTaxed() * (Number(this.tax) || 0) / 100; },
    total() { return this.subtotalTaxed() + this.subtotalExempt() + this.taxAmount() + (Number(this.isc) || 0); },
    netReceivable() { return this.total() - (Number(this.itbisRet) || 0) - (Number(this.isrRet) || 0); },
    altTotal() {
      const r = Number(this.rate) || 0;
      if (this.currency === 'USD') return this.total() * r;
      return r > 0 ? this.total() / r : 0;
    },
    addLine() { this.items.push({ d: '', q: 1, p: 0, disc: 0, exempt: false }); this.$nextTick(() => { if (window.lucide) window.lucide.createIcons(); }); },
    removeLine(index) { if (this.items.length > 1) this.items.splice(index, 1); },
    openNew() { this.reset(); this.open(); },
    openEdit(d) {
      d = d || {};
      this.form = {
        id: d.id || 0, client_id: d.client_id || '', title: d.title || '',
        ncf_type: d.ncf_type || defaults.type || '01', ncf_prefix: d.ncf_prefix === 'E' ? 'E' : 'B',
        payment_condition: d.payment_condition || defaults.condition || 'Contado', payment_method: d.payment_method || '',
        issue_date: d.issue_date || defaults.issueDate || '', due_date: d.due_date || defaults.dueDate || '',
        modifies_ncf: d.modifies_ncf || '', notes: d.notes || '', terms: (d.terms && d.terms.length) ? d.terms : (defaults.terms || '')
      };
      this.items = (d.items && d.items.length) ? d.items.map(function (it) { return { d: it.d || '', q: Number(it.q) || 0, p: Number(it.p) || 0, disc: Number(it.disc) || 0, exempt: !!it.exempt }; }) : [{ d: '', q: 1, p: 0, disc: 0, exempt: false }];
      this.tax = (d.tax_rate !== undefined && d.tax_rate !== '' && Number(d.tax_rate) >= 0) ? Number(d.tax_rate) : (Number(defaults.tax) >= 0 ? Number(defaults.tax) : 18);
      this.isc = Number(d.isc_amount) || 0; this.itbisRet = Number(d.itbis_retained) || 0; this.isrRet = Number(d.isr_retained) || 0;
      this.currency = d.currency === 'USD' ? 'USD' : 'DOP';
      this.rate = Number(d.exchange_rate) > 0 ? Number(d.exchange_rate) : (Number(defaults.rate) > 0 ? Number(defaults.rate) : 60);
      this.open();
    },
    applyPrefill(d) {
      this.reset();
      this.form.client_id = d.client_id || '';
      this.form.title = d.title || '';
      this.form.notes = d.notes || '';
      this.items = (d.items && d.items.length) ? d.items.map(function (it) { return { d: it.d || '', q: Number(it.q) || 0, p: Number(it.p) || 0, disc: Number(it.disc) || 0, exempt: !!it.exempt }; }) : [{ d: '', q: 1, p: 0, disc: 0, exempt: false }];
      if (d.tax_rate !== undefined && Number(d.tax_rate) >= 0) this.tax = Number(d.tax_rate);
      this.currency = d.currency === 'USD' ? 'USD' : 'DOP';
      if (Number(d.exchange_rate) > 0) this.rate = Number(d.exchange_rate);
    },
    init() {
      this.reset();
      if (opts.autoEdit) { this.openEdit(opts.autoEdit); }
      else if (opts.prefill) { this.applyPrefill(opts.prefill); this.open(); }
      else if (opts.autoOpen) { this.open(); }
    },
    open() { const d = this.$refs.dlg; if (d && !d.open) d.showModal(); this.$nextTick(() => { if (window.lucide) window.lucide.createIcons(); }); },
    close() { const d = this.$refs.dlg; if (d && d.open) d.close(); }
  };
};

