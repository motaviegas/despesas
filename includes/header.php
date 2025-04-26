<header>
    <nav>
        <div class="logo"><img src="<?php echo getBaseURL(); ?>/assets/img/logo_p.png" alt="Logo" height="40"><?php echo $system_name ?? 'Budget control'; ?></div>
        <ul class="nav-links">
            <li><a href="<?php echo $base_url; ?>/dashboard.php">Dashboard</a></li>
            <li><a href="<?php echo $base_url; ?>/projetos/listar.php">Projetos</a></li>
            <?php if (ehAdmin()): ?>
            <li><a href="<?php echo $base_url; ?>/admin/usuarios.php">Usuários</a></li>
            <?php endif; ?>
            <li><a href="<?php echo $base_url; ?>/logout.php">Sair</a></li>
        </ul>
    </nav>
</header>