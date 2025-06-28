<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="/assets/css/search.css">
</head>
<body>
    <div class="search-card">
        <h2>Find out your product’s price history</h2>
        <form class="search-form">
            <input type="url" class="search-input" placeholder="Paste the product link" required>
            <button type="submit" class="search-button"><i class="fas fa-magnifying-glass"></i></button>
        </form>
        <div>Supported Merchants</div>
        <div class="merchants-container">
            <div class="search-merchants-amazon">
                <img src="/assets/images/logos/amazon.png" alt="Amazon">
            </div>
            <div class="search-merchants-flipkart">
                <img src="/assets/images/logos/flipkart.png" alt="Flipkart">
            </div>
        </div>
    </div>  
    <div id="search-preview-popup" class="popup" style="display: none;">
        <i class="fas fa-times popup-close" onclick="hidePopup('search-preview-popup')"></i>
        <div class="popup-content"></div>
    </div>
    <div id="search-error-popup" class="popup" style="display: none;">
        <i class="fas fa-times popup-close" onclick="hidePopup('search-error-popup')"></i>
        <div class="popup-content"></div>
    </div>  
    <script src="/assets/js/search.js"></script>
</body>
</html>