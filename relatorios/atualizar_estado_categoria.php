<?php
require_once '../config/db.php';
require_once '../includes/functions.php';
verificarLogin();

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['projeto_id']) && isset($_POST['categoria_id']) && isset($_POST['expandido'])) {
    $projeto_id = intval($_POST['projeto_id']);
    $categoria_id = intval($_POST['categoria_id']);
    $expandido = (bool)$_POST['expandido'];
    
    // Inicializar categorias expandidas na sessão se necessário
    if (!isset($_SESSION['categorias_expandidas'])) {
        $_SESSION['categorias_expandidas'] = [];
    }
    if (!isset($_SESSION['categorias_expandidas'][$projeto_id])) {
        $_SESSION['categorias_expandidas'][$projeto_id] = [];
    }
    
    if ($expandido) {
        // Adicionar à lista de expandidos se não estiver
        if (!in_array($categoria_id, $_SESSION['categorias_expandidas'][$projeto_id])) {
            $_SESSION['categorias_expandidas'][$projeto_id][] = $categoria_id;
        }
    } else {
        // Remover da lista de expandidos
        $_SESSION['categorias_expandidas'][$projeto_id] = array_diff(
            $_SESSION['categorias_expandidas'][$projeto_id], 
            [$categoria_id]
        );
    }
    
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Parâmetros inválidos']);
}
?>
