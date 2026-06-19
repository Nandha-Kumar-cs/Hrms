<?php
/**
 * Reusable read-only "record details" modal.
 *
 * Include once on a list page (before footer.php), then add a trigger button
 * per row:
 *
 *   <button type="button" class="btn btn-xs btn-outline-info"
 *           data-bs-toggle="modal" data-bs-target="#recModal"
 *           data-rec-title="Loan / Advance — <?= h($r['emp_name']) ?>"
 *           data-rec="<?= h(json_encode(['Type' => 'Loan', 'Amount' => money($r['amount'])])) ?>">
 *     <i class="fa fa-eye"></i>
 *   </button>
 *
 * `data-rec` is an ordered label => value map (plain text values). Rendering
 * uses textContent only, so values are never interpreted as HTML.
 */
?>
<div class="modal fade" id="recModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">
          <i class="fa fa-circle-info me-2 text-info"></i><span id="recModalTitleText">Details</span>
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body"><dl class="row mb-0" id="recModalBody"></dl></div>
      <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button></div>
    </div>
  </div>
</div>
<script>
(function () {
  var modal = document.getElementById('recModal');
  if (!modal) return;
  modal.addEventListener('show.bs.modal', function (ev) {
    var btn = ev.relatedTarget; if (!btn) return;
    document.getElementById('recModalTitleText').textContent = btn.getAttribute('data-rec-title') || 'Details';
    var data = {};
    try { data = JSON.parse(btn.getAttribute('data-rec') || '{}'); } catch (e) {}
    var body = document.getElementById('recModalBody'); body.innerHTML = '';
    Object.keys(data).forEach(function (k) {
      var dt = document.createElement('dt'); dt.className = 'col-sm-5 text-muted fw-normal'; dt.textContent = k;
      var dd = document.createElement('dd'); dd.className = 'col-sm-7'; dd.textContent = (data[k] === null || data[k] === '') ? '—' : data[k];
      body.appendChild(dt); body.appendChild(dd);
    });
  });
})();
</script>
