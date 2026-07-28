<?php
$filepath = 'c:/xampp/htdocs/group31petron_system_official4/public/manager_stock_request_review.php';
$content = file_get_contents($filepath);
$content = str_replace("\r\n", "\n", $content);

// 1. Update Title to Purchase Management
$content = str_replace(
    '<i class="fas fa-clipboard-list" style="color: #002F6C;"></i> Purchase Requests',
    '<i class="fas fa-clipboard-list" style="color: #002F6C;"></i> Purchase Management',
    $content
);

// 2. Insert Main Page Tabs right above prSummaryCardsGrid if not present
$main_tabs_html = <<<'HTML'
    <!-- Main Page Tabs -->
    <div class="main-page-tabs" style="display: flex; gap: 10px; margin-bottom: 24px; border-bottom: 2px solid #e2e8f0; padding-bottom: 12px;">
        <button type="button" id="mainTabPrBtn" onclick="switchPendingSubTab('pr')" style="padding: 10px 24px; font-size: 14px; font-weight: 700; color: #ffffff !important; background-color: #002F6C !important; border: 1.5px solid #002F6C !important; cursor: pointer; border-radius: 8px; display: inline-flex; align-items: center; gap: 8px; transition: all 0.2s;">
            <i class="fas fa-clipboard-check"></i> Purchase Request
        </button>
        <button type="button" id="mainTabHistoryBtn" onclick="switchPendingSubTab('history')" style="padding: 10px 24px; font-size: 14px; font-weight: 700; color: #475569 !important; background-color: #f8fafc !important; border: 1.5px solid #cbd5e1 !important; cursor: pointer; border-radius: 8px; display: inline-flex; align-items: center; gap: 8px; transition: all 0.2s;">
            <i class="fas fa-history"></i> Purchase History
        </button>
    </div>
HTML;

if (strpos($content, 'id="mainTabPrBtn"') === false) {
    $content = str_replace(
        '<!-- PR Summary Cards -->',
        $main_tabs_html . "\n    <!-- PR Summary Cards -->",
        $content
    );
}

// 3. Update Category Nav ID
$old_cat_nav = <<<'HTML'
    <!-- Sub-tabs Navigation -->
    <div class="sub-tab-nav" style="display: flex; gap: 8px; margin-bottom: 20px;">
HTML;

$new_cat_nav = <<<'HTML'
    <!-- Sub-tabs Navigation -->
    <div id="pendingCategoryNav" class="sub-tab-nav" style="display: flex; gap: 8px; margin-bottom: 20px;">
HTML;

$content = str_replace($old_cat_nav, $new_cat_nav, $content);
$content = str_replace(
    '<div class="sub-tab-nav" style="display: flex; gap: 8px; margin-bottom: 20px;">',
    '<div id="pendingCategoryNav" class="sub-tab-nav" style="display: flex; gap: 8px; margin-bottom: 20px;">',
    $content
);

// 4. Remove duplicate subtabHistoryBtn inside category nav if present
$history_btn_in_nav = <<<'HTML'
        <button type="button" id="subtabHistoryBtn" onclick="switchPendingSubTab('history')" style="padding: 9px 20px; font-size: 13px; font-weight: 600; color: #64748b !important; border: 1.5px solid #e2e8f0 !important; background: #fff !important; cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: center; gap: 7px; border-radius: 8px;">
            <i class="fas fa-history"></i> Purchase History
        </button>
HTML;
$content = str_replace($history_btn_in_nav, '', $content);

