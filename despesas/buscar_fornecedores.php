<?php
require_once '../config/db.php';
require_once '../includes/functions.php';
verificarLogin();

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['termo'])) {
    $termo = trim($_POST['termo']);
    
    $fornecedores = buscarFornecedoresPorTermo($pdo, $termo);
    
    if (count($fornecedores) > 0) {
        foreach ($fornecedores as $fornecedor) {
            echo '<div class="sugestao-fornecedor" data-id="' . $fornecedor['id'] . '" data-nome="' . htmlspecialchars($fornecedor['nome']) . '">';
            echo htmlspecialchars($fornecedor['nome']);
            echo '</div>';
        }
    } else {
        echo '<div class="sem-resultados">Nenhum fornecedor encontrado. O que você digitar será salvo como novo fornecedor.</div>';
    }
}
?>