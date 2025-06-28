<?php
require_once '../../config/database.php';
require_once '../../config/globals.php';
require_once '../../config/security.php';
require_once '../../middleware/csrf.php';

startApplicationSession();

// Debug logging
error_log("Session data on admin logs: " . print_r($_SESSION, true));
error_log("JWT token present: " . (isset($_SESSION['jwt']) ? 'yes' : 'no'));

if (!isset($_SESSION['admin_id'])) {
    error_log("No admin_id in session, redirecting to login");
    header("Location: " . LOGIN_REDIRECT);
    exit;
}

$logDir = '../../logs/';

// Enhanced file scanning with proper error handling
$logs = [];
if (is_dir($logDir) && is_readable($logDir)) {
    $files = scandir($logDir);
    if ($files !== false) {
        $logs = array_filter(array_diff($files, ['.', '..']), function($file) use ($logDir) {
            $fullPath = $logDir . $file;
            return is_file($fullPath) && is_readable($fullPath) && pathinfo($file, PATHINFO_EXTENSION) === 'log';
        });
        
        // Sort by modification time (newest first)
        usort($logs, function($a, $b) use ($logDir) {
            return filemtime($logDir . $b) - filemtime($logDir . $a);
        });
    }
}

// Fix pagination with per_page parameter handling
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = isset($_GET['per_page']) ? max(10, min(100, (int)$_GET['per_page'])) : 50;
$offset = ($page - 1) * $perPage;
$totalPages = ceil(count($logs) / $perPage);
$paginatedLogs = array_slice($logs, $offset, $perPage);

