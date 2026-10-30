<?php
/**
 * Emisión del recibo de seña de una reserva aprobada. Solo se ingresa el importe (USD); el resto
 * sale de la reserva. El email del cliente se confirma (obligatorio) para poder enviárselo.
 * @var array $r @var string $concepto @var array $brandMails @var array $old @var ?array $flash @var string $basePath
 */
$v = fn($k, $def = '') => htmlspecialchars((string) ($old[$k] ?? $def));
$id = (int) $r['id'];
$emailReserva = trim((string) ($r['cliente_email'] ?? ''));
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">🧾 Emitir recibo de seña <span class="text-muted fw-normal">· Reserva N° <?= (int) $r['numero'] ?></span></h1>
    <a href="<?= $basePath ?>/ventas/reservas/<?= $id ?>" class="btn btn-outline-secondary btn-sm">← Volver a la reserva</a>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= htmlspecialchars($flash['type']) ?> py-2"><?= htmlspecialchars($flash['msg']) ?></div>
<?php endif; ?>

<form method="post" action="<?= $basePath ?>/ventas/reservas/<?= $id ?>/recibo" id="reciboForm">
    <div class="row g-4">
        <div class="col-md-6">
            <div class="card h-100"><div class="card-body">
                <div class="card-title fw-bold mb-3">Datos del recibo <span class="text-muted fw-normal small">(salen de la reserva)</span></div>
                <dl class="row mb-0 small">
                    <dt class="col-5 text-muted fw-normal">Marca</dt><dd class="col-7"><?= htmlspecialchars((string) $r['marca']) ?></dd>
                    <dt class="col-5 text-muted fw-normal">Cliente</dt><dd class="col-7"><?= htmlspecialchars((string) $r['cliente_nombre']) ?></dd>
                    <dt class="col-5 text-muted fw-normal">CUIT / CUIL</dt><dd class="col-7 mono"><?= htmlspecialchars((string) $r['cliente_nro_doc']) ?></dd>
                    <dt class="col-5 text-muted fw-normal">Modelo</dt><dd class="col-7"><?= htmlspecialchars((string) ($r['modelo'] ?: '—')) ?></dd>
                    <dt class="col-5 text-muted fw-normal">VIN</dt><dd class="col-7 mono"><?= htmlspecialchars((string) ($r['vin'] ?: '—')) ?></dd>
                </dl>
                <div class="form-text mt-2">La numeración es automática por marca. El concepto por defecto es <em>«<?= htmlspecialchars($concepto) ?>»</em>.</div>
            </div></div>
        </div>
        <div class="col-md-6">
            <div class="card h-100"><div class="card-body">
                <div class="card-title fw-bold mb-3">Importe y envío</div>
                <div class="mb-3">
                    <label class="form-label small mb-1">Importe de la seña (USD) <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text">USD</span>
                        <input type="text" name="importe" id="importe" class="form-control text-end mono" inputmode="decimal"
                               value="<?= $v('importe') ?>" placeholder="0" autocomplete="off" required autofocus>
                    </div>
                    <div class="form-text">Siempre en dólares. Es el único dato que se ingresa.</div>
                </div>
                <div class="mb-3">
                    <label class="form-label small mb-1">Email del cliente <span class="text-danger">*</span></label>
                    <input type="email" name="cliente_email" class="form-control form-control-sm"
                           value="<?= $v('cliente_email', $emailReserva) ?>" placeholder="cliente@correo.com" required>
                    <div class="form-text">Queda registrado en el recibo. <strong>No</strong> se le envía por mail.</div>
                </div>
                <div class="mb-2">
                    <label class="form-label small mb-1">Concepto</label>
                    <input type="text" name="concepto" class="form-control form-control-sm" value="<?= $v('concepto', $concepto) ?>">
                </div>
                <div class="small text-muted">
                    <?php if ($brandMails): ?>
                        El recibo se envía a: <strong><?= htmlspecialchars(implode(', ', $brandMails)) ?></strong>.
                        <span class="d-block">Al cliente <strong>no</strong> se le envía por mail.</span>
                    <?php else: ?>
                        <span class="text-warning-emphasis">No hay casillas configuradas para <?= htmlspecialchars((string) $r['marca']) ?></span>
                        (Admin → Configuración): el recibo se genera igual y queda descargable. Al cliente no se le envía por mail.
                    <?php endif; ?>
                </div>
            </div></div>
        </div>
    </div>

    <div class="alert alert-info py-2 mt-3 small mb-0">
        Al emitirlo se genera el PDF<?= $brandMails ? ', se envía a las casillas de la marca' : '' ?> y queda registrado.
        <strong>Al cliente no se le envía por mail. Se emite un solo recibo por reserva.</strong>
    </div>

    <div class="mt-3">
        <button type="submit" class="btn btn-primary">🧾 Emitir y enviar recibo</button>
        <a href="<?= $basePath ?>/ventas/reservas/<?= $id ?>" class="btn btn-outline-secondary ms-1">Cancelar</a>
    </div>
</form>

<script>
(function () {
    var form = document.getElementById('reciboForm');
    if (!form) { return; }
    // El recibo se emite SOLO al apretar el botón: Enter en un campo no envía el formulario
    // (evita el guardado accidental al tipear el importe).
    form.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && e.target && e.target.tagName === 'INPUT') { e.preventDefault(); }
    });
    // Confirmación: emitir es irreversible (uno por reserva).
    form.addEventListener('submit', function (e) {
        var imp = (document.getElementById('importe') || {}).value || '';
        if (!confirm('Se va a emitir el recibo por USD ' + (imp || '?') + '.\nEs un solo recibo por reserva y no se puede modificar. ¿Confirmás?')) {
            e.preventDefault();
        }
    });
})();
</script>
