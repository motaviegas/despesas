<?php
require_once '../config/db.php';
require_once '../includes/functions.php';
verificarLogin();

$mensagem = '';
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

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Verificar se um arquivo foi enviado
    if (isset($_FILES['arquivo_csv']) && $_FILES['arquivo_csv']['error'] == 0) {
        $tmp_name = $_FILES['arquivo_csv']['tmp_name'];
        $usuario_id = $_SESSION['usuario_id'];
        
        // Limpar categorias existentes (opcional, pode querer manter histórico)
        $stmt = $pdo->prepare("DELETE FROM categorias WHERE projeto_id = :projeto_id");
        $stmt->bindParam(':projeto_id', $projeto_id, PDO::PARAM_INT);
        $stmt->execute();
        
        // Processar importação
        if (processarImportacaoCSV($pdo, $tmp_name, $projeto_id, $usuario_id)) {
            $mensagem = "Orçamento importado com sucesso!";
            header("Location: ../projetos/ver.php?projeto_id=$projeto_id");
            exit;
        } else {
            $mensagem = "Erro ao processar o arquivo CSV.";
        }
    } else {
        $mensagem = "Por favor, selecione um arquivo CSV válido.";
    }
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Importar Orçamento - Gestão de Eventos</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="container">
        <h1>Importar Orçamento</h1>
        <h2>Projeto: <?php echo htmlspecialchars($projeto['nome']); ?></h2>
        
        <?php if (!empty($mensagem)): ?>
            <div class="alert alert-info"><?php echo $mensagem; ?></div>
        <?php endif; ?>
        
        <p>Importe seu orçamento a partir de um arquivo CSV (valores separados por tabulação).</p>
        <p>O arquivo deve ter o seguinte formato:</p>
        <pre>
Nr (Numero) de conta    DESCRIÇÃO DA RUBRICA    Budget
1    PESSOAL    23,000.00 €
1.1    Direcção (1)    1,000.00 €
...
        </pre>
        
        <form method="post" action="" enctype="multipart/form-data">
            <div class="form-group">
                <label for="arquivo_csv">Arquivo CSV:</label>
                <input type="file" id="arquivo_csv" name="arquivo_csv" accept=".csv,.txt" required>
            </div>
            
            <button type="submit" class="btn btn-primary">Importar</button>
        </form>
        
        <div class="actions">
            <a href="../orcamento/editar.php?projeto_id=<?php echo $projeto_id; ?>" class="btn btn-secondary">Editar Orçamento Manualmente</a>
            <a href="../despesas/registrar.php?projeto_id=<?php echo $projeto_id; ?>" class="btn btn-secondary">Registrar Despesas</a>
        </div>
    </div>
    
    <?php include '../includes/footer.php'; ?>
</body>
</html>
