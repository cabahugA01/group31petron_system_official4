<div class="modal fade" id="stockRequestModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-box-plus"></i> Request Stock</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form method="post" id="stockRequestForm" data-draft-module="stock_request_form">
                <div class="modal-body">
                    <?php if ($product_info): ?>
                        <div style="background: #f8fafc; padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; border-left: 3px solid var(--petron-blue);">
                            <div style="font-weight: 600; color: #0f172a; margin-bottom: 4px;"><?php echo htmlspecialchars($product_info['name']); ?></div>
                            <div style="font-size: 12px; color: #64748b;">
                                <span><i class="fas fa-barcode"></i> <?php echo htmlspecialchars($product_info['sku'] ?? 'N/A'); ?></span>
                                <span style="margin-left: 12px;"><i class="fas fa-tag"></i> <?php echo htmlspecialchars($product_info['category_name'] ?? 'Uncategorized'); ?></span>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <div class="form-group">
                        <label style="font-size: 13px; font-weight: 600; color: #475569;">Request Type</label>
                        <select name="type" class="form-control" required>
                            <option value="merch" selected>Merchandise</option>
                            <option value="fuel">Fuel</option>
                        </select>
                    </div>

                    <div style="background:#e8f0fe;border:1px solid #b3c8f5;border-radius:8px;padding:10px 14px;font-size:12px;color:#1a3a6b;margin-top:8px;">
                        <i class="fas fa-info-circle"></i> The manager will review and set the quantity for this request.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Submit Request</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var modal = document.getElementById('stockRequestModal');
    if (modal) {
        $(modal).modal('show');
        
        $(modal).on('hidden.bs.modal', function() {
            if (window.history.length > 1) {
                window.history.back();
            } else {
                window.location.href = 'inventory_list.php';
            }
        });
    }
});
</script>
