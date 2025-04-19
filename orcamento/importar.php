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
        if (isset($_POST['limpar_existentes']) && $_POST['limpar_existentes'] == '1') {
            // Verificar se há despesas associadas às categorias atuais
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as total_despesas 
                FROM despesas d 
                JOIN categorias c ON d.categoria_id = c.id 
                WHERE c.projeto_id = :projeto_id
            ");
            $stmt->bindParam(':projeto_id', $projeto_id, PDO::PARAM_INT);
            $stmt->execute();
            $result = $stmt->fetch();
            
            if ($result['total_despesas'] > 0) {
                $mensagem = "Não é possível limpar as categorias existentes pois há despesas associadas. Por favor, exclua as despesas primeiro ou importe sem limpar.";
            } else {
                // Se não há despesas, podemos excluir as categorias
                $stmt = $pdo->prepare("DELETE FROM categorias WHERE projeto_id = :projeto_id");
                $stmt->bindParam(':projeto_id', $projeto_id, PDO::PARAM_INT);
                $stmt->execute();
            }
        }
        
        // Se não há mensagem de erro, processar importação
        if (empty($mensagem)) {
            // Processar importação
            if (processarImportacaoCSV($pdo, $tmp_name, $projeto_id, $usuario_id)) {
                $mensagem = "Orçamento importado com sucesso!";
                header("Location: ../orcamento/editar.php?projeto_id=$projeto_id&mensagem=Orçamento importado com sucesso!");
                exit;
            } else {
                // Se houver erro específico da importação, mostrar
                if (isset($_SESSION['erro_importacao'])) {
                    $mensagem = "Erro ao processar o arquivo: " . $_SESSION['erro_importacao'];
                    unset($_SESSION['erro_importacao']);
                } else {
                    $mensagem = "Erro ao processar o arquivo CSV.";
                }
            }
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
    <style>
        .exemplo-csv {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            font-family: monospace;
            white-space: pre;
            overflow-x: auto;
        }
        
        .upload-area {
            border: 2px dashed #ddd;
            border-radius: 5px;
            padding: 25px;
            text-align: center;
            margin-bottom: 20px;
            transition: all 0.3s;
            cursor: pointer;
        }
        
        .upload-area:hover {
            border-color: #2062b7;
        }
        
        .upload-area i {
            font-size: 48px;
            color: #6c757d;
            margin-bottom: 15px;
        }
        
        .upload-area p {
            margin: 0;
            color: #6c757d;
        }
        
        .upload-area input[type="file"] {
            display: none;
        }
        
        .upload-message {
            margin-top: 10px;
            display: none;
        }
        
        .upload-preview {
            margin-top: 20px;
            display: none;
        }
        
        .instructions {
            margin-bottom: 30px;
        }
        
        .alert-info {
            margin-bottom: 20px;
        }
        
        .form-options {
            margin-top: 20px;
            margin-bottom: 20px;
        }
        
        .custom-checkbox {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .custom-checkbox input[type="checkbox"] {
            margin-right: 10px;
        }
    </style>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="container">
        <h1>Importar Orçamento</h1>
        <h2>Projeto: <?php echo htmlspecialchars($projeto['nome']); ?></h2>
        
        <?php if (!empty($mensagem)): ?>
            <div class="alert alert-info"><?php echo $mensagem; ?></div>
        <?php endif; ?>
        
        <div class="instructions">
            <h3>Instruções para Importação</h3>
            <p>Importe seu orçamento a partir de um arquivo CSV (valores separados por vírgula, ponto e vírgula ou tabulação).</p>
            <p>O arquivo deve ter o seguinte formato:</p>
            
            <div class="exemplo-csv">Nr (Numero) de conta    DESCRIÇÃO DA RUBRICA    Budget
1    PESSOAL    23,000.00 €
1.1    Direcção (1)    1,000.00 €
1.1.1    Direcção de programação    0.00 €
1.1.2    Direcção de produção    0.00 €
1.1.3    Projeccionistas (2)    0.00 €
1.1.4    Outros    1,000.00 €
1.2    Equipa permanente    9,000.00 €
1.2.1    Produção    5,000.00 €
1.2.2    Comunicação    2,000.00 €
1.2.3    Outros    2,000.00 €</div>
            
            <p><strong>Observações importantes:</strong></p>
            <ul>
                <li>A primeira linha é considerada cabeçalho e será ignorada.</li>
                <li>O formato do orçamento pode usar ponto ou vírgula como separador decimal.</li>
                <li>Serão detectados automaticamente os delimitadores (tab, ponto e vírgula ou vírgula).</li>
                <li>As categorias devem seguir a hierarquia correta com níveis indicados por pontos (ex: 1, 1.1, 1.1.1).</li>
                <li>O orçamento total de categorias de nível superior será automaticamente calculado com base nas subcategorias.</li>
            </ul>
        </div>
        
        <form method="post" action="" enctype="multipart/form-data" id="import-form">
            <div class="upload-area" id="upload-area">
                <i class="fa fa-upload"></i>
                <p>Clique aqui para selecionar um arquivo CSV ou arraste e solte</p>
                <input type="file" id="arquivo_csv" name="arquivo_csv" accept=".csv,.txt">
                <div class="upload-message" id="upload-message"></div>
            </div>
            
            <div class="upload-preview" id="upload-preview">
                <h4>Arquivo selecionado:</h4>
                <p id="file-name"></p>
                <button type="button" class="btn btn-sm btn-secondary" id="change-file">Alterar arquivo</button>
            </div>
            
            <div class="form-options">
                <div class="custom-checkbox">
                    <input type="checkbox" id="limpar_existentes" name="limpar_existentes" value="1">
                    <label for="limpar_existentes">Limpar categorias existentes antes de importar</label>
                </div>
                <p class="form-help">Atenção: Esta opção remove todas as categorias atuais. Só é possível se não houver despesas associadas.</p>
            </div>
            
            <button type="submit" class="btn btn-primary">Importar</button>
            <a href="../orcamento/editar.php?projeto_id=<?php echo $projeto_id; ?>" class="btn btn-secondary">Cancelar</a>
        </form>
        
        <div class="actions" style="margin-top: 30px;">
            <a href="../orcamento/editar.php?projeto_id=<?php echo $projeto_id; ?>" class="btn btn-secondary">Editar Orçamento Manualmente</a>
            <a href="../despesas/registrar.php?projeto_id=<?php echo $projeto_id; ?>" class="btn btn-secondary">Registrar Despesas</a>
            <a href="../relatorios/gerar.php?projeto_id=<?php echo $projeto_id; ?>" class="btn btn-secondary">Ver Relatório</a>
        </div>
    </div>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const uploadArea = document.getElementById('upload-area');
            const fileInput = document.getElementById('arquivo_csv');
            const uploadMessage = document.getElementById('upload-message');
            const uploadPreview = document.getElementById('upload-preview');
            const fileName = document.getElementById('file-name');
            const changeFileBtn = document.getElementById('change-file');
            
            // Lidar com clique na área de upload
            uploadArea.addEventListener('click', function() {
                fileInput.click();
            });
            
            // Atualizar interface quando um arquivo é selecionado
            fileInput.addEventListener('change', function() {
                if (fileInput.files.length) {
                    const file = fileInput.files[0];
                    fileName.textContent = file.name + ' (' + formatBytes(file.size) + ')';
                    uploadArea.style.display = 'none';
                    uploadPreview.style.display = 'block';
                }
            });
            
            // Botão para alterar o arquivo
            changeFileBtn.addEventListener('click', function() {
                fileInput.value = '';
                uploadArea.style.display = 'block';
                uploadPreview.style.display = 'none';
            });
            
            // Aceitar drag & drop
            uploadArea.addEventListener('dragover', function(e) {
                e.preventDefault();
                uploadArea.style.borderColor = '#2062b7';
                uploadArea.style.backgroundColor = '#f0f7ff';
            });
            
            uploadArea.addEventListener('dragleave', function() {
                uploadArea.style.borderColor = '#ddd';
                uploadArea.style.backgroundColor = '';
            });
            
            uploadArea.addEventListener('drop', function(e) {
                e.preventDefault();
                uploadArea.style.borderColor = '#ddd';
                uploadArea.style.backgroundColor = '';
                
                if (e.dataTransfer.files.length) {
                    fileInput.files = e.dataTransfer.files;
                    if (fileInput.files[0].name.endsWith('.csv') || fileInput.files[0].name.endsWith('.txt')) {
                        const file = fileInput.files[0];
                        fileName.textContent = file.name + ' (' + formatBytes(file.size) + ')';
                        uploadArea.style.display = 'none';
                        uploadPreview.style.display = 'block';
                    } else {
                        uploadMessage.textContent = 'Por favor, selecione apenas arquivos CSV ou TXT.';
                        uploadMessage.style.color = '#dc3545';
                        uploadMessage.style.display = 'block';
                    }
                }
            });
            
            // Validação do formulário antes de enviar
            document.getElementById('import-form').addEventListener('submit', function(e) {
                if (!fileInput.files.length) {
                    e.preventDefault();
                    uploadMessage.textContent = 'Por favor, selecione um arquivo para importar.';
                    uploadMessage.style.color = '#dc3545';
                    uploadMessage.style.display = 'block';
                }
            });
            
            // Função para formatar tamanho do arquivo
            function formatBytes(bytes, decimals = 2) {
                if (bytes === 0) return '0 Bytes';
                
                const k = 1024;
                const dm = decimals < 0 ? 0 : decimals;
                const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
                
                const i = Math.floor(Math.log(bytes) / Math.log(k));
                
                return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
            }
        });
    </script>
    
    <?php include '../includes/footer.php'; ?>
</body>
</html>
