        </main>
    </div>
</div>
</div>
<div class="crm-toast-wrap" id="crm-toasts" aria-live="polite" aria-atomic="false"></div>

<dialog id="crm-pdf-modal" class="crm-modal crm-modal--pdf" onclick="if(event.target===this)crmPdfPreviewClose()" oncancel="event.preventDefault();crmPdfPreviewClose()">
    <div class="crm-modal__head">
        <span class="crm-modal__icon"><i data-lucide="file-text"></i></span>
        <div class="crm-modal__titles">
            <h2 id="crm-pdf-title">Vista previa de cotización</h2>
            <p>Documento PDF · A4 vertical</p>
        </div>
        <a id="crm-pdf-open" href="#" target="_blank" rel="noopener" class="crm-modal__close" title="Abrir en pestaña nueva"><i data-lucide="external-link"></i></a>
        <button type="button" class="crm-modal__close" style="margin-left:.4rem" onclick="crmPdfPreviewClose()" aria-label="Cerrar"><i data-lucide="x"></i></button>
    </div>
    <div class="crm-pdf-frame-wrap"><iframe id="crm-pdf-frame" title="Vista previa del PDF" src="about:blank"></iframe></div>
    <div class="crm-modal__foot">
        <button type="button" class="crm-secondary-btn" onclick="crmPdfPreviewClose()">Cerrar</button>
        <a id="crm-pdf-download" href="#" class="crm-primary-btn"><i data-lucide="download" class="h-4 w-4"></i>Descargar PDF</a>
    </div>
</dialog>
</body>
</html>
