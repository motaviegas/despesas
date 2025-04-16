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
$stmt = $pdo->prepare("SELECT id, numero_conta, descricao, nivel FROM categorias WHERE id = :id AND projeto_id = :projeto_id");
$stmt->bindParam(':id', $categoria_id, PDO::PARAM_INT);
$stmt->bindParam(':projeto_id', $projeto_id, PDO::PARAM_INT);
$stmt->execute();
$categoria = $stmt->fetch();

if (!$categoria) {
    header("Location: ../orcamento/editar.php?projeto_id=$projeto_id");
    exit;
}

// Verificar se existem despesas associadas à categoria
$stmt = $pdo->prepare("SELECT COUNT(*) as total FROM despesas WHERE categoria_id = :categoria_id");
$stmt->bindParam(':categoria_id', $categoria_id, PDO::PARAM_INT);
$stmt->execute();
$result = $stmt->fetch();
$tem_despesas = ($result['total'] > 0);

// Verificar se existem subcategorias
$stmt = $pdo->prepare("SELECT COUNT(*) as total FROM categorias WHERE categoria_pai_id = :categoria_id");
$stmt->bindParam(':categoria_id', $categoria_id, PDO::PARAM_INT);
$stmt->execute();
$result = $stmt->fetch();
$tem_subcategorias = ($result['total'] > 0);

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['confirmar_exclusao'])) {
    try {
        // Verificar se é possível excluir
        if ($tem_despesas) {
            $mensagem = "Não é possível excluir esta categoria pois existem despesas associadas a ela.";
        } elseif ($tem_subcategorias) {
            $mensagem = "Não é possível excluir esta categoria pois existem subcategorias associadas a ela.";
        } else {
            // Excluir histórico de budget primeiro (chave estrangeira)
            $stmt = $pdo->prepare("DELETE FROM historico_budget WHERE categoria_id = :categoria_id");
            $stmt->bindParam(':categoria_id', $categoria_id, PDO::PARAM_INT);
            $stmt->execute();
            
            // Excluir categoria
            $stmt = $pdo->prepare("DELETE FROM categorias WHERE id = :id");
            $stmt->bindParam(':id', $categoria_id, PDO::PARAM_INT);
            $stmt->execute();
            
            // Redirecionar para a página de edição de orçamento
            header("Location: ../orcamento/editar.php?projeto_id=$projeto_id&mensagem=Categoria excluída com sucesso!");
            exit;
        }
    } catch (PDOException $e) {
        $mensagem = "Erro ao excluir categoria: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Excluir Categoria - Gestão de Eventos</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .warning {
            background-color: #f8d7da;
            color: #721c24;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="container">
        <h1>Excluir Categoria</h1>
        <h2>Projeto: <?php echo htmlspecialchars($projeto['nome']); ?></h2>
        
        <?php if (!empty($mensagem)): ?>
            <div class="alert alert-danger"><?php echo $mensagem; ?></div>
        <?php endif; ?>
        
        <div class="categoria-info">
            <p><strong>Número da conta:</strong> <?php echo htmlspecialchars($categoria['numero_conta']); ?></p>
            <p><strong>Descrição:</strong> <?php echo htmlspecialchars($categoria['descricao']); ?></p>
            <p><strong>Nível:</strong> <?php echo $categoria['nivel']; ?></p>
        </div>
        
        <?php if ($tem_despesas): ?>
            <div class="warning">
                <p><strong>Atenção:</strong> Esta categoria possui despesas associadas e não pode ser excluída.</p>
                <p>Por favor, exclua ou realoque todas as despesas antes de tentar excluir esta categoria.</p>
            </div>
            <a href="../orcamento/editar.php?projeto_id=<?php echo $projeto_id; ?>" class="btn btn-secondary">Voltar</a>
        <?php elseif ($tem_subcategorias): ?>
            <div class="warning">
                <p><strong>Atenção:</strong> Esta categoria possui subcategorias e não pode ser excluída.</p>
                <p>Por favor, exclua todas as subcategorias antes de tentar excluir esta categoria.</p>
            </div>
            <a href="../orcamento/editar.php?projeto_id=<?php echo $projeto_id; ?>" class="btn btn-secondary">Voltar</a>
        <?php else: ?>
            <div class="warning">
                <p><strong>Atenção:</strong> Você está prestes a excluir esta categoria permanentemente.</p>
                <p>Esta ação não pode ser desfeita.</p>
            </div>
            
            <form method="post" action="">
                <input type="hidden" name="confirmar_exclusao" value="1">
                <button type="submit" class="btn btn-danger">Confirmar Exclusão</button>
                <a href="../orcamento/editar.php?projeto_id=<?php echo $projeto_id; ?>" class="btn btn-secondary">Cancelar</a>
            </form>
        <?php endif; ?>
    </div>
    
    <?php include '../includes/footer.php'; ?>
</body>
</html>