// Generate CSRF token
$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include '../../include/header.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo $csrfToken; ?>">
    <title>Logs - AmezPrice</title>
    <link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body>
    <?php include '../../include/navbar.php'; ?>
    <div class="admin-container">
        <?php include '../../include/admin_sidebar.php'; ?>
        <div class="admin-content">
            <h1>Logs</h1>
            <div class="card">
                <p>Logs older than 3 months are automatically deleted. You can also manually delete logs below.</p>
                
                <!-- Items per page selector -->
                <div class="items-per-page-container" style="margin-bottom: 16px;">
                    <label for="per-page" style="margin-right: 8px;">Items per page:</label>
                    <select id="per-page" aria-label="Items per page">
                        <option value="10" <?php echo $perPage == 10 ? 'selected' : ''; ?>>10</option>
                        <option value="25" <?php echo $perPage == 25 ? 'selected' : ''; ?>>25</option>
                        <option value="50" <?php echo $perPage == 50 ? 'selected' : ''; ?>>50</option>
                        <option value="100" <?php echo $perPage == 100 ? 'selected' : ''; ?>>100</option>
                    </select>
                    <span style="margin-left: 16px; color: #666;">
                        Showing <?php echo count($paginatedLogs); ?> of <?php echo count($logs); ?> log files
                    </span>
                </div>

                <?php if (empty($logs)): ?>
                    <div class="no-logs" style="text-align: center; padding: 40px; color: #666;">
                        <i class="fas fa-inbox" style="font-size: 48px; margin-bottom: 16px;"></i>
                        <p>No log files found in the logs directory.</p>
                    </div>
                <?php else: ?>
                    <div class="admin-table">
                        <table>
                            <thead>
                                <tr>
                                    <th class="sortable">Log File</th>
                                    <th class="sortable">Last Modified</th>
                                    <th class="sortable">File Size</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($paginatedLogs as $log): ?>
                                    <?php 
                                    $filePath = $logDir . $log;
                                    $fileSize = file_exists($filePath) ? formatBytes(filesize($filePath)) : 'N/A';
                                    ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($log); ?></td>
                                        <td><?php echo date('Y-m-d H:i:s', filemtime($filePath)); ?></td>
                                        <td><?php echo $fileSize; ?></td>
                                        <td>
                                            <button class="btn btn-primary" onclick="viewLog('<?php echo htmlspecialchars($log); ?>')">
                                                <i class="fas fa-eye"></i> View
                                            </button>
                                            <button class="btn btn-secondary" onclick="downloadLog('<?php echo htmlspecialchars($log); ?>')">
                                                <i class="fas fa-download"></i> Download
                                            </button>
                                            <button class="btn btn-delete" onclick="confirmDeleteLog('<?php echo htmlspecialchars($log); ?>')">
                                                <i class="fas fa-trash"></i> Delete
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Enhanced Pagination -->
                    <?php if ($totalPages > 1): ?>
                        <div class="pagination" style="text-align: center; margin-top: 24px;">
                            <?php if ($page > 1): ?>
                                <a href="/admin/logs/index.php?page=<?php echo $page - 1; ?>&per_page=<?php echo $perPage; ?>" 
                                   class="btn btn-secondary">
                                    <i class="fas fa-chevron-left"></i> Prev
                                </a>
                            <?php endif; ?>
                            
                            <?php
                            $start = max(1, $page - 2);
                            $end = min($totalPages, $page + 2);
                            
                            if ($start > 1): ?>
                                <a href="/admin/logs/index.php?page=1&per_page=<?php echo $perPage; ?>" class="btn btn-secondary">1</a>
                                <?php if ($start > 2): ?>
                                    <span class="pagination-ellipsis">...</span>
                                <?php endif; ?>
                            <?php endif; ?>
                            
                            <?php for ($i = $start; $i <= $end; $i++): ?>
                                <a href="/admin/logs/index.php?page=<?php echo $i; ?>&per_page=<?php echo $perPage; ?>" 
                                   class="btn <?php echo $i === $page ? 'btn-primary' : 'btn-secondary'; ?>">
                                    <?php echo $i; ?>
                                </a>
                            <?php endfor; ?>
                            
                            <?php if ($end < $totalPages): ?>
                                <?php if ($end < $totalPages - 1): ?>
                                    <span class="pagination-ellipsis">...</span>
                                <?php endif; ?>
                                <a href="/admin/logs/index.php?page=<?php echo $totalPages; ?>&per_page=<?php echo $perPage; ?>" 
                                   class="btn btn-secondary"><?php echo $totalPages; ?></a>
                            <?php endif; ?>
                            
                            <?php if ($page < $totalPages): ?>
                                <a href="/admin/logs/index.php?page=<?php echo $page + 1; ?>&per_page=<?php echo $perPage; ?>" 
                                   class="btn btn-secondary">
                                    Next <i class="fas fa-chevron-right"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                        
                        <div class="pagination-info" style="text-align: center; margin-top: 8px; color: #666; font-size: 14px;">
                            Page <?php echo $page; ?> of <?php echo $totalPages; ?> 
                            (<?php echo count($logs); ?> total files)
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php include '../../include/footer.php'; ?>
    
    <!-- Enhanced Popups -->
    <div id="log-view-popup" class="popup" style="display: none;">
        <div class="popup-header">
            <h3 id="log-filename">Log Viewer</h3>
            <i class="fas fa-times popup-close" onclick="hidePopup('log-view-popup')"></i>
        </div>
        <div class="popup-content" style="max-height: 70vh; overflow-y: auto;">
            <div class="loading-spinner" style="text-align: center; padding: 40px;">
                <i class="fas fa-spinner fa-spin"></i> Loading log content...
            </div>
        </div>
        <div class="popup-footer" style="text-align: right; padding: 16px; border-top: 1px solid #ddd;">
            <button class="btn btn-secondary" onclick="hidePopup('log-view-popup')">Close</button>
            <button class="btn btn-primary" id="download-current-log" style="margin-left: 8px;">
                <i class="fas fa-download"></i> Download
            </button>
        </div>
    </div>
    
    <div id="delete-log-popup" class="popup" style="display: none;">
        <div class="popup-header">
            <h3>Confirm Delete</h3>
            <i class="fas fa-times popup-close" onclick="hidePopup('delete-log-popup')"></i>
        </div>
        <div class="popup-content">
            <p>Are you sure you want to delete this log file?</p>
            <p><strong id="delete-filename"></strong></p>
            <p style="color: #e74c3c; font-size: 14px;">
                <i class="fas fa-exclamation-triangle"></i> This action cannot be undone.
            </p>
        </div>
        <div class="popup-footer" style="text-align: right; padding: 16px; border-top: 1px solid #ddd;">
            <button class="btn btn-secondary" onclick="hidePopup('delete-log-popup')">Cancel</button>
            <button class="btn btn-delete" id="confirm-delete-btn" style="margin-left: 8px;">
                <i class="fas fa-trash"></i> Delete
            </button>
        </div>
    </div>
    
    <div class="popup-overlay" style="display: none;"></div>
    
    <script src="/assets/js/admin.js"></script>
    <script>
        // Enhanced log management functions
        let currentLogFile = '';
        
        // Items per page change handler
        document.getElementById('per-page').addEventListener('change', function() {
            const url = new URL(window.location);
            url.searchParams.set('per_page', this.value);
            url.searchParams.set('page', '1');
            window.location = url;
        });

        // Enhanced view log function
        async function viewLog(filename) {
            currentLogFile = filename;
            document.getElementById('log-filename').textContent = filename;
            document.getElementById('download-current-log').onclick = () => downloadLog(filename);
            
            showPopup('log-view-popup');
            
            try {
                const response = await fetch('/admin/logs/view.php', {
                    method: 'POST',
                    headers: { 
                        'Content-Type': 'application/json', 
                        'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content 
                    },
                    body: JSON.stringify({ filename })
                });
                
                const result = await response.json();
                const popupContent = document.querySelector('#log-view-popup .popup-content');
                
                if (result.status === 'success') {
                    popupContent.innerHTML = `
                        <div class="log-content">
                            <div class="log-stats" style="background: #f8f9fa; padding: 12px; margin-bottom: 16px; border-radius: 4px;">
                                <strong>File:</strong> ${filename} | 
                                <strong>Size:</strong> ${result.size} | 
                                <strong>Lines:</strong> ${result.lines}
                            </div>
                            <pre class="log-text" style="
                                background: #1e1e1e; 
                                color: #d4d4d4; 
                                padding: 16px; 
                                border-radius: 4px; 
                                font-family: 'Consolas', 'Monaco', monospace; 
                                font-size: 13px; 
                                line-height: 1.4; 
                                white-space: pre-wrap; 
                                overflow-x: auto;
                            ">${result.content}</pre>
                        </div>
                    `;
                } else {
                    popupContent.innerHTML = `
                        <div class="error-message" style="text-align: center; padding: 40px; color: #e74c3c;">
                            <i class="fas fa-exclamation-triangle" style="font-size: 48px; margin-bottom: 16px;"></i>
                            <h4>Error Loading Log</h4>
                            <p>${result.message}</p>
                        </div>
                    `;
                }
            } catch (error) {
                document.querySelector('#log-view-popup .popup-content').innerHTML = `
                    <div class="error-message" style="text-align: center; padding: 40px; color: #e74c3c;">
                        <i class="fas fa-exclamation-triangle" style="font-size: 48px; margin-bottom: 16px;"></i>
                        <h4>Connection Error</h4>
                        <p>Failed to load log file. Please try again.</p>
                    </div>
                `;
            }
        }

        // Enhanced delete confirmation
        function confirmDeleteLog(filename) {
            currentLogFile = filename;
            document.getElementById('delete-filename').textContent = filename;
            document.getElementById('confirm-delete-btn').onclick = () => deleteLog(filename);
            showPopup('delete-log-popup');
        }

        // Delete log function
        async function deleteLog(filename) {
            try {
                const response = await fetch('/admin/logs/delete.php', {
                    method: 'POST',
                    headers: { 
                        'Content-Type': 'application/json', 
                        'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content 
                    },
                    body: JSON.stringify({ filename })
                });
                
                const result = await response.json();
                
                if (result.status === 'success') {
                    hidePopup('delete-log-popup');
                    // Show success message and reload
                    showNotification('Log file deleted successfully', 'success');
                    setTimeout(() => {
                        window.location.reload();
                    }, 1500);
                } else {
                    showNotification(result.message || 'Failed to delete log file', 'error');
                }
            } catch (error) {
                showNotification('Connection error. Please try again.', 'error');
            }
        }

        // Download log function
        function downloadLog(filename) {
            const link = document.createElement('a');
            link.href = `/admin/logs/download.php?file=${encodeURIComponent(filename)}`;
            link.download = filename;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }

        // Notification system
        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = `notification notification-${type}`;
            notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                padding: 16px 20px;
                background: ${type === 'success' ? '#28a745' : type === 'error' ? '#dc3545' : '#007bff'};
                color: white;
                border-radius: 4px;
                box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                z-index: 10000;
                font-weight: 500;
                max-width: 400px;
            `;
            notification.innerHTML = `
                <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'}"></i>
                ${message}
            `;
            
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.style.opacity = '0';
                notification.style.transform = 'translateX(100%)';
                setTimeout(() => notification.remove(), 300);
            }, 3000);
        }
    </script>
</body>
</html>

<?php
// Helper function for file size formatting
function formatBytes($size, $precision = 2) {
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    
    for ($i = 0; $size > 1024 && $i < count($units) - 1; $i++) {
        $size /= 1024;
    }
    
    return round($size, $precision) . ' ' . $units[$i];
}
?>