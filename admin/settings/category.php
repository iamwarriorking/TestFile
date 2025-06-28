<?php
require_once '../../config/database.php';
require_once '../../config/security.php';
require_once '../../config/globals.php';
require_once '../../middleware/csrf.php';

startApplicationSession();

// Load category config file correctly
$categoryConfig = require_once '../../config/category.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: " . LOGIN_REDIRECT);
    exit;
}

$stmt = $pdo->prepare("SELECT DISTINCT category FROM products WHERE category IS NOT NULL");
$stmt->execute();
$validCategories = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Get subcategories for AJAX request
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_subcategories' && isset($_GET['category'])) {
    $category = $_GET['category'];
    $stmt = $pdo->prepare("SELECT DISTINCT subcategory FROM products WHERE category = ? AND subcategory IS NOT NULL");
    $stmt->execute([$category]);
    $subcategories = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'subcategories' => $subcategories]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $categories = $input['categories'] ?? [];

    if (count($categories) > 15) {
        echo json_encode(['status' => 'error', 'message' => 'Maximum 15 categories allowed']);
        exit;
    }

    if (count($categories) < 3) {
        echo json_encode(['status' => 'error', 'message' => 'Minimum 3 categories required']);
        exit;
    }

    $newConfig = [];
    foreach ($categories as $cat) {
        if (empty($cat['heading']) || !in_array($cat['category'], $validCategories) || !in_array($cat['platform'], ['Amazon', 'Flipkart'])) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid category data']);
            exit;
        }
        $newConfig[] = [
            'heading' => $cat['heading'],
            'category' => $cat['category'],
            'subcategory' => $cat['subcategory'] ?? '', // Add subcategory support
            'platform' => $cat['platform']
        ];
    }

    file_put_contents('../../config/category.php', "<?php\nreturn " . var_export($newConfig, true) . ";\n?>");
    file_put_contents('../../logs/admin.log', "[" . date('Y-m-d H:i:s') . "] Category settings updated by admin ID {$_SESSION['admin_id']}\n", FILE_APPEND);
    echo json_encode(['status' => 'success', 'message' => 'Categories updated']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include '../../include/header.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Category Settings - AmezPrice</title>
    <link rel="stylesheet" href="/assets/css/admin.css">
    <style>
        .category-grid {
            display: grid;
            grid-template-columns: 2fr 2fr 2fr 1.5fr auto;
            gap: 16px;
            align-items: center;
            margin-bottom: 16px;
            padding: 12px;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            background-color: #f9f9f9;
        }
        
        .category-grid-header {
            display: grid;
            grid-template-columns: 2fr 2fr 2fr 1.5fr auto;
            gap: 16px;
            align-items: center;
            margin-bottom: 16px;
            padding: 12px;
            background-color: #f5f5f5;
            border-radius: 8px;
            font-weight: bold;
            color: #333;
        }
        
        .category-grid input,
        .category-grid select {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            box-sizing: border-box;
        }
        
        .category-grid input:focus,
        .category-grid select:focus {
            outline: none;
            border-color: #007bff;
            box-shadow: 0 0 0 2px rgba(0, 123, 255, 0.25);
        }
        
        .remove-row {
            width: 40px;
            height: 40px;
            padding: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
        }
        
        @media (max-width: 768px) {
            .category-grid,
            .category-grid-header {
                grid-template-columns: 1fr;
                gap: 8px;
            }
            
            .category-grid-header {
                display: none;
            }
        }
    </style>
</head>
<body>
    <?php include '../../include/navbar.php'; ?>
    <div class="admin-container">
        <?php include '../../include/admin_sidebar.php'; ?>
        <div class="admin-content">
            <div class="settings-submenu">
                <a href="/admin/settings/api_ui.php">API & UI</a>
                <a href="/admin/settings/category.php" class="active">Category</a>
                <a href="/admin/settings/telegram.php">Telegram</a>
                <a href="/admin/settings/social_security.php">Social & Security</a>
                <a href="/admin/settings/mail.php">Mail</a>
            </div>
            <h1>Category Settings</h1>
            <div class="card">
                <form id="category-form">
                    <!-- Header Row -->
                    <div class="category-grid-header">
                        <div>Heading</div>
                        <div>Category</div>
                        <div>Subcategory</div>
                        <div>Platform</div>
                        <div>Action</div>
                    </div>
                    
                    <div id="category-rows">
                        <?php foreach ($categoryConfig as $index => $cat): ?>
                            <div class="category-row category-grid" data-index="<?php echo $index; ?>">
                                <input type="text" name="heading[]" value="<?php echo htmlspecialchars($cat['heading']); ?>" placeholder="Enter heading" required>
                                
                                <select name="category[]" required onchange="loadSubcategoriesForRow(this, <?php echo $index; ?>)">
                                    <option value="">Select Category</option>
                                    <?php foreach ($validCategories as $validCat): ?>
                                        <option value="<?php echo htmlspecialchars($validCat); ?>" <?php echo $cat['category'] === $validCat ? 'selected' : ''; ?>><?php echo htmlspecialchars($validCat); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                
                                <select name="subcategory[]" id="subcategory-<?php echo $index; ?>">
                                    <option value="">Select Subcategory</option>
                                    <?php if (!empty($cat['category'])): ?>
                                        <?php
                                        $stmt = $pdo->prepare("SELECT DISTINCT subcategory FROM products WHERE category = ? AND subcategory IS NOT NULL");
                                        $stmt->execute([$cat['category']]);
                                        $subcategories = $stmt->fetchAll(PDO::FETCH_COLUMN);
                                        foreach ($subcategories as $subcat):
                                        ?>
                                            <option value="<?php echo htmlspecialchars($subcat); ?>" <?php echo (isset($cat['subcategory']) && $cat['subcategory'] === $subcat) ? 'selected' : ''; ?>><?php echo htmlspecialchars($subcat); ?></option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                                
                                <select name="platform[]" required>
                                    <option value="Amazon" <?php echo $cat['platform'] === 'Amazon' ? 'selected' : ''; ?>>Amazon</option>
                                    <option value="Flipkart" <?php echo $cat['platform'] === 'Flipkart' ? 'selected' : ''; ?>>Flipkart</option>
                                </select>
                                
                                <div style="display: flex; justify-content: center;">
                                    <?php if ($index >= 3): ?>
                                        <button type="button" class="btn btn-secondary remove-row" title="Remove this category">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <div style="margin-top: 20px;">
                        <button type="button" id="add-row" class="btn btn-secondary" style="margin-right: 16px;">
                            <i class="fas fa-plus"></i> Add Category
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save Categories
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php include '../../include/footer.php'; ?>
    <div id="success-popup" class="popup" style="display: none;">
        <i class="fas fa-times popup-close" onclick="hidePopup('success-popup')"></i>
        <div class="popup-content"></div>
    </div>
    <div id="error-popup" class="popup" style="display: none;">
        <i class="fas fa-times popup-close" onclick="hidePopup('error-popup')"></i>
        <div class="popup-content"></div>
    </div>
    <div class="popup-overlay" style="display: none;"></div>
    <script src="/assets/js/admin.js"></script>
    <script>
        const categoryRows = document.getElementById('category-rows');
        const addRowButton = document.getElementById('add-row');
        const maxRows = 15;
        let rowCount = <?php echo count($categoryConfig); ?>;

        function updateAddButton() {
            addRowButton.style.display = rowCount >= maxRows ? 'none' : 'block';
        }

        // Function to load subcategories for a specific row
        async function loadSubcategoriesForRow(categorySelect, rowIndex) {
            const category = categorySelect.value;
            const subcategorySelect = document.getElementById(`subcategory-${rowIndex}`);
            
            if (!subcategorySelect) return;
            
            if (!category) {
                subcategorySelect.innerHTML = '<option value="">Select Subcategory (Optional)</option>';
                return;
            }
            
            subcategorySelect.innerHTML = '<option value="">Loading...</option>';
            
            try {
                const response = await fetch(`?ajax=get_subcategories&category=${encodeURIComponent(category)}`);
                const data = await response.json();
                
                subcategorySelect.innerHTML = '<option value="">Select Subcategory (Optional)</option>';
                if (data.success && data.subcategories) {
                    data.subcategories.forEach(subcat => {
                        const option = document.createElement('option');
                        option.value = subcat;
                        option.textContent = subcat;
                        subcategorySelect.appendChild(option);
                    });
                }
            } catch (error) {
                console.error('Error loading subcategories:', error);
                subcategorySelect.innerHTML = '<option value="">Error loading subcategories</option>';
            }
        }

        addRowButton.addEventListener('click', () => {
            if (rowCount >= maxRows) return;

            const newRow = document.createElement('div');
            newRow.className = 'category-row category-grid';
            newRow.dataset.index = rowCount;
            newRow.innerHTML = `
                <input type="text" name="heading[]" placeholder="Enter heading" required>
                
                <select name="category[]" required onchange="loadSubcategoriesForRow(this, ${rowCount})">
                    <option value="">Select Category</option>
                    <?php foreach ($validCategories as $validCat): ?>
                        <option value="<?php echo htmlspecialchars($validCat); ?>"><?php echo htmlspecialchars($validCat); ?></option>
                    <?php endforeach; ?>
                </select>
                
                <select name="subcategory[]" id="subcategory-${rowCount}">
                    <option value="">Select Subcategory (Optional)</option>
                </select>
                
                <select name="platform[]" required>
                    <option value="Amazon" selected>Amazon</option>
                    <option value="Flipkart">Flipkart</option>
                </select>
                
                <div style="display: flex; justify-content: center;">
                    <button type="button" class="btn btn-secondary remove-row" title="Remove this category">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            `;
            categoryRows.appendChild(newRow);
            rowCount++;
            updateAddButton();
        });

        categoryRows.addEventListener('click', (e) => {
            if (e.target.classList.contains('remove-row') || e.target.parentElement.classList.contains('remove-row')) {
                const row = e.target.closest('.category-row');
                row.remove();
                rowCount--;
                updateAddButton();
            }
        });

        document.getElementById('category-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(e.target);
            const categories = [];
            const headings = formData.getAll('heading[]');
            const cats = formData.getAll('category[]');
            const subcats = formData.getAll('subcategory[]');
            const platforms = formData.getAll('platform[]');

            for (let i = 0; i < headings.length; i++) {
                categories.push({
                    heading: headings[i],
                    category: cats[i],
                    subcategory: subcats[i] || '',
                    platform: platforms[i]
                });
            }

            try {
                const response = await fetch('/admin/settings/category.php', {
                    method: 'POST',
                    headers: { 
                        'Content-Type': 'application/json', 
                        'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content 
                    },
                    body: JSON.stringify({ categories })
                });
                const result = await response.json();

                if (result.status === 'success') {
                    showPopup('success-popup', `<h3>Success</h3><p>${result.message}</p>`);
                } else {
                    showPopup('error-popup', `<h3>Error</h3><p>${result.message}</p>`);
                }
            } catch (error) {
                console.error('Error saving categories:', error);
                showPopup('error-popup', `<h3>Error</h3><p>Failed to save categories. Please try again.</p>`);
            }
        });

        updateAddButton();
    </script>
</body>
</html>