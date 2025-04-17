<?php
require_once '../config/db.php';
require_once '../includes/functions.php';
verificarLogin();

$mensagem = '';
$projeto_id = isset($_GET['projeto_id']) ? intval($_GET['projeto_id']) : 0;
$despesa_id = isset($_GET['despesa_id']) ? intval($_GET['despesa_id']) : 0;

// Verificar se o projeto existe
$stmt = $pdo->prepare("SELECT id, nome FROM projetos WHERE id = :id");
$stmt->bindParam(':id', $projeto_id, PDO::PARAM_INT);
$stmt->execute();
$projeto = $stmt->fetch();

if (!$projeto) {
    header('Location: ../projetos/listar.php');
    exit;
}

// Verificar se a despesa existe
$stmt = $pdo->prepare("
    SELECT d.*, c.numero_conta, c.descricao as categoria_descricao, f.nome as fornecedor
    FROM despesas d
    JOIN categorias c ON d.categoria_id = c.id
    JOIN fornecedores f ON d.fornecedor_id = f.id
    WHERE d.id = :id AND d.projeto_id = :projeto_id
");
$stmt->bindParam(':id', $despesa_id, PDO::PARAM_INT);
$stmt->bindParam(':projeto_id', $projeto_id, PDO::PARAM_INT);
$stmt->execute();
$despesa = $stmt->fetch();

if (!$despesa) {
    header("Location: ../despesas/listar.php?projeto_id=$projeto_id");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['confirmar_exclusao'])) {
    try {
        // Iniciar transação
        $pdo->beginTransaction();
        
        // Obter dados da despesa para o histórico
        $usuario_id = $_SESSION['usuario_id'];
        $data_exclusao = date('Y-m-d H:i:s');
        
        // Registrar no histórico de exclusões
        $stmt = $pdo->prepare("
            INSERT INTO historico_exclusoes 
            (tipo_registro, registro_id, projeto_id, categoria_id, fornecedor_id, 
             tipo, valor, descricao, data_despesa, excluido_por, data_exclusao, motivo) 
            VALUES 
            ('despesa', :registro_id, :projeto_id, :categoria_id, :fornecedor_id, 
             :tipo, :valor, :descricao, :data_despesa, :excluido_por, :data_exclusao, :motivo)
        ");
        $stmt->bindParam(':registro_id', $despesa_id, PDO::PARAM_INT);
        $stmt->bindParam(':projeto_id', $projeto_id, PDO::PARAM_INT);
        $stmt->bindParam(':categoria_id', $despesa['categoria_id'], PDO::PARAM_INT);
        $stmt->bindParam(':fornecedor_id', $despesa['fornecedor_id'], PDO::PARAM_INT);
        $stmt->bindParam(':tipo', $despesa['tipo'], PDO::PARAM_STR);
        $stmt->bindParam(':valor', $despesa['valor'], PDO::PARAM_STR);
        $stmt->bindParam(':descricao', $despesa['descricao'], PDO::PARAM_STR);
        $stmt->bindParam(':data_despesa', $despesa['data_despesa'], PDO::PARAM_STR);
        $stmt->bindParam(':excluido_por', $usuario_id, PDO::PARAM_INT);
        $stmt->bindParam(':data_exclusao', $data_exclusao, PDO::PARAM_STR);
        $motivo = $_POST['motivo'] ?? 'Não especificado';
        $stmt->bindParam(':motivo', $motivo, PDO::PARAM_STR);
        $stmt->execute();
        
        // Verificar se há anexo e excluir o arquivo
        if (!empty($despesa['anexo_path'])) {
            $anexo_full_path = '../assets/arquivos/' . $despesa['anexo_path'];
            if (file_exists($anexo_full_path)) {
                unlink($anexo_full_path);
            }
        }
        
        // Excluir a despesa
        $stmt = $pdo->prepare("DELETE FROM despesas WHERE id = :id");
        $stmt->bindParam(':id', $despesa_id, PDO::PARAM_INT);
        $stmt->execute();
        
        // Confirmar transação
        $pdo->commit();
        
        // Redirecionar para a lista de despesas com mensagem de sucesso
        header("Location: ../despesas/listar.php?projeto_id=$projeto_id&mensagem=Despesa excluída com sucesso!");
        exit;
        
    } catch (PDOException $e) {
        // Reverter em caso de erro
        $pdo->rollBack();
        $mensagem = "Erro ao excluir despesa: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Excluir Despesa - Gestão de Eventos</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .warning {
            background-color: #f8d7da;
            color: #721c24;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        .despesa-info {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .despesa-info p {
            margin-bottom: 8px;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="container">
        <h1>Excluir Despesa</h1>
        <h2>Projeto: <?php echo htmlspecialchars($projeto['nome']); ?></h2>
        
        <?php if (!empty($mensagem)): ?>
            <div class="alert alert-danger"><?php echo $mensagem; ?></div>
        <?php endif; ?>
        
        <div class="despesa-info">
            <h3>Informações da Despesa</h3>
            <p><strong>Categoria:</strong> <?php echo htmlspecialchars($despesa['numero_conta'] . ' - ' . $despesa['categoria_descricao']); ?></p>
            <p><strong>Fornecedor:</strong> <?php echo htmlspecialchars($despesa['fornecedor']); ?></p>
            <p><strong>Tipo:</strong> <?php echo ucfirst($despesa['tipo']); ?></p>
            <p><strong>Valor:</strong> <?php echo number_format($despesa['valor'], 2, ',', '.'); ?> €</p>
            <p><strong>Data:</strong> <?php echo date('d/m/Y', strtotime($despesa['data_despesa'])); ?></p>
            <p><strong>Descrição:</strong> <?php echo htmlspecialchars($despesa['descricao']); ?></p>
            <?php if (!empty($despesa['anexo_path'])): ?>
                <p><strong>Anexo:</strong> <a href="../assets/arquivos/<?php echo htmlspecialchars($despesa['anexo_path']); ?>" target="_blank">Ver anexo</a></p>
            <?php endif; ?>
        </div>
        
        <div class="warning">
            <p><strong>Atenção:</strong> Você está prestes a excluir esta despesa permanentemente.</p>
            <p>O anexo associado a esta despesa será excluído definitivamente e não poderá ser recuperado.</p>
            <p>Esta ação não pode ser desfeita.</p>
        </div>
        
        <form method="post" action="">
            <div class="form-group">
                <label for="motivo">Motivo da exclusão:</label>
                <textarea id="motivo" name="motivo" rows="3" required></textarea>
            </div>
            
            <input type="hidden" name="confirmar_exclusao" value="1">
            <button type="submit" class="btn btn-danger">Confirmar Exclusão</button>
            <a href="../despesas/listar.php?projeto_id=<?php echo $projeto_id; ?>" class="btn btn-secondary">Cancelar</a>
        </form>
    </div>
    
    <?php include '../includes/footer.php'; ?>
</body>
</html>
