<?php
require_once 'config/db.php';
require_once 'includes/functions.php';
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

// Obter dados completos dos projetos, incluindo estatísticas financeiras
$stmt = $pdo->prepare("
  SELECT 
      p.id, p.nome, p.descricao, p.data_criacao, p.arquivado
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

// Processar cada projeto para calcular métricas adicionais
foreach ($projetos as &$projeto) {
  // Obter categorias e calcular totais corretamente
  $categorias = obterCategoriasDespesas($pdo, $projeto['id']);
  $categorias_com_totais = calcularTotaisCategoriasDespesas($categorias);
  
  // Obter o total global (id 0)
  $total_global = isset($categorias_com_totais[0]) ? $categorias_com_totais[0] : [
      'budget' => 0,
      'total_despesas' => 0,
      'delta' => 0
  ];
  
  // Adicionar informações calculadas ao projeto
  $projeto['orcamento_total'] = $total_global['budget'];
  $projeto['total_despesas'] = $total_global['total_despesas'];
  $projeto['orcamento_remanescente'] = $total_global['delta'];
  
  // Calcular percentagem de execução
  $projeto['percentagem_execucao'] = ($projeto['orcamento_total'] > 0) ? 
      ($projeto['total_despesas'] / $projeto['orcamento_total']) * 100 : 0;
  
  // Determinar a classe da barra de progresso
  if ($projeto['total_despesas'] > $projeto['orcamento_total']) {
      // Se despesas > orçamento, sempre vermelho intenso
      $projeto['barra_classe'] = "vermelho-intenso";
      $projeto['barra_largura'] = 100; // Limitar a 100% para visualização
      $projeto['saldo_classe'] = "ultrapassado";
  } elseif ($projeto['percentagem_execucao'] <= 50) {
      $projeto['barra_classe'] = "verde";
      $projeto['barra_largura'] = $projeto['percentagem_execucao'] * 2; // Dobro do valor para ocupar metade quando chegar a 50%
  } elseif ($projeto['percentagem_execucao'] <= 75) {
      $projeto['barra_classe'] = "amarelo";
      $projeto['barra_largura'] = $projeto['percentagem_execucao'];
  } elseif ($projeto['percentagem_execucao'] <= 95) {
      $projeto['barra_classe'] = "laranja";
      $projeto['barra_largura'] = $projeto['percentagem_execucao'];
  } else {
      $projeto['barra_classe'] = "vermelho";
      $projeto['barra_largura'] = $projeto['percentagem_execucao'];
      $projeto['saldo_classe'] = "";
  }
}
unset($projeto); // Remover referência

// Obter estatísticas gerais - APENAS PARA PROJETOS ATIVOS
$estatisticas_gerais = [
  'total_projetos' => 0,
  'total_despesas' => 0,
  'soma_despesas_total' => 0,
  'orcamento_total' => 0
];

// Contabilizar totais dos projetos ativos
foreach ($projetos as $projeto) {
  if (!$projeto['arquivado']) {
      $estatisticas_gerais['total_projetos']++;
      $estatisticas_gerais['soma_despesas_total'] += $projeto['total_despesas'];
      $estatisticas_gerais['orcamento_total'] += $projeto['orcamento_total'];
      
      // Contabilizar número de despesas
      $stmt = $pdo->prepare("SELECT COUNT(*) AS total FROM despesas WHERE projeto_id = :projeto_id");
      $stmt->bindParam(':projeto_id', $projeto['id'], PDO::PARAM_INT);
      $stmt->execute();
      $resultado = $stmt->fetch();
      $estatisticas_gerais['total_despesas'] += $resultado['total'];
  }
}

// Obter últimas 5 despesas registradas (geral) - APENAS DE PROJETOS ATIVOS
$stmt = $pdo->prepare("
  SELECT 
      d.id, d.valor, d.data_despesa, d.descricao,
      p.nome as projeto_nome, p.id as projeto_id,
      c.numero_conta, c.descricao as categoria_descricao,
      f.nome as fornecedor
  FROM 
      despesas d
  JOIN 
      projetos p ON d.projeto_id = p.id
  JOIN 
      categorias c ON d.categoria_id = c.id
  JOIN 
      fornecedores f ON d.fornecedor_id = f.id
  WHERE 
      p.criado_por = :usuario_id AND p.arquivado = FALSE
  ORDER BY 
      d.data_registro DESC
  LIMIT 5
");
$stmt->bindParam(':usuario_id', $usuario_id, PDO::PARAM_INT);
$stmt->execute();
$ultimas_despesas = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Dashboard - <?php echo $system_name ?? 'Budget control'; ?></title>
  <link rel="stylesheet" href="assets/css/style.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
  <style>
      .dashboard-container {
          display: grid;
          grid-template-columns: 1fr 300px;
          gap: 25px;
      }
      
      .main-content {
          width: 100%;
      }
      
      .sidebar {
          background-color: white;
          border-radius: 8px;
          padding: 20px;
          box-shadow: 0 2px 10px rgba(0,0,0,0.05);
      }
      
      .stats-overview {
          display: flex;
          flex-wrap: wrap;
          gap: 15px;
          margin-bottom: 20px;
      }
      
      .stat-card {
          flex: 1 1 200px;
          background-color: white;
          border-radius: 8px;
          padding: 20px;
          box-shadow: 0 2px 10px rgba(0,0,0,0.05);
      }
      
      .stat-title {
          color: #6c757d;
          font-size: 14px;
          margin-bottom: 10px;
      }
      
      .stat-value {
          font-size: 24px;
          font-weight: bold;
          margin-bottom: 5px;
      }
      
      .alert-info {
          margin-bottom: 20px;
      }
      
      .projetos-header {
          display: flex;
          justify-content: space-between;
          align-items: center;
          margin-bottom: 20px;
      }
      
      .busca-container {
          position: relative;
          margin-bottom: 20px;
      }
      
      .busca-input {
          width: 100%;
          padding: 12px 40px 12px 12px;
          border: 1px solid #ddd;
          border-radius: 8px;
          font-size: 16px;
      }
      
      .busca-icon {
          position: absolute;
          right: 12px;
          top: 50%;
          transform: translateY(-50%);
          color: #6c757d;
      }
      
      .projeto-card {
          background-color: white;
          border-radius: 12px;
          padding: 20px;
          margin-bottom: 20px;
          box-shadow: 0 2px 10px rgba(0,0,0,0.05);
          transition: all 0.3s ease;
      }
      
      .projeto-card:hover {
          transform: translateY(-5px);
          box-shadow: 0 15px 30px rgba(0,0,0,0.1);
      }
      
      .projeto-header {
          display: flex;
          justify-content: space-between;
          align-items: flex-start;
          margin-bottom: 15px;
      }
      
      .projeto-titulo {
          font-size: 20px;
          font-weight: bold;
          color: #2062b7;
          margin-bottom: 5px;
      }
      
      .projeto-info {
          margin-bottom: 15px;
      }
      
      .projeto-descricao {
          color: #495057;
          margin-bottom: 10px;
      }
      
      .projeto-meta {
          color: #6c757d;
          font-size: 14px;
          margin-bottom: 5px;
      }
      
      .projeto-financeiro {
          background-color: #f8f9fa;
          border-radius: 8px;
          padding: 15px;
          margin-bottom: 15px;
      }
      
      .financeiro-item {
          display: flex;
          justify-content: space-between;
          margin-bottom: 10px;
      }
      
      .financeiro-label {
          color: #495057;
          font-weight: 500;
      }
      
      .financeiro-valor {
          font-weight: bold;
          font-family: 'Consolas', monospace;
      }
      
      .progress-container {
          margin: 15px 0;
      }
      
      .projeto-acoes {
          display: flex;
          flex-wrap: wrap;
          gap: 10px;
      }
      
      .ultimas-despesas {
          margin-top: 30px;
      }
      
      .despesa-item {
          padding: 10px 0;
          border-bottom: 1px solid #e9ecef;
      }
      
      .despesa-item:last-child {
          border-bottom: none;
      }
      
      .despesa-projeto {
          font-weight: bold;
          color: #2062b7;
      }
      
      .despesa-valor {
          font-weight: bold;
          font-family: 'Consolas', monospace;
      }
      
      .despesa-data {
          color: #6c757d;
          font-size: 14px;
      }
      
      .despesa-fornecedor {
          color: #495057;
      }
      
      .projeto-arquivado {
          opacity: 0.7;
      }
      
      .badge {
          display: inline-block;
          padding: 4px 8px;
          border-radius: 4px;
          font-size: 12px;
          font-weight: bold;
          text-transform: uppercase;
      }
      
      .badge-arquivado {
          background-color: #f8f9fa;
          color: #6c757d;
      }
      
      .menu-lateral {
          list-style: none;
          padding: 0;
          margin: 0 0 20px 0;
      }
      
      .menu-lateral li {
          margin-bottom: 5px;
      }
      
      .menu-lateral a {
          display: block;
          padding: 10px 15px;
          border-radius: 4px;
          text-decoration: none;
          color: #495057;
          transition: all 0.2s;
      }
      
      .menu-lateral a:hover {
          background-color: #f0f7ff;
          color: #2062b7;
      }
      
      .menu-lateral a.active {
          background-color: #2062b7;
          color: white;
      }
      
      .menu-lateral i {
          width: 20px;
          margin-right: 10px;
          text-align: center;
      }

      .sem-projetos {
          background-color: #f8f9fa;
          padding: 30px;
          text-align: center;
          border-radius: 8px;
          margin-top: 20px;
      }

      .sem-projetos p {
          margin-bottom: 15px;
          color: #6c757d;
      }
      
      /* Estilos atualizados para as barras de progresso */
      .progress-bar.vermelho-intenso {
          background: linear-gradient(to right, #b71c1c, #dc3545);
          box-shadow: 0 0 8px rgba(220, 53, 69, 0.6);
          animation: pulse 1.5s infinite;
      }
      
      @keyframes pulse {
          0% { opacity: 1; }
          50% { opacity: 0.8; }
          100% { opacity: 1; }
      }
      
      @media (max-width: 992px) {
          .dashboard-container {
              grid-template-columns: 1fr;
          }
          
          .sidebar {
              order: -1;
              margin-bottom: 20px;
          }
      }
  </style>
</head>
<body>
<?php include 'includes/header.php'; ?>
  
     <div class="container">
         <h1>Dashboard - <?php echo $system_name ?? 'Budget control'; ?></h1>
      
         <?php if (!empty($mensagem)): ?>
             <div class="alert alert-info"><?php echo $mensagem; ?></div>
         <?php endif; ?>
      
         <div class="stats-overview">
             <div class="stat-card">
                 <div class="stat-title">Total de Projetos</div>
                 <div class="stat-value"><?php echo $estatisticas_gerais['total_projetos']; ?></div>
             </div>
          
             <div class="stat-card">
                 <div class="stat-title">Total de Despesas</div>
                 <div class="stat-value"><?php echo $estatisticas_gerais['total_despesas']; ?></div>
             </div>
          
             <div class="stat-card">
                 <div class="stat-title">Orçamento Total</div>
                 <div class="stat-value"><?php echo number_format($estatisticas_gerais['orcamento_total'], 2, ',', '.'); ?> €</div>
             </div>
          
             <div class="stat-card">
                 <div class="stat-title">Despesas Totais</div>
                 <div class="stat-value"><?php echo number_format($estatisticas_gerais['soma_despesas_total'], 2, ',', '.'); ?> €</div>
             </div>
         </div>
      
         <div class="dashboard-container">
             <div class="main-content">
                 <div class="projetos-header">
                     <h2>Meus Projetos</h2>
                  
                     <div class="actions">
                         <a href="projetos/criar.php" class="btn btn-primary">
                             <i class="fa fa-plus"></i> Novo Projeto
                         </a>
                      
                         <?php if ($mostrar_arquivados): ?>
                             <a href="?mostrar_arquivados=0" class="btn btn-secondary">
                                 <i class="fa fa-eye-slash"></i> Ocultar Arquivados
                             </a>
                         <?php else: ?>
                             <a href="?mostrar_arquivados=1" class="btn btn-secondary">
                                 <i class="fa fa-archive"></i> Mostrar Arquivados
                             </a>
                         <?php endif; ?>
                     </div>
                 </div>
              
                 <div class="busca-container">
                     <input type="text" id="busca-projetos" class="busca-input" placeholder="Buscar projetos...">
                     <i class="fa fa-search busca-icon"></i>
                 </div>
              
                 <?php if (count($projetos) > 0): ?>
                     <div class="projetos-lista" id="lista-projetos">
                         <?php foreach ($projetos as $projeto): ?>
                             <div class="projeto-card <?php echo $projeto['arquivado'] ? 'projeto-arquivado' : ''; ?>">
                                 <div class="projeto-header">
                                     <div>
                                         <div class="projeto-titulo"><?php echo htmlspecialchars($projeto['nome']); ?></div>
                                         <div class="projeto-meta">Criado em: <?php echo date('d/m/Y', strtotime($projeto['data_criacao'])); ?></div>
                                     </div>
                                  
                                     <?php if ($projeto['arquivado']): ?>
                                         <span class="badge badge-arquivado">Arquivado</span>
                                     <?php endif; ?>
                                 </div>
                              
                                 <div class="projeto-info">
                                     <p class="projeto-descricao"><?php echo htmlspecialchars($projeto['descricao']); ?></p>
                                 </div>
                              
                                 <div class="projeto-financeiro">
                                     <div class="financeiro-item">
                                         <span class="financeiro-label">Orçamento Total:</span>
                                         <span class="financeiro-valor"><?php echo number_format($projeto['orcamento_total'], 2, ',', '.'); ?> €</span>
                                     </div>
                                  
                                     <div class="financeiro-item">
                                         <span class="financeiro-label">Total Despesas:</span>
                                         <span class="financeiro-valor"><?php echo number_format($projeto['total_despesas'], 2, ',', '.'); ?> €</span>
                                     </div>
                                  
                                     <div class="financeiro-item">
                                         <span class="financeiro-label">Saldo:</span>
                                         <span class="financeiro-valor <?php echo $projeto['orcamento_remanescente'] >= 0 ? 'positivo' : 'negativo ' . ($projeto['saldo_classe'] ?? ''); ?>">
                                             <?php echo number_format($projeto['orcamento_remanescente'], 2, ',', '.'); ?> €
                                         </span>
                                     </div>
                                  
                                     <div class="progress-container">
                                         <div class="progress-bar <?php echo $projeto['barra_classe']; ?>" style="width: <?php echo $projeto['barra_largura']; ?>%"></div>
                                         <span class="progress-text"><?php echo number_format($projeto['percentagem_execucao'], 1, ',', '.'); ?>%</span>
                                     </div>
                                 </div>
                              
                                 <div class="projeto-acoes">
                                     <a href="relatorios/gerar.php?projeto_id=<?php echo $projeto['id']; ?>" class="btn btn-sm">
                                         <i class="fa fa-bar-chart"></i> Orçamento
                                     </a>
                                  
                                     <a href="despesas/registrar.php?projeto_id=<?php echo $projeto['id']; ?>" class="btn btn-sm">
                                         <i class="fa fa-plus-circle"></i> Nova Despesa
                                     </a>
                                  
                                     <a href="projetos/ver.php?projeto_id=<?php echo $projeto['id']; ?>" class="btn btn-sm">
                                         <i class="fa fa-eye"></i> Detalhes
                                     </a>
                                  
                                     <form method="post" action="" style="display: inline;">
                                         <input type="hidden" name="projeto_id" value="<?php echo $projeto['id']; ?>">
                                         <?php if ($projeto['arquivado']): ?>
                                             <input type="hidden" name="acao" value="desarquivar">
                                             <button type="submit" class="btn btn-sm btn-secondary">
                                                 <i class="fa fa-box-archive"></i> Desarquivar
                                             </button>
                                         <?php else: ?>
                                             <input type="hidden" name="acao" value="arquivar">
                                             <button type="submit" class="btn btn-sm btn-secondary">
                                                 <i class="fa fa-archive"></i> Arquivar
                                             </button>
                                         <?php endif; ?>
                                     </form>
                                 </div>
                             </div>
                         <?php endforeach; ?>
                     </div>
                 <?php else: ?>
                     <div class="sem-projetos">
                         <p>Você ainda não tem projetos<?php echo $mostrar_arquivados ? '' : ' ativos'; ?>.</p>
                         <p>
                            <?php if (!$mostrar_arquivados && isset($_GET['mostrar_arquivados'])): ?>
                                <a href="?mostrar_arquivados=1">Ver projetos arquivados</a> ou 
                            <?php endif; ?>
                            <a href="projetos/criar.php" class="btn btn-primary">Criar meu primeiro projeto</a>
                         </p>
                     </div>
                 <?php endif; ?>
             </div>
          
             <div class="sidebar">
                 <h3>Menu Rápido</h3>
                 <ul class="menu-lateral">
                     <li><a href="dashboard.php" class="active"><i class="fa fa-tachometer"></i> Dashboard</a></li>
                     <li><a href="projetos/listar.php"><i class="fa fa-folder"></i> Projetos</a></li>
                     <li><a href="projetos/criar.php"><i class="fa fa-plus-circle"></i> Novo Projeto</a></li>
                     <?php if (ehAdmin()): ?>
                     <li><a href="admin/usuarios.php"><i class="fa fa-users"></i> Gerenciar Usuários</a></li>
                     <?php endif; ?>
                 </ul>
              
                 <h3>Últimas Despesas</h3>
              
                 <?php if (count($ultimas_despesas) > 0): ?>
                     <div class="ultimas-despesas">
                         <?php foreach ($ultimas_despesas as $despesa): ?>
                             <div class="despesa-item">
                                 <div class="despesa-projeto">
                                     <a href="projetos/ver.php?projeto_id=<?php echo $despesa['projeto_id']; ?>">
                                         <?php echo htmlspecialchars($despesa['projeto_nome']); ?>
                                     </a>
                                 </div>
                                 <div class="despesa-valor"><?php echo number_format($despesa['valor'], 2, ',', '.'); ?> €</div>
                                 <div class="despesa-categoria"><?php echo htmlspecialchars($despesa['numero_conta'] . ' - ' . $despesa['categoria_descricao']); ?></div>
                                 <div class="despesa-fornecedor"><?php echo htmlspecialchars($despesa['fornecedor']); ?></div>
                                 <div class="despesa-data"><?php echo date('d/m/Y', strtotime($despesa['data_despesa'])); ?></div>
                             </div>
                         <?php endforeach; ?>
                     </div>
                 <?php else: ?>
                     <p>Nenhuma despesa registrada.</p>
                 <?php endif; ?>
              
                 <div style="margin-top: 20px;">
                     <a href="despesas/listar.php" class="btn btn-sm btn-block">Ver Todas as Despesas</a>
                 </div>
             </div>
         </div>
     </div>
  
     <script>
     document.addEventListener('DOMContentLoaded', function() {
         // Funcionalidade de busca de projetos
         const buscaInput = document.getElementById('busca-projetos');
         const projetos = document.querySelectorAll('.projeto-card');
      
         buscaInput.addEventListener('input', function() {
             const termo = this.value.toLowerCase().trim();
          
             projetos.forEach(function(projeto) {
                 const titulo = projeto.querySelector('.projeto-titulo').textContent.toLowerCase();
                 const descricao = projeto.querySelector('.projeto-descricao').textContent.toLowerCase();
              
                 if (titulo.includes(termo) || descricao.includes(termo)) {
                     projeto.style.display = '';
                 } else {
                     projeto.style.display = 'none';
                 }
             });
          
             // Verificar se há projetos visíveis
             const projetosVisiveis = document.querySelectorAll('.projeto-card[style=""]').length;
             const listaProjetos = document.getElementById('lista-projetos');
          
             if (projetosVisiveis === 0 && termo !== '') {
                 if (!document.getElementById('sem-resultados-busca')) {
                     const mensagem = document.createElement('div');
                     mensagem.id = 'sem-resultados-busca';
                     mensagem.className = 'sem-projetos';
                     mensagem.innerHTML = '<p>Nenhum projeto encontrado para "' + termo + '"</p>';
                     listaProjetos.appendChild(mensagem);
                 }
             } else {
                 const semResultados = document.getElementById('sem-resultados-busca');
                 if (semResultados) {
                     semResultados.remove();
                 }
             }
         });
      
         // Tooltips para os valores de execução do orçamento
         const barrasProgresso = document.querySelectorAll('.progress-container');
      
         barrasProgresso.forEach(function(barra) {
             const percentagem = barra.querySelector('.progress-text').textContent;
             let mensagem = '';
          
             if (percentagem.includes('100')) {
                 mensagem = 'Orçamento totalmente utilizado';
             } else if (parseFloat(percentagem) > 100) {
                 mensagem = 'Orçamento excedido!';
             } else {
                 mensagem = 'Progresso da execução orçamentária';
             }
          
             barra.setAttribute('title', mensagem);
         });
     });
     </script>
  
     <?php include 'includes/footer.php'; ?>
  </body>
  </html>
