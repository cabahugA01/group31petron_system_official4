<?php
// Maintenance Scripts Tab - Backup, Restore, Indexing, Optimization
global $pdo, $config;

// Group scripts by type
$scriptsByType = [];
foreach ($config['scripts'] as $script) {
    $scriptsByType[$script['script_type']][] = $script;
}
?>

<div class="maintenance-scripts-container">
    <div class="scripts-header">
        <p><i class="fas fa-info-circle"></i> Execute maintenance scripts to keep your database running optimally. Some operations may require confirmation.</p>
    </div>

    <?php foreach ($scriptsByType as $scriptType => $scripts): ?>
        <div class="script-type-section">
            <h3>
                <i class="fas fa-<?php echo getScriptTypeIcon($scriptType); ?>"></i>
                <?php echo getScriptTypeTitle($scriptType); ?>
            </h3>
            <div class="maintenance-grid">
                <?php foreach ($scripts as $script): ?>
                    <div class="script-card <?php echo $script['is_dangerous'] ? 'danger' : ($script['requires_confirmation'] ? 'warning' : ''); ?>">
                        <h4>
                            <i class="fas fa-<?php echo getScriptIcon($script['script_type']); ?>"></i>
                            <?php echo htmlspecialchars($script['script_name']); ?>
                        </h4>
                        <p><?php echo htmlspecialchars($script['description']); ?></p>
                        
                        <?php if ($script['estimated_duration']): ?>
                            <div class="script-details">
                                <strong>Estimated Duration:</strong> <?php echo htmlspecialchars($script['estimated_duration']); ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($script['is_dangerous']): ?>
                            <div class="alert alert-danger" style="margin-top: 10px;">
                                <i class="fas fa-exclamation-triangle"></i>
                                <strong>Dangerous Operation:</strong> This operation can cause data loss if not used properly.
                            </div>
                        <?php elseif ($script['requires_confirmation']): ?>
                            <div class="alert alert-warning" style="margin-top: 10px;">
                                <i class="fas fa-exclamation-circle"></i>
                                <strong>Confirmation Required:</strong> This operation requires careful consideration.
                            </div>
                        <?php endif; ?>
                        
                        <div class="script-meta">
                            <span class="duration">
                                <i class="fas fa-clock"></i>
                                <?php echo $script['estimated_duration'] ?? 'Duration unknown'; ?>
                            </span>
                            <button class="btn-execute <?php echo $script['is_dangerous'] ? 'danger' : ($script['requires_confirmation'] ? 'warning' : ''); ?>" 
                                    onclick="executeScript('<?php echo $script['script_key']; ?>', '<?php echo htmlspecialchars($script['script_name']); ?>', <?php echo $script['requires_confirmation'] ? 'true' : 'false'; ?>)">
                                <i class="fas fa-play"></i> Execute
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- Execution Status Modal -->
<div id="executionModal" class="modal" style="display: none;">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-cog fa-spin"></i> Executing Script</h3>
            <button class="modal-close" onclick="closeExecutionModal()">&times;</button>
        </div>
        <div class="modal-body">
            <p id="executionMessage">Executing script...</p>
            <div class="progress-container">
                <div class="progress-bar">
                    <div class="progress-fill" id="progressFill"></div>
                </div>
            </div>
            <div id="executionResult" style="margin-top: 20px; display: none;"></div>
        </div>
    </div>
</div>

<style>
.scripts-header {
    background: #f8f9fa;
    padding: 20px;
    border-radius: 10px;
    margin-bottom: 30px;
    border-left: 4px solid #667eea;
}

.script-type-section {
    margin-bottom: 40px;
}

.script-type-section h3 {
    color: #495057;
    margin-bottom: 20px;
    padding-bottom: 10px;
    border-bottom: 2px solid #e9ecef;
    display: flex;
    align-items: center;
    gap: 10px;
}

.script-details {
    color: #6c757d;
    font-size: 0.9rem;
    margin: 10px 0;
}

.alert {
    padding: 10px 15px;
    border-radius: 5px;
    margin: 10px 0;
}

.alert-danger {
    background: #f8d7da;
    border: 1px solid #f5c6cb;
    color: #721c24;
}

.alert-warning {
    background: #fff3cd;
    border: 1px solid #ffeaa7;
    color: #856404;
}

.modal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    z-index: 1000;
}

.modal-content {
    background: white;
    border-radius: 10px;
    max-width: 500px;
    margin: 100px auto;
    position: relative;
}

.modal-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 20px;
    border-radius: 10px 10px 0 0;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-header h3 {
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.modal-close {
    background: none;
    border: none;
    color: white;
    font-size: 24px;
    cursor: pointer;
}

.modal-body {
    padding: 30px;
}

.progress-container {
    margin: 20px 0;
}

.progress-bar {
    width: 100%;
    height: 10px;
    background: #e9ecef;
    border-radius: 5px;
    overflow: hidden;
}

.progress-fill {
    height: 100%;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    width: 0%;
    transition: width 0.3s ease;
}

#executionResult {
    padding: 15px;
    border-radius: 5px;
    margin-top: 20px;
}

