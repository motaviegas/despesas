<?php
require_once '../config/db.php';
require_once '../includes/functions.php';
verificarLogin();

$mensagem = '';
$tipo_mensagem = 'info';
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
        $tipo_mensagem = 'danger';
    } elseif (!is_numeric($valor) || $valor <= 0) {
        $mensagem = "Por favor, informe um valor válido para a despesa.";
        $tipo_mensagem = 'danger';
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
                    $tipo_mensagem = 'danger';
                }
            } else {
                $mensagem = "Tipo de arquivo não permitido. Utilize PDF ou imagens.";
                $tipo_mensagem = 'danger';
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
                
                // Limpar o formulário após o sucesso
                $categoria_id = '';
                $fornecedor = '';
                $tipo = 'serviço';
                $valor = '';
                $descricao = '';
                $data_despesa = date('Y-m-d');
                
            } catch (PDOException $e) {
                // Reverter em caso de erro
                $pdo->rollBack();
                $mensagem = "Erro ao registrar despesa: " . $e->getMessage();
                $tipo_mensagem = 'danger';
            }
        }
    }
}

// Obter categorias para o dropdown - Ordenar por número de conta para facilitar a localização
$stmt = $pdo->prepare("
    SELECT c.id, c.numero_conta, c.descricao, c.budget,
           COALESCE(SUM(d.valor), 0) as total_despesas
    FROM categorias c
    LEFT JOIN despesas d ON c.id = d.categoria_id
    WHERE c.projeto_id = :projeto_id
    GROUP BY c.id
    ORDER BY c.numero_conta
");
$stmt->bindParam(':projeto_id', $projeto_id, PDO::PARAM_INT);
$stmt->execute();
$categorias = $stmt->fetchAll();

// Obter os fornecedores mais frequentes para sugestões iniciais
$stmt = $pdo->prepare("
    SELECT DISTINCT f.nome
    FROM fornecedores f
    JOIN despesas d ON f.id = d.fornecedor_id
    JOIN projetos p ON d.projeto_id = p.id
    WHERE p.criado_por = :usuario_id
    ORDER BY COUNT(d.id) DESC
    LIMIT 10
");
$stmt->bindParam(':usuario_id', $_SESSION['usuario_id'], PDO::PARAM_INT);
$stmt->execute();
$fornecedores_sugeridos = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Obter estatísticas básicas do projeto
$stmt = $pdo->prepare("
    SELECT 
        COUNT(DISTINCT d.id) as total_despesas,
        COALESCE(SUM(d.valor), 0) as total_gasto,
        (SELECT COALESCE(SUM(budget), 0) FROM categorias WHERE projeto_id = :projeto_id AND nivel = 1) as orcamento_total
    FROM 
        despesas d
    WHERE 
        d.projeto_id = :projeto_id2
");
$stmt->bindParam(':projeto_id', $projeto_id, PDO::PARAM_INT);
$stmt->bindParam(':projeto_id2', $projeto_id, PDO::PARAM_INT);
$stmt->execute();
$estatisticas = $stmt->fetch();

// Calcular saldo e percentagem de execução
$saldo = $estatisticas['orcamento_total'] - $estatisticas['total_gasto'];
$percentagem_execucao = ($estatisticas['orcamento_total'] > 0) ? 
    ($estatisticas['total_gasto'] / $estatisticas['orcamento_total']) * 100 : 0;

// Determinar a classe do saldo com base na percentagem
if ($percentagem_execucao <= 50) {
    $barra_classe = "verde";
    $saldo_classe = "positivo";
} elseif ($percentagem_execucao <= 75) {
    $barra_classe = "amarelo";
    $saldo_classe = "positivo";
} elseif ($percentagem_execucao <= 95) {
    $barra_classe = "laranja";
    $saldo_classe = "positivo";
} else {
    if ($percentagem_execucao > 100) {
        $barra_classe = "vermelho-intenso";
        $saldo_classe = "ultrapassado";
    } else {
        $barra_classe = "vermelho";
        $saldo_classe = "negativo";
    }
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registrar Despesa - Gestão de Eventos</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        .form-container {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 25px;
        }
        
        .form-principal {
            background-color: white;
            border-radius: 8px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .sidebar {
            background-color: white;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .resumo-projeto {
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 1px solid #e9ecef;
        }
        
        .resumo-title {
            font-size: 18px;
            margin-bottom: 15px;
            color: #495057;
            font-weight: 500;
        }
        
        .resumo-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
        }
        
        .resumo-label {
            color: #6c757d;
            font-weight: 500;
        }
        
        .resumo-valor {
            font-weight: bold;
            font-family: 'Consolas', monospace;
        }
        
        .fornecedores-sugeridos {
            margin-top: 25px;
        }
        
        .tag-container {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 10px;
        }
        
        .tag {
            display: inline-block;
            padding: 5px 10px;
            background-color: #f0f7ff;
            border-radius: 20px;
            cursor: pointer;
            transition: all 0.2s;
            color: #2062b7;
            font-size: 14px;
        }
        
        .tag:hover {
            background-color: #2062b7;
            color: white;
        }
        
        .sugestoes {
            position: absolute;
            z-index: 1000;
            background: white;
            width: 100%;
            max-height: 200px;
            overflow-y: auto;
            border: 1px solid #ddd;
            border-top: none;
            border-radius: 0 0 4px 4px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        
        .sugestao-categoria, .sugestao-fornecedor, .sem-resultados {
            padding: 10px 15px;
            cursor: pointer;
            transition: background 0.2s;
        }
        
        .sugestao-categoria:hover, .sugestao-fornecedor:hover {
            background-color: #f0f7ff;
        }
        
        .categoria-info {
            display: flex;
            justify-content: space-between;
            padding: 10px;
            background-color: #f8f9fa;
            border-radius: 4px;
            margin-top: 5px;
            font-size: 14px;
        }
        
        .categoria-info.success {
            background-color: #d4edda;
            color: #155724;
        }
        
        .categoria-info.warning {
            background-color: #fff3cd;
            color: #856404;
        }
        
        .categoria-info.danger {
            background-color: #f8d7da;
            color: #721c24;
        }
        
        .campo-obrigatorio label::after {
            content: " *";
            color: #dc3545;
        }
        
        .file-upload-container {
            position: relative;
            overflow: hidden;
            display: inline-block;
            margin-top: 10px;
        }
        
        .file-upload-btn {
            border: 2px dashed #ddd;
            color: #6c757d;
            background-color: white;
            padding: 15px;
            border-radius: 5px;
            font-size: 16px;
            width: 100%;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .file-upload-btn:hover {
            border-color: #2062b7;
            color: #2062b7;
        }
        
        .file-upload-input {
            position: absolute;
            left: 0;
            top: 0;
            opacity: 0;
            width: 100%;
            height: 100%;
            cursor: pointer;
        }
        
        .file-name-display {
            margin-top: 10px;
            padding: 5px 10px;
            background-color: #f0f7ff;
            border-radius: 4px;
            display: none;
        }
        
        .file-name-display i {
            margin-right: 5px;
            color: #2062b7;
        }
        
        .btn-block {
            display: block;
            width: 100%;
        }
        
        @media (max-width: 992px) {
            .form-container {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="container">
        <h1>Registrar Despesa</h1>
        <h2>Projeto: <?php echo htmlspecialchars($projeto['nome']); ?></h2>
        
        <?php if (!empty($mensagem)): ?>
            <div class="alert alert-<?php echo $tipo_mensagem; ?>"><?php echo $mensagem; ?></div>
        <?php endif; ?>
        
        <div class="form-container">
            <div class="form-principal">
                <form method="post" action="" enctype="multipart/form-data" id="form-despesa">
                    <div class="form-group campo-obrigatorio">
                        <label for="busca_categoria">Categoria/Subconta:</label>
                        <input type="text" id="busca_categoria" placeholder="Digite número ou descrição da categoria" 
                               value="<?php echo isset($categoria_descricao) ? htmlspecialchars($categoria_descricao) : ''; ?>">
                        <input type="hidden" id="categoria_id" name="categoria_id" value="<?php echo isset($categoria_id) ? $categoria_id : ''; ?>" required>
                        <div id="sugestoes_categoria" class="sugestoes"></div>
                        <div id="categoria_info" class="categoria-info" style="display: none;"></div>
                    </div>
                    
                    <div class="form-group campo-obrigatorio">
                        <label for="fornecedor">Fornecedor:</label>
                        <input type="text" id="fornecedor" name="fornecedor" 
                               value="<?php echo isset($fornecedor) ? htmlspecialchars($fornecedor) : ''; ?>" required>
                        <div id="sugestoes_fornecedor" class="sugestoes"></div>
                    </div>
                    
                    <div class="form-group">
                        <label for="tipo">Tipo:</label>
                        <select id="tipo" name="tipo">
                            <option value="serviço" <?php echo (isset($tipo) && $tipo === 'serviço') ? 'selected' : ''; ?>>Serviço</option>
                            <option value="bem" <?php echo (isset($tipo) && $tipo === 'bem') ? 'selected' : ''; ?>>Bem</option>
                        </select>
                    </div>
                    
                    <div class="form-group campo-obrigatorio">
                        <label for="valor">Valor (€):</label>
                        <input type="text" id="valor" name="valor" placeholder="0,00" 
                               value="<?php echo isset($valor) ? htmlspecialchars($valor) : ''; ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="descricao">Descrição:</label>
                        <textarea id="descricao" name="descricao" rows="3"><?php echo isset($descricao) ? htmlspecialchars($descricao) : ''; ?></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label for="data_despesa">Data:</label>
                        <input type="date" id="data_despesa" name="data_despesa" 
                               value="<?php echo isset($data_despesa) ? $data_despesa : date('Y-m-d'); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="anexo">Anexo (Fatura/Recibo):</label>
                        <div class="file-upload-container">
                            <div class="file-upload-btn" id="file-upload-label">
                                <i class="fa fa-upload"></i> Clique para selecionar um arquivo
                            </div>
                            <input type="file" id="anexo" name="anexo" class="file-upload-input" 
                                   accept=".pdf,.jpg,.jpeg,.png,.gif,.tiff,.webp">
                        </div>
                        <div class="file-name-display" id="file-name-display"></div>
                        <small>Formatos aceitos: PDF, JPG, JPEG, PNG, GIF, TIFF, WEBP</small>
                    </div>
                    
                    <button type="submit" class="btn btn-primary btn-block">
                        <i class="fa fa-check-circle"></i> Registrar Despesa
                    </button>
                </form>
            </div>
            
            <div class="sidebar">
                <div class="resumo-projeto">
                    <h3 class="resumo-title">Resumo do Projeto</h3>
                    
                    <div class="resumo-item">
                        <span class="resumo-label">Orçamento Total:</span>
                        <span class="resumo-valor"><?php echo number_format($estatisticas['orcamento_total'], 2, ',', '.'); ?> €</span>
                    </div>
                    
                    <div class="resumo-item">
                        <span class="resumo-label">Total de Despesas:</span>
                        <span class="resumo-valor"><?php echo number_format($estatisticas['total_gasto'], 2, ',', '.'); ?> €</span>
                    </div>
                    
                    <div class="resumo-item">
                        <span class="resumo-label">Saldo:</span>
                        <span class="resumo-valor <?php echo $saldo_classe; ?>"><?php echo number_format($saldo, 2, ',', '.'); ?> €</span>
                    </div>
                    
                    <div class="progress-container">
                        <div class="progress-bar <?php echo $barra_classe; ?>" style="width: <?php echo min($percentagem_execucao, 100); ?>%"></div>
                        <span class="progress-text"><?php echo number_format($percentagem_execucao, 1, ',', '.'); ?>%</span>
                    </div>
                </div>
                
                <div class="fornecedores-sugeridos">
                    <h3 class="resumo-title">Fornecedores Recentes</h3>
                    <div class="tag-container">
                        <?php foreach ($fornecedores_sugeridos as $forn): ?>
                            <span class="tag" onclick="selecionarFornecedor('<?php echo htmlspecialchars($forn); ?>')">
                                <?php echo htmlspecialchars($forn); ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <div class="acoes" style="margin-top: 30px;">
                    <a href="../relatorios/gerar.php?projeto_id=<?php echo $projeto_id; ?>" class="btn btn-secondary btn-block">
                        <i class="fa fa-bar-chart"></i> Ver Relatório
                    </a>
                    <a href="../despesas/listar.php?projeto_id=<?php echo $projeto_id; ?>" class="btn btn-secondary btn-block" style="margin-top: 10px;">
                        <i class="fa fa-list"></i> Listar Despesas
                    </a>
                    <a href="../projetos/ver.php?projeto_id=<?php echo $projeto_id; ?>" class="btn btn-secondary btn-block" style="margin-top: 10px;">
                        <i class="fa fa-arrow-left"></i> Voltar ao Projeto
                    </a>
                </div>
            </div>
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
                        $('#sugestoes_categoria').show();
                    }
                });
            } else {
                $('#sugestoes_categoria').html('');
                $('#sugestoes_categoria').hide();
            }
        });
        
        // Selecionar categoria
        $(document).on('click', '.sugestao-categoria', function() {
            const id = $(this).data('id');
            const numero = $(this).data('numero');
            const descricao = $(this).data('descricao');
            
            $('#busca_categoria').val(descricao + ' (' + numero + ')');
            $('#categoria_id').val(id);
            $('#sugestoes_categoria').html('');
            $('#sugestoes_categoria').hide();
            
            // Obter informações detalhadas da categoria para mostrar status
            $.ajax({
                url: 'obter_info_categoria.php',
                method: 'POST',
                data: { categoria_id: id },
                dataType: 'json',
                success: function(data) {
                    if (data) {
                        const percentagem = data.budget > 0 ? (data.total_despesas / data.budget * 100).toFixed(1) : 0;
                        let classe = 'success';
                        
                        if (percentagem > 85 && percentagem <= 95) {
                            classe = 'warning';
                        } else if (percentagem > 95) {
                            classe = 'danger';
                        }
                        
                        $('#categoria_info').html(
                            '<span>Orçamento disponível: ' + formatarMoeda(data.budget - data.total_despesas) + ' €</span>' +
                            '<span>' + percentagem + '% utilizado</span>'
                        );
                        $('#categoria_info').removeClass().addClass('categoria-info ' + classe);
                        $('#categoria_info').show();
                    }
                }
            });
        });
        
        // Fechar sugestões ao clicar fora
        $(document).on('click', function(e) {
            if (!$(e.target).closest('#busca_categoria, #sugestoes_categoria').length) {
                $('#sugestoes_categoria').hide();
            }
            if (!$(e.target).closest('#fornecedor, #sugestoes_fornecedor').length) {
                $('#sugestoes_fornecedor').hide();
            }
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
                        $('#sugestoes_fornecedor').show();
                    }
                });
            } else {
                $('#sugestoes_fornecedor').html('');
                $('#sugestoes_fornecedor').hide();
            }
        });
        
        // Selecionar fornecedor
        $(document).on('click', '.sugestao-fornecedor', function() {
            $('#fornecedor').val($(this).data('nome'));
            $('#sugestoes_fornecedor').html('');
            $('#sugestoes_fornecedor').hide();
        });
        
        // Manipular upload de arquivo
        $('#anexo').on('change', function() {
            const fileName = $(this).val().split('\\').pop();
            if (fileName) {
                $('#file-name-display').html('<i class="fa fa-file"></i> ' + fileName);
                $('#file-name-display').show();
                $('#file-upload-label').text('Arquivo selecionado');
            } else {
                $('#file-name-display').hide();
                $('#file-upload-label').html('<i class="fa fa-upload"></i> Clique para selecionar um arquivo');
            }
        });
        
        // Formatação do campo de valor para moeda
        $('#valor').on('input', function() {
            let valor = $(this).val().replace(/\D/g, '');
            
            if (valor.length > 0) {
                valor = (parseInt(valor, 10) / 100).toFixed(2);
                $(this).val(valor.replace('.', ','));
            }
        });
        
        // Validação do formulário antes de enviar
        $('#form-despesa').on('submit', function(e) {
            let valid = true;
            
            if (!$('#categoria_id').val()) {
                $('#busca_categoria').css('border-color', '#dc3545');
                valid = false;
            } else {
                $('#busca_categoria').css('border-color', '');
            }
            
            if (!$('#fornecedor').val()) {
                $('#fornecedor').css('border-color', '#dc3545');
                valid = false;
            } else {
                $('#fornecedor').css('border-color', '');
            }
            
            if (!$('#valor').val()) {
                $('#valor').css('border-color', '#dc3545');
                valid = false;
            } else {
                $('#valor').css('border-color', '');
            }
            
            if (!valid) {
                e.preventDefault();
                
                // Mostrar mensagem de erro se ainda não existir
                if ($('.alert').length === 0) {
                    $('<div class="alert alert-danger">Por favor, preencha todos os campos obrigatórios.</div>')
                        .insertAfter('h2');
                }
                
                // Scrollar para o topo para mostrar mensagem
                window.scrollTo(0, 0);
            }
        });
    });
    
    // Função para selecionar fornecedor a partir das tags
    function selecionarFornecedor(nome) {
        $('#fornecedor').val(nome);
    }
    
    // Função para formatar valores monetários
    function formatarMoeda(valor) {
        return valor.toLocaleString('pt-PT', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }
    </script>
    
    <!-- Script para criar a função de completar categoria via PHP -->
    <script>
    function criarSugestaoCategorias() {
        const categorias = [
            <?php foreach ($categorias as $cat): ?>
            {
                id: <?php echo $cat['id']; ?>,
                numero: "<?php echo htmlspecialchars($cat['numero_conta']); ?>",
                descricao: "<?php echo htmlspecialchars($cat['descricao']); ?>",
                budget: <?php echo $cat['budget']; ?>,
                total_despesas: <?php echo $cat['total_despesas']; ?>
            },
            <?php endforeach; ?>
        ];
        
        // Adiciona um listener ao input para processar buscas localmente
        $('#busca_categoria').on('input', function() {
            const termo = $(this).val().toLowerCase();
            
            if (termo.length < 2) {
                $('#sugestoes_categoria').hide();
                return;
            }
            
            const resultados = categorias.filter(cat => 
                cat.descricao.toLowerCase().includes(termo) || 
                cat.numero.toLowerCase().includes(termo)
            );
            
            if (resultados.length > 0) {
                let html = '';
                resultados.forEach(cat => {
                    html += `<div class="sugestao-categoria" data-id="${cat.id}" data-numero="${cat.numero}" data-descricao="${cat.descricao}">
                              ${cat.descricao} (${cat.numero})
                            </div>`;
                });
                $('#sugestoes_categoria').html(html);
            } else {
                $('#sugestoes_categoria').html('<div class="sem-resultados">Nenhuma categoria encontrada</div>');
            }
            
            $('#sugestoes_categoria').show();
        });
    }
    
    // Inicializar a busca de categorias offline para melhor desempenho
    $(document).ready(function() {
        criarSugestaoCategorias();
    });
    </script>
    
    <?php include '../includes/footer.php'; ?>
</body>
</html>
