<?php
require_once '../config/db.php';
require_once '../includes/functions.php';
verificarLogin();

$mensagem = '';
$projeto_id = isset($_GET['projeto_id']) ? intval($_GET['projeto_id']) : 0;
$categoria_id = isset($_GET['categoria_id']) ? intval($_GET['categoria_id']) : 0;

// Verificar se o projeto existe
$stmt = $pdo->prepare("SELECT id, nome FROM projetos WHERE id = :id");
$stmt->bindParam(':id', $projeto_id, PDO::PARAM_INT);
$stmt->execute();
$projeto = $stmt->fetch();

if (!$projeto) {
    header('Location: ../projetos/listar.php');
    exit;
}

// Verificar se a categoria existe
$stmt = $pdo->prepare("SELECT id, numero_conta, descricao, budget, nivel FROM categorias WHERE id = :id AND projeto_id = :projeto_id");
$stmt->bindParam(':id', $categoria_id, PDO::PARAM_INT);
$stmt->bindParam(':projeto_id', $projeto_id, PDO::PARAM_INT);
$stmt->execute();
$categoria = $stmt->fetch();

if (!$categoria) {
    header("Location: ../orcamento/editar.php?projeto_id=$projeto_id");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $descricao = trim($_POST['descricao']);
    $budget = str_replace([',', '€'], ['.', ''], trim($_POST['budget']));
    $motivo = trim($_POST['motivo']);
    $usuario_id = $_SESSION['usuario_id'];
    
    // Validar dados
    if (empty($descricao) || !is_numeric($budget)) {
        $mensagem = "Por favor, preencha todos os campos corretamente.";
    } else {
        try {
            // Só permite a edição direta de orçamento para categorias folha (nível >= 3)
            if ($categoria['nivel'] >= 3) {
                atualizarBudgetCategoria($pdo, $categoria_id, $budget, $usuario_id, $motivo);
            }
            
            // Atualizar descrição
            $stmt = $pdo->prepare("UPDATE categorias SET descricao = :descricao WHERE id = :id");
            $stmt->bindParam(':descricao', $descricao, PDO::PARAM_STR);
            $stmt->bindParam(':id', $categoria_id, PDO::PARAM_INT);
            $stmt->execute();
            
            $mensagem = "Categoria atualizada com sucesso!";
        } catch (PDOException $e) {
            $mensagem = "Erro ao atualizar categoria: " . $e->getMessage();
        }
    }
}

// Obter histórico de alterações de budget
$stmt = $pdo->prepare("
    SELECT h.*, u.email as usuario_email
    FROM historico_budget h
    JOIN usuarios u ON h.alterado_por = u.id
    WHERE h.categoria_id = :categoria_id
    ORDER BY h.data_alteracao DESC
    LIMIT 10
");
$stmt->bindParam(':categoria_id', $categoria_id, PDO::PARAM_INT);
$stmt->execute();
$historico = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar Categoria - Gestão de Eventos</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="container">
        <h1>Editar Categoria</h1>
        <h2>Projeto: <?php echo htmlspecialchars($projeto['nome']); ?></h2>
        
        <?php if (!empty($mensagem)): ?>
            <div class="alert alert-info"><?php echo $mensagem; ?></div>
        <?php endif; ?>
        
        <div class="categoria-info">
            <p><strong>Número da conta:</strong> <?php echo htmlspecialchars($categoria['numero_conta']); ?></p>
            <p><strong>Nível:</strong> <?php echo $categoria['nivel']; ?></p>
        </div>
        
        <form method="post" action="">
            <div class="form-group">
                <label for="descricao">Descrição:</label>
                <input type="text" id="descricao" name="descricao" value="<?php echo htmlspecialchars($categoria['descricao']); ?>" required>
            </div>
            
            <div class="form-group">
                <label for="budget">Orçamento (€):</label>
                <input type="text" id="budget" name="budget" value="<?php echo number_format($categoria['budget'], 2, ',', '.'); ?>" <?php echo ($categoria['nivel'] < 3) ? 'readonly' : ''; ?>>
                <?php if ($categoria['nivel'] < 3): ?>
                <small class="info">Este valor é calculado automaticamente como a soma das subcategorias.</small>
                <?php endif; ?>
            </div>
            
            <div class="form-group">
                <label for="motivo">Motivo da alteração:</label>
                <textarea id="motivo" name="motivo" rows="3" <?php echo ($categoria['nivel'] < 3) ? 'readonly' : ''; ?>></textarea>
            </div>
            
            <button type="submit" class="btn btn-primary">Salvar Alterações</button>
            <a href="../orcamento/editar.php?projeto_id=<?php echo $projeto_id; ?>" class="btn btn-secondary">Voltar</a>
        </form>
        
        <?php if (count($historico) > 0): ?>
        <h3>Histórico de Alterações</h3>
        <table>
            <thead>
                <tr>
                    <th>Data</th>
                    <th>Valor Anterior</th>
                    <th>Valor Novo</th>
                    <th>Alterado Por</th>
                    <th>Motivo</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($historico as $item): ?>
                <tr>
                    <td><?php echo date('d/m/Y H:i', strtotime($item['data_alteracao'])); ?></td>
                    <td><?php echo number_format($item['valor_anterior'], 2, ',', '.'); ?> €</td>
                    <td><?php echo number_format($item['valor_novo'], 2, ',', '.'); ?> €</td>
                    <td><?php echo htmlspecialchars($item['usuario_email']); ?></td>
                    <td><?php echo htmlspecialchars($item['motivo']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    
    <?php include '../includes/footer.php'; ?>
</body>
</html>
