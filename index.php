<?php
// Redirecionamento simples para a página de login ou dashboard
session_start();

// Se o usuário já estiver logado, redirecionar para o dashboard
if (isset($_SESSION['usuario_id'])) {
    header('Location: dashboard.php');
    exit;
} else {
    // Caso contrário, redirecionar para a página de login
    header('Location: login.php');
    exit;
}
?>