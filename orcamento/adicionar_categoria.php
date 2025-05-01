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
    $numero_conta = trim($_POST['numero_conta']);
    $descricao = trim($_POST['descricao']);
    $budget = str_replace([',', '€'], ['.', ''], trim($_POST['budget']));
    
    // Validar dados
    if (empty($numero_conta) || empty($descricao) || !is_numeric($budget)) {
        $mensagem = "Por favor, preencha todos os campos corretamente.";
    } else {
        try {
            // Determinar nível
            $nivel = substr_count($numero_conta, '.') + 1;
            
            // Encontrar categoria pai automaticamente
            $categoria_pai_id = null;
            if ($nivel > 1) {
                $categoria_pai_id = encontrarCategoriaPai($pdo, $projeto_id, $numero_conta);
                
                if (!$categoria_pai_id) {
                    $mensagem = "Categoria pai não encontrada. Certifique-se de criar as categorias em ordem hierárquica.";
                }
            }
            
            // Se não houve erro na busca da categoria pai, continuar
            if (empty($mensagem)) {
                // Verificar se o número da conta já existe
                $stmt = $pdo->prepare("SELECT id FROM categorias WHERE projeto_id = :projeto_id AND numero_conta = :numero_conta");
                $stmt->bindParam(':projeto_id', $projeto_id, PDO::PARAM_INT);
                $stmt->bindParam(':numero_conta', $numero_conta, PDO::PARAM_STR);
                $stmt->execute();
                
                if ($stmt->rowCount() > 0) {
                    $mensagem = "Este número de conta já existe neste projeto.";
                } else {
                    // Inserir categoria
                    $stmt = $pdo->prepare("INSERT INTO categorias (projeto_id, numero_conta, descricao, budget, categoria_pai_id, nivel) 
                                          VALUES (:projeto_id, :numero_conta, :descricao, :budget, :categoria_pai_id, :nivel)");
                    $stmt->bindParam(':projeto_id', $projeto_id, PDO::PARAM_INT);
                    $stmt->bindParam(':numero_conta', $numero_conta, PDO::PARAM_STR);
                    $stmt->bindParam(':descricao', $descricao, PDO::PARAM_STR);
                    $stmt->bindParam(':budget', $budget, PDO::PARAM_STR);
                    $stmt->bindParam(':categoria_pai_id', $categoria_pai_id, PDO::PARAM_INT);
                    $stmt->bindParam(':nivel', $nivel, PDO::PARAM_INT);
                    $stmt->execute();
                    
                    // Redirecionar para a página de edição de orçamento
                    header("Location: ../orcamento/editar.php?projeto_id=$projeto_id&mensagem=Categoria adicionada com sucesso!");
                    exit;
                }
            }
        } catch (PDOException $e) {
            $mensagem = "Erro ao adicionar categoria: " . $e->getMessage();
        }
    }
}

// Obter categorias para o dropdown
$stmt = $pdo->prepare("SELECT id, numero_conta, descricao FROM categorias WHERE projeto_id = :projeto_id ORDER BY numero_conta");
$stmt->bindParam(':projeto_id', $projeto_id, PDO::PARAM_INT);
$stmt->execute();
$categorias = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Adicionar Categoria - Gestão de Eventos</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .categorias-existentes {
            margin-bottom: 20px;
            padding: 15px;
            background-color: #f8f9fa;
            border-radius: 5px;
        }
        .categoria-item {
            margin-bottom: 5px;
            padding: 5px 0;
            border-bottom: 1px solid #eee;
        }
        .nivel-1 { font-weight: bold; }
        .nivel-2 { margin-left: 20px; }
        .nivel-3 { margin-left: 40px; }
        .nivel-4 { margin-left: 60px; }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="container">
        <h1>Adicionar Nova Categoria</h1>
        <h2>Projeto: <?php echo htmlspecialchars($projeto['nome']); ?></h2>
        
        <?php if (!empty($mensagem)): ?>
            <div class="alert alert-info"><?php echo $mensagem; ?></div>
        <?php endif; ?>
        
        <?php if (count($categorias) > 0): ?>
        <div class="categorias-existentes">
            <h3>Categorias Existentes</h3>
            <p>Para criar subcategorias, use o formato correto (ex: se existe "1", você pode criar "1.1")</p>
            <?php foreach ($categorias as $cat): 
                $nivel_classe = 'nivel-' . (substr_count($cat['numero_conta'], '.') + 1);
            ?>
                <div class="categoria-item <?php echo $nivel_classe; ?>">
                    <strong><?php echo htmlspecialchars($cat['numero_conta']); ?></strong> - 
                    <?php echo htmlspecialchars($cat['descricao']); ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        
        <form method="post" action="">
            <div class="form-group">
                <label for="numero_conta">Número da Conta:</label>
                <input type="text" id="numero_conta" name="numero_conta" placeholder="Ex: 1 ou 1.1 ou 1.1.1" required>
                <small class="info">O formato deve seguir o padrão de hierarquia (ex: 1, 1.1, 1.1.1)</small>
            </div>
            
            <div class="form-group">
                <label for="descricao">Descrição:</label>
                <input type="text" id="descricao" name="descricao" required>
            </div>
            
            <div class="form-group">
                <label for="budget">Orçamento (€):</label>
                <input type="text" id="budget" name="budget" placeholder="0,00" required>
                <small class="info">Para categorias de nível 1 e 2, o orçamento será calculado automaticamente como a soma das subcategorias.</small>
            </div>
            
            <button type="submit" class="btn btn-primary">Adicionar Categoria</button>
            <a href="../orcamento/editar.php?projeto_id=<?php echo $projeto_id; ?>" class="btn btn-secondary">Cancelar</a>
        </form>
    </div>
    
    <?php include '../includes/footer.php'; ?>
</body>
</html>