#executionResult.success {
    background: #d4edda;
    border: 1px solid #c3e6cb;
    color: #155724;
}

#executionResult.error {
    background: #f8d7da;
    border: 1px solid #f5c6cb;
    color: #721c24;
}
</style>

<script>
// Database-driven script type configurations
const scriptTypeConfig = {
    'backup': { icon: 'fa-download', title: 'Backup Operations' },
    'restore': { icon: 'fa-upload', title: 'Restore Operations' },
    'indexing': { icon: 'fa-sort-amount-down', title: 'Indexing Operations' },
    'optimization': { icon: 'fa-tachometer-alt', title: 'Optimization Operations' },
    'cleanup': { icon: 'fa-broom', title: 'Cleanup Operations' },
    'repair': { icon: 'fa-wrench', title: 'Repair Operations' }
};

function getScriptTypeIcon(type) {
    return scriptTypeConfig[type]?.icon || 'fa-cog';
}

function getScriptTypeTitle(type) {
    return scriptTypeConfig[type]?.title || 'Other Operations';
}

function getScriptIcon(type) {
    return scriptTypeConfig[type]?.icon || 'fa-cog';
}

function executeScript(scriptKey, scriptName, requiresConfirmation) {
    if (requiresConfirmation) {
        const message = scriptName.toLowerCase().includes('restore') || scriptName.toLowerCase().includes('purge') 
            ? `<i class="fas fa-exclamation-triangle"></i> WARNING: This operation can cause data loss!\n\nAre you absolutely sure you want to execute "${scriptName}"?\n\nThis action cannot be undone.`
            : `Are you sure you want to execute "${scriptName}"?`;
        
        if (!confirm(message)) {
            return;
        }
    }
    
    // Show execution modal
    document.getElementById('executionModal').style.display = 'block';
    document.getElementById('executionMessage').textContent = `Executing "${scriptName}"...`;
    document.getElementById('executionResult').style.display = 'none';
    
    // Simulate progress
    let progress = 0;
    const progressInterval = setInterval(() => {
        progress += 10;
        document.getElementById('progressFill').style.width = progress + '%';
        
        if (progress >= 90) {
            clearInterval(progressInterval);
        }
    }, 200);
    
    // Execute script via AJAX
    fetch(`../backend/api/database_maintenance.php?action=execute&script=${scriptKey}`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => response.json())
    .then(data => {
        clearInterval(progressInterval);
        document.getElementById('progressFill').style.width = '100%';
        
        setTimeout(() => {
            const resultDiv = document.getElementById('executionResult');
            resultDiv.style.display = 'block';
            
            if (data.success) {
                resultDiv.className = 'success';
                resultDiv.innerHTML = `
                    <h4><i class="fas fa-check-circle"></i> Script Executed Successfully</h4>
                    <p><strong>Script:</strong> ${scriptName}</p>
                    <p><strong>Message:</strong> ${data.message}</p>
                    ${data.output ? `<p><strong>Output:</strong><br><pre>${data.output}</pre></p>` : ''}
                    ${data.duration ? `<p><strong>Duration:</strong> ${data.duration}</p>` : ''}
                `;
            } else {
                resultDiv.className = 'error';
                resultDiv.innerHTML = `
                    <h4><i class="fas fa-exclamation-triangle"></i> Script Execution Failed</h4>
                    <p><strong>Script:</strong> ${scriptName}</p>
                    <p><strong>Error:</strong> ${data.message}</p>
                    ${data.error ? `<p><strong>Details:</strong><br><pre>${data.error}</pre></p>` : ''}
                `;
            }
        }, 500);
    })
    .catch(error => {
        clearInterval(progressInterval);
        console.error('Error:', error);
        
        const resultDiv = document.getElementById('executionResult');
        resultDiv.style.display = 'block';
        resultDiv.className = 'error';
        resultDiv.innerHTML = `
            <h4><i class="fas fa-exclamation-triangle"></i> Communication Error</h4>
            <p>Failed to communicate with the server. Please check your connection and try again.</p>
        `;
    });
}

function closeExecutionModal() {
    document.getElementById('executionModal').style.display = 'none';
}
</script>

<?php
// Helper functions for script icons and titles
function getScriptTypeIcon($type) {
    $icons = [
        'backup' => 'fa-download',
        'restore' => 'fa-upload',
        'indexing' => 'fa-sort-amount-down',
        'optimization' => 'fa-tachometer-alt',
        'cleanup' => 'fa-broom',
        'repair' => 'fa-wrench'
    ];
    return $icons[$type] ?? 'fa-cog';
}

function getScriptTypeTitle($type) {
    $titles = [
        'backup' => 'Backup Operations',
        'restore' => 'Restore Operations',
        'indexing' => 'Indexing Operations',
        'optimization' => 'Optimization Operations',
        'cleanup' => 'Cleanup Operations',
        'repair' => 'Repair Operations'
    ];
    return $titles[$type] ?? 'Other Operations';
}

function getScriptIcon($type) {
    return getScriptTypeIcon($type);
}
?>
