<?php
session_start();

// 1. Definição de variáveis globais e configurações iniciais
$step = isset($_GET['step']) ? intval($_GET['step']) : 1;
$errors = [];
$requirements = [
    'php' => '7.4.0',
    'extensions' => [
        'pdo',
        'pdo_mysql',
        'gd',
        'fileinfo',
        'json',
        'session'
    ]
];

// 2. Função para verificar a versão do PHP
function check_php_version($required_version) {
    return version_compare(PHP_VERSION, $required_version, '>=');
}

$requirements = [
    'php' => '7.4.0',
    'extensions' => [
        'pdo',
        'pdo_mysql',
        'gd',
        'fileinfo',
        'json',
        'session'
    ],
    'mysql' => [
        'version' => '5.7.0'
    ]
];

// 2.1 Função para verificar a versão do MySQL

if ($connection['success']) {
    $mysql_check = check_mysql_version($connection['pdo'], $requirements['mysql']['version']);
    // Exibir resultado na interface
}

// 3. Função para verificar extensões do PHP
function check_extension($extension) {
    return extension_loaded($extension);
}

// 4. Função para criar tabelas no banco de dados
function create_database_tables($pdo) {
    try {
        // 4.1 Tabela de usuários
        $pdo->exec("CREATE TABLE IF NOT EXISTS usuarios (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(255) NOT NULL UNIQUE,
            senha VARCHAR(255) NOT NULL,
            tipo_conta ENUM('admin', 'normal') NOT NULL DEFAULT 'normal',
            data_criacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // 4.2 Tabela de projetos
        $pdo->exec("CREATE TABLE IF NOT EXISTS projetos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nome VARCHAR(255) NOT NULL,
            descricao TEXT,
            logo_path VARCHAR(255),
            criado_por INT NOT NULL,
            data_criacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            arquivado BOOLEAN DEFAULT FALSE,
            FOREIGN KEY (criado_por) REFERENCES usuarios(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // 4.3 Tabela de categorias
        $pdo->exec("CREATE TABLE IF NOT EXISTS categorias (
            id INT AUTO_INCREMENT PRIMARY KEY,
            projeto_id INT NOT NULL,
            numero_conta VARCHAR(50) NOT NULL,
            descricao VARCHAR(255) NOT NULL,
            budget DECIMAL(15,2) NOT NULL DEFAULT 0,
            nivel INT NOT NULL DEFAULT 1,
            categoria_pai_id INT,
            FOREIGN KEY (projeto_id) REFERENCES projetos(id),
            FOREIGN KEY (categoria_pai_id) REFERENCES categorias(id) ON DELETE SET NULL,
            UNIQUE KEY (projeto_id, numero_conta)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // 4.4 Tabela de fornecedores
        $pdo->exec("CREATE TABLE IF NOT EXISTS fornecedores (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nome VARCHAR(255) NOT NULL UNIQUE,
            data_criacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // 4.5 Tabela de despesas
        $pdo->exec("CREATE TABLE IF NOT EXISTS despesas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            projeto_id INT NOT NULL,
            categoria_id INT NOT NULL,
            fornecedor_id INT NOT NULL,
            tipo ENUM('serviço', 'bem') NOT NULL DEFAULT 'serviço',
            valor DECIMAL(15,2) NOT NULL,
            descricao TEXT,
            data_despesa DATE NOT NULL,
            data_registro TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            anexo_path VARCHAR(255),
            registrado_por INT NOT NULL,
            ultima_atualizacao TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (projeto_id) REFERENCES projetos(id),
            FOREIGN KEY (categoria_id) REFERENCES categorias(id),
            FOREIGN KEY (fornecedor_id) REFERENCES fornecedores(id),
            FOREIGN KEY (registrado_por) REFERENCES usuarios(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // 4.6 Tabela de histórico de alterações de orçamento
        $pdo->exec("CREATE TABLE IF NOT EXISTS historico_budget (
            id INT AUTO_INCREMENT PRIMARY KEY,
            categoria_id INT NOT NULL,
            valor_anterior DECIMAL(15,2) NOT NULL,
            valor_novo DECIMAL(15,2) NOT NULL,
            alterado_por INT NOT NULL,
            data_alteracao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            motivo TEXT,
            FOREIGN KEY (categoria_id) REFERENCES categorias(id),
            FOREIGN KEY (alterado_por) REFERENCES usuarios(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // 4.7 Tabela de histórico de exclusões
        $pdo->exec("CREATE TABLE IF NOT EXISTS historico_exclusoes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tipo_registro ENUM('despesa', 'categoria') NOT NULL,
            registro_id INT NOT NULL,
            projeto_id INT NOT NULL,
            categoria_id INT,
            fornecedor_id INT,
            tipo ENUM('serviço', 'bem'),
            valor DECIMAL(15,2),
            descricao TEXT,
            data_despesa DATE,
            excluido_por INT NOT NULL,
            data_exclusao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            motivo TEXT,
            FOREIGN KEY (projeto_id) REFERENCES projetos(id),
            FOREIGN KEY (excluido_por) REFERENCES usuarios(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // 4.8 Tabela de histórico de edições
        $pdo->exec("CREATE TABLE IF NOT EXISTS historico_edicoes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tipo_registro ENUM('despesa', 'categoria') NOT NULL,
            registro_id INT NOT NULL,
            projeto_id INT NOT NULL,
            categoria_id_anterior INT,
            categoria_id_novo INT,
            fornecedor_anterior VARCHAR(255),
            fornecedor_novo VARCHAR(255),
            tipo_anterior ENUM('serviço', 'bem'),
            tipo_novo ENUM('serviço', 'bem'),
            valor_anterior DECIMAL(15,2),
            valor_novo DECIMAL(15,2),
            descricao_anterior TEXT,
            descricao_nova TEXT,
            data_despesa_anterior DATE,
            data_despesa_nova DATE,
            editado_por INT NOT NULL,
            data_edicao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (projeto_id) REFERENCES projetos(id),
            FOREIGN KEY (editado_por) REFERENCES usuarios(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        return true;
    } catch (PDOException $e) {
        return $e->getMessage();
    }
}

// 5. Função para criar diretórios necessários
function create_directories() {
    $directories = [
        'assets/arquivos',
        'assets/img',
        'config',
        'includes'
    ];
    
    $errors = [];
    foreach ($directories as $dir) {
        if (!file_exists($dir) && !mkdir($dir, 0755, true)) {
            $errors[] = "Não foi possível criar o diretório: $dir";
        }
    }
    
    return $errors;
}

// 6. Função para gerar o arquivo de configuração
function generate_config_file($host, $db_name, $username, $password, $system_name) {
    $config_content = "<?php
\$host = '$host';
\$db_name = '$db_name';
\$username = '$username';
\$password = '$password';
\$base_url = '$base_url';
\$system_name = '$system_name';

try {
    \$pdo = new PDO(\"mysql:host=\$host;dbname=\$db_name;charset=utf8\", \$username, \$password);
    \$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    \$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch(PDOException \$e) {
    die(\"Erro de conexão: \" . \$e->getMessage());
}
?>";

    return file_put_contents('config/db.php', $config_content) !== false;
}

// 7. Função para validar o upload do logo
function validate_logo_upload() {
    if (!isset($_FILES['logo']) || $_FILES['logo']['error'] == UPLOAD_ERR_NO_FILE) {
        return true; // Logo é opcional
    }
    
    $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
    $max_size = 1024 * 1024; // 1MB
    
    if ($_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
        return "Erro no upload do logo: " . $_FILES['logo']['error'];
    }
    
    if (!in_array($_FILES['logo']['type'], $allowed_types)) {
        return "Tipo de arquivo não permitido. Por favor, envie um JPEG, PNG ou GIF.";
    }
    
    if ($_FILES['logo']['size'] > $max_size) {
        return "O tamanho do logo deve ser menor que 1MB.";
    }
    
    return true;
}

// 8. Função para processar o upload do logo
function process_logo_upload() {
    if (!isset($_FILES['logo']) || $_FILES['logo']['error'] == UPLOAD_ERR_NO_FILE) {
        return 'logo_p.png'; // Logo padrão
    }
    
    $upload_dir = 'assets/img/';
    $file_ext = pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION);
    $file_name = 'logo.' . $file_ext;
    $target_file = $upload_dir . $file_name;
    
    // Redimensionar a imagem para garantir que não seja muito grande
    list($width, $height) = getimagesize($_FILES['logo']['tmp_name']);
    $max_width = 200;
    $max_height = 80;
    
    if ($width > $max_width || $height > $max_height) {
        $ratio = min($max_width / $width, $max_height / $height);
        $new_width = $width * $ratio;
        $new_height = $height * $ratio;
        
        $src = null;
        switch ($_FILES['logo']['type']) {
            case 'image/jpeg':
            case 'image/jpg':
                $src = imagecreatefromjpeg($_FILES['logo']['tmp_name']);
                break;
            case 'image/png':
                $src = imagecreatefrompng($_FILES['logo']['tmp_name']);
                break;
            case 'image/gif':
                $src = imagecreatefromgif($_FILES['logo']['tmp_name']);
                break;
        }
        
        $dst = imagecreatetruecolor($new_width, $new_height);
        
        // Preservar transparência para PNG e GIF
        if ($_FILES['logo']['type'] == 'image/png' || $_FILES['logo']['type'] == 'image/gif') {
            imagecolortransparent($dst, imagecolorallocatealpha($dst, 0, 0, 0, 127));
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
        }
        
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $new_width, $new_height, $width, $height);
        
        switch ($_FILES['logo']['type']) {
            case 'image/jpeg':
            case 'image/jpg':
                imagejpeg($dst, $target_file, 90);
                break;
            case 'image/png':
                imagepng($dst, $target_file, 9);
                break;
            case 'image/gif':
                imagegif($dst, $target_file);
                break;
        }
        
        imagedestroy($src);
        imagedestroy($dst);
    } else {
        move_uploaded_file($_FILES['logo']['tmp_name'], $target_file);
    }
    
    return $file_name;
}

// 9. Função para testar a conexão com o banco de dados
function test_database_connection($host, $db_name, $username, $password) {
    try {
        // Tente usar IP se for localhost
        $connection_host = ($host === 'localhost') ? '127.0.0.1' : $host;
        
        // Primeiro conectar sem especificar o banco de dados
        $pdo = new PDO("mysql:host=$connection_host;charset=utf8", $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5
        ]);
        
        // Tentar criar o banco de dados se não existir
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db_name` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        
        // Conectar ao banco de dados criado
        $pdo = new PDO("mysql:host=$connection_host;dbname=$db_name;charset=utf8", $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5
        ]);
        
        return ['success' => true, 'pdo' => $pdo];
    } catch (PDOException $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// 10. Função para criar o primeiro usuário administrador
function create_admin_user($pdo, $email, $password) {
    try {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        
        $stmt = $pdo->prepare("INSERT INTO usuarios (email, senha, tipo_conta) VALUES (:email, :senha, 'admin')");
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':senha', $hashed_password);
        $stmt->execute();
        
        return true;
    } catch (PDOException $e) {
        return $e->getMessage();
    }
}

// 11. Função para processar o formulário do Step 2
function process_step2() {
    $db_host = $_POST['db_host'] ?? '';
    $db_name = $_POST['db_name'] ?? '';
    $db_user = $_POST['db_user'] ?? '';
    $db_pass = $_POST['db_pass'] ?? '';
    
    if (empty($db_host) || empty($db_name) || empty($db_user)) {
        return ['success' => false, 'message' => 'Todos os campos do banco de dados são obrigatórios, exceto a senha se o usuário não tiver senha.'];
    }
    
    $connection = test_database_connection($db_host, $db_name, $db_user, $db_pass);
    if (!$connection['success']) {
        return ['success' => false, 'message' => 'Erro ao conectar ao banco de dados: ' . $connection['message']];
    }
    
    $_SESSION['db_host'] = $db_host;
    $_SESSION['db_name'] = $db_name;
    $_SESSION['db_user'] = $db_user;
    $_SESSION['db_pass'] = $db_pass;
    $_SESSION['pdo'] = $connection['pdo'];
    
    return ['success' => true];
}

// 12. Função para processar o formulário do Step 3
function process_step3() {
    $admin_email = $_POST['admin_email'] ?? '';
    $admin_password = $_POST['admin_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $system_name = $_POST['system_name'] ?? 'Gestão de Eventos';
    
    if (empty($admin_email) || empty($admin_password) || empty($confirm_password)) {
        return ['success' => false, 'message' => 'Todos os campos são obrigatórios.'];
    }
    
    if (!filter_var($admin_email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => 'E-mail de administrador inválido.'];
    }
    
    if ($admin_password !== $confirm_password) {
        return ['success' => false, 'message' => 'As senhas não correspondem.'];
    }
    
    if (strlen($admin_password) < 6) {
        return ['success' => false, 'message' => 'A senha deve ter pelo menos 6 caracteres.'];
    }
    
    $logo_validation = validate_logo_upload();
    if ($logo_validation !== true) {
        return ['success' => false, 'message' => $logo_validation];
    }
    
    $_SESSION['admin_email'] = $admin_email;
    $_SESSION['admin_password'] = $admin_password;
    $_SESSION['system_name'] = $system_name;
    
    return ['success' => true];
}

// 13. Função para finalizar a instalação
function finalize_installation() {
    try {
        $pdo = new PDO("mysql:host={$_SESSION['db_host']};dbname={$_SESSION['db_name']}", $_SESSION['db_user'], $_SESSION['db_pass']);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Criar tabelas
        $tables_result = create_database_tables($pdo);
        if ($tables_result !== true) {
            return ['success' => false, 'message' => 'Erro ao criar tabelas: ' . $tables_result];
        }
        
        // Criar diretórios
        $dir_errors = create_directories();
        if (!empty($dir_errors)) {
            return ['success' => false, 'message' => implode('<br>', $dir_errors)];
        }
        
        // Processar upload do logo
        $logo_path = process_logo_upload();
        
        // Criar usuário administrador
        $admin_result = create_admin_user($pdo, $_SESSION['admin_email'], $_SESSION['admin_password']);
        if ($admin_result !== true) {
            return ['success' => false, 'message' => 'Erro ao criar usuário administrador: ' . $admin_result];
        }
        
        // Gerar arquivo de configuração
        $config_result = generate_config_file(
            $_SESSION['db_host'],
            $_SESSION['db_name'],
            $_SESSION['db_user'],
            $_SESSION['db_pass'],
            $_SESSION['system_name']
        );
        
        if (!$config_result) {
            return ['success' => false, 'message' => 'Erro ao gerar arquivo de configuração.'];
        }
        
        // Criar arquivo .htaccess para proteger a instalação de ser executada novamente
        file_put_contents('install_lock', 'Installation completed on ' . date('Y-m-d H:i:s'));
        
        return ['success' => true];
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Erro durante a instalação: ' . $e->getMessage()];
    }
}

// 14. Verificar se a instalação já foi concluída
if (file_exists('install_lock') && !isset($_GET['force'])) {
    $lock_message = "A instalação já foi concluída. Se deseja reinstalar, delete o arquivo 'install_lock' ou acesse com o parâmetro 'force'.";
} else {
    // 15. Processar formulários
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        switch ($step) {
            case 2:
                $result = process_step2();
                if ($result['success']) {
                    $step = 3;
                } else {
                    $errors[] = $result['message'];
                }
                break;
                
            case 3:
                $result = process_step3();
                if ($result['success']) {
                    $step = 4;
                } else {
                    $errors[] = $result['message'];
                }
                break;
                
            case 4:
                $result = finalize_installation();
                if ($result['success']) {
                    $step = 5;
                } else {
                    $errors[] = $result['message'];
                    $step = 4;
                }
                break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instalação - Sistema de Gestão de Despesas</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
        body {
            background-color: #f5f5f7;
            font-family: 'Segoe UI', Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            padding-top: 20px;
        }
        .container {
            max-width: 800px;
            background-color: #fff;
            border-radius: 10px;
            padding: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 40px;
        }
        .step-header {
            text-align: center;
            margin-bottom: 30px;
        }
        .step-indicator {
            display: flex;
            justify-content: space-between;
            margin-bottom: 40px;
            position: relative;
        }
        .step-indicator::before {
            content: '';
            position: absolute;
            top: 14px;
            left: 0;
            right: 0;
            height: 2px;
            background-color: #e9ecef;
            z-index: 1;
        }
        .step {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background-color: #e9ecef;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            position: relative;
            z-index: 2;
        }
        .step.active {
            background-color: #2062b7;
            color: #fff;
        }
        .step.completed {
            background-color: #28a745;
            color: #fff;
        }
        .step-content {
            margin-bottom: 30px;
        }
        .form-group label {
            font-weight: bold;
        }
        .requirement-item {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #f2f2f2;
        }
        .requirement-status {
            font-weight: bold;
        }
        .status-ok {
            color: #28a745;
        }
        .status-error {
            color: #dc3545;
        }
        .error-list {
            background-color: #f8d7da;
            color: #721c24;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        .instructions {
            background-color: #f0f7ff;
            padding: 15px;
            border-left: 4px solid #2062b7;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        .logo-preview {
            max-width: 300px;
            max-height: 120px;
            margin-top: 10px;
            display: none;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        .step-buttons {
            display: flex;
            justify-content: space-between;
            margin-top: 30px;
        }
        .success-message {
            text-align: center;
            margin: 40px 0;
        }
        .success-icon {
            font-size: 64px;
            color: #28a745;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="step-header">
            <h1>Instalação do Sistema de Gestão de Despesas</h1>
            <p>Siga os passos para configurar sua instalação</p>
        </div>
        
        <?php if (isset($lock_message)): ?>
            <div class="alert alert-warning">
                <?php echo $lock_message; ?>
                <p>
                    <a href="index.php" class="btn btn-primary mt-3">Ir para a página inicial</a>
                </p>
            </div>
        <?php else: ?>
            <div class="step-indicator">
                <div class="step <?php echo $step >= 1 ? 'active' : ''; ?> <?php echo $step > 1 ? 'completed' : ''; ?>">1</div>
                <div class="step <?php echo $step >= 2 ? 'active' : ''; ?> <?php echo $step > 2 ? 'completed' : ''; ?>">2</div>
                <div class="step <?php echo $step >= 3 ? 'active' : ''; ?> <?php echo $step > 3 ? 'completed' : ''; ?>">3</div>
                <div class="step <?php echo $step >= 4 ? 'active' : ''; ?> <?php echo $step > 4 ? 'completed' : ''; ?>">4</div>
                <div class="step <?php echo $step >= 5 ? 'active' : ''; ?>">5</div>
            </div>
            
            <?php if (!empty($errors)): ?>
                <div class="error-list">
                    <strong>Erros encontrados:</strong>
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo $error; ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            
            <div class="step-content">
                <?php if ($step == 1): ?>
                    <!-- Passo 1: Verificação de requisitos -->
                    <h2>Passo 1: Verificação de Requisitos</h2>
                    <div class="instructions">
                        <p>O sistema verificará os requisitos necessários para a instalação. Certifique-se de que todos os requisitos estão satisfeitos antes de prosseguir.</p>
                    </div>
                    
                    <h4>Requisitos do Sistema</h4>
                    <div class="card mb-4">
                        <div class="card-body">
                            <div class="requirement-item">
                                <span>Versão do PHP (>= <?php echo $requirements['php']; ?>)</span>
                                <?php $php_check = check_php_version($requirements['php']); ?>
                                <span class="requirement-status <?php echo $php_check ? 'status-ok' : 'status-error'; ?>">
                                    <?php echo $php_check ? 'OK ('.PHP_VERSION.')' : 'Erro (Versão atual: '.PHP_VERSION.')'; ?>
                                </span>
                            </div>
                            
                            <?php foreach ($requirements['extensions'] as $extension): ?>
                                <div class="requirement-item">
                                    <span>Extensão PHP: <?php echo $extension; ?></span>
                                    <?php $ext_check = check_extension($extension); ?>
                                    <span class="requirement-status <?php echo $ext_check ? 'status-ok' : 'status-error'; ?>">
                                        <?php echo $ext_check ? 'OK' : 'Não instalada'; ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                            
                            <div class="requirement-item">
                                <span>Permissão de escrita no diretório atual</span>
                                <?php $write_check = is_writable('.'); ?>
                                <span class="requirement-status <?php echo $write_check ? 'status-ok' : 'status-error'; ?>">
                                    <?php echo $write_check ? 'OK' : 'Sem permissão'; ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="step-buttons">
                        <?php
                        // Verificar se todos os requisitos foram atendidos
                        $all_requirements_met = 
                            check_php_version($requirements['php']) && 
                            array_reduce($requirements['extensions'], function($carry, $extension) {
                                return $carry && check_extension($extension);
                            }, true) &&
                            is_writable('.');
                        ?>
                        
                        <?php if ($all_requirements_met): ?>
                            <a href="?step=2" class="btn btn-primary">Próximo Passo</a>
                        <?php else: ?>
                            <div class="alert alert-danger">
                                Por favor, resolva os requisitos não atendidos antes de continuar.
                            </div>
                        <?php endif; ?>
                    </div>
                    
                <?php elseif ($step == 2): ?>
                    <!-- Passo 2: Configuração do Banco de Dados -->
                    <h2>Passo 2: Configuração do Banco de Dados</h2>
                    <div class="instructions">
                        <p>Forneça as informações de conexão com o banco de dados MySQL. O banco de dados será criado automaticamente se não existir.</p>
                    </div>
                    
                    <form method="post" action="?step=2">
                        <div class="form-group">
                            <label for="db_host">Host do Banco de Dados:</label>
                            <input type="text" class="form-control" id="db_host" name="db_host" value="<?php echo $_SESSION['db_host'] ?? 'localhost'; ?>" required>
                            <small class="form-text text-muted">Geralmente "localhost" ou endereço IP do servidor de banco de dados.</small>
                        </div>
                        
                        <div class="form-group">
                            <label for="db_name">Nome do Banco de Dados:</label>
                            <input type="text" class="form-control" id="db_name" name="db_name" value="<?php echo $_SESSION['db_name'] ?? 'gestao_eventos'; ?>" required>
                            <small class="form-text text-muted">O banco de dados será criado automaticamente se não existir.</small>
                        </div>
                        
                        <div class="form-group">
                            <label for="db_user">Usuário do Banco de Dados:</label>
                            <input type="text" class="form-control" id="db_user" name="db_user" value="<?php echo $_SESSION['db_user'] ?? 'root'; ?>" required>
                            <small class="form-text text-muted">Usuário com permissão para criar e modificar bancos de dados.</small>
                        </div>
                        
                        <div class="form-group">
                            <label for="db_pass">Senha do Banco de Dados:</label>
                            <input type="password" class="form-control" id="db_pass" name="db_pass" value="<?php echo $_SESSION['db_pass'] ?? ''; ?>">
                            <small class="form-text text-muted">Deixe em branco se não houver senha.</small>
                        </div>
                        
                        <div class="step-buttons">
                            <a href="?step=1" class="btn btn-secondary">Voltar</a>
                            <button type="submit" class="btn btn-primary">Próximo Passo</button>
                        </div>
                    </form>
                    
                <?php elseif ($step == 3): ?>
                    <!-- Passo 3: Configuração do Sistema -->
                    <h2>Passo 3: Configuração do Sistema</h2>
                    <div class="instructions">
                        <p>Configure as informações básicas do sistema e do administrador.</p>
                    </div>
                    
                    <form method="post" action="?step=3" enctype="multipart/form-data">
                        <div class="form-group">
                            <label for="admin_email">E-mail do Administrador:</label>
                            <input type="email" class="form-control" id="admin_email" name="admin_email" value="<?php echo $_SESSION['admin_email'] ?? ''; ?>" required>
                            <small class="form-text text-muted">Este e-mail será usado para o primeiro login como administrador.</small>
                        </div>
                        
                        <div class="form-group">
                            <label for="admin_password">Senha do Administrador:</label>
                            <input type="password" class="form-control" id="admin_password" name="admin_password" required>
                            <small class="form-text text-muted">Mínimo de 6 caracteres.</small>
                        </div>
                        
                        <div class="form-group">
                            <label for="confirm_password">Confirmar Senha:</label>
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="system_name">Nome do Sistema:</label>
                            <input type="text" class="form-control" id="system_name" name="system_name" value="<?php echo $_SESSION['system_name'] ?? 'Gestão de Eventos'; ?>" required>
                            <small class="form-text text-muted">Nome que aparecerá no cabeçalho e título das páginas.</small>
                        </div>
                        
                        <div class="form-group">
                            <label for="logo">Logo do Sistema (opcional):</label>
                            <input type="file" class="form-control-file" id="logo" name="logo" accept="image/jpeg,image/png,image/gif">
                            <small class="form-text text-muted">Tamanho máximo: 1MB. Formatos: JPEG, PNG, GIF. A imagem será redimensionada se necessário.</small>
                            <img id="logo-preview" class="logo-preview" alt="Preview do logo">
                        </div>
                        
                        <div class="step-buttons">
                            <a href="?step=2" class="btn btn-secondary">Voltar</a>
                            <button type="submit" class="btn btn-primary">Próximo Passo</button>
                        </div>
                    </form>
                    
                <?php elseif ($step == 4): ?>
                    <!-- Passo 4: Confirmação e Instalação -->
                    <h2>Passo 4: Confirmação e Instalação</h2>
                    <div class="instructions">
                        <p>Revise as informações abaixo e clique em "Concluir Instalação" para finalizar o processo.</p>
                    </div>
                    
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5>Informações do Banco de Dados</h5>
                        </div>
                        <div class="card-body">
                            <p><strong>Host:</strong> <?php echo $_SESSION['db_host']; ?></p>
                            <p><strong>Nome do Banco:</strong> <?php echo $_SESSION['db_name']; ?></p>
                            <p><strong>Usuário:</strong> <?php echo $_SESSION['db_user']; ?></p>
                        </div>
                    </div>
                    
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5>Informações do Sistema</h5>
                        </div>
                        <div class="card-body">
                            <p><strong>Nome do Sistema:</strong> <?php echo $_SESSION['system_name']; ?></p>
                            <p><strong>E-mail do Administrador:</strong> <?php echo $_SESSION['admin_email']; ?></p>
                            <p><strong>Logo:</strong> <?php echo isset($_FILES['logo']) && $_FILES['logo']['error'] == 0 ? 'Personalizado' : 'Padrão'; ?></p>
                        </div>
                    </div>
                    
                    <form method="post" action="?step=4">
                        <div class="alert alert-warning">
                            <p><strong>Atenção:</strong> Ao concluir a instalação, serão criados:</p>
                            <ul>
                                <li>O banco de dados e suas tabelas</li>
                                <li>Arquivos de configuração</li>
                                <li>Diretórios para armazenar arquivos enviados</li>
                            </ul>
                            <p>Certifique-se de que todas as informações estão corretas.</p>
                        </div>
                        
                        <div class="step-buttons">
                            <a href="?step=3" class="btn btn-secondary">Voltar</a>
                            <button type="submit" class="btn btn-success">Concluir Instalação</button>
                        </div>
                    </form>
                    
                <?php elseif ($step == 5): ?>
                    <!-- Passo 5: Instalação Concluída -->
                    <div class="success-message">
                        <div class="success-icon">✓</div>
                        <h2>Instalação Concluída com Sucesso!</h2>
                        <p>O sistema de Gestão de Despesas foi instalado corretamente.</p>
                        <p>Você pode agora acessar o sistema e começar a utilizá-lo.</p>
                        
                        <div class="alert alert-info mt-4">
                            <p><strong>Informações de Acesso:</strong></p>
                            <p>E-mail: <?php echo $_SESSION['admin_email']; ?></p>
                            <p>Senha: A senha que você definiu durante a instalação</p>
                        </div>
                        
                        <div class="mt-4">
                            <p><strong>Por segurança, o instalador será desativado automaticamente.</strong></p>
                            <p>Se precisar reinstalar, exclua o arquivo "install_lock" na raiz do diretório.</p>
                        </div>
                        
                        <a href="index.php" class="btn btn-primary mt-4">Ir para o Sistema</a>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    <script>
        // 16. Script para preview do logo
        document.getElementById('logo').addEventListener('change', function(event) {
            const fileInput = event.target;
            const logoPreview = document.getElementById('logo-preview');
            
            if (fileInput.files && fileInput.files[0]) {
                const reader = new FileReader();
                
                reader.onload = function(e) {
                    logoPreview.src = e.target.result;
                    logoPreview.style.display = 'block';
                }
                
                reader.readAsDataURL(fileInput.files[0]);
            } else {
                logoPreview.style.display = 'none';
            }
        });
        
        // 17. Validação do formulário
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function(event) {
                const passwordInputs = form.querySelectorAll('input[type="password"]');
                
                if (passwordInputs.length >= 2) {
                    const password = form.querySelector('#admin_password')?.value;
                    const confirmPassword = form.querySelector('#confirm_password')?.value;
                    
                    if (password && confirmPassword && password !== confirmPassword) {
                        alert('As senhas não correspondem!');
                        event.preventDefault();
                        return false;
                    }
                    
                    if (password && password.length < 6) {
                        alert('A senha deve ter pelo menos 6 caracteres!');
                        event.preventDefault();
                        return false;
                    }
                }
                
                return true;
            });
        });
    </script>
</body>
</html>
