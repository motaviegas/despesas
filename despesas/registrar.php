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
    $categoria_id = intval($_POST['categoria_id']);
    $fornecedor = trim($_POST['fornecedor']);
    $tipo = $_POST['tipo'];
    $valor = str_replace([',', '€'], ['.', ''], trim($_POST['valor']));
    $descricao = trim($_POST['descricao']);
    $data_despesa = $_POST['data_despesa'] ?: date('Y-m-d');
    $usuario_id = $_SESSION['usuario_id'];
    
    // Validar dados
    if (empty($categoria_id) || empty($fornecedor) || empty($valor)) {
        $mensagem = "Por favor, preencha todos os campos obrigatórios.";
    } else {
        // Processar anexo
        $anexo_path = null;
        if (isset($_FILES['anexo']) && $_FILES['anexo']['error'] == 0) {
            $upload_dir = '../assets/arquivos/';
            
            // Criar o diretório se não existir
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            $file_name = time() . '_' . basename($_FILES['anexo']['name']);
            $target_file = $upload_dir . $file_name;
            
            $file_type = strtolower(pathinfo($target_file, PATHINFO_EXTENSION));
            $allowed_types = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'tiff', 'webp'];
            
            if (in_array($file_type, $allowed_types)) {
                if (move_uploaded_file($_FILES['anexo']['tmp_name'], $target_file)) {
                    $anexo_path = $file_name;
                } else {
                    $mensagem = "Erro ao fazer upload do anexo.";
                }
            } else {
                $mensagem = "Tipo de arquivo não permitido. Utilize PDF ou imagens.";
            }
        }
        
        if (empty($mensagem)) {
            try {
                // Iniciar transação para garantir integridade
                $pdo->beginTransaction();
                
                // Registrar a despesa
                $despesa_id = registrarDespesa($pdo, $projeto_id, $categoria_id, $fornecedor, $tipo, $valor, $descricao, $data_despesa, $usuario_id, $anexo_path);
                
                // Atualizar as somas nas categorias pai
                atualizarSomasDespesasCategoriasPai($pdo, $categoria_id, $valor, $usuario_id);
                
                // Confirmar a transação
                $pdo->commit();
                
                $mensagem = "Despesa registrada com sucesso!";
            } catch (PDOException $e) {
                // Reverter em caso de erro
                $pdo->rollBack();
                $mensagem = "Erro ao registrar despesa: " . $e->getMessage();
            }
        }
    }
}

// Obter categorias para o dropdown - Ordenar por número de conta para facilitar a localização
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
    <title>Registrar Despesa - Gestão de Eventos</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>
<body>
    <?php include '../includes/header.php'; ?>
<div class="container">
    <h1>Registrar Despesa</h1>
    <h2>Projeto: <?php echo htmlspecialchars($projeto['nome']); ?></h2>
    
    <?php if (!empty($mensagem)): ?>
        <div class="alert alert-info"><?php echo $mensagem; ?></div>
    <?php endif; ?>
    
    <form method="post" action="" enctype="multipart/form-data">
        <div class="form-group">
            <label for="categoria">Categoria/Subconta:</label>
            <input type="text" id="busca_categoria" placeholder="Digite número ou descrição da conta">
            <input type="hidden" id="categoria_id" name="categoria_id" required>
            <div id="sugestoes_categoria" class="sugestoes"></div>
        </div>
        
        <div class="form-group">
            <label for="fornecedor">Fornecedor:</label>
            <input type="text" id="fornecedor" name="fornecedor" required>
            <div id="sugestoes_fornecedor" class="sugestoes"></div>
        </div>
        
        <div class="form-group">
            <label for="tipo">Tipo:</label>
            <select id="tipo" name="tipo">
                <option value="serviço">Serviço</option>
                <option value="bem">Bem</option>
            </select>
        </div>
        
        <div class="form-group">
            <label for="valor">Valor (€):</label>
            <input type="text" id="valor" name="valor" placeholder="0.00" required>
        </div>
        
        <div class="form-group">
            <label for="descricao">Descrição:</label>
            <textarea id="descricao" name="descricao" rows="3"></textarea>
        </div>
        
        <div class="form-group">
            <label for="data_despesa">Data:</label>
            <input type="date" id="data_despesa" name="data_despesa" value="<?php echo date('Y-m-d'); ?>">
        </div>
        
        <div class="form-group">
            <label for="anexo">Anexo (Fatura/Recibo):</label>
            <input type="file" id="anexo" name="anexo" accept=".pdf,.jpg,.jpeg,.png,.gif,.tiff,.webp">
        </div>
        
        <button type="submit" class="btn btn-primary">Registrar Despesa</button>
    </form>
    
    <div class="actions">
        <a href="../relatorios/gerar.php?projeto_id=<?php echo $projeto_id; ?>" class="btn btn-secondary">Ver Relatório</a>
    </div>
</div>

<script>
$(document).ready(function() {
    // Autocompletar categoria
    $('#busca_categoria').keyup(function() {
        const termo = $(this).val();
        if (termo.length >= 2) {
            $.ajax({
                url: 'buscar_categorias.php',
                method: 'POST',
                data: { termo: termo, projeto_id: <?php echo $projeto_id; ?> },
                success: function(response) {
                    $('#sugestoes_categoria').html(response);
                }
            });
        } else {
            $('#sugestoes_categoria').html('');
        }
    });
    
    // Selecionar categoria
    $(document).on('click', '.sugestao-categoria', function() {
        $('#busca_categoria').val($(this).data('descricao') + ' (' + $(this).data('numero') + ')');
        $('#categoria_id').val($(this).data('id'));
        $('#sugestoes_categoria').html('');
    });
    
    // Autocompletar fornecedor
    $('#fornecedor').keyup(function() {
        const termo = $(this).val();
        if (termo.length >= 2) {
            $.ajax({
                url: 'buscar_fornecedores.php',
                method: 'POST',
                data: { termo: termo },
                success: function(response) {
                    $('#sugestoes_fornecedor').html(response);
                }
            });
        } else {
            $('#sugestoes_fornecedor').html('');
        }
    });
    
    // Selecionar fornecedor
    $(document).on('click', '.sugestao-fornecedor', function() {
        $('#fornecedor').val($(this).data('nome'));
        $('#sugestoes_fornecedor').html('');
    });
});
</script>

<?php include '../includes/footer.php'; ?>

</body>
</html>
