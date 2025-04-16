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

// Processar edição de orçamento
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Verificar se está adicionando uma nova categoria
    if (isset($_POST['adicionar_categoria'])) {
        $numero_conta = trim($_POST['numero_conta']);
        $descricao = trim($_POST['descricao']);
        $budget = str_replace([',', '€'], ['.', ''], trim($_POST['budget']));
        $usuario_id = $_SESSION['usuario_id'];
        
        // Validar dados
        if (empty($numero_conta) || empty($descricao) || !is_numeric($budget)) {
            $mensagem = "Por favor, preencha todos os campos corretamente.";
        } else {
            // Verificar se o número de conta já existe
            $stmt = $pdo->prepare("SELECT id FROM categorias WHERE projeto_id = :projeto_id AND numero_conta = :numero_conta");
            $stmt->bindParam(':projeto_id', $projeto_id, PDO::PARAM_INT);
            $stmt->bindParam(':numero_conta', $numero_conta, PDO::PARAM_STR);
            $stmt->execute();
            
            if ($stmt->rowCount() > 0) {
                $mensagem = "Este número de conta já existe. Por favor, use outro.";
            } else {
                // Determinar nível e categoria pai
                $nivel = substr_count($numero_conta, '.') + 1;
                $categoria_pai_id = null;
                
                if ($nivel > 1) {
                    $partes = explode('.', $numero_conta);
                    array_pop($partes);
                    $numero_pai = implode('.', $partes);
                    
                    // Buscar categoria pai
                    $stmt = $pdo->prepare("SELECT id FROM categorias WHERE projeto_id = :projeto_id AND numero_conta = :numero_conta");
                    $stmt->bindParam(':projeto_id', $projeto_id, PDO::PARAM_INT);
                    $stmt->bindParam(':numero_conta', $numero_pai, PDO::PARAM_STR);
                    $stmt->execute();
                    $categoria_pai = $stmt->fetch();
                    
                    if ($categoria_pai) {
                        $categoria_pai_id = $categoria_pai['id'];
                    } else {
                        $mensagem = "Categoria pai não encontrada. Certifique-se de criar as categorias em ordem hierárquica.";
                    }
                }
                
                if (empty($mensagem)) {
                    try {
                        $id = inserirCategoria($pdo, $projeto_id, $numero_conta, $descricao, $budget, $categoria_pai_id, $nivel);
                        
                        // Se tem uma categoria pai, atualizar o orçamento da mesma
                        if ($categoria_pai_id) {
                            atualizarBudgetCategoriasPai($pdo, $id, $budget, $usuario_id);
                        }
                        
                        $mensagem = "Categoria adicionada com sucesso!";
                    } catch (PDOException $e) {
                        $mensagem = "Erro ao adicionar categoria: " . $e->getMessage();
                    }
                }
            }
        }
    }
    
    // Verificar se está atualizando o orçamento de uma categoria
    elseif (isset($_POST['atualizar_budget'])) {
        $categoria_id = intval($_POST['categoria_id']);
        $novo_budget = str_replace([',', '€'], ['.', ''], trim($_POST['novo_budget']));
        $motivo = trim($_POST['motivo']);
        $usuario_id = $_SESSION['usuario_id'];
        
        if (!is_numeric($novo_budget)) {
            $mensagem = "Por favor, informe um valor válido para o orçamento.";
        } elseif (empty($motivo)) {
            $mensagem = "Por favor, informe o motivo da alteração.";
        } else {
            try {
                atualizarBudgetCategoria($pdo, $categoria_id, $novo_budget, $usuario_id, $motivo);
                $mensagem = "Orçamento atualizado com sucesso!";
            } catch (PDOException $e) {
                $mensagem = "Erro ao atualizar orçamento: " . $e->getMessage();
            }
        }
    }
}

