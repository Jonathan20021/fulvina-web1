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

<?php $pwUser = current_user(); if (!empty($pwUser['must_change_password'])): ?>
<?php $pwErr = $_SESSION['pwchange_error'] ?? ''; unset($_SESSION['pwchange_error']); ?>
<div class="crm-force-pw" role="dialog" aria-modal="true" aria-labelledby="force-pw-title" style="position:fixed;inset:0;z-index:10000;display:flex;align-items:center;justify-content:center;background:rgba(8,18,30,.74);backdrop-filter:blur(3px);padding:1rem">
    <div style="width:min(440px,100%);background:#fff;border-radius:16px;box-shadow:0 30px 80px -18px rgba(0,0,0,.55);overflow:hidden">
        <div style="padding:1.5rem 1.5rem 0">
            <span style="display:inline-flex;align-items:center;justify-content:center;width:46px;height:46px;border-radius:13px;background:#eef6f0;color:#0a7d36"><i data-lucide="shield-alert"></i></span>
            <h2 id="force-pw-title" style="margin:.85rem 0 .25rem;font-size:1.22rem;font-weight:700;color:#0e1a28">Crea tu contraseña</h2>
            <p style="margin:0;color:#56697b;font-size:.9rem;line-height:1.5">Por seguridad, antes de continuar debes reemplazar la contraseña temporal por una personal.</p>
        </div>
        <?php if ($pwErr !== ''): ?>
        <div style="margin:1rem 1.5rem 0;padding:.6rem .8rem;border-radius:10px;background:#fef2f2;border:1px solid #fecaca;color:#b42318;font-size:.85rem;font-weight:600"><?= e($pwErr) ?></div>
        <?php endif; ?>
        <form method="post" action="<?= url('crm/cambiar_password.php') ?>" style="padding:1.1rem 1.5rem 1.4rem;display:grid;gap:.85rem">
            <?= csrf_field() ?>
            <label class="crm-field"><span class="required">Nueva contraseña</span><input type="password" name="new_password" minlength="8" required autofocus autocomplete="new-password" class="crm-input" placeholder="Mínimo 8 caracteres"></label>
            <label class="crm-field"><span class="required">Confirmar contraseña</span><input type="password" name="confirm_password" minlength="8" required autocomplete="new-password" class="crm-input" placeholder="Repite la contraseña"></label>
            <button type="submit" class="crm-primary-btn" style="width:100%;justify-content:center"><i data-lucide="check" class="h-4 w-4"></i>Guardar y continuar</button>
        </form>
        <div style="padding:0 1.5rem 1.35rem;text-align:center">
            <form method="post" action="<?= url('crm/logout.php') ?>" style="display:inline">
                <?= csrf_field() ?>
                <button type="submit" style="background:none;border:0;color:#8696a6;font-size:.8rem;cursor:pointer;text-decoration:underline">Cerrar sesión</button>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>
</body>
</html>
