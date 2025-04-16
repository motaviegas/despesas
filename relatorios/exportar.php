<?php
require_once '../config/db.php';
require_once '../includes/functions.php';
verificarLogin();

$projeto_id = isset($_GET['projeto_id']) ? intval($_GET['projeto_id']) : 0;
$formato = isset($_GET['formato']) ? $_GET['formato'] : 'csv';

// Verificar se o projeto existe
$stmt = $pdo->prepare("SELECT id, nome FROM projetos WHERE id = :id");
$stmt->bindParam(':id', $projeto_id, PDO::PARAM_INT);
$stmt->execute();
$projeto = $stmt->fetch();

if (!$projeto) {
    header('Location: ../projetos/listar.php');
    exit;
}

// Obter dados do orçamento e despesas
$categorias_despesas_raw = obterCategoriasDespesas($pdo, $projeto_id);

// Calcular totais recursivamente para todas as categorias
$categorias_despesas = calcularTotaisCategoriasDespesas($categorias_despesas_raw);

// Nome do arquivo
$filename = 'relatorio_' . preg_replace('/[^a-zA-Z0-9]/', '_', $projeto['nome']) . '_' . date('Y-m-d') . '.' . ($formato == 'excel' ? 'xlsx' : 'csv');

// Ordenar categorias para o relatório (começando com o total global e depois por número de conta)
$categorias_ordenadas = [];

// Adicionar o total global primeiro
if (isset($categorias_despesas[0])) {
    $categorias_ordenadas[] = $categorias_despesas[0];
}

// Adicionar as demais categorias ordenadas por número de conta
$categorias_restantes = array_filter($categorias_despesas, function($key) {
    return $key !== 0;
}, ARRAY_FILTER_USE_KEY);

uasort($categorias_restantes, function($a, $b) {
    return strnatcmp($a['numero_conta'], $b['numero_conta']);
});

$categorias_ordenadas = array_merge($categorias_ordenadas, $categorias_restantes);

// Preparar cabeçalhos para download
if ($formato == 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    // Exportar como CSV
    $output = fopen('php://output', 'w');
    
    // Cabeçalho
    fputcsv($output, ['Nr de conta', 'DESCRIÇÃO DA RUBRICA', 'Tot Despesa', 'Delta Budget', 'Budget']);
    
    // Dados
    foreach ($categorias_ordenadas as $categoria) {
        fputcsv($output, [
            $categoria['numero_conta'],
            $categoria['descricao'],
            $categoria['total_despesas'],
            $categoria['delta'],
            $categoria['budget']
        ]);
    }
    
    fclose($output);
} elseif ($formato == 'excel') {
    // Para Excel, você precisaria de uma biblioteca como PhpSpreadsheet
    // Aqui vamos apenas gerar um CSV com separador diferente como exemplo
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    // Exportar como "Excel" (CSV com separador diferente)
    $output = fopen('php://output', 'w');
    
    // BOM para Excel reconhecer UTF-8
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Cabeçalho
    fputcsv($output, ['Nr de conta', 'DESCRIÇÃO DA RUBRICA', 'Tot Despesa', 'Delta Budget', 'Budget'], ';');
    
    // Dados
    foreach ($categorias_ordenadas as $categoria) {
        fputcsv($output, [
            $categoria['numero_conta'],
            $categoria['descricao'],
            str_replace('.', ',', $categoria['total_despesas']),
            str_replace('.', ',', $categoria['delta']),
            str_replace('.', ',', $categoria['budget'])
        ], ';');
    }
    
    fclose($output);
}
?>