// Obter todas as categorias do projeto
$stmt = $pdo->prepare("
    SELECT c.id, c.numero_conta, c.descricao, c.budget, c.nivel, c.categoria_pai_id,
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
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar Orçamento - Gestão de Eventos</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .categoria-1 { font-weight: bold; }
        .categoria-2 { padding-left: 20px; }
        .categoria-3 { padding-left: 40px; }
        .categoria-4 { padding-left: 60px; }
        .categoria-5 { padding-left: 80px; }
        .editar-budget {
            cursor: pointer;
            color: #0056b3;
            text-decoration: underline;
        }
        #editar-form {
            display: none;
            margin-top: 20px;
            padding: 20px;
            border: 1px solid #ddd;
            border-radius: 4px;
            background-color: #f9f9f9;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="container">
        <h1>Editar Orçamento</h1>
        <h2>Projeto: <?php echo htmlspecialchars($projeto['nome']); ?></h2>
        
        <?php if (!empty($mensagem)): ?>
            <div class="alert alert-info"><?php echo $mensagem; ?></div>
        <?php endif; ?>
        
        <div class="actions">
            <button id="mostrar-adicionar" class="btn btn-primary">Adicionar Nova Categoria</button>
            <a href="../orcamento/importar.php?projeto_id=<?php echo $projeto_id; ?>" class="btn btn-secondary">Importar Orçamento</a>
            <a href="../relatorios/gerar.php?projeto_id=<?php echo $projeto_id; ?>" class="btn btn-secondary">Ver Relatório</a>
        </div>
        
        <!-- Formulário para adicionar categoria (inicialmente oculto) -->
        <div id="adicionar-form" style="display: none; margin-top: 20px; padding: 20px; border: 1px solid #ddd; border-radius: 4px;">
            <h3>Adicionar Nova Categoria</h3>
            
            <form method="post" action="">
                <div class="form-group">
                    <label for="numero_conta">Número de Conta:</label>
                    <input type="text" id="numero_conta" name="numero_conta" required placeholder="Ex: 1 ou 1.1 ou 1.1.1">
                    <small>Use a notação com pontos para indicar hierarquia (ex: 1.1, 1.1.1)</small>
                </div>
                
                <div class="form-group">
                    <label for="descricao">Descrição:</label>
                    <input type="text" id="descricao" name="descricao" required>
                </div>
                
                <div class="form-group">
                    <label for="budget">Orçamento (€):</label>
                    <input type="text" id="budget" name="budget" placeholder="0.00" required>
                </div>
                
                <button type="submit" name="adicionar_categoria" class="btn btn-primary">Adicionar</button>
                <button type="button" id="cancelar-adicionar" class="btn btn-secondary">Cancelar</button>
            </form>
        </div>
        
        <!-- Formulário para editar orçamento (inicialmente oculto) -->
        <div id="editar-form">
            <h3>Atualizar Orçamento</h3>
            
            <form method="post" action="">
                <input type="hidden" id="categoria_id" name="categoria_id">
                
                <div class="form-group">
                    <label for="categoria_info">Categoria:</label>
                    <div id="categoria_info"></div>
                </div>
                
                <div class="form-group">
                    <label for="budget_atual">Orçamento Atual:</label>
                    <div id="budget_atual"></div>
                </div>
                
                <div class="form-group">
                    <label for="novo_budget">Novo Orçamento (€):</label>
                    <input type="text" id="novo_budget" name="novo_budget" placeholder="0.00" required>
                </div>
                
                <div class="form-group">
                    <label for="motivo">Motivo da Alteração:</label>
                    <textarea id="motivo" name="motivo" rows="3" required></textarea>
                </div>
                
                <button type="submit" name="atualizar_budget" class="btn btn-primary">Atualizar</button>
                <button type="button" id="cancelar-editar" class="btn btn-secondary">Cancelar</button>
            </form>
        </div>
        
        <h3>Categorias Atuais</h3>
        
        <?php if (count($categorias) > 0): ?>
            <table>
                <thead>
                    <tr>
                        <th>Número</th>
                        <th>Descrição</th>
                        <th>Orçamento</th>
                        <th>Despesas</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($categorias as $categoria): ?>
                        <tr class="categoria-<?php echo $categoria['nivel']; ?>">
                            <td><?php echo htmlspecialchars($categoria['numero_conta']); ?></td>
                            <td><?php echo htmlspecialchars($categoria['descricao']); ?></td>
                            <td>
                                <span class="editar-budget" data-id="<?php echo $categoria['id']; ?>" 
                                      data-numero="<?php echo htmlspecialchars($categoria['numero_conta']); ?>" 
                                      data-descricao="<?php echo htmlspecialchars($categoria['descricao']); ?>" 
                                      data-budget="<?php echo $categoria['budget']; ?>">
                                    <?php echo number_format($categoria['budget'], 2, ',', '.'); ?> €
                                </span>
                            </td>
                            <td><?php echo number_format($categoria['total_despesas'], 2, ',', '.'); ?> €</td>
                            <td>
                                <a href="../historico/ver.php?categoria_id=<?php echo $categoria['id']; ?>" class="btn btn-sm">Histórico</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p>Nenhuma categoria encontrada. Adicione uma nova categoria ou importe o orçamento.</p>
        <?php endif; ?>
    </div>
    
    <script>
        // Mostrar/ocultar formulário de adição de categoria
        document.getElementById('mostrar-adicionar').addEventListener('click', function() {
            document.getElementById('adicionar-form').style.display = 'block';
            document.getElementById('editar-form').style.display = 'none';
        });
        
        document.getElementById('cancelar-adicionar').addEventListener('click', function() {
            document.getElementById('adicionar-form').style.display = 'none';
        });
        
        // Mostrar/ocultar formulário de edição de orçamento
        document.querySelectorAll('.editar-budget').forEach(function(element) {
            element.addEventListener('click', function() {
                const id = this.getAttribute('data-id');
                const numero = this.getAttribute('data-numero');
                const descricao = this.getAttribute('data-descricao');
                const budget = this.getAttribute('data-budget');
                
                document.getElementById('categoria_id').value = id;
                document.getElementById('categoria_info').textContent = numero + ' - ' + descricao;
                document.getElementById('budget_atual').textContent = parseFloat(budget).toLocaleString('pt-PT', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' €';
                document.getElementById('novo_budget').value = budget.replace('.', ',');
                
                document.getElementById('editar-form').style.display = 'block';
                document.getElementById('adicionar-form').style.display = 'none';
                
                // Scroll para o formulário
                document.getElementById('editar-form').scrollIntoView({behavior: 'smooth'});
            });
        });
        
        document.getElementById('cancelar-editar').addEventListener('click', function() {
            document.getElementById('editar-form').style.display = 'none';
        });
    </script>
    
    <?php include '../includes/footer.php'; ?>
</body>
</html>