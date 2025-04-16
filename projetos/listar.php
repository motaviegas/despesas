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

$stmt = $pdo->prepare("SELECT id, nome, descricao, data_criacao, arquivado 
                      FROM projetos 
                      WHERE criado_por = :usuario_id $sql_filtro
                      ORDER BY arquivado ASC, data_criacao DESC");
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
            <table class="table">
                <thead>
                    <tr>
                        <th>Nome</th>
                        <th>Descrição</th>
                        <th>Data de Criação</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($projetos as $projeto): ?>
                        <tr class="<?php echo $projeto['arquivado'] ? 'projeto-arquivado' : ''; ?>">
                            <td><?php echo htmlspecialchars($projeto['nome']); ?></td>
                            <td><?php echo htmlspecialchars($projeto['descricao']); ?></td>
                            <td><?php echo date('d/m/Y', strtotime($projeto['data_criacao'])); ?></td>
                            <td class="acoes-projeto">
                                <a href="../relatorios/gerar.php?projeto_id=<?php echo $projeto['id']; ?>" class="btn btn-sm">Orçamento</a>
                                <a href="../despesas/registrar.php?projeto_id=<?php echo $projeto['id']; ?>" class="btn btn-sm">Despesas</a>
                                <a href="../relatorios/gerar.php?projeto_id=<?php echo $projeto['id']; ?>" class="btn btn-sm">Relatórios</a>
                                
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
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p>Nenhum projeto encontrado.</p>
        <?php endif; ?>
    </div>
    
    <?php include '../includes/footer.php'; ?>
</body>
</html>
