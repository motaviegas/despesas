<?php
require_once '../config/db.php';
require_once '../includes/functions.php';
verificarLogin();

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['termo']) && isset($_POST['projeto_id'])) {
    $termo = trim($_POST['termo']);
    $projeto_id = intval($_POST['projeto_id']);
    
    $categorias = obterCategoriasPorDescricaoOuNumero($pdo, $projeto_id, $termo);
    
    if (count($categorias) > 0) {
        foreach ($categorias as $categoria) {
            echo '<div class="sugestao-categoria" data-id="' . $categoria['id'] . '" data-numero="' . htmlspecialchars($categoria['numero_conta']) . '" data-descricao="' . htmlspecialchars($categoria['descricao']) . '">';
            echo htmlspecialchars($categoria['descricao']) . ' (' . htmlspecialchars($categoria['numero_conta']) . ')';
            echo '</div>';
        }
    } else {
        echo '<div class="sem-resultados">Nenhuma categoria encontrada</div>';
    }
}
?>