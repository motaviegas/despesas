<?php
require_once '../config/db.php';
require_once '../includes/functions.php';
verificarLogin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['categoria_id'])) {
    $categoria_id = intval($_POST['categoria_id']);
    
    try {
        // Obter informações da categoria
        $stmt = $pdo->prepare("
            SELECT c.id, c.numero_conta, c.descricao, c.budget, c.nivel, c.categoria_pai_id,
                   COALESCE(SUM(d.valor), 0) as total_despesas,
                   (c.budget - COALESCE(SUM(d.valor), 0)) as saldo_disponivel
            FROM categorias c
            LEFT JOIN despesas d ON c.id = d.categoria_id
            WHERE c.id = :categoria_id
            GROUP BY c.id
        ");
        $stmt->bindParam(':categoria_id', $categoria_id, PDO::PARAM_INT);
        $stmt->execute();
        $categoria = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($categoria) {
            // Calcular percentagem de execução
            if ($categoria['budget'] > 0) {
                $categoria['percentagem_execucao'] = ($categoria['total_despesas'] / $categoria['budget']) * 100;
            } else {
                $categoria['percentagem_execucao'] = 0;
            }
            
            // Retornar como JSON
            echo json_encode($categoria);
        } else {
            echo json_encode(['erro' => 'Categoria não encontrada']);
        }
    } catch (PDOException $e) {
        echo json_encode(['erro' => 'Erro ao obter informações: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['erro' => 'Parâmetros inválidos']);
}
?>
