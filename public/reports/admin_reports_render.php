<?php
/**
 * ADMIN REPORTS RENDER LAYER
 * Renders the exact UI tables and summaries for all 7 Admin Report Categories
 */

if (!defined('PETRON_SYSTEM')) {
    define('PETRON_SYSTEM', true);
}

function renderAdminReportContent(string $cat, string $tab, array $report_data): void {
    $rows = $report_data['rows'] ?? [];

    switch ($cat) {

        // =========================================================================
        // 1. SALES REPORTS
        // =========================================================================
        case 'sales':
            if ($tab === 'fuel_sales') {
                $ugt_rows    = $report_data['ugt_rows']    ?? [];
                $fuel_summary= $report_data['fuel_summary'] ?? [];
                $recon       = $report_data['reconciliation'] ?? [];
                $variance    = $report_data['variance']    ?? [];
                ?>

                <?php if (empty($ugt_rows)): ?>
                    <div style="text-align:center;padding:48px 0;color:#94a3b8;">
                        <i class="fas fa-gas-pump" style="font-size:40px;opacity:0.3;display:block;margin-bottom:12px;"></i>
                        <p style="margin:0;font-size:14px;">No fuel transaction records found for this period.</p>
                    </div>
                <?php else: ?>

                <!-- =====================================================
                     SECTION 1: UGT DAILY SALES TABLE
                     ===================================================== -->
                <div class="rpt-section-heading"><i class="fas fa-table"></i> UGT Daily Sales Table</div>
                <div class="table-responsive mb-4">
                    <table class="rpt-table align-middle">
                        <thead>
                            <tr>
                                <th style="text-align:left;">Date</th>
                                <th style="text-align:left;">UGT No.</th>
                                <th style="text-align:left;">Fuel Type</th>
                                <th style="text-align:right;">Beginning Reading</th>
                                <th style="text-align:right;">Ending Reading</th>
                                <th style="text-align:right;">Total Calibration (L)</th>
                                <th style="text-align:right;">Net Volume Sold (L)</th>
                                <th style="text-align:right;">Selling Price/L</th>
                                <th style="text-align:right;">Total Fuel Sales</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $grand_beg = 0; $grand_end = 0; $grand_calib = 0; $grand_vol = 0; $grand_sales = 0;
                            foreach ($ugt_rows as $r):
                                $beg       = (float)$r['beginning_reading'];
                                $end       = (float)$r['ending_reading'];
                                $calib     = (float)$r['total_calibration'];
                                $net_vol   = (float)$r['net_volume_sold'];
                                $t_sales   = (float)$r['total_fuel_sales'];

                                $grand_beg   += $beg;
                                $grand_end   += $end;
                                $grand_calib += $calib;
                                $grand_vol   += $net_vol;
                                $grand_sales += $t_sales;
                            ?>
                            <tr>
                                <td style="text-align:left;"><?= date('M d, Y', strtotime($r['report_date'])) ?></td>
                                <td style="text-align:left;"><strong><?= htmlspecialchars($r['ugt_no']) ?></strong></td>
                                <td style="text-align:left;"><?= htmlspecialchars($r['fuel_type']) ?></td>
                                <td style="text-align:right;"><?= number_format($beg, 2) ?></td>
                                <td style="text-align:right;"><?= number_format($end, 2) ?></td>
                                <td style="text-align:right;"><?= number_format($calib, 2) ?> L</td>
                                <td style="text-align:right;" class="fw-bold"><?= number_format($net_vol, 2) ?> L</td>
                                <td style="text-align:right;">₱<?= number_format((float)$r['price_per_liter'], 2) ?></td>
                                <td style="text-align:right;" class="fw-bold text-success">₱<?= number_format($t_sales, 2) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="3" class="fw-bold" style="text-align:left;">TOTALS</td>
                                <td style="text-align:right;" class="fw-bold"><?= number_format($grand_beg, 2) ?></td>
                                <td style="text-align:right;" class="fw-bold"><?= number_format($grand_end, 2) ?></td>
                                <td style="text-align:right;" class="fw-bold"><?= number_format($grand_calib, 2) ?> L</td>
                                <td style="text-align:right;" class="fw-bold"><?= number_format($grand_vol, 2) ?> L</td>
                                <td></td>
                                <td style="text-align:right;" class="fw-bold text-success">₱<?= number_format($grand_sales, 2) ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <!-- =====================================================
                     SECTION 2: DAILY FUEL SALES SUMMARY (by fuel type)
                     ===================================================== -->
                <div class="rpt-section-heading"><i class="fas fa-chart-bar"></i> Daily Fuel Sales Summary</div>
                <div class="table-responsive mb-4">
                    <table class="rpt-table align-middle">
                        <thead>
                            <tr>
                                <th style="text-align:left;">Fuel Type</th>
                                <th style="text-align:center;">No. of UGTs</th>
                                <th style="text-align:right;">Total Volume Sold (L)</th>
                                <th style="text-align:right;">Selling Price/L</th>
                                <th style="text-align:right;">Total Sales</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $sum_ugts = 0; $sum_vol = 0; $sum_total = 0; 
                            foreach ($fuel_summary as $s): 
                                $sum_ugts  += (int)$s['ugt_count'];
                                $sum_vol   += (float)$s['total_volume'];
                                $sum_total += (float)$s['total_sales']; 
                            ?>
                            <tr>
                                <td style="text-align:left;"><strong><?= htmlspecialchars($s['fuel_type']) ?></strong></td>
                                <td style="text-align:center;"><?= (int)$s['ugt_count'] ?></td>
                                <td style="text-align:right;"><?= number_format((float)$s['total_volume'], 2) ?> L</td>
                                <td style="text-align:right;">₱<?= number_format((float)$s['avg_price'], 2) ?></td>
                                <td style="text-align:right;" class="fw-bold text-success">₱<?= number_format((float)$s['total_sales'], 2) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td style="text-align:left;" class="fw-bold">TOTAL FUEL SALES</td>
                                <td style="text-align:center;" class="fw-bold"><?= $sum_ugts ?></td>
                                <td style="text-align:right;" class="fw-bold"><?= number_format($sum_vol, 2) ?> L</td>
                                <td></td>
                                <td style="text-align:right;" class="fw-bold text-success">₱<?= number_format($sum_total, 2) ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <!-- =====================================================
                     SECTION 3: DAILY RECONCILIATION SUMMARY
                     ===================================================== -->
                <div class="rpt-section-heading"><i class="fas fa-balance-scale"></i> Daily Reconciliation Summary</div>
                <div class="table-responsive mb-4">
                    <table class="rpt-table align-middle" style="max-width:600px;">
                        <thead>
                            <tr>
                                <th style="text-align:left;">Description</th>
                                <th style="text-align:right;">Value</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $rr = [
                                'Total UGTs Monitored'   => number_format((int)($recon['total_ugts'] ?? 0)),
                                'Total Beginning Reading' => number_format((float)($recon['total_beginning'] ?? 0), 2),
                                'Total Ending Reading'    => number_format((float)($recon['total_ending'] ?? 0), 2),
                                'Total Calibration'      => number_format((float)($recon['total_calibration'] ?? 0), 2) . ' L',
                                'Total Net Volume Sold'  => number_format((float)($recon['total_volume_sold'] ?? 0), 2) . ' L',
                                'Total Fuel Sales'       => '₱' . number_format((float)($recon['total_fuel_sales'] ?? 0), 2),
                            ];
                            foreach ($rr as $desc => $val):
                            ?>
                            <tr>
                                <td style="width:60%;font-weight:600;color:#334155;text-align:left;"><?= $desc ?></td>
                                <td style="font-weight:700;text-align:right;color:<?= str_contains($desc,'Sales') ? '#16a34a' : '#00264D' ?>;"><?= $val ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- =====================================================
                     SECTION 4: VARIANCE CHECK
                     ===================================================== -->
                <div class="rpt-section-heading"><i class="fas fa-search-dollar"></i> Variance Check</div>
                <div class="table-responsive mb-2">
                    <table class="rpt-table align-middle">
                        <thead>
                            <tr>
                                <th style="text-align:left;">Fuel Type</th>
                                <th style="text-align:right;">Expected Sales</th>
                                <th style="text-align:right;">Recorded Sales</th>
                                <th style="text-align:right;">Variance</th>
                                <th style="text-align:center;">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($variance as $v):
                                $var = (float)$v['variance'];
                                $abs_var = abs($var);
                                $is_ok = $abs_var < 1;
                                $status_color = $is_ok ? '#16a34a' : ($var < 0 ? '#dc2626' : '#d97706');
                                $status_label = $is_ok ? 'Balanced' : ($var < 0 ? 'Short' : 'Investigate');
                                $status_bg    = $is_ok ? '#f0fdf4' : ($var < 0 ? '#fef2f2' : '#fffbeb');
                            ?>
                            <tr>
                                <td style="text-align:left;"><strong><?= htmlspecialchars($v['fuel_type']) ?></strong></td>
                                <td style="text-align:right;">₱<?= number_format((float)$v['expected_sales'], 2) ?></td>
                                <td style="text-align:right;">₱<?= number_format((float)$v['recorded_sales'], 2) ?></td>
                                <td style="text-align:right;" class="fw-bold" style="color:<?= $status_color ?>;">
                                    <?= $var >= 0 ? '' : '-' ?>₱<?= number_format($abs_var, 2) ?>
                                </td>
                                <td style="text-align:center;">
                                    <span style="padding:3px 10px;border-radius:12px;font-size:11px;font-weight:700;background:<?= $status_bg ?>;color:<?= $status_color ?>;">
                                        <?= $status_label ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>



                <?php endif; ?>
                <?php

            } else { // daily_merch_service
                $merch            = $report_data['merchandise'] ?? [];
                $jos              = $report_data['job_orders'] ?? [];
                $merch_subtotal   = (float)($report_data['merchandise_subtotal'] ?? array_sum(array_column($merch, 'amount')));
                $jo_subtotal      = (float)($report_data['job_orders_subtotal'] ?? array_sum(array_column($jos, 'total_amount')));
                $daily_summary    = $report_data['daily_summary'] ?? [
                    'merchandise' => ['count' => count($merch), 'amount' => $merch_subtotal],
                    'job_order'   => ['count' => count($jos),   'amount' => $jo_subtotal],
                    'overall'     => ['count' => count($merch) + count($jos), 'amount' => $merch_subtotal + $jo_subtotal]
                ];
                $payment_summary  = $report_data['payment_summary'] ?? [];
                $category_summary = $report_data['category_summary'] ?? [];
                $service_revenue  = $report_data['service_revenue'] ?? [
                    'labor_fee'   => array_sum(array_column($jos, 'labor_fee')),
                    'service_fee' => array_sum(array_column($jos, 'service_fee')),
                    'parts'       => array_sum(array_column($jos, 'parts_cost')),
                    'overall'     => $jo_subtotal
                ];
                $status_summary   = $report_data['status_summary'] ?? [];
                ?>

                <!-- 1. MERCHANDISE SALES TRANSACTIONS -->
                <div class="rpt-section-heading"><i class="fas fa-shopping-cart me-2"></i>MERCHANDISE SALES TRANSACTIONS</div>
                <div class="table-responsive mb-4">
                    <table class="rpt-table align-middle">
                        <thead>
                            <tr>
                                <th style="text-align:left;">Receipt No.</th>
                                <th style="text-align:left;">Date</th>
                                <th style="text-align:left;">Customer</th>
                                <th style="text-align:left;">Category</th>
                                <th style="text-align:left;">Product</th>
                                <th style="text-align:center;">Qty</th>
                                <th style="text-align:right;">Unit Price</th>
                                <th style="text-align:right;">Amount</th>
                                <th style="text-align:left;">Payment Method</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($merch)): ?>
                                <tr><td colspan="9" class="text-center py-4 text-muted">No merchandise sales transactions found.</td></tr>
                            <?php else: foreach ($merch as $m): ?>
                                <tr>
                                    <td style="text-align:left;"><code><?= htmlspecialchars($m['receipt_no']) ?></code></td>
                                    <td style="text-align:left;"><?= !empty($m['date']) ? date('m/d/Y', strtotime($m['date'])) : '-' ?></td>
                                    <td style="text-align:left;"><?= htmlspecialchars($m['customer']) ?></td>
                                    <td style="text-align:left;"><?= htmlspecialchars($m['category'] ?? 'General') ?></td>
                                    <td style="text-align:left;"><strong><?= htmlspecialchars($m['product']) ?></strong></td>
                                    <td style="text-align:center;" class="fw-bold"><?= number_format((float)$m['quantity']) ?></td>
                                    <td style="text-align:right;">₱<?= number_format((float)$m['unit_price'], 2) ?></td>
                                    <td style="text-align:right;" class="fw-bold text-primary">₱<?= number_format((float)$m['amount'], 2) ?></td>
                                    <td style="text-align:left;"><span class="badge bg-secondary"><?= htmlspecialchars($m['payment_method']) ?></span></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="7" class="fw-bold" style="text-align:right;">Subtotal Merchandise Sales:</td>
                                <td class="fw-bold text-success" style="text-align:right;">₱<?= number_format($merch_subtotal, 2) ?></td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <!-- 2. JOB ORDER SERVICE TRANSACTIONS -->
                <div class="rpt-section-heading"><i class="fas fa-tools me-2"></i>JOB ORDER SERVICE TRANSACTIONS</div>
                <div class="table-responsive mb-4">
                    <table class="rpt-table align-middle">
                        <thead>
                            <tr>
                                <th style="text-align:left;">JO No.</th>
                                <th style="text-align:left;">Date</th>
                                <th style="text-align:left;">Customer</th>
                                <th style="text-align:left;">Vehicle</th>
                                <th style="text-align:left;">Mechanic</th>
                                <th style="text-align:left;">Service</th>
                                <th style="text-align:right;">Labor Fee</th>
                                <th style="text-align:right;">Service Fee</th>
                                <th style="text-align:right;">Parts Cost</th>
                                <th style="text-align:right;">Total Amount</th>
                                <th style="text-align:left;">Payment Method</th>
                                <th style="text-align:center;">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($jos)): ?>
                                <tr><td colspan="12" class="text-center py-4 text-muted">No job order service transactions found.</td></tr>
                            <?php else: foreach ($jos as $j):
                                $st = strtolower($j['status'] ?? '');
                                $badge = 'bg-secondary';
                                if (str_contains($st, 'completed')) $badge = 'bg-success';
                                elseif (str_contains($st, 'released')) $badge = 'bg-primary';
                                elseif (str_contains($st, 'pending')) $badge = 'bg-warning text-dark';
                                elseif (str_contains($st, 'cancel') || str_contains($st, 'reject')) $badge = 'bg-danger';
                            ?>
                                <tr>
                                    <td style="text-align:left;"><code><?= htmlspecialchars($j['jo_no']) ?></code></td>
                                    <td style="text-align:left;"><?= !empty($j['date']) ? date('m/d/Y', strtotime($j['date'])) : '-' ?></td>
                                    <td style="text-align:left;"><?= htmlspecialchars($j['customer']) ?></td>
                                    <td style="text-align:left;"><?= htmlspecialchars($j['vehicle']) ?></td>
                                    <td style="text-align:left;"><?= htmlspecialchars($j['mechanic']) ?></td>
                                    <td style="text-align:left;"><strong><?= htmlspecialchars($j['service']) ?></strong></td>
                                    <td style="text-align:right;">₱<?= number_format((float)$j['labor_fee'], 2) ?></td>
                                    <td style="text-align:right;">₱<?= number_format((float)$j['service_fee'], 2) ?></td>
                                    <td style="text-align:right;">₱<?= number_format((float)$j['parts_cost'], 2) ?></td>
                                    <td style="text-align:right;" class="fw-bold text-success">₱<?= number_format((float)$j['total_amount'], 2) ?></td>
                                    <td style="text-align:left;"><span class="badge bg-secondary"><?= htmlspecialchars($j['payment_method']) ?></span></td>
                                    <td style="text-align:center;"><span class="badge <?= $badge ?>"><?= htmlspecialchars($j['status']) ?></span></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="9" class="fw-bold" style="text-align:right;">Subtotal Service Sales:</td>
                                <td class="fw-bold text-success" style="text-align:right;">₱<?= number_format($jo_subtotal, 2) ?></td>
                                <td colspan="2"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <!-- 3. DAILY SALES SUMMARY -->
                <div class="rpt-section-heading"><i class="fas fa-chart-bar me-2"></i>DAILY SALES SUMMARY</div>
                <div class="table-responsive mb-4">
                    <table class="rpt-table align-middle" style="max-width:600px;">
                        <thead>
                            <tr>
                                <th style="text-align:left;">Description</th>
                                <th style="text-align:center;">Transactions</th>
                                <th style="text-align:right;">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td style="text-align:left;">Merchandise Sales</td>
                                <td style="text-align:center;" class="fw-bold"><?= number_format((int)($daily_summary['merchandise']['count'] ?? 0)) ?></td>
                                <td style="text-align:right;" class="fw-bold">₱<?= number_format((float)($daily_summary['merchandise']['amount'] ?? 0), 2) ?></td>
                            </tr>
                            <tr>
                                <td style="text-align:left;">Job Order Sales</td>
                                <td style="text-align:center;" class="fw-bold"><?= number_format((int)($daily_summary['job_order']['count'] ?? 0)) ?></td>
                                <td style="text-align:right;" class="fw-bold">₱<?= number_format((float)($daily_summary['job_order']['amount'] ?? 0), 2) ?></td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td style="text-align:left;" class="fw-bold">Overall Daily Sales</td>
                                <td style="text-align:center;" class="fw-bold"><?= number_format((int)($daily_summary['overall']['count'] ?? 0)) ?></td>
                                <td style="text-align:right;" class="fw-bold text-success">₱<?= number_format((float)($daily_summary['overall']['amount'] ?? 0), 2) ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <!-- 4. PAYMENT METHOD SUMMARY -->
                <div class="rpt-section-heading"><i class="fas fa-credit-card me-2"></i>PAYMENT METHOD SUMMARY</div>
                <div class="table-responsive mb-4">
                    <table class="rpt-table align-middle" style="max-width:600px;">
                        <thead>
                            <tr>
                                <th style="text-align:left;">Payment Method</th>
                                <th style="text-align:center;">Transactions</th>
                                <th style="text-align:right;">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $pm_tot_cnt = 0;
                            $pm_tot_amt = 0;
                            if (empty($payment_summary)):
                            ?>
                                <tr><td colspan="3" class="text-center py-3 text-muted">No payment transactions recorded.</td></tr>
                            <?php else: foreach ($payment_summary as $pm_name => $pm_data):
                                $pm_tot_cnt += (int)($pm_data['count'] ?? 0);
                                $pm_tot_amt += (float)($pm_data['amount'] ?? 0);
                            ?>
                                <tr>
                                    <td style="text-align:left;"><strong><?= htmlspecialchars($pm_name) ?></strong></td>
                                    <td style="text-align:center;" class="fw-bold"><?= number_format((int)($pm_data['count'] ?? 0)) ?></td>
                                    <td style="text-align:right;" class="fw-bold">₱<?= number_format((float)($pm_data['amount'] ?? 0), 2) ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                        <?php if (!empty($payment_summary)): ?>
                        <tfoot>
                            <tr>
                                <td style="text-align:left;" class="fw-bold">TOTAL:</td>
                                <td style="text-align:center;" class="fw-bold"><?= number_format($pm_tot_cnt) ?></td>
                                <td style="text-align:right;" class="fw-bold text-success">₱<?= number_format($pm_tot_amt, 2) ?></td>
                            </tr>
                        </tfoot>
                        <?php endif; ?>
                    </table>
                </div>

                <!-- 5. SALES BY CATEGORY -->
                <div class="rpt-section-heading"><i class="fas fa-tags me-2"></i>SALES BY CATEGORY</div>
                <div class="table-responsive mb-4">
                    <table class="rpt-table align-middle" style="max-width:600px;">
                        <thead>
                            <tr>
                                <th style="text-align:left;">Category</th>
                                <th style="text-align:center;">Transactions</th>
                                <th style="text-align:right;">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $cat_tot_cnt = 0;
                            $cat_tot_amt = 0;
                            if (empty($category_summary)):
                            ?>
                                <tr><td colspan="3" class="text-center py-3 text-muted">No category sales recorded.</td></tr>
                            <?php else: foreach ($category_summary as $cat_name => $cat_data):
                                $cat_tot_cnt += (int)($cat_data['count'] ?? 0);
                                $cat_tot_amt += (float)($cat_data['amount'] ?? 0);
                            ?>
                                <tr>
                                    <td style="text-align:left;"><strong><?= htmlspecialchars($cat_name) ?></strong></td>
                                    <td style="text-align:center;" class="fw-bold"><?= number_format((int)($cat_data['count'] ?? 0)) ?></td>
                                    <td style="text-align:right;" class="fw-bold">₱<?= number_format((float)($cat_data['amount'] ?? 0), 2) ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                        <?php if (!empty($category_summary)): ?>
                        <tfoot>
                            <tr>
                                <td style="text-align:left;" class="fw-bold">TOTAL:</td>
                                <td style="text-align:center;" class="fw-bold"><?= number_format($cat_tot_cnt) ?></td>
                                <td style="text-align:right;" class="fw-bold text-success">₱<?= number_format($cat_tot_amt, 2) ?></td>
                            </tr>
                        </tfoot>
                        <?php endif; ?>
                    </table>
                </div>

                <!-- 6. SERVICE REVENUE BREAKDOWN -->
                <div class="rpt-section-heading"><i class="fas fa-file-invoice-dollar me-2"></i>SERVICE REVENUE BREAKDOWN</div>
                <div class="table-responsive mb-4">
                    <table class="rpt-table align-middle" style="max-width:600px;">
                        <thead>
                            <tr>
                                <th style="text-align:left;">Description</th>
                                <th style="text-align:right;">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td style="text-align:left;">Labor Fee Revenue</td>
                                <td style="text-align:right;" class="fw-bold">₱<?= number_format((float)($service_revenue['labor_fee'] ?? 0), 2) ?></td>
                            </tr>
                            <tr>
                                <td style="text-align:left;">Service Fee Revenue</td>
                                <td style="text-align:right;" class="fw-bold">₱<?= number_format((float)($service_revenue['service_fee'] ?? 0), 2) ?></td>
                            </tr>
                            <tr>
                                <td style="text-align:left;">Parts Revenue</td>
                                <td style="text-align:right;" class="fw-bold">₱<?= number_format((float)($service_revenue['parts'] ?? 0), 2) ?></td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td style="text-align:left;" class="fw-bold">Total Service Income:</td>
                                <td style="text-align:right;" class="fw-bold text-success">₱<?= number_format((float)($service_revenue['overall'] ?? 0), 2) ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>


                <!-- 7. TRANSACTION STATUS SUMMARY -->
                <div class="rpt-section-heading"><i class="fas fa-tasks me-2"></i>TRANSACTION STATUS SUMMARY</div>
                <div class="table-responsive mb-4">
                    <table class="rpt-table align-middle" style="max-width:600px;">
                        <thead>
                            <tr>
                                <th>Status</th>
                                <th class="text-center">Count</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $st_tot = 0;
                            if (empty($status_summary)):
                            ?>
                                <tr><td colspan="2" class="text-center py-3 text-muted">No transaction status records found.</td></tr>
                            <?php else: foreach ($status_summary as $st_label => $st_cnt):
                                $st_tot += (int)$st_cnt;
                            ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($st_label) ?></strong></td>
                                    <td class="text-center fw-bold"><?= number_format((int)$st_cnt) ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                        <?php if (!empty($status_summary)): ?>
                        <tfoot>
                            <tr>
                                <td class="fw-bold">TOTAL:</td>
                                <td class="text-center fw-bold"><?= number_format($st_tot) ?></td>
                            </tr>
                        </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
                <?php
            }
            break;

        // =========================================================================
        // 2. INVENTORY REPORTS
        // =========================================================================
        case 'inventory':
            if (!function_exists('inv_status_badge')) {
                function inv_status_badge(string $status): string {
                    $s = strtolower(trim($status));
                    if ($s === 'available')      return 'bg-success';
                    if ($s === 'low stock' || $s === 'low fuel')  return 'bg-warning text-dark';
                    if ($s === 'critical stock' || $s === 'critical fuel') return 'bg-orange text-white';
                    if ($s === 'out of stock')   return 'bg-danger';
                    if ($s === 'expired')        return 'bg-secondary';
                    return 'bg-secondary';
                }
            }
            $rows = $report_data['rows'] ?? [];
            ?>

            <!-- ═══════════════════════════════════════════════════════════════════════
                 1. MERCHANDISE INVENTORY REPORT
            ═══════════════════════════════════════════════════════════════════════ -->
            <?php if ($tab === 'merch_inventory'): ?>
                <div class="rpt-section-heading"><i class="fas fa-boxes me-2"></i>MERCHANDISE INVENTORY REPORT</div>
                <div class="table-responsive mb-4">
                    <table class="rpt-table align-middle">
                        <thead><tr>
                            <th>SKU</th><th>Batch ID</th><th>Product</th><th>Category</th>
                            <th>UOM</th><th class="text-center">Initial Stock</th>
                            <th class="text-center">Current Stock</th><th class="text-center">Reorder Level</th>
                            <th>Expiration Date</th><th>Status</th><th>Last Updated</th>
                        </tr></thead>
                        <tbody>
                        <?php if (empty($rows)): ?>
                            <tr><td colspan="11" class="text-center py-4 text-muted">No merchandise inventory items found.</td></tr>
                        <?php else: foreach ($rows as $r):
                            $badge = inv_status_badge($r['status'] ?? '');
                            $expDate = $r['expiration_date'] ?? '';
                            $isExpired = !empty($expDate) && strtotime($expDate) < time();
                        ?>
                            <tr>
                                <td><code><?= htmlspecialchars($r['sku'] ?: 'N/A') ?></code></td>
                                <td><code><?= htmlspecialchars($r['batch_id'] ?: 'N/A') ?></code></td>
                                <td><strong><?= htmlspecialchars($r['product'] ?? '') ?></strong></td>
                                <td><?= htmlspecialchars($r['category'] ?? 'General') ?></td>
                                <td><?= htmlspecialchars($r['uom'] ?? 'pcs') ?></td>
                                <td class="text-center"><?= number_format((float)($r['initial_stock'] ?? 0)) ?></td>
                                <td class="text-center fw-bold"><?= number_format((float)($r['current_stock'] ?? 0)) ?></td>
                                <td class="text-center"><?= number_format((float)($r['reorder_level'] ?? 0)) ?></td>
                                <td class="<?= $isExpired ? 'text-danger fw-bold' : '' ?>"><?= !empty($expDate) ? date('m/d/Y', strtotime($expDate)) : 'N/A' ?></td>
                                <td><span class="badge <?= $badge ?>"><?= htmlspecialchars($r['status'] ?? 'N/A') ?></span></td>
                                <td class="text-muted" style="font-size:11px"><?= !empty($r['last_updated']) ? date('m/d/Y H:i', strtotime($r['last_updated'])) : 'N/A' ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>

            <!-- ═══════════════════════════════════════════════════════════════════════
                 2. FUEL INVENTORY REPORT
            ═══════════════════════════════════════════════════════════════════════ -->
            <?php elseif ($tab === 'fuel_inventory'): ?>
                <div class="rpt-section-heading"><i class="fas fa-gas-pump me-2"></i>FUEL INVENTORY REPORT</div>
                <div class="table-responsive mb-4">
                    <table class="rpt-table align-middle">
                        <thead><tr>
                            <th>UGT</th><th>Fuel Type</th>
                            <th class="text-end">Current Volume (L)</th>
                            <th class="text-end">Tank Capacity (L)</th>
                            <th class="text-end">Reorder Level (L)</th>
                            <th class="text-end">Critical Level (L)</th>
                            <th class="text-center">Available %</th>
                            <th>Status</th><th>Last Updated</th>
                        </tr></thead>
                        <tbody>
                        <?php if (empty($rows)): ?>
                            <tr><td colspan="9" class="text-center py-4 text-muted">No fuel inventory records found.</td></tr>
                        <?php else: foreach ($rows as $r):
                            $badge = inv_status_badge($r['status'] ?? '');
                            $pct   = (float)($r['available_pct'] ?? 0);
                            $barColor = $pct <= 20 ? '#ef4444' : ($pct <= 40 ? '#f59e0b' : '#22c55e');
                        ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($r['ugt'] ?? 'N/A') ?></strong></td>
                                <td><?= htmlspecialchars($r['fuel_type'] ?? '') ?></td>
                                <td class="text-end fw-bold"><?= number_format((float)($r['current_volume'] ?? 0), 2) ?></td>
                                <td class="text-end"><?= number_format((float)($r['tank_capacity'] ?? 0), 2) ?></td>
                                <td class="text-end text-warning"><?= number_format((float)($r['reorder_level'] ?? 0), 2) ?></td>
                                <td class="text-end text-danger"><?= number_format((float)($r['critical_level'] ?? 0), 2) ?></td>
                                <td class="text-center">
                                    <div style="background:#e5e7eb;border-radius:999px;height:8px;width:80px;display:inline-block;vertical-align:middle;margin-right:6px">
                                        <div style="background:<?= $barColor ?>;width:<?= min(100,$pct) ?>%;height:100%;border-radius:999px"></div>
                                    </div>
                                    <span class="fw-bold" style="color:<?= $barColor ?>"><?= $pct ?>%</span>
                                </td>
                                <td><span class="badge <?= $badge ?>"><?= htmlspecialchars($r['status'] ?? 'N/A') ?></span></td>
                                <td class="text-muted" style="font-size:11px"><?= !empty($r['last_updated']) ? date('m/d/Y H:i', strtotime($r['last_updated'])) : 'N/A' ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>

            <!-- ═══════════════════════════════════════════════════════════════════════
                 3. INVENTORY MOVEMENT REPORT
            ═══════════════════════════════════════════════════════════════════════ -->
            <?php elseif ($tab === 'inventory_movement'): ?>
                <div class="rpt-section-heading"><i class="fas fa-exchange-alt me-2"></i>INVENTORY MOVEMENT REPORT</div>
                <div class="table-responsive mb-4">
                    <table class="rpt-table align-middle">
                        <thead><tr>
                            <th>Date</th><th>Batch ID</th><th>Product</th>
                            <th>Transaction Type</th>
                            <th class="text-center">Qty</th>
                            <th class="text-center">Balance After</th>
                            <th>Performed By</th>
                        </tr></thead>
                        <tbody>
                        <?php if (empty($rows)): ?>
                            <tr><td colspan="7" class="text-center py-4 text-muted">No inventory movement logs found for this date range.</td></tr>
                        <?php else: foreach ($rows as $r):
                            $qty = (float)($r['qty'] ?? 0);
                            $ttColor = 'bg-secondary';
                            $tt = strtolower($r['transaction_type'] ?? '');
                            if ($tt === 'stock in')   $ttColor = 'bg-success';
                            elseif ($tt === 'sales')  $ttColor = 'bg-primary';
                            elseif ($tt === 'return') $ttColor = 'bg-info text-dark';
                            elseif ($tt === 'expired' || $tt === 'damaged') $ttColor = 'bg-danger';
                            elseif (str_contains($tt, 'adjustment')) $ttColor = 'bg-warning text-dark';
                        ?>
                            <tr>
                                <td><?= htmlspecialchars($r['date'] ?? '') ?></td>
                                <td><code><?= htmlspecialchars($r['batch_id'] ?? 'N/A') ?></code></td>
                                <td><strong><?= htmlspecialchars($r['product'] ?? '') ?></strong></td>
                                <td><span class="badge <?= $ttColor ?>"><?= htmlspecialchars($r['transaction_type'] ?? '') ?></span></td>
                                <td class="text-center fw-bold <?= $qty >= 0 ? 'text-success' : 'text-danger' ?>">
                                    <?= ($qty > 0 ? '+' : '') . number_format($qty, 2) ?>
                                </td>
                                <td class="text-center fw-bold"><?= number_format((float)($r['balance_after'] ?? 0), 2) ?></td>
                                <td><?= htmlspecialchars($r['performed_by'] ?? 'System') ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>

            <!-- ═══════════════════════════════════════════════════════════════════════
                 4. INVENTORY ADJUSTMENT REPORT
            ═══════════════════════════════════════════════════════════════════════ -->
            <?php elseif ($tab === 'inventory_adjustment'): ?>
                <div class="rpt-section-heading"><i class="fas fa-sliders-h me-2"></i>INVENTORY ADJUSTMENT REPORT</div>
                <div class="table-responsive mb-4">
                    <table class="rpt-table align-middle">
                        <thead><tr>
                            <th>Request No.</th><th>Batch ID</th><th>Product</th>
                            <th>Adjustment Type</th>
                            <th class="text-center">Qty</th>
                            <th>Requested By</th><th>Approved By</th>
                            <th>Status</th><th>Approval Date</th>
                        </tr></thead>
                        <tbody>
                        <?php if (empty($rows)): ?>
                            <tr><td colspan="9" class="text-center py-4 text-muted">No inventory adjustment records found.</td></tr>
                        <?php else: foreach ($rows as $r):
                            $st  = strtolower($r['status'] ?? '');
                            $badge = $st === 'approved' ? 'bg-success' : ($st === 'rejected' ? 'bg-danger' : 'bg-warning text-dark');
                            $qty = (float)($r['qty'] ?? 0);
                        ?>
                            <tr>
                                <td><code><?= htmlspecialchars($r['request_no'] ?? 'N/A') ?></code></td>
                                <td><code><?= htmlspecialchars($r['batch_id'] ?? 'N/A') ?></code></td>
                                <td><strong><?= htmlspecialchars($r['product'] ?? '') ?></strong></td>
                                <td><?= htmlspecialchars($r['adjustment_type'] ?? '') ?></td>
                                <td class="text-center fw-bold <?= $qty < 0 ? 'text-danger' : 'text-success' ?>">
                                    <?= ($qty > 0 ? '+' : '') . number_format($qty) ?>
                                </td>
                                <td><?= htmlspecialchars($r['requested_by'] ?? '') ?></td>
                                <td><?= htmlspecialchars($r['approved_by'] ?: '-') ?></td>
                                <td><span class="badge <?= $badge ?>"><?= htmlspecialchars(ucfirst($r['status'] ?? '')) ?></span></td>
                                <td class="text-muted"><?= !empty($r['approval_date']) ? date('m/d/Y', strtotime($r['approval_date'])) : '-' ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>

            <!-- ═══════════════════════════════════════════════════════════════════════
                 5. EXPIRED & DAMAGED REPORT
            ═══════════════════════════════════════════════════════════════════════ -->
            <?php else: // expired_damaged ?>
                <div class="rpt-section-heading"><i class="fas fa-exclamation-triangle me-2 text-danger"></i>EXPIRED &amp; DAMAGED REPORT</div>
                <div class="table-responsive mb-4">
                    <table class="rpt-table align-middle">
                        <thead><tr>
                            <th>Batch ID</th><th>Product</th>
                            <th>Expiration Date</th>
                            <th class="text-center">Expired Qty</th>
                            <th class="text-center">Damaged Qty</th>
                            <th class="text-center">Total Deduction</th>
                            <th>Approved By</th><th>Approval Date</th>
                        </tr></thead>
                        <tbody>
                        <?php if (empty($rows)): ?>
                            <tr><td colspan="8" class="text-center py-4 text-muted">No expired or damaged items reported for this date range.</td></tr>
                        <?php else: foreach ($rows as $r):
                            $expDate = $r['expiration_date'] ?? '';
                        ?>
                            <tr>
                                <td><code><?= htmlspecialchars($r['batch_id'] ?? 'N/A') ?></code></td>
                                <td><strong><?= htmlspecialchars($r['product'] ?? '') ?></strong></td>
                                <td><?= !empty($expDate) ? date('m/d/Y', strtotime($expDate)) : 'N/A' ?></td>
                                <td class="text-center text-muted fw-bold"><?= number_format((float)($r['expired_qty'] ?? 0)) ?></td>
                                <td class="text-center text-danger fw-bold"><?= number_format((float)($r['damaged_qty'] ?? 0)) ?></td>
                                <td class="text-center fw-bold" style="color:#f97316"><?= number_format((float)($r['total_deduction'] ?? 0)) ?></td>
                                <td><?= htmlspecialchars($r['approved_by'] ?: '-') ?></td>
                                <td class="text-muted"><?= !empty($r['approval_date']) ? date('m/d/Y', strtotime($r['approval_date'])) : '-' ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            <?php
            break;

        // =========================================================================
        // 3. OPERATIONS REPORTS
        // =========================================================================
        case 'operations':
            $rows    = $report_data['rows'] ?? [];
            $summary = $report_data['summary'] ?? [];

            // ─── 1. JOB ORDER REPORT ─────────────────────────────────────────
            if ($tab === 'job_order') {
                ?>
                <div class="rpt-section-heading"><i class="fas fa-clipboard-list me-2"></i>JOB ORDER REPORT</div>
                <div class="table-responsive mb-4">
                    <table class="rpt-table align-middle">
                        <thead>
                            <tr>
                                <th>JO No.</th>
                                <th>Date</th>
                                <th>Customer</th>
                                <th>Vehicle</th>
                                <th>Mechanic</th>
                                <th>Service Category</th>
                                <th class="text-end">Labor Fee</th>
                                <th class="text-end">Service Fee</th>
                                <th class="text-end">Parts Cost</th>
                                <th class="text-end">Total Amount</th>
                                <th>Payment Method</th>
                                <th>Status</th>
                                <th>Released Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($rows)): ?>
                                <tr><td colspan="13" class="text-center py-4 text-muted">No Job Orders found for this date range.</td></tr>
                            <?php else: foreach ($rows as $r):
                                $st = strtolower($r['status'] ?? '');
                                $badge = 'bg-secondary';
                                if (in_array($st, ['completed', 'verified', 'finalized'])) $badge = 'bg-success';
                                elseif (in_array($st, ['in progress', 'awaiting parts'])) $badge = 'bg-warning text-dark';
                                elseif ($st === 'released') $badge = 'bg-info text-dark';
                                elseif (in_array($st, ['cancelled', 'rejected'])) $badge = 'bg-danger';
                            ?>
                                <tr>
                                    <td><code><?= htmlspecialchars($r['jo_no'] ?? 'N/A') ?></code></td>
                                    <td><?= !empty($r['date']) ? date('m/d/Y H:i', strtotime($r['date'])) : '-' ?></td>
                                    <td><strong><?= htmlspecialchars($r['customer'] ?? '') ?></strong></td>
                                    <td><?= htmlspecialchars($r['vehicle'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($r['mechanic'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($r['service_category'] ?? '') ?></td>
                                    <td class="text-end">₱<?= number_format((float)($r['labor_fee'] ?? 0), 2) ?></td>
                                    <td class="text-end">₱<?= number_format((float)($r['service_fee'] ?? 0), 2) ?></td>
                                    <td class="text-end">₱<?= number_format((float)($r['parts_cost'] ?? 0), 2) ?></td>
                                    <td class="text-end fw-bold text-primary">₱<?= number_format((float)($r['total_amount'] ?? 0), 2) ?></td>
                                    <td><span class="badge bg-secondary"><?= htmlspecialchars($r['payment_method'] ?? 'Unpaid') ?></span></td>
                                    <td><span class="badge <?= $badge ?>"><?= htmlspecialchars(ucfirst($r['status'] ?? '')) ?></span></td>
                                    <td class="text-muted"><?= !empty($r['released_date']) ? date('m/d/Y H:i', strtotime($r['released_date'])) : 'N/A' ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="6" class="fw-bold text-end">SUMMARY TOTALS:</td>
                                <td class="text-end fw-bold">₱<?= number_format((float)($summary['total_labor'] ?? 0), 2) ?></td>
                                <td class="text-end fw-bold">₱<?= number_format((float)($summary['total_service_fee'] ?? 0), 2) ?></td>
                                <td class="text-end fw-bold">₱<?= number_format((float)($summary['total_parts'] ?? 0), 2) ?></td>
                                <td class="text-end fw-bold text-success">₱<?= number_format((float)($summary['overall_revenue'] ?? 0), 2) ?></td>
                                <td colspan="3"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <!-- Job Order Summary (10 Individual Plain Card Boxes) -->
                <div class="rpt-section-heading mt-4 mb-3"><i class="fas fa-calculator me-2"></i>Job Order Summary</div>
                <div class="row row-cols-2 row-cols-sm-3 row-cols-md-5 g-3 mb-4">
                    <div class="col">
                        <div class="rpt-summary-card p-3 border rounded bg-white text-center shadow-sm" style="border: 1px solid #cbd5e1 !important;">
                            <div class="label text-muted small text-uppercase fw-bold mb-1">Total Job Orders</div>
                            <div class="value fs-5 fw-bold text-dark"><?= number_format((int)($summary['total_jos'] ?? 0)) ?></div>
                        </div>
                    </div>
                    <div class="col">
                        <div class="rpt-summary-card p-3 border rounded bg-white text-center shadow-sm" style="border: 1px solid #cbd5e1 !important;">
                            <div class="label text-muted small text-uppercase fw-bold mb-1">Completed Job Orders</div>
                            <div class="value fs-5 fw-bold text-success"><?= number_format((int)($summary['completed_jos'] ?? 0)) ?></div>
                        </div>
                    </div>
                    <div class="col">
                        <div class="rpt-summary-card p-3 border rounded bg-white text-center shadow-sm" style="border: 1px solid #cbd5e1 !important;">
                            <div class="label text-muted small text-uppercase fw-bold mb-1">Pending Job Orders</div>
                            <div class="value fs-5 fw-bold text-warning"><?= number_format((int)($summary['pending_jos'] ?? 0)) ?></div>
                        </div>
                    </div>
                    <div class="col">
                        <div class="rpt-summary-card p-3 border rounded bg-white text-center shadow-sm" style="border: 1px solid #cbd5e1 !important;">
                            <div class="label text-muted small text-uppercase fw-bold mb-1">In Progress Job Orders</div>
                            <div class="value fs-5 fw-bold text-primary"><?= number_format((int)($summary['in_progress_jos'] ?? 0)) ?></div>
                        </div>
                    </div>
                    <div class="col">
                        <div class="rpt-summary-card p-3 border rounded bg-white text-center shadow-sm" style="border: 1px solid #cbd5e1 !important;">
                            <div class="label text-muted small text-uppercase fw-bold mb-1">Released Vehicles</div>
                            <div class="value fs-5 fw-bold text-info"><?= number_format((int)($summary['released_jos'] ?? 0)) ?></div>
                        </div>
                    </div>

                    <div class="col">
                        <div class="rpt-summary-card p-3 border rounded bg-white text-center shadow-sm" style="border: 1px solid #cbd5e1 !important;">
                            <div class="label text-muted small text-uppercase fw-bold mb-1">Cancelled Job Orders</div>
                            <div class="value fs-5 fw-bold text-danger"><?= number_format((int)($summary['cancelled_jos'] ?? 0)) ?></div>
                        </div>
                    </div>
                    <div class="col">
                        <div class="rpt-summary-card p-3 border rounded bg-white text-center shadow-sm" style="border: 1px solid #cbd5e1 !important;">
                            <div class="label text-muted small text-uppercase fw-bold mb-1">Total Labor Fee (₱)</div>
                            <div class="value fs-5 fw-bold text-dark">₱<?= number_format((float)($summary['total_labor'] ?? 0), 2) ?></div>
                        </div>
                    </div>
                    <div class="col">
                        <div class="rpt-summary-card p-3 border rounded bg-white text-center shadow-sm" style="border: 1px solid #cbd5e1 !important;">
                            <div class="label text-muted small text-uppercase fw-bold mb-1">Total Service Fee (₱)</div>
                            <div class="value fs-5 fw-bold text-dark">₱<?= number_format((float)($summary['total_service_fee'] ?? 0), 2) ?></div>
                        </div>
                    </div>
                    <div class="col">
                        <div class="rpt-summary-card p-3 border rounded bg-white text-center shadow-sm" style="border: 1px solid #cbd5e1 !important;">
                            <div class="label text-muted small text-uppercase fw-bold mb-1">Total Parts Revenue (₱)</div>
                            <div class="value fs-5 fw-bold text-dark">₱<?= number_format((float)($summary['total_parts'] ?? 0), 2) ?></div>
                        </div>
                    </div>
                    <div class="col">
                        <div class="rpt-summary-card p-3 border rounded bg-white text-center shadow-sm" style="border: 1px solid #cbd5e1 !important;">
                            <div class="label text-muted small text-uppercase fw-bold mb-1">Overall Service Revenue (₱)</div>
                            <div class="value fs-5 fw-bold text-success">₱<?= number_format((float)($summary['overall_revenue'] ?? 0), 2) ?></div>
                        </div>
                    </div>
                </div>
                <?php

            // ─── 2. MECHANIC PERFORMANCE REPORT ──────────────────────────────
            } else {
                ?>
                <div class="rpt-section-heading"><i class="fas fa-user-cog me-2"></i>MECHANIC PERFORMANCE REPORT</div>
                <div class="table-responsive mb-4">
                    <table class="rpt-table align-middle">
                        <thead>
                            <tr>
                                <th>Mechanic</th>
                                <th class="text-center">Assigned Jobs</th>
                                <th class="text-center">Completed Jobs</th>
                                <th class="text-center">Pending Jobs</th>
                                <th class="text-center">Cancelled Jobs</th>
                                <th class="text-center">Average Completion Time</th>
                                <th class="text-end">Labor Revenue</th>
                                <th class="text-end">Service Revenue</th>
                                <th class="text-end">Total Revenue</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($rows)): ?>
                                <tr><td colspan="9" class="text-center py-4 text-muted">No mechanic performance data available.</td></tr>
                            <?php else: foreach ($rows as $r):
                                $avg_mins = (float)($r['avg_completion_mins'] ?? 0);
                            ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($r['mechanic'] ?? '') ?></strong></td>
                                    <td class="text-center fw-bold"><?= number_format((int)($r['assigned_jobs'] ?? 0)) ?></td>
                                    <td class="text-center text-success fw-bold"><?= number_format((int)($r['completed_jobs'] ?? 0)) ?></td>
                                    <td class="text-center text-warning fw-bold"><?= number_format((int)($r['pending_jobs'] ?? 0)) ?></td>
                                    <td class="text-center text-danger fw-bold"><?= number_format((int)($r['cancelled_jobs'] ?? 0)) ?></td>
                                    <td class="text-center"><?= $avg_mins > 0 ? number_format($avg_mins) . ' mins' : 'N/A' ?></td>
                                    <td class="text-end">₱<?= number_format((float)($r['labor_revenue'] ?? 0), 2) ?></td>
                                    <td class="text-end">₱<?= number_format((float)($r['service_revenue'] ?? 0), 2) ?></td>
                                    <td class="text-end fw-bold text-success">₱<?= number_format((float)($r['total_revenue'] ?? 0), 2) ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td class="fw-bold">SUMMARY TOTALS:</td>
                                <td class="text-center fw-bold"><?= number_format((int)($summary['total_assigned'] ?? 0)) ?></td>
                                <td class="text-center fw-bold text-success"><?= number_format((int)($summary['total_completed'] ?? 0)) ?></td>
                                <td class="text-center fw-bold text-warning"><?= number_format((int)($summary['total_pending'] ?? 0)) ?></td>
                                <td class="text-center fw-bold text-danger"><?= number_format((int)array_sum(array_column($rows, 'cancelled_jobs'))) ?></td>
                                <td class="text-center fw-bold"><?= !empty($summary['overall_avg_mins']) ? number_format((float)$summary['overall_avg_mins']) . ' mins' : 'N/A' ?></td>
                                <td class="text-end fw-bold">₱<?= number_format((float)array_sum(array_column($rows, 'labor_revenue')), 2) ?></td>
                                <td class="text-end fw-bold">₱<?= number_format((float)array_sum(array_column($rows, 'service_revenue')), 2) ?></td>
                                <td class="text-end fw-bold text-success">₱<?= number_format((float)($summary['overall_revenue'] ?? 0), 2) ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <!-- Mechanic Performance Summary (6 Individual Plain Card Boxes) -->
                <div class="rpt-section-heading mt-4 mb-3"><i class="fas fa-calculator me-2"></i>Mechanic Performance Summary</div>
                <div class="row row-cols-1 row-cols-sm-2 row-cols-md-3 g-3 mb-4">
                    <div class="col">
                        <div class="rpt-summary-card p-3 border rounded bg-white text-center shadow-sm" style="border: 1px solid #cbd5e1 !important;">
                            <div class="label text-muted small text-uppercase fw-bold mb-1">Total Mechanics</div>
                            <div class="value fs-5 fw-bold text-dark"><?= number_format((int)($summary['total_mechanics'] ?? 0)) ?></div>
                        </div>
                    </div>
                    <div class="col">
                        <div class="rpt-summary-card p-3 border rounded bg-white text-center shadow-sm" style="border: 1px solid #cbd5e1 !important;">
                            <div class="label text-muted small text-uppercase fw-bold mb-1">Total Assigned Jobs</div>
                            <div class="value fs-5 fw-bold text-primary"><?= number_format((int)($summary['total_assigned'] ?? 0)) ?></div>
                        </div>
                    </div>
                    <div class="col">
                        <div class="rpt-summary-card p-3 border rounded bg-white text-center shadow-sm" style="border: 1px solid #cbd5e1 !important;">
                            <div class="label text-muted small text-uppercase fw-bold mb-1">Total Completed Jobs</div>
                            <div class="value fs-5 fw-bold text-success"><?= number_format((int)($summary['total_completed'] ?? 0)) ?></div>
                        </div>
                    </div>
                    <div class="col">
                        <div class="rpt-summary-card p-3 border rounded bg-white text-center shadow-sm" style="border: 1px solid #cbd5e1 !important;">
                            <div class="label text-muted small text-uppercase fw-bold mb-1">Total Pending Jobs</div>
                            <div class="value fs-5 fw-bold text-warning"><?= number_format((int)($summary['total_pending'] ?? 0)) ?></div>
                        </div>
                    </div>
                    <div class="col">
                        <div class="rpt-summary-card p-3 border rounded bg-white text-center shadow-sm" style="border: 1px solid #cbd5e1 !important;">
                            <div class="label text-muted small text-uppercase fw-bold mb-1">Average Completion Time</div>
                            <div class="value fs-5 fw-bold text-info"><?= !empty($summary['overall_avg_mins']) ? number_format((float)$summary['overall_avg_mins']) . ' mins' : 'N/A' ?></div>
                        </div>
                    </div>
                    <div class="col">
                        <div class="rpt-summary-card p-3 border rounded bg-white text-center shadow-sm" style="border: 1px solid #cbd5e1 !important;">
                            <div class="label text-muted small text-uppercase fw-bold mb-1">Overall Revenue Generated (₱)</div>
                            <div class="value fs-5 fw-bold text-success">₱<?= number_format((float)($summary['overall_revenue'] ?? 0), 2) ?></div>
                        </div>
                    </div>
                </div>
                <?php
            }
            break;

        // =========================================================================
        // 4. PROCUREMENT REPORTS
        // =========================================================================
        case 'procurement':

            // ─── 1. PURCHASE ORDER REPORT ────────────────────────────────────
            if ($tab === 'purchase_order') {
                ?>
                <div class="rpt-section-heading"><i class="fas fa-file-alt me-2"></i>PURCHASE ORDER REPORT</div>
                <div class="table-responsive mb-4">
                    <table class="rpt-table align-middle">
                        <thead>
                            <tr>
                                <th>PO No.</th>
                                <th>PO Date</th>
                                <th>Requested By</th>
                                <th>Approved By</th>
                                <th>Supplier</th>
                                <th class="text-center">Items</th>
                                <th class="text-center">Total Qty</th>
                                <th class="text-end">Estimated Cost</th>
                                <th>Expected Delivery</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($rows)): ?>
                                <tr><td colspan="10" class="text-center py-4 text-muted">No purchase orders found for this date range.</td></tr>
                            <?php else: foreach ($rows as $r):
                                $st = strtolower($r['status'] ?? '');
                                $badge = 'bg-secondary';
                                if (str_contains($st, 'complet')) $badge = 'bg-success';
                                elseif (str_contains($st, 'approv')) $badge = 'bg-primary';
                                elseif (str_contains($st, 'partial')) $badge = 'bg-info text-dark';
                                elseif (str_contains($st, 'pending')) $badge = 'bg-warning text-dark';
                                elseif (str_contains($st, 'cancel')) $badge = 'bg-danger';
                            ?>
                                <tr>
                                    <td><code><?= htmlspecialchars($r['po_number'] ?? 'N/A') ?></code></td>
                                    <td><?= !empty($r['po_date']) ? date('m/d/Y H:i', strtotime($r['po_date'])) : '-' ?></td>
                                    <td><?= htmlspecialchars($r['requested_by'] ?? 'N/A') ?></td>
                                    <td><?= htmlspecialchars($r['approved_by'] ?? 'N/A') ?></td>
                                    <td><strong><?= htmlspecialchars($r['supplier'] ?? 'Petron Corporation') ?></strong></td>
                                    <td class="text-center"><?= number_format((int)($r['item_count'] ?? 0)) ?></td>
                                    <td class="text-center fw-bold"><?= number_format((float)($r['total_qty'] ?? 0)) ?></td>
                                    <td class="text-end fw-bold">₱<?= number_format((float)($r['estimated_cost'] ?? 0), 2) ?></td>
                                    <td><?= !empty($r['expected_delivery_date']) ? date('m/d/Y', strtotime($r['expected_delivery_date'])) : 'N/A' ?></td>
                                    <td><span class="badge <?= $badge ?>"><?= htmlspecialchars(ucfirst($r['status'] ?? '')) ?></span></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="6" class="fw-bold text-end">SUMMARY TOTALS:</td>
                                <td class="text-center fw-bold"><?= number_format(array_sum(array_column($rows, 'total_qty'))) ?></td>
                                <td class="text-end fw-bold text-success">₱<?= number_format(array_sum(array_column($rows, 'estimated_cost')), 2) ?></td>
                                <td colspan="2"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <?php

            // ─── 2. FUEL RECONCILIATION REPORT ───────────────────────────────
            } elseif ($tab === 'fuel_reconciliation' || $tab === 'delivery_validation') {
                ?>
                <div class="rpt-section-heading"><i class="fas fa-gas-pump me-2"></i>FUEL RECONCILIATION REPORT</div>
                <div class="table-responsive mb-4">
                    <table class="rpt-table align-middle">
                        <thead>
                            <tr>
                                <th>UGT No.</th>
                                <th>Fuel Type</th>
                                <th class="text-end">Beginning Reading</th>
                                <th class="text-end">Ending Reading</th>
                                <th class="text-end">Calibration</th>
                                <th class="text-end">Net Volume</th>
                                <th class="text-end">Selling Price</th>
                                <th class="text-end">Fuel Sales</th>
                                <th class="text-center">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($rows)): ?>
                                <tr><td colspan="9" class="text-center py-4 text-muted">No fuel reconciliation records found for this period.</td></tr>
                            <?php else:
                                $tot_net_vol = 0;
                                $tot_sales = 0;
                                foreach ($rows as $r):
                                    $net_vol = (float)($r['net_volume'] ?? 0);
                                    $sales   = (float)($r['fuel_sales'] ?? 0);
                                    $tot_net_vol += $net_vol;
                                    $tot_sales   += $sales;
                                    $st = $r['status'] ?? 'Pending';
                                    $badge = ($st === 'Submitted' || $st === 'Approved' || $st === 'Verified') ? 'bg-success' : 'bg-warning text-dark';
                            ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($r['ugt_no'] ?? 'UGT #1') ?></strong></td>
                                    <td><?= htmlspecialchars($r['fuel_type'] ?? 'Fuel') ?></td>
                                    <td class="text-end"><?= number_format((float)($r['beginning_reading'] ?? 0), 2) ?></td>
                                    <td class="text-end"><?= number_format((float)($r['ending_reading'] ?? 0), 2) ?></td>
                                    <td class="text-end"><?= number_format((float)($r['calibration'] ?? 0), 2) ?></td>
                                    <td class="text-end fw-bold text-success"><?= number_format($net_vol, 2) ?> L</td>
                                    <td class="text-end">₱<?= number_format((float)($r['selling_price'] ?? 0), 2) ?></td>
                                    <td class="text-end fw-bold text-primary">₱<?= number_format($sales, 2) ?></td>
                                    <td class="text-center"><span class="badge <?= $badge ?>"><?= htmlspecialchars($st) ?></span></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                        <?php if (!empty($rows)): ?>
                        <tfoot>
                            <tr>
                                <td colspan="5" class="fw-bold text-end">TOTAL:</td>
                                <td class="fw-bold text-end text-success"><?= number_format($tot_net_vol, 2) ?> L</td>
                                <td></td>
                                <td class="fw-bold text-end text-primary">₱<?= number_format($tot_sales, 2) ?></td>
                                <td></td>
                            </tr>
                        </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
                <?php

            // ─── 3. PO VS RECEIVED REPORT ─────────────────────────────────────
            } elseif ($tab === 'po_vs_received') {
                ?>
                <div class="rpt-section-heading"><i class="fas fa-balance-scale me-2"></i>PO VS RECEIVED REPORT</div>
                <div class="table-responsive mb-4">
                    <table class="rpt-table align-middle">
                        <thead>
                            <tr>
                                <th>PO No.</th>
                                <th>Product</th>
                                <th class="text-center">Ordered Qty</th>
                                <th class="text-center">Received Qty</th>
                                <th class="text-center">Variance</th>
                                <th>Expected Delivery</th>
                                <th>Actual Delivery</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($rows)): ?>
                                <tr><td colspan="8" class="text-center py-4 text-muted">No PO vs Received data found for this date range.</td></tr>
                            <?php else: foreach ($rows as $r):
                                $var   = (float)($r['variance'] ?? 0);
                                $st    = $r['status'] ?? 'Pending Delivery';
                                $badge = 'bg-secondary';
                                if ($st === 'Complete') $badge = 'bg-success';
                                elseif ($st === 'Partial') $badge = 'bg-warning text-dark';
                                elseif ($st === 'Pending Delivery') $badge = 'bg-info text-dark';
                                elseif ($st === 'Over Delivered') $badge = 'bg-primary';
                                elseif ($st === 'Under Delivered') $badge = 'bg-danger';
                                $var_class = $var == 0 ? 'text-success' : ($var > 0 ? 'text-primary' : 'text-danger');
                            ?>
                                <tr>
                                    <td><code><?= htmlspecialchars($r['po_number'] ?? 'N/A') ?></code></td>
                                    <td><strong><?= htmlspecialchars($r['product'] ?? '') ?></strong></td>
                                    <td class="text-center fw-bold"><?= number_format((float)($r['ordered_qty'] ?? 0)) ?></td>
                                    <td class="text-center fw-bold"><?= number_format((float)($r['received_qty'] ?? 0)) ?></td>
                                    <td class="text-center fw-bold <?= $var_class ?>"><?= ($var > 0 ? '+' : '') . number_format($var, 2) ?></td>
                                    <td><?= !empty($r['expected_delivery_date']) ? date('m/d/Y', strtotime($r['expected_delivery_date'])) : 'N/A' ?></td>
                                    <td><?= !empty($r['actual_delivery']) ? date('m/d/Y', strtotime($r['actual_delivery'])) : '-' ?></td>
                                    <td><span class="badge <?= $badge ?>"><?= htmlspecialchars($st) ?></span></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                        <?php if (!empty($rows)): ?>
                        <tfoot>
                            <tr>
                                <td colspan="2" class="fw-bold text-end">SUMMARY TOTALS:</td>
                                <td class="text-center fw-bold"><?= number_format(array_sum(array_column($rows, 'ordered_qty'))) ?></td>
                                <td class="text-center fw-bold"><?= number_format(array_sum(array_column($rows, 'received_qty'))) ?></td>
                                <td class="text-center fw-bold <?= array_sum(array_column($rows, 'variance')) == 0 ? 'text-success' : (array_sum(array_column($rows, 'variance')) > 0 ? 'text-primary' : 'text-danger') ?>">
                                    <?= (array_sum(array_column($rows, 'variance')) > 0 ? '+' : '') . number_format(array_sum(array_column($rows, 'variance')), 2) ?>
                                </td>
                                <td colspan="3"></td>
                            </tr>
                        </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
                <?php

            // ─── 4. STOCK-IN APPROVAL REPORT ─────────────────────────────────
            } else {
                ?>
                <div class="rpt-section-heading"><i class="fas fa-check-circle me-2"></i>STOCK-IN APPROVAL REPORT</div>
                <div class="table-responsive mb-4">
                    <table class="rpt-table align-middle">
                        <thead>
                            <tr>
                                <th>Batch ID</th>
                                <th>Product</th>
                                <th class="text-center">Qty Received</th>
                                <th class="text-end">Unit Cost</th>
                                <th class="text-end">Selling Price</th>
                                <th>Approved By</th>
                                <th>Approval Date</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($rows)): ?>
                                <tr><td colspan="8" class="text-center py-4 text-muted">No stock-in approval records found for this date range.</td></tr>
                            <?php else: foreach ($rows as $r):
                                $st    = $r['status'] ?? 'Pending Approval';
                                $badge = 'bg-secondary';
                                if ($st === 'Approved') $badge = 'bg-success';
                                elseif ($st === 'Rejected') $badge = 'bg-danger';
                                elseif ($st === 'Pending Approval') $badge = 'bg-warning text-dark';
                            ?>
                                <tr>
                                    <td><code><?= htmlspecialchars($r['batch_id'] ?? 'N/A') ?></code></td>
                                    <td><strong><?= htmlspecialchars($r['product'] ?? '') ?></strong></td>
                                    <td class="text-center fw-bold"><?= number_format((float)($r['qty_received'] ?? 0)) ?></td>
                                    <td class="text-end">₱<?= number_format((float)($r['unit_cost'] ?? 0), 2) ?></td>
                                    <td class="text-end fw-bold text-success">₱<?= number_format((float)($r['selling_price'] ?? 0), 2) ?></td>
                                    <td><?= htmlspecialchars($r['approved_by'] ?? 'N/A') ?></td>
                                    <td><?= !empty($r['approval_date']) ? date('m/d/Y H:i', strtotime($r['approval_date'])) : '-' ?></td>
                                    <td><span class="badge <?= $badge ?>"><?= htmlspecialchars($st) ?></span></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                        <?php if (!empty($rows)): ?>
                        <tfoot>
                            <tr>
                                <td colspan="2" class="fw-bold text-end">SUMMARY TOTALS:</td>
                                <td class="text-center fw-bold"><?= number_format(array_sum(array_column($rows, 'qty_received'))) ?></td>
                                <td class="text-end fw-bold">₱<?= number_format(array_sum(array_column($rows, 'unit_cost')), 2) ?></td>
                                <td class="text-end fw-bold text-success">₱<?= number_format(array_sum(array_column($rows, 'selling_price')), 2) ?></td>
                                <td colspan="3"></td>
                            </tr>
                        </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
                <?php
            }
            break;

        // 5. FINANCIAL REPORTS
        // =========================================================================
        case 'financial':

            // ─── 1. REVENUE SUMMARY REPORT ───────────────────────────────────
            if ($tab === 'revenue_summary') {
                $tot_fuel  = array_sum(array_column($rows, 'fuel_revenue'));
                $tot_merch = array_sum(array_column($rows, 'merchandise_revenue'));
                $tot_serv  = array_sum(array_column($rows, 'service_revenue'));
                $tot_gross = array_sum(array_column($rows, 'gross_revenue'));
                $tot_disc  = array_sum(array_column($rows, 'total_discounts'));
                $tot_net   = array_sum(array_column($rows, 'net_revenue'));
                ?>
                <div class="rpt-section-heading"><i class="fas fa-chart-line me-2"></i>REVENUE SUMMARY REPORT</div>
                <div class="table-responsive mb-4">
                    <table class="rpt-table align-middle">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th class="text-end">Fuel Revenue</th>
                                <th class="text-end">Merchandise Revenue</th>
                                <th class="text-end">Service Revenue</th>
                                <th class="text-end">Gross Revenue</th>
                                <th class="text-end">Total Discounts</th>
                                <th class="text-end">Net Revenue</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($rows)): ?>
                                <tr><td colspan="7" class="text-center py-4 text-muted">No revenue records found for this date range.</td></tr>
                            <?php else: foreach ($rows as $r): ?>
                                <tr>
                                    <td><?= !empty($r['date']) ? date('m/d/Y', strtotime($r['date'])) : '-' ?></td>
                                    <td class="text-end">₱<?= number_format((float)($r['fuel_revenue'] ?? 0), 2) ?></td>
                                    <td class="text-end">₱<?= number_format((float)($r['merchandise_revenue'] ?? 0), 2) ?></td>
                                    <td class="text-end">₱<?= number_format((float)($r['service_revenue'] ?? 0), 2) ?></td>
                                    <td class="text-end fw-bold">₱<?= number_format((float)($r['gross_revenue'] ?? 0), 2) ?></td>
                                    <td class="text-end text-muted">₱<?= number_format((float)($r['total_discounts'] ?? 0), 2) ?></td>
                                    <td class="text-end fw-bold text-success">₱<?= number_format((float)($r['net_revenue'] ?? 0), 2) ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                        <?php if (!empty($rows)): ?>
                        <tfoot>
                            <tr>
                                <td class="fw-bold">TOTALS:</td>
                                <td class="text-end fw-bold">₱<?= number_format($tot_fuel, 2) ?></td>
                                <td class="text-end fw-bold">₱<?= number_format($tot_merch, 2) ?></td>
                                <td class="text-end fw-bold">₱<?= number_format($tot_serv, 2) ?></td>
                                <td class="text-end fw-bold">₱<?= number_format($tot_gross, 2) ?></td>
                                <td class="text-end fw-bold text-muted">₱<?= number_format($tot_disc, 2) ?></td>
                                <td class="text-end fw-bold text-success">₱<?= number_format($tot_net, 2) ?></td>
                            </tr>
                        </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
                <?php

            // ─── 2. ACCOUNTS RECEIVABLE REPORT ───────────────────────────────
            } elseif ($tab === 'receivables') {
                $tot_ar      = array_sum(array_column($rows, 'outstanding_balance'));
                $curr_ar     = 0;
                $overdue_ar  = 0;
                foreach ($rows as $r) {
                    $st = strtolower($r['status'] ?? '');
                    if ($st === 'overdue') {
                        $overdue_ar += (float)($r['outstanding_balance'] ?? 0);
                    } elseif ($st === 'current' || $st === 'due today') {
                        $curr_ar += (float)($r['outstanding_balance'] ?? 0);
                    }
                }
                ?>
                <div class="rpt-section-heading"><i class="fas fa-file-invoice-dollar me-2"></i>ACCOUNTS RECEIVABLE REPORT</div>
                <div class="table-responsive mb-4">
                    <table class="rpt-table align-middle">
                        <thead>
                            <tr>
                                <th>Customer</th>
                                <th>Account Type</th>
                                <th>Invoice No.</th>
                                <th>Transaction Date</th>
                                <th>Due Date</th>
                                <th class="text-end">Outstanding Balance</th>
                                <th class="text-center">Days Overdue</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($rows)): ?>
                                <tr><td colspan="8" class="text-center py-4 text-muted">No accounts receivable or credit records found.</td></tr>
                            <?php else: foreach ($rows as $r):
                                $st = strtolower($r['status'] ?? '');
                                $badge = 'bg-secondary';
                                if ($st === 'paid') $badge = 'bg-success';
                                elseif ($st === 'current') $badge = 'bg-info text-dark';
                                elseif ($st === 'due today') $badge = 'bg-warning text-dark';
                                elseif ($st === 'overdue') $badge = 'bg-danger';
                            ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($r['customer'] ?? 'N/A') ?></strong></td>
                                    <td><?= htmlspecialchars($r['account_type'] ?? 'Credit Account (AR)') ?></td>
                                    <td><code><?= htmlspecialchars($r['invoice_no'] ?? 'N/A') ?></code></td>
                                    <td><?= !empty($r['transaction_date']) ? date('m/d/Y', strtotime($r['transaction_date'])) : '-' ?></td>
                                    <td><?= !empty($r['due_date']) ? date('m/d/Y', strtotime($r['due_date'])) : '-' ?></td>
                                    <td class="text-end fw-bold text-danger">₱<?= number_format((float)($r['outstanding_balance'] ?? 0), 2) ?></td>
                                    <td class="text-center <?= (int)($r['days_overdue'] ?? 0) > 0 ? 'text-danger fw-bold' : '' ?>"><?= number_format((int)($r['days_overdue'] ?? 0)) ?></td>
                                    <td><span class="badge <?= $badge ?>"><?= htmlspecialchars($r['status'] ?? 'Current') ?></span></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                        <?php if (!empty($rows)): ?>
                        <tfoot>
                            <tr>
                                <td colspan="5" class="fw-bold text-end">TOTAL RECEIVABLES: ₱<?= number_format($tot_ar, 2) ?></td>
                                <td class="fw-bold text-info">CURRENT: ₱<?= number_format($curr_ar, 2) ?></td>
                                <td colspan="2" class="fw-bold text-danger">OVERDUE: ₱<?= number_format($overdue_ar, 2) ?></td>
                            </tr>
                        </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
                <?php

            // ─── 3. PAYMENT COLLECTION REPORT ────────────────────────────────
            } elseif ($tab === 'payment_collections') {
                $tot_col = array_sum(array_column($rows, 'amount_paid'));
                ?>
                <div class="rpt-section-heading"><i class="fas fa-hand-holding-usd me-2"></i>PAYMENT COLLECTION REPORT</div>
                <div class="table-responsive mb-4">
                    <table class="rpt-table align-middle">
                        <thead>
                            <tr>
                                <th>OR No.</th>
                                <th>Customer</th>
                                <th>Invoice No.</th>
                                <th>Payment Method</th>
                                <th class="text-end">Amount Paid</th>
                                <th>Collected By</th>
                                <th>Payment Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($rows)): ?>
                                <tr><td colspan="7" class="text-center py-4 text-muted">No payment collection records found for this date range.</td></tr>
                            <?php else: foreach ($rows as $r): ?>
                                <tr>
                                    <td><code><?= htmlspecialchars($r['or_no'] ?? 'N/A') ?></code></td>
                                    <td><strong><?= htmlspecialchars($r['customer'] ?? 'N/A') ?></strong></td>
                                    <td><code><?= htmlspecialchars($r['invoice_no'] ?? 'N/A') ?></code></td>
                                    <td><span class="badge bg-secondary"><?= htmlspecialchars($r['payment_method'] ?? 'Cash') ?></span></td>
                                    <td class="text-end fw-bold text-success">₱<?= number_format((float)($r['amount_paid'] ?? 0), 2) ?></td>
                                    <td><?= htmlspecialchars($r['collected_by'] ?? 'N/A') ?></td>
                                    <td><?= !empty($r['payment_date']) ? date('m/d/Y H:i', strtotime($r['payment_date'])) : '-' ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                        <?php if (!empty($rows)): ?>
                        <tfoot>
                            <tr>
                                <td colspan="4" class="fw-bold text-end">TOTAL COLLECTIONS:</td>
                                <td class="text-end fw-bold text-success">₱<?= number_format($tot_col, 2) ?></td>
                                <td colspan="2"></td>
                            </tr>
                        </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
                <?php

            // ─── 4. SALES VS COLLECTION REPORT ───────────────────────────────
            } else {
                $tot_sales  = array_sum(array_column($rows, 'total_sales'));
                $tot_csales = array_sum(array_column($rows, 'total_credit_sales'));
                $tot_cols   = array_sum(array_column($rows, 'total_collections'));
                $rem_rec    = array_sum(array_column($rows, 'outstanding_balance'));
                ?>
                <div class="rpt-section-heading"><i class="fas fa-balance-scale-right me-2"></i>SALES VS COLLECTION REPORT</div>
                <div class="table-responsive mb-4">
                    <table class="rpt-table align-middle">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th class="text-end">Total Sales</th>
                                <th class="text-end">Total Credit Sales</th>
                                <th class="text-end">Total Collections</th>
                                <th class="text-end">Outstanding Balance</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($rows)): ?>
                                <tr><td colspan="5" class="text-center py-4 text-muted">No sales vs collection records found for this date range.</td></tr>
                            <?php else: foreach ($rows as $r): ?>
                                <tr>
                                    <td><?= !empty($r['date']) ? date('m/d/Y', strtotime($r['date'])) : '-' ?></td>
                                    <td class="text-end fw-bold">₱<?= number_format((float)($r['total_sales'] ?? 0), 2) ?></td>
                                    <td class="text-end text-primary">₱<?= number_format((float)($r['total_credit_sales'] ?? 0), 2) ?></td>
                                    <td class="text-end text-success fw-bold">₱<?= number_format((float)($r['total_collections'] ?? 0), 2) ?></td>
                                    <td class="text-end text-danger fw-bold">₱<?= number_format((float)($r['outstanding_balance'] ?? 0), 2) ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                        <?php if (!empty($rows)): ?>
                        <tfoot>
                            <tr>
                                <td class="fw-bold">TOTALS:</td>
                                <td class="text-end fw-bold">₱<?= number_format($tot_sales, 2) ?></td>
                                <td class="text-end fw-bold text-primary">₱<?= number_format($tot_csales, 2) ?></td>
                                <td class="text-end fw-bold text-success">₱<?= number_format($tot_cols, 2) ?></td>
                                <td class="text-end fw-bold text-danger">₱<?= number_format($rem_rec, 2) ?></td>
                            </tr>
                        </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
                <?php
            }
            break;

        // =========================================================================
        // 6. CUSTOMER REPORTS
        // =========================================================================
        case 'customer':
            ?>
            <style>
            .cust-info-block { page-break-inside: avoid; }
            .cust-section-divider { border-top: 2.5px solid #1e3a5f; margin: 32px 0 20px; page-break-before: auto; }
            .cust-profile-card { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 14px 18px; margin-bottom: 18px; }
            .cust-profile-card .info-row { display: flex; flex-wrap: wrap; gap: 0; }
            .cust-profile-card .info-item { flex: 0 0 33.333%; padding: 5px 0; font-size: 12px; }
            .cust-profile-card .info-label { color: #64748b; font-weight: 600; display: block; font-size: 11px; text-transform: uppercase; letter-spacing: .3px; }
            .cust-profile-card .info-value { color: #1e293b; font-weight: 500; font-size: 13px; }
            .cust-sub-heading { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: #1e3a5f; border-bottom: 1px solid #cbd5e1; padding-bottom: 5px; margin: 14px 0 8px; }
            .cust-stats-grid { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 6px; }
            .cust-stat-box { flex: 1 1 130px; background: #fff; border: 1px solid #e2e8f0; border-radius: 5px; padding: 8px 10px; text-align: center; }
            .cust-stat-box .stat-label { font-size: 10px; color: #64748b; font-weight: 600; text-transform: uppercase; display: block; }
            .cust-stat-box .stat-val { font-size: 15px; font-weight: 700; color: #1e3a5f; display: block; margin-top: 2px; }
            @media print {
                .cust-section-divider { page-break-before: always; border-top: 2px solid #1e3a5f; margin-top: 0; }
                .cust-info-block { page-break-inside: avoid; }
                .no-print { display: none !important; }
            }
            </style>

            <?php
            // Details pre-fetched from data layer
            $all_details = $report_data['customer_details'] ?? [];
            ?>

            <!-- ===================== SECTION 1: CUSTOMER REPORT TABLE ===================== -->
            <h4 class="rpt-section-heading text-uppercase fw-bold border-bottom pb-2 mb-3" style="color:#1e3a5f;font-size:14px;letter-spacing:.5px;">
                <i class="fas fa-users me-2"></i>Customer Report Table
            </h4>
            <div class="table-responsive mb-5">
                <table class="rpt-table align-middle" style="font-size:11.5px;width:100%;">
                    <thead>
                        <tr>
                            <th>Customer ID</th>
                            <th>Customer Name</th>
                            <th>Contact No.</th>
                            <th>Customer Type</th>
                            <th class="text-center">Total Visits</th>
                            <th class="text-center">Total Transactions</th>
                            <th class="text-center">Total Job Orders</th>
                            <th class="text-center">Total Merch Purchases</th>
                            <th class="text-end">Total Amount Spent</th>
                            <th class="text-end">Outstanding Balance</th>
                            <th>Last Visit</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rows)): ?>
                            <tr><td colspan="12" class="text-center py-4 text-muted">No customer records found.</td></tr>
                        <?php else:
                            $grand_total_spent   = 0;
                            $grand_total_balance = 0;
                            foreach ($rows as $r):
                                $grand_total_spent   += (float)($r['total_amount_spent'] ?? 0);
                                $grand_total_balance += (float)($r['outstanding_balance'] ?? 0);
                                $status_lc = strtolower($r['status'] ?? 'active');
                                $badge_cls = match(true) {
                                    str_contains($status_lc, 'active')   => 'bg-success',
                                    str_contains($status_lc, 'inactive') => 'bg-secondary',
                                    str_contains($status_lc, 'pending')  => 'bg-warning text-dark',
                                    default                              => 'bg-secondary',
                                };
                        ?>
                            <tr>
                                <td><code><?= htmlspecialchars($r['customer_id_code'] ?? '') ?></code></td>
                                <td><strong><?= htmlspecialchars($r['customer_name'] ?? '') ?></strong></td>
                                <td><?= htmlspecialchars($r['contact_no'] ?? 'N/A') ?></td>
                                <td><span class="badge bg-secondary"><?= htmlspecialchars(ucwords($r['customer_type'] ?? 'Walk-in')) ?></span></td>
                                <td class="text-center fw-bold"><?= number_format((int)($r['total_visits'] ?? 0)) ?></td>
                                <td class="text-center"><?= number_format((int)($r['total_transactions'] ?? 0)) ?></td>
                                <td class="text-center"><?= number_format((int)($r['total_job_orders'] ?? 0)) ?></td>
                                <td class="text-center"><?= number_format((int)($r['total_merch_purchases'] ?? 0)) ?></td>
                                <td class="text-end fw-bold text-success">₱<?= number_format((float)($r['total_amount_spent'] ?? 0), 2) ?></td>
                                <td class="text-end fw-bold <?= (float)($r['outstanding_balance'] ?? 0) > 0 ? 'text-danger' : 'text-muted' ?>">
                                    ₱<?= number_format((float)($r['outstanding_balance'] ?? 0), 2) ?>
                                </td>
                                <td><?= htmlspecialchars($r['last_visit'] ? date('m/d/Y', strtotime($r['last_visit'])) : 'N/A') ?></td>
                                <td><span class="badge <?= $badge_cls ?>"><?= htmlspecialchars(ucfirst($r['status'] ?? 'Active')) ?></span></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                    <tfoot>
                        <tr class="fw-bold" style="background:#f1f5f9;">
                            <td colspan="8" class="text-end">Total:</td>
                            <td class="text-end text-success">₱<?= number_format($grand_total_spent ?? 0, 2) ?></td>
                            <td class="text-end text-danger">₱<?= number_format($grand_total_balance ?? 0, 2) ?></td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <?php if (!empty($rows)): ?>
            <!-- ===================== SECTION 2: CUSTOMER INFORMATION REPORT ===================== -->
            <div class="cust-section-divider"></div>
            <h4 class="text-uppercase fw-bold mb-4" style="color:#1e3a5f;font-size:14px;letter-spacing:.5px;">
                <i class="fas fa-id-card me-2"></i>Customer Information Report
            </h4>

            <?php foreach ($rows as $ri => $r):
                $d     = $all_details[$r['id']] ?? [];
                $info  = $d['info']  ?? [];
                $vehs  = $d['vehicles'] ?? [];
                $jos   = $d['service_history'] ?? [];
                $merch = $d['merch_history'] ?? [];
                $pmts  = $d['payment_history'] ?? [];
                $ar    = $d['ar_history'] ?? [];
                $stats = $d['stats'] ?? [];
                $ctype_val = strtolower($info['customer_type'] ?? $info['type'] ?? '');
                $is_credit_fleet = str_contains($ctype_val, 'credit') || str_contains($ctype_val, 'fleet');
            ?>
            <div class="cust-info-block mb-5 <?= $ri > 0 ? 'mt-4' : '' ?>">

                <!-- Customer Header -->
                <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
                    <div class="d-flex align-items-center gap-3">
                        <div style="width:38px;height:38px;border-radius:50%;background:#e2e8f0;color:#1e3a5f;display:flex;align-items:center;justify-content:center;font-size:16px;font-weight:700;border:1px solid #cbd5e1;">
                            <?= strtoupper(substr($r['customer_name'] ?? 'C', 0, 1)) ?>
                        </div>
                        <div>
                            <h5 class="mb-0 fw-bold text-dark" style="font-size:15px;"><?= htmlspecialchars($r['customer_name'] ?? '') ?></h5>
                            <small class="text-muted" style="font-size:11px;">
                                <code style="font-size:11px;color:#1e3a5f;"><?= htmlspecialchars($r['customer_id_code'] ?? '') ?></code> &nbsp;·&nbsp; <span class="badge bg-secondary" style="font-size:10px;"><?= htmlspecialchars(ucwords($r['customer_type'] ?? 'Walk-in')) ?></span>
                            </small>
                        </div>
                    </div>
                </div>

                <!-- 1. Customer Information -->
                <p class="cust-sub-heading"><i class="fas fa-user me-1"></i>Customer Information</p>
                <div class="cust-profile-card">
                    <div class="info-row">
                        <div class="info-item">
                            <span class="info-label">Customer ID</span>
                            <span class="info-value"><?= htmlspecialchars($info['customer_id'] ?? $r['customer_id_code'] ?? 'N/A') ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Full Name</span>
                            <span class="info-value"><?= htmlspecialchars($info['name'] ?? 'N/A') ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Contact Number</span>
                            <span class="info-value"><?= htmlspecialchars($info['contact_number'] ?? $info['phone'] ?? 'N/A') ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Address</span>
                            <span class="info-value"><?= htmlspecialchars($info['address'] ?? 'N/A') ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Customer Type</span>
                            <span class="info-value"><?= htmlspecialchars(ucwords($info['customer_type'] ?? $info['type'] ?? 'Walk-in')) ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Date Registered</span>
                            <span class="info-value"><?= htmlspecialchars($info['registered_at'] ? date('m/d/Y', strtotime($info['registered_at'])) : ($info['created_at'] ? date('m/d/Y', strtotime($info['created_at'])) : 'N/A')) ?></span>
                        </div>
                    </div>
                </div>

                <!-- 2. Vehicle History -->
                <p class="cust-sub-heading"><i class="fas fa-car me-1"></i>Vehicle History</p>
                <div class="table-responsive mb-3">
                    <table class="rpt-table" style="font-size:11.5px;width:100%;">
                        <thead><tr><th>Plate No.</th><th>Vehicle</th><th>Brand</th><th>Model</th><th>Year</th><th>Last Service</th></tr></thead>
                        <tbody>
                        <?php if (empty($vehs)): ?>
                            <tr><td colspan="6" class="text-center text-muted py-2" style="font-size:11px;">No vehicles registered.</td></tr>
                        <?php else: foreach ($vehs as $v): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($v['plate_number'] ?? 'N/A') ?></strong></td>
                                <td><?= htmlspecialchars($v['vehicle_type'] ?? 'Vehicle') ?></td>
                                <td><?= htmlspecialchars($v['brand'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($v['model'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($v['year_model'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($v['last_service'] ? date('m/d/Y', strtotime($v['last_service'])) : 'N/A') ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- 3. Job Order History -->
                <p class="cust-sub-heading"><i class="fas fa-tools me-1"></i>Job Order History</p>
                <div class="table-responsive mb-3">
                    <table class="rpt-table" style="font-size:11.5px;width:100%;">
                        <thead><tr><th>JO No.</th><th>Date</th><th>Service</th><th>Mechanic</th><th>Status</th><th class="text-end">Total Amount</th></tr></thead>
                        <tbody>
                        <?php if (empty($jos)): ?>
                            <tr><td colspan="6" class="text-center text-muted py-2" style="font-size:11px;">No job order history found.</td></tr>
                        <?php else:
                            $jo_total = 0;
                            foreach ($jos as $j):
                                $jo_total += (float)($j['amount'] ?? 0); ?>
                            <tr>
                                <td><code><?= htmlspecialchars($j['jo_no'] ?? 'N/A') ?></code></td>
                                <td><?= htmlspecialchars($j['date'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($j['service'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($j['mechanic'] ?? 'Unassigned') ?></td>
                                <td><span class="badge bg-secondary" style="font-size:10px;"><?= htmlspecialchars($j['status'] ?? 'N/A') ?></span></td>
                                <td class="text-end fw-bold text-success">₱<?= number_format((float)($j['amount'] ?? 0), 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                            <tr class="fw-bold" style="background:#f8fafc;">
                                <td colspan="5" class="text-end">Total Job Order Amount:</td>
                                <td class="text-end text-success">₱<?= number_format($jo_total, 2) ?></td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- 4. Merchandise Purchase History -->
                <p class="cust-sub-heading"><i class="fas fa-shopping-cart me-1"></i>Merchandise Purchase History</p>
                <div class="table-responsive mb-3">
                    <table class="rpt-table" style="font-size:11.5px;width:100%;">
                        <thead><tr><th>Receipt No.</th><th>Date</th><th>Product</th><th class="text-center">Qty</th><th class="text-end">Amount</th></tr></thead>
                        <tbody>
                        <?php if (empty($merch)): ?>
                            <tr><td colspan="5" class="text-center text-muted py-2" style="font-size:11px;">No merchandise purchase history found.</td></tr>
                        <?php else:
                            $merch_total = 0;
                            foreach ($merch as $m):
                                $merch_total += (float)($m['amount'] ?? 0); ?>
                            <tr>
                                <td><code><?= htmlspecialchars($m['receipt_no'] ?? 'N/A') ?></code></td>
                                <td><?= htmlspecialchars($m['date'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($m['product'] ?? 'N/A') ?></td>
                                <td class="text-center fw-bold"><?= (int)($m['quantity'] ?? 0) ?></td>
                                <td class="text-end fw-bold text-primary">₱<?= number_format((float)($m['amount'] ?? 0), 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                            <tr class="fw-bold" style="background:#f8fafc;">
                                <td colspan="4" class="text-end">Total Merchandise Amount:</td>
                                <td class="text-end text-primary">₱<?= number_format($merch_total, 2) ?></td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- 5. Payment History -->
                <p class="cust-sub-heading"><i class="fas fa-receipt me-1"></i>Payment History</p>
                <div class="table-responsive mb-3">
                    <table class="rpt-table" style="font-size:11.5px;width:100%;">
                        <thead><tr><th>Date</th><th>OR No.</th><th>Payment Method</th><th class="text-end">Amount</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php if (empty($pmts)): ?>
                            <tr><td colspan="5" class="text-center text-muted py-2" style="font-size:11px;">No payment history found.</td></tr>
                        <?php else:
                            $pmt_total = 0;
                            foreach ($pmts as $p):
                                $pmt_total += (float)($p['amount'] ?? 0); ?>
                            <tr>
                                <td><?= htmlspecialchars($p['date'] ?? 'N/A') ?></td>
                                <td><code><?= htmlspecialchars($p['or_no'] ?? 'N/A') ?></code></td>
                                <td><?= htmlspecialchars($p['payment_method'] ?? 'N/A') ?></td>
                                <td class="text-end fw-bold text-success">₱<?= number_format((float)($p['amount'] ?? 0), 2) ?></td>
                                <td><span class="badge bg-success" style="font-size:10px;"><?= htmlspecialchars($p['status'] ?? 'Completed') ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                            <tr class="fw-bold" style="background:#f8fafc;">
                                <td colspan="3" class="text-end">Total Payments:</td>
                                <td class="text-end text-success">₱<?= number_format($pmt_total, 2) ?></td>
                                <td></td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- 6. Accounts Receivable History (Credit/Fleet only) -->
                <?php if ($is_credit_fleet): ?>
                <p class="cust-sub-heading" style="color:#b91c1c;"><i class="fas fa-file-invoice-dollar me-1"></i>Accounts Receivable History <small class="text-muted fw-normal">(Applicable for Credit Account / Fleet Card only)</small></p>
                <div class="table-responsive mb-3">
                    <table class="rpt-table" style="font-size:11.5px;width:100%;">
                        <thead><tr><th>Invoice No.</th><th>Due Date</th><th class="text-end">Outstanding Balance</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php if (empty($ar)): ?>
                            <tr><td colspan="4" class="text-center text-muted py-2" style="font-size:11px;">No outstanding receivables.</td></tr>
                        <?php else:
                            $ar_total = 0;
                            foreach ($ar as $a):
                                $ar_total += (float)($a['balance'] ?? 0); ?>
                            <tr>
                                <td><code><?= htmlspecialchars($a['invoice_no'] ?? 'N/A') ?></code></td>
                                <td><?= htmlspecialchars($a['due_date'] ?? 'N/A') ?></td>
                                <td class="text-end fw-bold text-danger">₱<?= number_format((float)($a['balance'] ?? 0), 2) ?></td>
                                <td><span class="badge bg-warning text-dark" style="font-size:10px;"><?= htmlspecialchars($a['status'] ?? 'Current') ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                            <tr class="fw-bold" style="background:#fff5f5;">
                                <td colspan="2" class="text-end">Total Outstanding Balance:</td>
                                <td class="text-end text-danger">₱<?= number_format($ar_total, 2) ?></td>
                                <td></td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>

                <!-- 7. Customer Statistics -->
                <p class="cust-sub-heading"><i class="fas fa-chart-bar me-1"></i>Customer Statistics</p>
                <div class="cust-stats-grid">
                    <div class="cust-stat-box">
                        <span class="stat-label">Total Visits</span>
                        <span class="stat-val"><?= number_format((int)($stats['total_visits'] ?? 0)) ?></span>
                    </div>
                    <div class="cust-stat-box">
                        <span class="stat-label">Total Job Orders</span>
                        <span class="stat-val" style="color:#1d4ed8;"><?= number_format((int)($stats['total_job_orders'] ?? 0)) ?></span>
                    </div>
                    <div class="cust-stat-box">
                        <span class="stat-label">Merch Purchases</span>
                        <span class="stat-val" style="color:#0891b2;"><?= number_format((int)($stats['total_merch_purchases'] ?? 0)) ?></span>
                    </div>
                    <div class="cust-stat-box">
                        <span class="stat-label">Total Amount Spent</span>
                        <span class="stat-val" style="color:#16a34a;">₱<?= number_format((float)($stats['total_amount_spent'] ?? 0), 2) ?></span>
                    </div>
                    <div class="cust-stat-box">
                        <span class="stat-label">Avg. Spending / Visit</span>
                        <span class="stat-val" style="color:#7c3aed;">₱<?= number_format((float)($stats['average_spending'] ?? 0), 2) ?></span>
                    </div>
                    <div class="cust-stat-box">
                        <span class="stat-label">Last Visit Date</span>
                        <span class="stat-val" style="font-size:12px;color:#475569;"><?= htmlspecialchars($stats['last_visit'] ?? 'N/A') ?></span>
                    </div>
                </div>

                <?php if ($ri < count($rows) - 1): ?>
                <div class="cust-section-divider"></div>
                <?php endif; ?>

            </div>
            <?php endforeach; ?>
            <?php endif; ?>

            <?php
            break;





        // =========================================================================
        // 7. AUDIT REPORTS
        // =========================================================================
        case 'audit':
            $tab_labels = [
                'user_activity_logs'   => ['icon' => 'fas fa-user-clock',     'title' => 'User Activity Logs'],
                'login_history'        => ['icon' => 'fas fa-sign-in-alt',    'title' => 'Login History'],
                'transaction_logs'     => ['icon' => 'fas fa-exchange-alt',   'title' => 'Transaction Logs'],
                'inventory_logs'       => ['icon' => 'fas fa-boxes',          'title' => 'Inventory Logs'],
                'approval_logs'        => ['icon' => 'fas fa-check-circle',   'title' => 'Approval Logs'],
                'archived_deactivated' => ['icon' => 'fas fa-archive',        'title' => 'Archived & Deactivated Logs'],
            ];
            $cur_tab = $tab_labels[$tab] ?? ['icon' => 'fas fa-history', 'title' => ucwords(str_replace('_', ' ', $tab))];
            ?>
            <h4 class="rpt-section-heading text-uppercase fw-bold border-bottom pb-2 mb-3" style="color:#1e3a5f;font-size:14px;letter-spacing:.5px;">
                <i class="<?= $cur_tab['icon'] ?> me-2"></i><?= htmlspecialchars($cur_tab['title']) ?>
            </h4>
            <div class="table-responsive">
                <table class="rpt-table align-middle" style="font-size:12px;width:100%;">

                <?php if ($tab === 'user_activity_logs'): ?>
                    <thead><tr>
                        <th>Date / Time</th>
                        <th>User</th>
                        <th>Role</th>
                        <th>Action</th>
                        <th>Details</th>
                        <th>IP Address</th>
                    </tr></thead>
                    <tbody>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="6" class="text-center py-4 text-muted">No user activity logs found.</td></tr>
                    <?php else: foreach ($rows as $r):
                        $act_lc = strtolower($r['action'] ?? '');
                        $act_cls = match(true) {
                            str_contains($act_lc, 'add') || str_contains($act_lc, 'creat')  => 'bg-success',
                            str_contains($act_lc, 'edit') || str_contains($act_lc, 'updat')  => 'bg-warning text-dark',
                            str_contains($act_lc, 'archive') || str_contains($act_lc, 'deact') => 'bg-danger',
                            str_contains($act_lc, 'login') || str_contains($act_lc, 'logout')  => 'bg-info text-dark',
                            default => 'bg-secondary',
                        };
                    ?>
                        <tr>
                            <td><small><?= htmlspecialchars($r['created_at'] ?? 'N/A') ?></small></td>
                            <td><strong><?= htmlspecialchars($r['user'] ?? 'N/A') ?></strong></td>
                            <td><span class="badge bg-secondary" style="font-size:10px;"><?= htmlspecialchars($r['role'] ?? 'N/A') ?></span></td>
                            <td><span class="badge <?= $act_cls ?>" style="font-size:10px;"><?= htmlspecialchars($r['action'] ?? 'N/A') ?></span></td>
                            <td><small><?= htmlspecialchars($r['details'] ?? 'N/A') ?></small></td>
                            <td><code style="font-size:10px;"><?= htmlspecialchars($r['ip_address'] ?? 'N/A') ?></code></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>

                <?php elseif ($tab === 'login_history'): ?>
                    <thead><tr>
                        <th>Date</th>
                        <th>User</th>
                        <th>Role</th>
                        <th>Login Time</th>
                        <th>Logout Time</th>
                        <th>Session</th>
                        <th>IP Address</th>
                        <th>Status</th>
                    </tr></thead>
                    <tbody>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="8" class="text-center py-4 text-muted">No login history found.</td></tr>
                    <?php else: foreach ($rows as $r):
                        $st = strtolower($r['status'] ?? '');
                        $st_cls = match(true) {
                            str_contains($st, 'success')  => 'bg-success',
                            str_contains($st, 'failed')   => 'bg-danger',
                            str_contains($st, 'locked')   => 'bg-warning text-dark',
                            default                       => 'bg-secondary',
                        };
                        $st_lbl = match(true) {
                            str_contains($st, 'success') => 'Success',
                            str_contains($st, 'fail')    => 'Failed Login',
                            str_contains($st, 'lock')    => 'Locked Account',
                            default                      => ucfirst($r['status'] ?? 'N/A'),
                        };
                        $fail_reason = trim($r['failure_reason'] ?? '');
                    ?>
                        <tr>
                            <td><small><?= htmlspecialchars(date('m/d/Y', strtotime($r['date'] ?? 'now'))) ?></small></td>
                            <td><strong><?= htmlspecialchars($r['user'] ?? $r['username'] ?? 'N/A') ?></strong></td>
                            <td><span class="badge bg-secondary" style="font-size:10px;"><?= htmlspecialchars($r['role'] ?? 'N/A') ?></span></td>
                            <td><small><?= htmlspecialchars($r['login_time'] ?? 'N/A') ?></small></td>
                            <td class="text-muted"><small><?= htmlspecialchars($r['logout_time'] ?? '—') ?></small></td>
                            <td class="text-muted"><small><?= htmlspecialchars($r['session_duration'] ?? '—') ?></small></td>
                            <td><code style="font-size:10px;"><?= htmlspecialchars($r['ip_address'] ?? 'N/A') ?></code></td>
                            <td>
                                <span class="badge <?= $st_cls ?>" style="font-size:10px;"><?= $st_lbl ?></span>
                                <?php if ($fail_reason): ?><br><small class="text-muted"><?= htmlspecialchars($fail_reason) ?></small><?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>

                <?php elseif ($tab === 'transaction_logs'): ?>
                    <thead><tr>
                        <th>Transaction ID</th>
                        <th>Module</th>
                        <th>Customer</th>
                        <th>Performed By</th>
                        <th class="text-end">Amount</th>
                        <th>Payment Method</th>
                        <th>Date / Time</th>
                        <th>Status</th>
                    </tr></thead>
                    <tbody>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="8" class="text-center py-4 text-muted">No transaction logs found.</td></tr>
                    </tbody>
                    <?php else: ?>
                    <?php
                        $grand_total = 0;
                        foreach ($rows as $r):
                            $grand_total += (float)($r['total_amount'] ?? 0);
                            $mod = $r['module'] ?? 'Sale';
                            $mod_cls = match($mod) {
                                'Job Order' => 'bg-primary',
                                'Return'    => 'bg-warning text-dark',
                                'Void'      => 'bg-danger',
                                'Refund'    => 'bg-info text-dark',
                                default     => 'bg-success',
                            };
                    ?>
                        <tr>
                            <td><code style="font-size:10px;"><?= htmlspecialchars($r['transaction_id'] ?? 'N/A') ?></code></td>
                            <td><span class="badge <?= $mod_cls ?>" style="font-size:10px;"><?= htmlspecialchars($mod) ?></span></td>
                            <td><?= htmlspecialchars($r['customer'] ?? 'Walk-in') ?></td>
                            <td><?= htmlspecialchars($r['performed_by'] ?? 'Staff') ?></td>
                            <td class="text-end fw-bold text-success">₱<?= number_format((float)($r['total_amount'] ?? 0), 2) ?></td>
                            <td><?= htmlspecialchars($r['payment_method'] ?? 'N/A') ?></td>
                            <td><small><?= htmlspecialchars($r['transaction_date'] ?? 'N/A') ?></small></td>
                            <td><span class="badge bg-secondary" style="font-size:10px;"><?= htmlspecialchars($r['status'] ?? 'N/A') ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="fw-bold" style="background:#f1f5f9;">
                            <td colspan="4" class="text-end">Total:</td>
                            <td class="text-end text-success">₱<?= number_format($grand_total, 2) ?></td>
                            <td colspan="3"></td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>

                <?php elseif ($tab === 'inventory_logs'): ?>
                    <thead><tr>
                        <th>Date / Time</th>
                        <th>Product</th>
                        <th>SKU</th>
                        <th>Action Type</th>
                        <th class="text-center">Qty Before</th>
                        <th class="text-center">Qty After</th>
                        <th class="text-center">Qty Change</th>
                        <th>Performed By</th>
                        <th>Notes / Reason</th>
                    </tr></thead>
                    <tbody>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="9" class="text-center py-4 text-muted">No inventory logs found.</td></tr>
                    <?php else: foreach ($rows as $r):
                        $act = $r['action_type'] ?? $r['action'] ?? 'N/A';
                        $act_c = match(true) {
                            str_contains($act, 'Stock In')   => 'bg-success',
                            str_contains($act, 'Stock Out')  => 'bg-danger',
                            str_contains($act, 'Adjust')     => 'bg-warning text-dark',
                            str_contains($act, 'Expired')    => 'bg-secondary',
                            str_contains($act, 'Damaged')    => 'bg-dark',
                            str_contains($act, 'Physical')   => 'bg-info text-dark',
                            default                          => 'bg-secondary',
                        };
                        $chg = (float)($r['quantity_change'] ?? 0);
                    ?>
                        <tr>
                            <td><small><?= htmlspecialchars($r['created_at'] ?? 'N/A') ?></small></td>
                            <td><strong><?= htmlspecialchars($r['product'] ?? 'N/A') ?></strong></td>
                            <td><code style="font-size:10px;"><?= htmlspecialchars($r['sku'] ?? 'N/A') ?></code></td>
                            <td><span class="badge <?= $act_c ?>" style="font-size:10px;"><?= htmlspecialchars($act) ?></span></td>
                            <td class="text-center"><?= number_format((float)($r['quantity_before'] ?? 0), 2) ?></td>
                            <td class="text-center"><?= number_format((float)($r['quantity_after'] ?? 0), 2) ?></td>
                            <td class="text-center fw-bold <?= $chg >= 0 ? 'text-success' : 'text-danger' ?>">
                                <?= ($chg > 0 ? '+' : '') . number_format($chg, 2) ?>
                            </td>
                            <td><?= htmlspecialchars($r['performed_by'] ?? 'System') ?></td>
                            <td><small class="text-muted"><?= htmlspecialchars($r['notes'] ?? 'N/A') ?></small></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>

                <?php elseif ($tab === 'approval_logs'): ?>
                    <thead><tr>
                        <th>Date / Time</th>
                        <th>Reference</th>
                        <th>Category</th>
                        <th>Requested By</th>
                        <th>Approved By</th>
                        <th>Old Value</th>
                        <th>New Value</th>
                        <th>Reviewed At</th>
                        <th>Status</th>
                    </tr></thead>
                    <tbody>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="9" class="text-center py-4 text-muted">No approval logs found.</td></tr>
                    <?php else: foreach ($rows as $r):
                        $st = strtolower($r['status'] ?? '');
                        $st_cls = match(true) {
                            str_contains($st, 'approv') => 'bg-success',
                            str_contains($st, 'reject') => 'bg-danger',
                            str_contains($st, 'pend')   => 'bg-warning text-dark',
                            default                     => 'bg-secondary',
                        };
                    ?>
                        <tr>
                            <td><small><?= htmlspecialchars($r['created_at'] ?? 'N/A') ?></small></td>
                            <td><strong><?= htmlspecialchars($r['reference'] ?? 'N/A') ?></strong></td>
                            <td><span class="badge bg-info text-dark" style="font-size:10px;"><?= htmlspecialchars($r['category'] ?? 'N/A') ?></span></td>
                            <td><?= htmlspecialchars($r['requested_by'] ?? 'N/A') ?></td>
                            <td><?= htmlspecialchars($r['approved_by'] ?? '—') ?></td>
                            <td class="text-danger"><?= htmlspecialchars($r['old_value'] ?? 'N/A') ?></td>
                            <td class="text-success fw-bold"><?= htmlspecialchars($r['new_value'] ?? 'N/A') ?></td>
                            <td><small class="text-muted"><?= htmlspecialchars($r['reviewed_at'] ?? '—') ?></small></td>
                            <td><span class="badge <?= $st_cls ?>" style="font-size:10px;"><?= htmlspecialchars(ucfirst($r['status'] ?? 'N/A')) ?></span></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>

                <?php else: // archived_deactivated ?>
                    <thead><tr>
                        <th>Date / Time</th>
                        <th>Module</th>
                        <th>Record / Name</th>
                        <th>Action</th>
                        <th>Reason</th>
                        <th>Performed By</th>
                    </tr></thead>
                    <tbody>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="6" class="text-center py-4 text-muted">No archived or deactivated records found for this period.</td></tr>
                    <?php else: foreach ($rows as $r):
                        $act = $r['action_done'] ?? 'N/A';
                        $act_cls = match(true) {
                            str_contains(strtolower($act), 'archive')    => 'bg-warning text-dark',
                            str_contains(strtolower($act), 'deactivat')  => 'bg-danger',
                            str_contains(strtolower($act), 'reactivat')  => 'bg-success',
                            default                                       => 'bg-secondary',
                        };
                    ?>
                        <tr>
                            <td><small><?= htmlspecialchars($r['created_at'] ?? 'N/A') ?></small></td>
                            <td><span class="badge bg-secondary" style="font-size:10px;"><?= htmlspecialchars($r['module'] ?? 'N/A') ?></span></td>
                            <td><strong><?= htmlspecialchars($r['record_name'] ?? 'N/A') ?></strong></td>
                            <td><span class="badge <?= $act_cls ?>" style="font-size:10px;"><?= htmlspecialchars($act) ?></span></td>
                            <td><small class="text-muted"><?= htmlspecialchars($r['reason'] ?? 'N/A') ?></small></td>
                            <td><?= htmlspecialchars($r['performed_by'] ?? 'Admin') ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>

                <?php endif; ?>
                </table>
            </div>
            <?php
            break;
    }
}

