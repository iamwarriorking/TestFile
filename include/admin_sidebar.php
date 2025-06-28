<aside class="admin-sidebar">
    <a href="/admin/dashboard.php" <?php echo basename($_SERVER['PHP_SELF']) === 'dashboard.php' ? 'class="active"' : ''; ?>><i class="fas fa-tachometer-alt"></i> Dashboard</a>
    <a href="/admin/products.php" <?php echo basename($_SERVER['PHP_SELF']) === 'products.php' ? 'class="active"' : ''; ?>><i class="fas fa-box"></i> Products</a>
    <a href="/admin/users.php" <?php echo basename($_SERVER['PHP_SELF']) === 'users.php' ? 'class="active"' : ''; ?>><i class="fas fa-users"></i> Users</a>
    <a href="/admin/oos.php" <?php echo basename($_SERVER['PHP_SELF']) === 'oos.php' ? 'class="active"' : ''; ?>><i class="fas fa-ban"></i> OOS</a>
    <a href="/admin/ntp.php" <?php echo basename($_SERVER['PHP_SELF']) === 'ntp.php' ? 'class="active"' : ''; ?>><i class="fas fa-box-open"></i> NTP</a>
    
    <div class="menu-item">
        <div class="menu-heading" onclick="toggleSubmenu(this)">
            <i class="fas fa-bullhorn"></i> Promotion
            <i class="fas fa-chevron-down submenu-arrow"></i>
        </div>
        <div class="submenu">
            <a href="/admin/promotion/channel.php" <?php echo basename($_SERVER['PHP_SELF']) === 'channel.php' ? 'class="active"' : ''; ?>><i class="fas fa-broadcast-tower"></i> Channel</a>
            <a href="/admin/promotion/dms.php" <?php echo basename($_SERVER['PHP_SELF']) === 'dms.php' ? 'class="active"' : ''; ?>><i class="fas fa-envelope"></i> DM</a>
            <a href="/admin/promotion/email.php" <?php echo basename($_SERVER['PHP_SELF']) === 'email.php' ? 'class="active"' : ''; ?>><i class="fas fa-mail-bulk"></i> Email</a>
        </div>
    </div>
    
    <a href="/admin/profile.php" <?php echo basename($_SERVER['PHP_SELF']) === 'profile.php' ? 'class="active"' : ''; ?>><i class="fas fa-user"></i> My Profile</a>
    <a href="/admin/settings/api_ui.php" <?php echo strpos($_SERVER['PHP_SELF'], 'settings') !== false ? 'class="active"' : ''; ?>><i class="fas fa-cog"></i> Settings</a>
    <a href="/admin/logs/index.php" <?php echo strpos($_SERVER['PHP_SELF'], 'logs') !== false ? 'class="active"' : ''; ?>><i class="fas fa-file-alt"></i> Logs</a>
    <div class="sidebar-spacer"></div>
</aside>
<i class="fas fa-bars admin-hamburger"></i>