// 5. Update switchPendingSubTab JS function
$new_js_switch = <<<'JS'
function switchPendingSubTab(type) {
    var mainPrBtn   = document.getElementById('mainTabPrBtn');
    var mainHistBtn = document.getElementById('mainTabHistoryBtn');

    var merchBtn = document.getElementById('subtabMerchBtn');
    var fuelBtn  = document.getElementById('subtabFuelBtn');
    var catNav   = document.getElementById('pendingCategoryNav');
    
    var merchSec  = document.getElementById('pendingMerchSection');
    var fuelSec   = document.getElementById('pendingFuelSection');
    var histSec   = document.getElementById('purchaseHistorySection');

    var prCards   = document.getElementById('prSummaryCardsGrid');
    var histCards = document.getElementById('historySummaryCardsGrid');

    if (type === 'history') {
        if (mainHistBtn) {
            mainHistBtn.style.setProperty('color', '#ffffff', 'important');
            mainHistBtn.style.setProperty('background-color', '#002F6C', 'important');
            mainHistBtn.style.setProperty('border', '1.5px solid #002F6C', 'important');
        }
        if (mainPrBtn) {
            mainPrBtn.style.setProperty('color', '#475569', 'important');
            mainPrBtn.style.setProperty('background-color', '#f8fafc', 'important');
            mainPrBtn.style.setProperty('border', '1.5px solid #cbd5e1', 'important');
        }
        if (histSec)   histSec.style.display   = 'block';
        if (merchSec)  merchSec.style.display  = 'none';
        if (fuelSec)   fuelSec.style.display   = 'none';
        if (catNav)    catNav.style.display    = 'none';
        if (prCards)   prCards.style.display   = 'none';
        if (histCards) histCards.style.display = 'none';
        filterPurchaseHistory();
    } else {
        if (mainPrBtn) {
            mainPrBtn.style.setProperty('color', '#ffffff', 'important');
            mainPrBtn.style.setProperty('background-color', '#002F6C', 'important');
            mainPrBtn.style.setProperty('border', '1.5px solid #002F6C', 'important');
        }
        if (mainHistBtn) {
            mainHistBtn.style.setProperty('color', '#475569', 'important');
            mainHistBtn.style.setProperty('background-color', '#f8fafc', 'important');
            mainHistBtn.style.setProperty('border', '1.5px solid #cbd5e1', 'important');
        }
        if (prCards) prCards.style.display = 'grid';
        if (histCards) histCards.style.display = 'none';
        if (histSec) histSec.style.display = 'none';
        if (catNav)  catNav.style.display  = 'flex';

        if (type === 'fuel') {
            if (fuelBtn) {
                fuelBtn.style.setProperty('color', '#002F6C', 'important');
                fuelBtn.style.setProperty('background-color', '#eff6ff', 'important');
                fuelBtn.style.setProperty('border', '1.5px solid #002F6C', 'important');
            }
            if (merchBtn) {
                merchBtn.style.setProperty('color', '#64748b', 'important');
                merchBtn.style.setProperty('background-color', '#fff', 'important');
                merchBtn.style.setProperty('border', '1.5px solid #e2e8f0', 'important');
            }
            if (fuelSec)  fuelSec.style.display  = 'block';
            if (merchSec) merchSec.style.display  = 'none';
        } else {
            // default 'pr' or 'merch'
            if (merchBtn) {
                merchBtn.style.setProperty('color', '#002F6C', 'important');
                merchBtn.style.setProperty('background-color', '#eff6ff', 'important');
                merchBtn.style.setProperty('border', '1.5px solid #002F6C', 'important');
            }
            if (fuelBtn) {
                fuelBtn.style.setProperty('color', '#64748b', 'important');
                fuelBtn.style.setProperty('background-color', '#fff', 'important');
                fuelBtn.style.setProperty('border', '1.5px solid #e2e8f0', 'important');
            }
            if (merchSec) merchSec.style.display = 'block';
            if (fuelSec)  fuelSec.style.display  = 'none';
        }
    }

    try {
        var url = new URL(window.location);
        url.searchParams.set('tab', type);
        window.history.replaceState({}, '', url);
        localStorage.setItem('pr_review_active_subtab', type);
    } catch(e) {}
}
JS;

$pattern = '/function switchPendingSubTab\(type\)\s*\{.*?\n\}/s';
$content = preg_replace($pattern, trim($new_js_switch), $content);

file_put_contents($filepath, $content);
echo "Successfully applied layout fix to manager_stock_request_review.php!\n";
