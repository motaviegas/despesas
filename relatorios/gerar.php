<?php
require_once '../config/db.php';
require_once '../includes/functions.php';
verificarLogin();

$projeto_id = isset($_GET['projeto_id']) ? intval($_GET['projeto_id']) : 0;
$mensagem = isset($_GET['mensagem']) ? $_GET['mensagem'] : '';

// Verificar se o projeto existe
$stmt = $pdo->prepare("SELECT id, nome FROM projetos WHERE id = :id");
$stmt->bindParam(':id', $projeto_id, PDO::PARAM_INT);
$stmt->execute();
$projeto = $stmt->fetch();

if (!$projeto) {
    header('Location: ../dashboard.php');
    exit;
}

// Inicializar categorias expandidas na sessão se necessário
if (!isset($_SESSION['categorias_expandidas'])) {
    $_SESSION['categorias_expandidas'] = [];
}
if (!isset($_SESSION['categorias_expandidas'][$projeto_id])) {
    $_SESSION['categorias_expandidas'][$projeto_id] = [];
}

// Processar ação de toggle para expandir/colapsar categoria
if (isset($_GET['toggle'])) {
    $toggle_id = intval($_GET['toggle']);
    
    if (in_array($toggle_id, $_SESSION['categorias_expandidas'][$projeto_id])) {
        // Remover da lista de expandidos
        $_SESSION['categorias_expandidas'][$projeto_id] = array_diff(
            $_SESSION['categorias_expandidas'][$projeto_id], 
            [$toggle_id]
        );
    } else {
        // Adicionar à lista de expandidos
        $_SESSION['categorias_expandidas'][$projeto_id][] = $toggle_id;
    }
    
    // Redirecionar para limpar a URL
    header("Location: gerar.php?projeto_id=$projeto_id");
    exit;
}

// Obter categorias e calcular totais
$categorias = obterCategoriasDespesas($pdo, $projeto_id);
$categorias_com_totais = calcularTotaisCategoriasDespesas($categorias);

// Organizar categorias por pai para facilitar a renderização
$categorias_por_pai = [];
foreach ($categorias_com_totais as $categoria) {
    if ($categoria['categoria_pai_id'] !== null) {
        if (!isset($categorias_por_pai[$categoria['categoria_pai_id']])) {
            $categorias_por_pai[$categoria['categoria_pai_id']] = [];
        }
        $categorias_por_pai[$categoria['categoria_pai_id']][] = $categoria['id'];
    }
}

// Verificar se estamos visualizando despesas de uma categoria
$ver_despesas_id = isset($_GET['ver_despesas']) ? intval($_GET['ver_despesas']) : 0;
$despesas_categoria = [];
if ($ver_despesas_id > 0) {
    $despesas_categoria = obterDespesasPorCategoria($pdo, $ver_despesas_id);
    
    // Obter informações da categoria para exibir no título
    $stmt = $pdo->prepare("SELECT numero_conta, descricao FROM categorias WHERE id = :id");
    $stmt->bindParam(':id', $ver_despesas_id, PDO::PARAM_INT);
    $stmt->execute();
    $categoria_despesas = $stmt->fetch();
}

// Exportar para CSV se solicitado
if (isset($_GET['exportar']) && $_GET['exportar'] == 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="relatorio_' . $projeto_id . '_' . date('Ymd') . '.csv"');
    
    gerarCSVRelatorio($pdo, $projeto_id, $categorias_com_totais);
    exit;
}

