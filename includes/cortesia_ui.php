<?php if (!empty($GLOBALS['cortesia_ok'])): ?>
  <div class="alert alert-info" style="margin-top:10px;"><?php echo $GLOBALS['cortesia_ok']; ?></div>
<?php endif; ?>
<button type="button" id="btnCortesia" class="btn btn-danger" data-toggle="modal" data-target="#cortesiaModal">Regalar cortes&iacute;a</button>
<div class="modal fade" id="cortesiaModal" tabindex="-1" role="dialog">
  <div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Regalar cortes&iacute;a</h5>
      <button type="button" class="close" data-dismiss="modal">&times;</button></div>
    <form method="POST">
      <div class="modal-body">
        <p style="color:#666;">Se otorga el plan sin costo. Queda en Facturas como <strong>CORTES&Iacute;A ($0)</strong> sin afectar ingresos.</p>
        <div class="form-group"><label>Plan a regalar:</label>
          <select name="cortesia_ticket_id" class="form-control" required>
            <option value="">-- Selecciona --</option>
            <?php foreach (($cortesia_tickets ?? []) as $ct): ?>
              <option value="<?php echo (int)$ct['id']; ?>"><?php echo htmlspecialchars($ct['name']); ?> (<?php echo (int)$ct['expire_days']; ?> d&iacute;as, <?php echo (int)$ct['occasions']; ?> ingresos)</option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-danger">Otorgar cortes&iacute;a</button>
      </div>
    </form>
  </div></div>
</div>
