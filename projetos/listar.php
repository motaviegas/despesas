<?php
require_once '../config/db.php';
require_once '../includes/functions.php';
verificarLogin();

$mensagem = '';
$mostrar_arquivados = isset($_GET['mostrar_arquivados']) ? (bool)$_GET['mostrar_arquivados'] : false;

// Processar ação de arquivar/desarquivar
if (isset($_POST['acao']) && isset($_POST['projeto_id'])) {
    $projeto_id = intval($_POST['projeto_id']);
    $acao = $_POST['acao'];
    
    if ($acao === 'arquivar') {
        $stmt = $pdo->prepare("UPDATE projetos SET arquivado = TRUE WHERE id = :id");
        $stmt->bindParam(':id', $projeto_id, PDO::PARAM_INT);
        $stmt->execute();
        $mensagem = "Projeto arquivado com sucesso!";
    } elseif ($acao === 'desarquivar') {
        $stmt = $pdo->prepare("UPDATE projetos SET arquivado = FALSE WHERE id = :id");
        $stmt->bindParam(':id', $projeto_id, PDO::PARAM_INT);
        $stmt->execute();
        $mensagem = "Projeto desarquivado com sucesso!";
    }
}

// Obter projetos do usuário
$usuario_id = $_SESSION['usuario_id'];
$sql_filtro = $mostrar_arquivados ? "" : "AND arquivado = FALSE";

// Melhorado: cálculo correto do orçamento total para cada projeto
$stmt = $pdo->prepare("
    SELECT 
        p.id, p.nome, p.descricao, p.data_criacao, p.arquivado,
        (SELECT COALESCE(SUM(budget), 0) FROM categorias WHERE projeto_id = p.id AND nivel = 1) as orcamento_total,
        (SELECT COALESCE(SUM(valor), 0) FROM despesas WHERE projeto_id = p.id) as total_despesas
    FROM 
        projetos p
    WHERE 
        p.criado_por = :usuario_id $sql_filtro
    ORDER BY 
        p.arquivado ASC, p.data_criacao DESC
");
$stmt->bindParam(':usuario_id', $usuario_id, PDO::PARAM_INT);
$stmt->execute();
$projetos = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Projetos - Gestão de Eventos</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .projeto-arquivado {
            opacity: 0.7;
            background-color: #f8f8f8;
        }
        .toggle-arquivados {
            margin-bottom: 20px;
        }
        .acoes-projeto {
            display: flex;
            gap: 5px;
        }
        .card-projetos {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        .projeto-card {
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            padding: 20px;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        .projeto-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        .projeto-header {
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
        }
        .projeto-titulo {
            font-size: 18px;
            font-weight: bold;
            color: #2062b7;
            margin-bottom: 5px;
        }
        .projeto-data {
            color: #6c757d;
            font-size: 14px;
        }
        .projeto-descricao {
            margin-bottom: 15px;
            color: #495057;
        }
        .financeiro-info {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
        }
        .orcamento-valor {
            font-weight: bold;
        }
        .projeto-acoes {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 15px;
        }
        .badge-arquivado {
            display: inline-block;
            background-color: #f8f9fa;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 12px;
            color: #6c757d;
            margin-left: 8px;
        }
        .sem-projetos {
            text-align: center;
            padding: 30px;
            background-color: #f8f9fa;
            border-radius: 8px;
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="container">
        <h1>Projetos</h1>
        
        <?php if (!empty($mensagem)): ?>
            <div class="alert alert-info"><?php echo $mensagem; ?></div>
        <?php endif; ?>
        
        <div class="actions">
            <a href="criar.php" class="btn btn-primary">Novo Projeto</a>
        </div>
        
        <div class="toggle-arquivados">
            <?php if ($mostrar_arquivados): ?>
                <a href="?mostrar_arquivados=0" class="btn btn-sm">Ocultar Projetos Arquivados</a>
            <?php else: ?>
                <a href="?mostrar_arquivados=1" class="btn btn-sm">Mostrar Projetos Arquivados</a>
            <?php endif; ?>
        </div>
        
        <?php if (count($projetos) > 0): ?>
            <div class="card-projetos">
                <?php foreach ($projetos as $projeto): ?>
                    <div class="projeto-card <?php echo $projeto['arquivado'] ? 'projeto-arquivado' : ''; ?>">
                        <div class="projeto-header">
                            <div class="projeto-titulo">
                                <?php echo htmlspecialchars($projeto['nome']); ?>
                                <?php if ($projeto['arquivado']): ?>
                                    <span class="badge-arquivado">Arquivado</span>
                                <?php endif; ?>
                            </div>
                            <div class="projeto-data">Criado em: <?php echo date('d/m/Y', strtotime($projeto['data_criacao'])); ?></div>
                        </div>
                        
                        <div class="projeto-descricao"><?php echo htmlspecialchars($projeto['descricao']); ?></div>
                        
                        <div class="financeiro-info">
                            <span>Orçamento Total:</span>
                            <span class="orcamento-valor"><?php echo number_format($projeto['orcamento_total'], 2, ',', '.'); ?> €</span>
                        </div>
                        
                        <div class="financeiro-info">
                            <span>Total Despesas:</span>
                            <span class="orcamento-valor"><?php echo number_format($projeto['total_despesas'], 2, ',', '.'); ?> €</span>
                        </div>
                        
                        <div class="financeiro-info">
                            <span>Saldo:</span>
                            <span class="orcamento-valor <?php echo ($projeto['orcamento_total'] - $projeto['total_despesas'] >= 0) ? 'positivo' : 'negativo'; ?>">
                                <?php echo number_format($projeto['orcamento_total'] - $projeto['total_despesas'], 2, ',', '.'); ?> €
                            </span>
                        </div>
                        
                        <div class="projeto-acoes">
                            <a href="../relatorios/gerar.php?projeto_id=<?php echo $projeto['id']; ?>" class="btn btn-sm">Orçamento</a>
                            <a href="../despesas/registrar.php?projeto_id=<?php echo $projeto['id']; ?>" class="btn btn-sm">Despesas</a>
                            <a href="../relatorios/gerar.php?projeto_id=<?php echo $projeto['id']; ?>" class="btn btn-sm">Relatórios</a>
                            <a href="ver.php?projeto_id=<?php echo $projeto['id']; ?>" class="btn btn-sm">Detalhes</a>
                            
                            <form method="post" action="" style="display: inline;">
                                <input type="hidden" name="projeto_id" value="<?php echo $projeto['id']; ?>">
                                <?php if ($projeto['arquivado']): ?>
                                    <input type="hidden" name="acao" value="desarquivar">
                                    <button type="submit" class="btn btn-sm btn-secondary">Desarquivar</button>
                                <?php else: ?>
                                    <input type="hidden" name="acao" value="arquivar">
                                    <button type="submit" class="btn btn-sm btn-secondary">Arquivar</button>
                                <?php endif; ?>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="sem-projetos">
                <p>Nenhum projeto <?php echo $mostrar_arquivados ? '' : 'ativo'; ?> encontrado.</p>
                <p><a href="criar.php" class="btn btn-primary">Criar Novo Projeto</a></p>
            </div>
        <?php endif; ?>
    </div>
    
    <?php include '../includes/footer.php'; ?>
</body>
</html>
