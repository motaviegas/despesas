<?php
require_once '../config/db.php';
require_once '../includes/functions.php';
verificarLogin();

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['categoria_id'])) {
    $categoria_id = intval($_POST['categoria_id']);
    $mostrar_despesas = isset($_POST['mostrar_despesas']) ? intval($_POST['mostrar_despesas']) : 0;
    
    // Obter todas as subcategorias diretas (filhos)
    $stmt = $pdo->prepare("
        SELECT c.id, c.numero_conta, c.descricao, c.budget, c.nivel, c.categoria_pai_id,
               COALESCE(SUM(d.valor), 0) as total_despesas,
               (c.budget - COALESCE(SUM(d.valor), 0)) as delta
        FROM categorias c
        LEFT JOIN despesas d ON c.id = d.categoria_id
        WHERE c.categoria_pai_id = :categoria_pai_id
        GROUP BY c.id
        ORDER BY c.numero_conta
    ");
    $stmt->bindParam(':categoria_pai_id', $categoria_id, PDO::PARAM_INT);
    $stmt->execute();
    $subcategorias_raw = $stmt->fetchAll();
    
    // Obter todas as categorias e despesas do projeto para calcular os totais corretos
    $stmt = $pdo->prepare("SELECT projeto_id FROM categorias WHERE id = :id");
    $stmt->bindParam(':id', $categoria_id, PDO::PARAM_INT);
    $stmt->execute();
    $projeto = $stmt->fetch();
    $projeto_id = $projeto['projeto_id'];
    
    // Obter todas as categorias do projeto e calcular os totais
    $categorias_despesas_raw = obterCategoriasDespesas($pdo, $projeto_id);
    $categorias_despesas = calcularTotaisCategoriasDespesas($categorias_despesas_raw);
    
    // Filtrar apenas as subcategorias desta categoria
    $subcategorias = [];
    foreach ($subcategorias_raw as $sub) {
        if (isset($categorias_despesas[$sub['id']])) {
            $subcategorias[$sub['id']] = $categorias_despesas[$sub['id']];
        }
    }
    
    // Exibir subcategorias se existirem
    if (count($subcategorias) > 0) {
        echo '<table class="subtabela">';
        
        $contador = 0; // Contador para alternância de cores
        foreach ($subcategorias as $subcategoria) {
            // Verificar se esta subcategoria tem filhos
            $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM categorias WHERE categoria_pai_id = :categoria_pai_id");
            $stmt->bindParam(':categoria_pai_id', $subcategoria['id'], PDO::PARAM_INT);
            $stmt->execute();
            $tem_filhos = $stmt->fetch()['total'] > 0;
            
            // Verificar se existem despesas nesta subcategoria
            $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM despesas WHERE categoria_id = :categoria_id");
            $stmt->bindParam(':categoria_id', $subcategoria['id'], PDO::PARAM_INT);
            $stmt->execute();
            $tem_despesas = $stmt->fetch()['total'] > 0;
            
            $prefixo = '';
            if ($tem_filhos) {
                $prefixo = '+ ';
            } elseif ($tem_despesas) {
                $prefixo = '+ ';
            } else {
                $prefixo = '- ';
            }
            
            // Aplicar cores alternadas
            $cor_classe = ($contador % 2 == 0) ? 'linha-clara' : 'linha-escura';
            $contador++;
            
            echo '<tr class="categoria-' . $subcategoria['nivel'] . ' subcategoria-item ' . $cor_classe . '">';
            echo '<td>' . htmlspecialchars($subcategoria['numero_conta']) . '</td>';
            
            // Adicionar classe expandir se tiver filhos ou despesas
            $expandir_class = ($tem_filhos || $tem_despesas) ? 'expandir' : '';
            echo '<td class="'.$expandir_class.'" data-id="' . $subcategoria['id'] . '" data-tem-despesas="' . ($tem_despesas ? '1' : '0') . '">' 
                . $prefixo . htmlspecialchars($subcategoria['descricao']) . '</td>';
            
            echo '<td>' . number_format($subcategoria['total_despesas'], 2, ',', '.') . ' €</td>';
            
            // Classe para o delta (positivo ou negativo)
            $delta_class = $subcategoria['delta'] >= 0 ? 'positivo' : 'negativo';
            echo '<td class="' . $delta_class . '">' . number_format($subcategoria['delta'], 2, ',', '.') . ' €</td>';
            
            echo '<td>' . number_format($subcategoria['budget'], 2, ',', '.') . ' €</td>';
            echo '</tr>';
            
            // Placeholder para subcategorias ou despesas
            echo '<tr class="subcategorias-' . $subcategoria['id'] . ' oculto"><td colspan="5" class="subcategorias-container"></td></tr>';
        }
        
        echo '</table>';
    }
    
    // Exibir as despesas desta categoria se solicitado ou se não houver subcategorias
    if ($mostrar_despesas || count($subcategorias) == 0) {
        $despesas = obterDespesasPorCategoria($pdo, $categoria_id);
        
        if (count($despesas) > 0) {
            echo '<table class="despesas-tabela">';
            echo '<tr>
                    <th>Data</th>
                    <th>Tipo</th>
                    <th>Fornecedor</th>
                    <th>Descrição</th>
                    <th>Valor</th>
                    <th>Anexo</th>
                  </tr>';
            
            $contador = 0; // Contador para alternância de cores
            foreach ($despesas as $despesa) {
                // Aplicar cores alternadas
                $cor_classe = ($contador % 2 == 0) ? 'linha-clara' : 'linha-escura';
                $contador++;
                
                echo '<tr class="despesa-item ' . $cor_classe . '">';
                echo '<td>' . date('d/m/Y', strtotime($despesa['data_despesa'])) . '</td>';
                echo '<td>' . ucfirst(htmlspecialchars($despesa['tipo'])) . '</td>';
                echo '<td>' . htmlspecialchars($despesa['fornecedor']) . '</td>';
                echo '<td>' . htmlspecialchars($despesa['descricao']) . '</td>';
                echo '<td>' . number_format($despesa['valor'], 2, ',', '.') . ' €</td>';
                
                // Link para o anexo, se existir
                if ($despesa['anexo_path']) {
                    echo '<td><a href="../assets/arquivos/' . htmlspecialchars($despesa['anexo_path']) . '" target="_blank" class="anexo-link">Ver fatura</a></td>';
                } else {
                    echo '<td>-</td>';
                }
                
                echo '</tr>';
            }
            
            echo '</table>';
        } else {
            echo '<div style="padding: 10px 15px;"><p>Nenhuma despesa registrada para esta categoria.</p></div>';
        }
    }
} else {
    echo 'Parâmetros inválidos.';
}
?>
