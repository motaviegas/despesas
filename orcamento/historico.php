<?php
require_once '../config/db.php';
require_once '../includes/functions.php';
verificarLogin();

$projeto_id = isset($_GET['projeto_id']) ? intval($_GET['projeto_id']) : 0;

// Verificar se o projeto existe
$stmt = $pdo->prepare("SELECT id, nome FROM projetos WHERE id = :id");
$stmt->bindParam(':id', $projeto_id, PDO::PARAM_INT);
$stmt->execute();
$projeto = $stmt->fetch();

if (!$projeto) {
    header('Location: ../projetos/listar.php');
    exit;
}

// Obter histórico de alterações de orçamento para todas as categorias do projeto
$stmt = $pdo->prepare("
    SELECT h.id, h.categoria_id, h.valor_anterior, h.valor_novo, h.data_alteracao, h.motivo,
           c.numero_conta, c.descricao,
           u.email as alterado_por
    FROM historico_budget h
    JOIN categorias c ON h.categoria_id = c.id
    JOIN usuarios u ON h.alterado_por = u.id
    WHERE c.projeto_id = :projeto_id
    ORDER BY h.data_alteracao DESC
");
$stmt->bindParam(':projeto_id', $projeto_id, PDO::PARAM_INT);
$stmt->execute();
$historico = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Histórico de Orçamento - Gestão de Eventos</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="container">
        <h1>Histórico de Alterações de Orçamento</h1>
        <h2>Projeto: <?php echo htmlspecialchars($projeto['nome']); ?></h2>
        
        <div class="actions">
            <a href="../orcamento/editar.php?projeto_id=<?php echo $projeto_id; ?>" class="btn btn-secondary">Editar Orçamento</a>
            <a href="../relatorios/gerar.php?projeto_id=<?php echo $projeto_id; ?>" class="btn btn-secondary">Ver Relatório</a>
            <a href="../projetos/ver.php?projeto_id=<?php echo $projeto_id; ?>" class="btn btn-secondary">Detalhes do Projeto</a>
        </div>
        
        <h3>Registro de Alterações</h3>
        
        <?php if (count($historico) > 0): ?>
            <table>
                <thead>
                    <tr>
                        <th>Data</th>
                        <th>Categoria</th>
                        <th>Valor Anterior</th>
                        <th>Valor Novo</th>
                        <th>Diferença</th>
                        <th>Alterado Por</th>
                        <th>Motivo</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($historico as $alteracao): ?>
                        <?php
                        // Calcular diferença e definir classe CSS
                        $diferenca = $alteracao['valor_novo'] - $alteracao['valor_anterior'];
                        $classe_diferenca = $diferenca >= 0 ? 'positivo' : 'negativo';
                        ?>
                        <tr>
                            <td><?php echo date('d/m/Y H:i', strtotime($alteracao['data_alteracao'])); ?></td>
                            <td>
                                <?php echo htmlspecialchars($alteracao['numero_conta'] . ' - ' . $alteracao['descricao']); ?>
                            </td>
                            <td><?php echo number_format($alteracao['valor_anterior'], 2, ',', '.'); ?> €</td>
                            <td><?php echo number_format($alteracao['valor_novo'], 2, ',', '.'); ?> €</td>
                            <td class="<?php echo $classe_diferenca; ?>">
                                <?php echo ($diferenca >= 0 ? '+' : ''); ?><?php echo number_format($diferenca, 2, ',', '.'); ?> €
                            </td>
                            <td><?php echo htmlspecialchars($alteracao['alterado_por']); ?></td>
                            <td><?php echo htmlspecialchars($alteracao['motivo']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p>Nenhuma alteração de orçamento registrada para este projeto.</p>
        <?php endif; ?>
    </div>
    
    <style>
        .positivo { color: green; }
        .negativo { color: red; }
    </style>
    
    <?php include '../includes/footer.php'; ?>
</body>
</html>