// Exportar para Excel se solicitado
if (isset($_GET['exportar']) && $_GET['exportar'] == 'excel') {
    // Implementar exportação para Excel aqui
    header('Location: gerar.php?projeto_id=' . $projeto_id . '&mensagem=Exportação para Excel será implementada em breve.');
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Relatório de Orçamento - Gestão de Eventos</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        .toggle-icon {
            color: #0056b3;
            cursor: pointer;
            display: inline-block;
            width: 20px;
            text-align: center;
            font-weight: bold;
        }
        .ver-despesas {
            color: #0056b3;
            text-decoration: underline;
            cursor: pointer;
            margin-left: 10px;
        }
        .nivel-1 { font-weight: bold; }
        .nivel-2 { padding-left: 20px; }
        .nivel-3 { padding-left: 40px; }
        .nivel-4 { padding-left: 60px; }
        .nivel-5 { padding-left: 80px; }
        .despesas-tabela {
            width: 100%;
            margin-top: 15px;
            margin-bottom: 15px;
        }
        .oculto {
            display: none;
        }
        .subcategoria-container {
            padding-left: 20px;
        }
        .expandir {
            cursor: pointer;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="container">
        <h1>Relatório de Orçamento</h1>
        <h2>Projeto: <?php echo htmlspecialchars($projeto['nome']); ?></h2>
        
        <?php if (!empty($mensagem)): ?>
            <div class="alert alert-info"><?php echo $mensagem; ?></div>
        <?php endif; ?>
        
        <div class="actions">
            <a href="?projeto_id=<?php echo $projeto_id; ?>&exportar=csv" class="btn">Exportar CSV</a>
            <a href="?projeto_id=<?php echo $projeto_id; ?>&exportar=excel" class="btn">Exportar Excel</a>
            <a href="../orcamento/editar.php?projeto_id=<?php echo $projeto_id; ?>" class="btn btn-sm">Editar Orçamento</a>
            <a href="../despesas/registrar.php?projeto_id=<?php echo $projeto_id; ?>" class="btn btn-sm">Nova Despesa</a>
            <a href="../orcamento/importar.php?projeto_id=<?php echo $projeto_id; ?>" class="btn btn-sm">Importar Correção</a>
            <a href="../projetos/ver.php?projeto_id=<?php echo $projeto_id; ?>" class="btn btn-sm">Detalhes</a>
        </div>
        
        <?php if ($ver_despesas_id > 0 && isset($categoria_despesas)): ?>
            <div class="despesas-container">
                <h3>Despesas: <?php echo htmlspecialchars($categoria_despesas['numero_conta'] . ' - ' . $categoria_despesas['descricao']); ?></h3>
                
                <?php if (count($despesas_categoria) > 0): ?>
                    <table class="despesas-tabela">
                        <thead>
                            <tr>
                                <th>Data</th>
                                <th>Tipo</th>
                                <th>Fornecedor</th>
                                <th>Descrição</th>
                                <th>Valor</th>
                                <th>Anexo</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($despesas_categoria as $index => $despesa): 
                                $classe = $index % 2 == 0 ? 'even-row' : 'odd-row';
                            ?>
                            <tr class="<?php echo $classe; ?>">
                                <td><?php echo date('d/m/Y', strtotime($despesa['data_despesa'])); ?></td>
                                <td><?php echo ucfirst($despesa['tipo']); ?></td>
                                <td><?php echo htmlspecialchars($despesa['fornecedor']); ?></td>
                                <td><?php echo htmlspecialchars($despesa['descricao']); ?></td>
                                <td><?php echo number_format($despesa['valor'], 2, ',', '.'); ?> €</td>
                                <td>
                                    <?php if (!empty($despesa['anexo_path'])): ?>
                                        <a href="../assets/arquivos/<?php echo $despesa['anexo_path']; ?>" target="_blank">Fatura</a>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p>Não há despesas registradas para esta categoria.</p>
                <?php endif; ?>
                
                <a href="?projeto_id=<?php echo $projeto_id; ?>" class="btn">Voltar ao Relatório</a>
            </div>
        <?php else: ?>
            <table class="relatorio">
                <thead>
                    <tr>
                        <th>Nr de conta</th>
                        <th>DESCRIÇÃO DA RUBRICA</th>
                        <th>Tot Despesa</th>
                        <th>Delta Budget</th>
                        <th>Budget</th>
                    </tr>
                </thead>
                <tbody id="categorias-container">
                    <?php if (isset($categorias_com_totais[0])): // Total Global ?>
                        <tr class="categoria-0">
                            <td>TOTAL</td>
                            <td>TOTAL GLOBAL</td>
                            <td><?php echo number_format($categorias_com_totais[0]['total_despesas'], 2, ',', '.'); ?> €</td>
                            <td class="<?php echo $categorias_com_totais[0]['delta'] >= 0 ? 'positivo' : 'negativo'; ?>">
                                <?php echo number_format($categorias_com_totais[0]['delta'], 2, ',', '.'); ?> €
                            </td>
                            <td><?php echo number_format($categorias_com_totais[0]['budget'], 2, ',', '.'); ?> €</td>
                        </tr>
                    <?php endif; ?>
                    
                    <?php 
                    // Função para renderizar categorias de forma recursiva
                    function renderizarCategorias($categorias, $categorias_por_pai, $nivel, $categorias_expandidas, $projeto_id) {
                        foreach ($categorias as $categoria) {
                            if ($categoria['nivel'] != $nivel) continue;
                            if ($categoria['id'] == 0) continue; // Pular o total global
                            
                            $expandida = in_array($categoria['id'], $categorias_expandidas);
                            $tem_filhos = isset($categorias_por_pai[$categoria['id']]);
                            $classe_delta = $categoria['delta'] >= 0 ? 'positivo' : 'negativo';
                            $classe_nivel = "nivel-{$nivel}";
                            
                            echo "<tr class='categoria-row' id='categoria-{$categoria['id']}'>";
                            echo "<td>{$categoria['numero_conta']}</td>";
                            echo "<td class='{$classe_nivel}'>";
                            
                            if ($tem_filhos) {
                                $simbolo = $expandida ? '−' : '+';
                                echo "<span class='toggle-icon expandir' data-id='{$categoria['id']}'>{$simbolo}</span> ";
                            } else {
                                echo "<span class='toggle-icon'></span>";
                            }
                            
                            echo htmlspecialchars($categoria['descricao']);
                            
                            // Adicionar link "Ver despesas" para categorias de nível 3 ou superior
                            if ($nivel >= 3) {
                                echo " <a href='?projeto_id={$projeto_id}&ver_despesas={$categoria['id']}' class='ver-despesas'>(Ver despesas)</a>";
                            }
                            
                            echo "</td>";
                            echo "<td>" . number_format($categoria['total_despesas'], 2, ',', '.') . " €</td>";
                            echo "<td class='{$classe_delta}'>" . number_format($categoria['delta'], 2, ',', '.') . " €</td>";
                            echo "<td>" . number_format($categoria['budget'], 2, ',', '.') . " €</td>";
                            echo "</tr>";
                            
                            // Criar linha para subcategorias (inicialmente oculta)
                            echo "<tr class='subcategorias-row subcategorias-{$categoria['id']} " . ($expandida ? '' : 'oculto') . "'>";
                            echo "<td colspan='5'><div class='subcategoria-container' id='subcategoria-container-{$categoria['id']}'>";
                            
                            // Se a categoria estiver expandida, renderizar seus filhos
                            if ($tem_filhos && $expandida) {
                                $filhos = array_filter($categorias, function($cat) use ($categoria) {
                                    return $cat['categoria_pai_id'] == $categoria['id'];
                                });
                                
                                renderizarCategorias($filhos, $categorias_por_pai, $nivel + 1, $categorias_expandidas, $projeto_id);
                            }
                            
                            echo "</div></td></tr>";
                        }
                    }
                    
                    // Obter categorias de nível 1
                    $categorias_nivel1 = array_filter($categorias_com_totais, function($cat) {
                        return $cat['nivel'] == 1;
                    });
                    
                    // Renderizar categorias começando pelo nível 1
                    renderizarCategorias(
                        $categorias_nivel1, 
                        $categorias_por_pai, 
                        1, 
                        $_SESSION['categorias_expandidas'][$projeto_id] ?? [], 
                        $projeto_id
                    );
                    ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    
    <script>
    $(document).ready(function() {
        // Manipular clique nos ícones de expansão
        $(document).on('click', '.toggle-icon.expandir', function() {
            var categoriaId = $(this).data('id');
            var isExpandido = $(this).text() === '−';
            
            // Alternar o símbolo
            $(this).text(isExpandido ? '+' : '−');
            
            // Mostrar/ocultar a linha de subcategorias
            $('.subcategorias-' + categoriaId).toggleClass('oculto');
            
            // Se estiver expandindo e o container estiver vazio, carregar via AJAX
            if (!isExpandido && $('#subcategoria-container-' + categoriaId).children().length === 0) {
                $.ajax({
                    url: '../relatorios/obter_subcategorias.php',
                    method: 'POST',
                    data: { 
                        categoria_id: categoriaId,
                        mostrar_despesas: 0
                    },
                    success: function(response) {
                        $('#subcategoria-container-' + categoriaId).html(response);
                    }
                });
            }
            
            // Atualizar o estado na sessão
            $.post('atualizar_estado_categoria.php', {
                projeto_id: <?php echo $projeto_id; ?>,
                categoria_id: categoriaId,
                expandido: !isExpandido
            });
        });
        
        // Já configurado para navegação normal
        $('.ver-despesas').on('click', function() {
            // O comportamento padrão já funciona
        });
    });
    </script>
    
    <?php include '../includes/footer.php'; ?>
</body>
</html>
