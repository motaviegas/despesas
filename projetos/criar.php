<?php
require_once '../config/db.php';
require_once '../includes/functions.php';
verificarLogin();

$mensagem = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $nome = trim($_POST['nome']);
    $descricao = trim($_POST['descricao']);
    $usuario_id = $_SESSION['usuario_id'];
    
    if (empty($nome)) {
        $mensagem = "Por favor, informe o nome do projeto.";
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO projetos (nome, descricao, criado_por) VALUES (:nome, :descricao, :criado_por)");
            $stmt->bindParam(':nome', $nome, PDO::PARAM_STR);
            $stmt->bindParam(':descricao', $descricao, PDO::PARAM_STR);
            $stmt->bindParam(':criado_por', $usuario_id, PDO::PARAM_INT);
            $stmt->execute();
            
            $projeto_id = $pdo->lastInsertId();
            $mensagem = "Projeto criado com sucesso!";
            
            // Redirecionar para a página de importação de orçamento
            header("Location: ../orcamento/importar.php?projeto_id=$projeto_id");
            exit;
        } catch (PDOException $e) {
            $mensagem = "Erro ao criar projeto: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Criar Projeto - Gestão de Eventos</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="container">
        <h1>Criar Novo Projeto</h1>
        
        <?php if (!empty($mensagem)): ?>
            <div class="alert alert-info"><?php echo $mensagem; ?></div>
        <?php endif; ?>
        
        <form method="post" action="">
            <div class="form-group">
                <label for="nome">Nome do Projeto:</label>
                <input type="text" id="nome" name="nome" required>
            </div>
            
            <div class="form-group">
                <label for="descricao">Descrição:</label>
                <textarea id="descricao" name="descricao" rows="4"></textarea>
            </div>
            
            <button type="submit" class="btn btn-primary">Criar Projeto</button>
        </form>
    </div>
    
    <?php include '../includes/footer.php'; ?>
</body>
</html>