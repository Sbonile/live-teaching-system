<?php
$scriptDir = dirname($_SERVER['SCRIPT_NAME']);
$depth = substr_count(trim($scriptDir, '/'), '/');
$basePath = $depth > 0 ? str_repeat('../', $depth) : '';
?>
<footer class="site-footer">
    <div class="container">
        <div style="display:flex; justify-content:space-between; flex-wrap:wrap; gap:15px; margin-bottom:20px;">
            <div>
                <p style="font-size:1.1rem; font-weight:bold; margin-bottom:8px;">🎓 LiveTeach SA</p>
                <p style="opacity:0.6; font-size:0.85rem;">South African Online Learning Platform</p>
            </div>
            <div style="display:flex; gap:20px; flex-wrap:wrap; align-items:center;">
                <a href="<?php echo $basePath; ?>classes/index.php" style="color:var(--cream); opacity:0.7; text-decoration:none; font-size:0.85rem;">Live Classes</a>
                <a href="<?php echo $basePath; ?>register.php" style="color:var(--cream); opacity:0.7; text-decoration:none; font-size:0.85rem;">Register</a>
                <a href="<?php echo $basePath; ?>login.php" style="color:var(--cream); opacity:0.7; text-decoration:none; font-size:0.85rem;">Login</a>
            </div>
        </div>
        <p style="text-align:center; opacity:0.5; font-size:0.8rem;">&copy; <?php echo date('Y'); ?> LiveTeach SA. All rights reserved.</p>
    </div>
</footer>
<script src="<?php echo $basePath; ?>assets/js/app.js"></script>
</body>
</html>
