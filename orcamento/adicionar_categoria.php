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
    $categoria_pai_id = !empty($_POST['categoria_pai_id']) ? intval($_POST['categoria_pai_id']) : null;
    
    // Validar dados
    if (empty($numero_conta) || empty($descricao) || !is_numeric($budget)) {
        $mensagem = "Por favor, preencha todos os campos corretamente.";
    } else {
        try {
            // Determinar nível
            $nivel = 1; // Padrão para categoria raiz
            
            if ($categoria_pai_id) {
                // Obter nível da categoria pai
                $stmt = $pdo->prepare("SELECT nivel FROM categorias WHERE id = :id");
                $stmt->bindParam(':id', $categoria_pai_id, PDO::PARAM_INT);
                $stmt->execute();
                $categoria_pai = $stmt->fetch();
                
                if ($categoria_pai) {
                    $nivel = $categoria_pai['nivel'] + 1;
                }
            } else {
                // Se não tem pai, determinar nível pelo número de pontos
                $nivel = substr_count($numero_conta, '.') + 1;
            }
            
            // Verificar se o número da conta já existe
            $stmt = $pdo->prepare("SELECT id FROM categorias WHERE projeto_id = :projeto_id AND numero_conta = :numero_conta");
            $stmt->bindParam(':projeto_id', $projeto_id, PDO::PARAM_INT);
            $stmt->bindParam(':numero_conta', $numero_conta, PDO::PARAM_STR);
            $stmt->execute();
            
            if ($stmt->rowCount() > 0) {
                $mensagem = "Este número de conta já existe neste projeto.";
            } else {
                // Inserir categoria
                $id = inserirCategoria($pdo, $projeto_id, $numero_conta, $descricao, $budget, $categoria_pai_id, $nivel);
                
                // Redirecionar para a página de edição de orçamento
                header("Location: ../orcamento/editar.php?projeto_id=$projeto_id&mensagem=Categoria adicionada com sucesso!");
                exit;
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
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="container">
        <h1>Adicionar Nova Categoria</h1>
        <h2>Projeto: <?php echo htmlspecialchars($projeto['nome']); ?></h2>
        
        <?php if (!empty($mensagem)): ?>
            <div class="alert alert-info"><?php echo $mensagem; ?></div>
        <?php endif; ?>
        
        <form method="post" action="">
            <div class="form-group">
                <label for="numero_conta">Número da Conta:</label>
                <input type="text" id="numero_conta" name="numero_conta" placeholder="Ex: 1.4.5" required>
                <small class="info">O formato deve seguir o padrão de hierarquia (ex: 1, 1.1, 1.1.1)</small>
            </div>
            
            <div class="form-group">
                <label for="descricao">Descrição:</label>
                <input type="text" id="descricao" name="descricao" required>
            </div>
            
            <div class="form-group">
                <label for="budget">Orçamento (€):</label>
                <input type="text" id="budget" name="budget" placeholder="0,00" required>
            </div>
            
            <div class="form-group">
                <label for="categoria_pai_id">Categoria Pai (opcional):</label>
                <select id="categoria_pai_id" name="categoria_pai_id">
                    <option value="">Nenhuma (categoria raiz)</option>
                    <?php foreach ($categorias as $categoria): ?>
                        <option value="<?php echo $categoria['id']; ?>"><?php echo htmlspecialchars($categoria['numero_conta'] . ' - ' . $categoria['descricao']); ?></option>
                    <?php endforeach; ?>
                </select>
                <small class="info">Se selecionada, o nível será determinado automaticamente</small>
            </div>
            
            <button type="submit" class="btn btn-primary">Adicionar Categoria</button>
            <a href="../orcamento/editar.php?projeto_id=<?php echo $projeto_id; ?>" class="btn btn-secondary">Cancelar</a>
        </form>
    </div>
    
    <?php include '../includes/footer.php'; ?>
</body>
</html>
