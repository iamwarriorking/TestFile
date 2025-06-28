<aside class="user-sidebar">
    <a href="/user/dashboard.php" <?php echo basename($_SERVER['PHP_SELF']) === 'dashboard.php' ? 'class="active"' : ''; ?>><i class="fas fa-tachometer-alt"></i> Dashboard</a>
    <a href="/user/favorites.php" <?php echo basename($_SERVER['PHP_SELF']) === 'favorites.php' ? 'class="active"' : ''; ?>><i class="fas fa-heart"></i> Favorites</a>
    <a href="/user/tracking.php" <?php echo basename($_SERVER['PHP_SELF']) === 'tracking.php' ? 'class="active"' : ''; ?>><i class="fas fa-eye"></i> Tracking</a>
    <a href="/user/account.php" <?php echo basename($_SERVER['PHP_SELF']) === 'account.php' ? 'class="active"' : ''; ?>><i class="fas fa-cog"></i> Account</a>
    <a href="/user/profile.php" <?php echo basename($_SERVER['PHP_SELF']) === 'profile.php' ? 'class="active"' : ''; ?>><i class="fas fa-user"></i> My Profile</a>
    <div class="sidebar-spacer"></div>
</aside>
<i class="fas fa-bars user-hamburger"></i>