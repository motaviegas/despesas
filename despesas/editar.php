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

// Obter categorias para o dropdown
$stmt = $pdo->prepare("SELECT id, numero_conta, descricao FROM categorias WHERE projeto_id = :projeto_id ORDER BY numero_conta");
$stmt->bindParam(':projeto_id', $projeto_id, PDO::PARAM_INT);
$stmt->execute();
$categorias = $stmt->fetchAll();

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
        // Verificar se houve alteração no anexo
        $anexo_path = $despesa['anexo_path']; // Manter o anexo existente
        
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
                    // Se já existir um anexo anterior, excluir
                    if (!empty($despesa['anexo_path'])) {
                        $old_file = $upload_dir . $despesa['anexo_path'];
                        if (file_exists($old_file)) {
                            unlink($old_file);
                        }
                    }
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
                
                // Registrar histórico da edição
                $stmt = $pdo->prepare("
                    INSERT INTO historico_edicoes 
                    (tipo_registro, registro_id, projeto_id, categoria_id_anterior, categoria_id_novo, 
                     fornecedor_anterior, fornecedor_novo, tipo_anterior, tipo_novo,
                     valor_anterior, valor_novo, descricao_anterior, descricao_nova,
                     data_despesa_anterior, data_despesa_nova, editado_por, data_edicao) 
                    VALUES 
                    ('despesa', :registro_id, :projeto_id, :categoria_id_anterior, :categoria_id_novo, 
                     :fornecedor_anterior, :fornecedor_novo, :tipo_anterior, :tipo_novo,
                     :valor_anterior, :valor_novo, :descricao_anterior, :descricao_nova,
                     :data_despesa_anterior, :data_despesa_nova, :editado_por, NOW())
                ");
                
                $stmt->bindParam(':registro_id', $despesa_id, PDO::PARAM_INT);
                $stmt->bindParam(':projeto_id', $projeto_id, PDO::PARAM_INT);
                $stmt->bindParam(':categoria_id_anterior', $despesa['categoria_id'], PDO::PARAM_INT);
                $stmt->bindParam(':categoria_id_novo', $categoria_id, PDO::PARAM_INT);
                $stmt->bindParam(':fornecedor_anterior', $despesa['fornecedor'], PDO::PARAM_STR);
                $stmt->bindParam(':fornecedor_novo', $fornecedor, PDO::PARAM_STR);
                $stmt->bindParam(':tipo_anterior', $despesa['tipo'], PDO::PARAM_STR);
                $stmt->bindParam(':tipo_novo', $tipo, PDO::PARAM_STR);
                $stmt->bindParam(':valor_anterior', $despesa['valor'], PDO::PARAM_STR);
                $stmt->bindParam(':valor_novo', $valor, PDO::PARAM_STR);
                $stmt->bindParam(':descricao_anterior', $despesa['descricao'], PDO::PARAM_STR);
                $stmt->bindParam(':descricao_nova', $descricao, PDO::PARAM_STR);
                $stmt->bindParam(':data_despesa_anterior', $despesa['data_despesa'], PDO::PARAM_STR);
                $stmt->bindParam(':data_despesa_nova', $data_despesa, PDO::PARAM_STR);
                $stmt->bindParam(':editado_por', $usuario_id, PDO::PARAM_INT);
                $stmt->execute();
                
                // Obter ou criar fornecedor
                $fornecedor_id = obterOuCriarFornecedor($pdo, $fornecedor);
                
                // Atualizar a despesa
                $stmt = $pdo->prepare("
                    UPDATE despesas SET 
                    categoria_id = :categoria_id,
                    fornecedor_id = :fornecedor_id,
                    tipo = :tipo,
                    valor = :valor,
                    descricao = :descricao,
                    data_despesa = :data_despesa,
                    anexo_path = :anexo_path,
                    ultima_atualizacao = NOW()
                    WHERE id = :id
                ");
                
                $stmt->bindParam(':categoria_id', $categoria_id, PDO::PARAM_INT);
                $stmt->bindParam(':fornecedor_id', $fornecedor_id, PDO::PARAM_INT);
                $stmt->bindParam(':tipo', $tipo, PDO::PARAM_STR);
                $stmt->bindParam(':valor', $valor, PDO::PARAM_STR);
                $stmt->bindParam(':descricao', $descricao, PDO::PARAM_STR);
                $stmt->bindParam(':data_despesa', $data_despesa, PDO::PARAM_STR);
                $stmt->bindParam(':anexo_path', $anexo_path, PDO::PARAM_STR);
                $stmt->bindParam(':id', $despesa_id, PDO::PARAM_INT);
                $stmt->execute();
                
                // Confirmar transação
                $pdo->commit();
                
                // Redirecionar para a lista de despesas com mensagem de sucesso
                header("Location: ../despesas/listar.php?projeto_id=$projeto_id&mensagem=Despesa atualizada com sucesso!");
                exit;
                
            } catch (PDOException $e) {
                // Reverter em caso de erro
                $pdo->rollBack();
                $mensagem = "Erro ao atualizar despesa: " . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar Despesa - Gestão de Eventos</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="container">
        <h1>Editar Despesa</h1>
        <h2>Projeto: <?php echo htmlspecialchars($projeto['nome']); ?></h2>
        
        <?php if (!empty($mensagem)): ?>
            <div class="alert alert-info"><?php echo $mensagem; ?></div>
        <?php endif; ?>
        
        <form method="post" action="" enctype="multipart/form-data">
            <div class="form-group">
                <label for="categoria">Categoria/Subconta:</label>
                <input type="text" id="busca_categoria" placeholder="Digite número ou descrição da conta" value="<?php echo htmlspecialchars($despesa['numero_conta'] . ' - ' . $despesa['categoria_descricao']); ?>">
                <input type="hidden" id="categoria_id" name="categoria_id" value="<?php echo $despesa['categoria_id']; ?>" required>
                <div id="sugestoes_categoria" class="sugestoes"></div>
            </div>
            
            <div class="form-group">
                <label for="fornecedor">Fornecedor:</label>
                <input type="text" id="fornecedor" name="fornecedor" value="<?php echo htmlspecialchars($despesa['fornecedor']); ?>" required>
                <div id="sugestoes_fornecedor" class="sugestoes"></div>
            </div>
            
            <div class="form-group">
                <label for="tipo">Tipo:</label>
                <select id="tipo" name="tipo">
                    <option value="serviço" <?php echo $despesa['tipo'] === 'serviço' ? 'selected' : ''; ?>>Serviço</option>
                    <option value="bem" <?php echo $despesa['tipo'] === 'bem' ? 'selected' : ''; ?>>Bem</option>
                </select>
            </div>
            
            <div class="form-group">
                <label for="valor">Valor (€):</label>
                <input type="text" id="valor" name="valor" placeholder="0.00" value="<?php echo number_format($despesa['valor'], 2, ',', '.'); ?>" required>
            </div>
            
            <div class="form-group">
                <label for="descricao">Descrição:</label>
                <textarea id="descricao" name="descricao" rows="3"><?php echo htmlspecialchars($despesa['descricao']); ?></textarea>
            </div>
            
            <div class="form-group">
                <label for="data_despesa">Data:</label>
                <input type="date" id="data_despesa" name="data_despesa" value="<?php echo $despesa['data_despesa']; ?>">
            </div>
            
            <div class="form-group">
                <label for="anexo">Anexo (Fatura/Recibo):</label>
                <?php if (!empty($despesa['anexo_path'])): ?>
                    <p>Anexo atual: <a href="../assets/arquivos/<?php echo htmlspecialchars($despesa['anexo_path']); ?>" target="_blank">Ver anexo</a></p>
                    <p>Deixe em branco para manter o anexo atual ou escolha um novo arquivo para substituí-lo:</p>
                <?php endif; ?>
                <input type="file" id="anexo" name="anexo" accept=".pdf,.jpg,.jpeg,.png,.gif,.tiff,.webp">
            </div>
            
            <button type="submit" class="btn btn-primary">Salvar Alterações</button>
            <a href="../despesas/listar.php?projeto_id=<?php echo $projeto_id; ?>" class="btn btn-secondary">Cancelar</a>
        </form>
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
