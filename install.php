<?php
// 1.0 INITIALIZATION AND SESSION SETUP
session_start();

// 1.1 DEBUGGING SETTINGS
error_log("SESSION DUMP: " . print_r($_SESSION, true));
$debug_info = [];

// 1.2 INSTALLATION STEPS CONTROL
$step = isset($_GET['step']) ? intval($_GET['step']) : 1;
$errors = [];

// 1.3 SYSTEM REQUIREMENTS DEFINITION
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

// 2.0 REQUIREMENT CHECK FUNCTIONS

/**
 * 2.1 CHECK PHP VERSION COMPATIBILITY
 * @param string $required_version Minimum required PHP version
 * @return bool True if version is compatible
 */
function check_php_version($required_version) {
    return version_compare(PHP_VERSION, $required_version, '>=');
}

/**
 * 2.2 CHECK MYSQL VERSION COMPATIBILITY
 * @param PDO $pdo Database connection
 * @param string $required_version Minimum required MySQL version
 * @return array Result with success status and version info
 */
function check_mysql_version($pdo, $required_version) {
    try {
        $version = $pdo->query("SELECT VERSION()")->fetchColumn();
        return [
            'success' => version_compare($version, $required_version, '>='),
            'version' => $version
        ];
    } catch (PDOException $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * 2.3 CHECK PHP EXTENSION AVAILABILITY
 * @param string $extension Extension name to check
 * @return bool True if extension is loaded
 */
function check_extension($extension) {
    return extension_loaded($extension);
}

// 3.0 DATABASE OPERATIONS

/**
 * 3.1 CREATE DATABASE TABLES
 * @param PDO $pdo Database connection
 * @return bool|string True on success, error message on failure
 */
function create_database_tables($pdo) {
    try {
        // 3.1.1 USERS TABLE
        $pdo->exec("CREATE TABLE IF NOT EXISTS usuarios (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(255) NOT NULL UNIQUE,
            senha VARCHAR(255) NOT NULL,
            tipo_conta ENUM('admin', 'normal') NOT NULL DEFAULT 'normal',
            data_criacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            ativo TINYINT(1) DEFAULT 0,
            token_ativacao VARCHAR(255),
            data_registo DATETIME,
            ultimo_login DATETIME
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // 3.1.2 PROJECTS TABLE
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

        // 3.1.3 CATEGORIES TABLE
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

        // 3.1.4 SUPPLIERS TABLE
        $pdo->exec("CREATE TABLE IF NOT EXISTS fornecedores (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nome VARCHAR(255) NOT NULL UNIQUE,
            data_criacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // 3.1.5 EXPENSES TABLE
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
            ip_address VARCHAR(45),
            user_agent VARCHAR(255),
            FOREIGN KEY (projeto_id) REFERENCES projetos(id),
            FOREIGN KEY (categoria_id) REFERENCES categorias(id),
            FOREIGN KEY (fornecedor_id) REFERENCES fornecedores(id),
            FOREIGN KEY (registrado_por) REFERENCES usuarios(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // 3.1.6 BUDGET HISTORY TABLE
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

        // 3.1.7 DELETION HISTORY TABLE
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

        // 3.1.8 EDIT HISTORY TABLE
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
            ip_address VARCHAR(45),
            FOREIGN KEY (projeto_id) REFERENCES projetos(id),
            FOREIGN KEY (editado_por) REFERENCES usuarios(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // 3.1.9 AUDIT LOG TABLE
        $pdo->exec("CREATE TABLE IF NOT EXISTS expense_audit_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            expense_id INT NOT NULL,
            action VARCHAR(20) NOT NULL,
            action_by INT NOT NULL,
            action_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            ip_address VARCHAR(45),
            user_agent VARCHAR(255),
            FOREIGN KEY (expense_id) REFERENCES despesas(id),
            FOREIGN KEY (action_by) REFERENCES usuarios(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        return true;
    } catch (PDOException $e) {
        return $e->getMessage();
    }
}

// 4.0 FILE SYSTEM OPERATIONS

/**
 * 4.1 CREATE REQUIRED DIRECTORIES
 * @return array List of errors encountered
 */
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
            $errors[] = "Failed to create directory: $dir";
        }
    }
    
    return $errors;
}

/**
 * 4.2 GENERATE CONFIGURATION FILE
 * @param string $host Database host
 * @param string $db_name Database name
 * @param string $username Database username
 * @param string $password Database password
 * @param string $system_name Application name
 * @return bool True on success
 */
function generate_config_file($host, $db_name, $username, $password, $system_name) {
    $config_content = "<?php
// 1.0 DATABASE CONFIGURATION
\$host = '$host';
\$db_name = '$db_name';
\$username = '$username';
\$password = '$password';
\$system_name = '$system_name';

// 2.0 SECURITY SETTINGS
define('CSRF_TOKEN_LIFETIME', 3600); // 1 hour
define('PASSWORD_RESET_TIMEOUT', 86400); // 24 hours
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_TIME', 1800); // 30 minutes

// 3.0 APPLICATION SETTINGS
date_default_timezone_set('Europe/Lisbon');

// 4.0 DATABASE CONNECTION
try {
    \$pdo = new PDO(\"mysql:host=\$host;dbname=\$db_name;charset=utf8mb4\", \$username, \$password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_PERSISTENT => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => \"SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci\"
    ]);
} catch(PDOException \$e) {
    error_log('Database connection failed: ' . \$e->getMessage());
    die('System temporarily unavailable. Please try again later.');
}
?>";

    return file_put_contents('config/db.php', $config_content) !== false;
}

// 5.0 IMAGE HANDLING FUNCTIONS

/**
 * 5.1 VALIDATE LOGO UPLOAD
 * @return bool|string True if valid, error message if invalid
 */
function validate_logo_upload() {
    if (!isset($_FILES['logo']) || $_FILES['logo']['error'] == UPLOAD_ERR_NO_FILE) {
        return true; // Logo is optional
    }
    
    $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
    $max_size = 1024 * 1024; // 1MB
    
    if ($_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
        return "Upload error: " . $_FILES['logo']['error'];
    }
    
    if (!in_array($_FILES['logo']['type'], $allowed_types)) {
        return "Invalid file type. Please upload JPEG, PNG or GIF.";
    }
    
    if ($_FILES['logo']['size'] > $max_size) {
        return "Logo size must be less than 1MB.";
    }
    
    return true;
}

/**
 * 5.2 PROCESS LOGO UPLOAD
 * @return string Path to uploaded logo
 */
function process_logo_upload() {
    if (!isset($_FILES['logo']) || $_FILES['logo']['error'] == UPLOAD_ERR_NO_FILE) {
        return 'logo_p.png'; // Default logo
    }
    
    $upload_dir = 'assets/img/';
    $file_ext = pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION);
    $file_name = 'logo.' . $file_ext;
    $target_file = $upload_dir . $file_name;
    
    // Resize image if needed
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
        
        // Preserve transparency for PNG and GIF
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

// 6.0 DATABASE CONNECTION FUNCTIONS

/**
 * 6.1 TEST DATABASE CONNECTION
 * @param string $host Database host
 * @param string $db_name Database name
 * @param string $username Database username
 * @param string $password Database password
 * @return array Connection result with status and PDO object
 */
function test_database_connection($host, $db_name, $username, $password) {
    try {
        $connection_methods = [
            "mysql:host=$host",
            "mysql:host=127.0.0.1",
            "mysql:unix_socket=/var/run/mysqld/mysqld.sock"
        ];
        
        $connected = false;
        $last_error = null;
        
        foreach ($connection_methods as $dsn_prefix) {
            try {
                $pdo = new PDO("$dsn_prefix", $username, $password);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $connected = true;
                break;
            } catch (PDOException $e) {
                $last_error = $e;
                continue;
            }
        }
        
        if (!$connected) {
            throw $last_error;
        }
        
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db_name` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        
        $pdo = new PDO("$dsn_prefix;dbname=$db_name;charset=utf8mb4", $username, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        return ['success' => true, 'pdo' => $pdo, 'connection_method' => $dsn_prefix];
    } catch (PDOException $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// 7.0 USER MANAGEMENT FUNCTIONS

/**
 * 7.1 CREATE ADMIN USER
 * @param PDO $pdo Database connection
 * @param string $email Admin email
 * @param string $password Admin password
 * @return bool|string True on success, error message on failure
 */
function create_admin_user($pdo, $email, $password) {
    try {
        $hashed_password = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        
        $stmt = $pdo->prepare("INSERT INTO usuarios 
                              (email, senha, tipo_conta, ativo, data_registo) 
                              VALUES (:email, :senha, 'admin', 1, NOW())");
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':senha', $hashed_password);
        $stmt->execute();
        
        return true;
    } catch (PDOException $e) {
        return $e->getMessage();
    }
}

// 8.0 INSTALLATION STEP PROCESSING

/**
 * 8.1 PROCESS STEP 2 (DATABASE CONFIGURATION)
 * @return array Result with success status and message
 */
function process_step2() {
    global $debug_info;
    
    $db_host = $_POST['db_host'] ?? '';
    $db_name = $_POST['db_name'] ?? '';
    $db_user = $_POST['db_user'] ?? '';
    $db_pass = $_POST['db_pass'] ?? '';
    
    error_log("process_step2: Host=$db_host, DB=$db_name, User=$db_user");
    
    if (empty($db_host) || empty($db_name) || empty($db_user)) {
        return ['success' => false, 'message' => 'All database fields are required except password if user has none.'];
    }
    
    $_SESSION['db_host'] = $db_host;
    $_SESSION['db_name'] = $db_name;
    $_SESSION['db_user'] = $db_user;
    $_SESSION['db_pass'] = $db_pass;
    
    $connection = test_database_connection($db_host, $db_name, $db_user, $db_pass);
    if (!$connection['success']) {
        return ['success' => false, 'message' => 'Database connection error: ' . $connection['message']];
    }
    
    return ['success' => true];
}

/**
 * 8.2 PROCESS STEP 3 (SYSTEM CONFIGURATION)
 * @return array Result with success status and message
 */
function process_step3() {
    $admin_email = $_POST['admin_email'] ?? '';
    $admin_password = $_POST['admin_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $system_name = $_POST['system_name'] ?? 'Budget Control';
    
    if (empty($admin_email) || empty($admin_password) || empty($confirm_password)) {
        return ['success' => false, 'message' => 'All fields are required.'];
    }
    
    if (!filter_var($admin_email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => 'Invalid admin email.'];
    }
    
    if ($admin_password !== $confirm_password) {
        return ['success' => false, 'message' => 'Passwords do not match.'];
    }
    
    if (strlen($admin_password) < 8) {
        return ['success' => false, 'message' => 'Password must be at least 8 characters.'];
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

/**
 * 8.3 FINALIZE INSTALLATION
 * @return array Result with success status and message
 */
function finalize_installation() {
    try {
        error_log("Finalizing installation: Host=" . ($_SESSION['db_host'] ?? 'EMPTY') . 
                 ", DB=" . ($_SESSION['db_name'] ?? 'EMPTY') . 
                 ", User=" . ($_SESSION['db_user'] ?? 'EMPTY'));
        
        $connection_host = ($_SESSION['db_host'] === 'localhost') ? '127.0.0.1' : $_SESSION['db_host'];
        $db_user = $_SESSION['db_user'];
        $db_pass = $_SESSION['db_pass'] ?? '';
        
        try {
            $pdo = new PDO("mysql:host=$connection_host;dbname={$_SESSION['db_name']};charset=utf8mb4", 
                          $db_user, $db_pass, [
                              PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                              PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                              PDO::ATTR_EMULATE_PREPARES => false
                          ]);
        } catch (PDOException $e) {
            error_log("First attempt failed: " . $e->getMessage());
            $pdo = new PDO("mysql:host=$connection_host", $db_user, $db_pass);
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$_SESSION['db_name']}` 
                       DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo = new PDO("mysql:host=$connection_host;dbname={$_SESSION['db_name']};charset=utf8mb4", 
                          $db_user, $db_pass);
        }
        
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $tables_result = create_database_tables($pdo);
        if ($tables_result !== true) {
            return ['success' => false, 'message' => 'Table creation error: ' . $tables_result];
        }
        
        $dir_errors = create_directories();
        if (!empty($dir_errors)) {
            return ['success' => false, 'message' => implode('<br>', $dir_errors)];
        }
        
        $logo_path = process_logo_upload();
        
        $admin_result = create_admin_user($pdo, $_SESSION['admin_email'], $_SESSION['admin_password']);
        if ($admin_result !== true) {
            return ['success' => false, 'message' => 'Admin creation error: ' . $admin_result];
        }
        
        $config_result = generate_config_file(
            $_SESSION['db_host'],
            $_SESSION['db_name'],
            $_SESSION['db_user'],
            $_SESSION['db_pass'],
            $_SESSION['system_name']
        );
        
        if (!$config_result) {
            return ['success' => false, 'message' => 'Config file generation failed.'];
        }
        
        file_put_contents('install_lock', 'Installation completed on ' . date('Y-m-d H:i:s'));
        
        return ['success' => true];
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Installation error: ' . $e->getMessage()];
    }
}

// 9.0 INSTALLATION LOCK CHECK
if (file_exists('install_lock') && !isset($_GET['force'])) {
    $lock_message = "Installation already completed. To reinstall, delete 'install_lock' file or use 'force' parameter.";
} else {

    // 10.0 PROCESS FORM SUBMISSIONS
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        switch ($step) {
            case 2:
                // 10.1 PROCESS DATABASE CONFIGURATION STEP
                $result = process_step2();
                if ($result['success']) {
                    $step = 3;
                    // 10.1.1 CLEAR ANY PREVIOUS ERRORS
                    $errors = [];
                } else {
                    // 10.1.2 STORE ERROR MESSAGE
                    $errors[] = $result['message'];
                    // 10.1.3 LOG FAILED ATTEMPT
                    error_log("Database configuration failed: " . $result['message']);
                }
                break;
                
            case 3:
                // 10.2 PROCESS SYSTEM CONFIGURATION STEP
                $result = process_step3();
                if ($result['success']) {
                    $step = 4;
                    // 10.2.1 CLEAR ANY PREVIOUS ERRORS
                    $errors = [];
                } else {
                    // 10.2.2 STORE ERROR MESSAGE
                    $errors[] = $result['message'];
                    // 10.2.3 LOG FAILED ATTEMPT
                    error_log("System configuration failed: " . $result['message']);
                }
                break;
                
            case 4:
                // 10.3 PROCESS FINAL INSTALLATION STEP
                $result = finalize_installation();
                if ($result['success']) {
                    $step = 5;
                    // 10.3.1 CLEAR SESSION DATA
                    session_unset();
                    // 10.3.2 LOG SUCCESSFUL INSTALLATION
                    error_log("Installation completed successfully for system: " . $_SESSION['system_name']);
                } else {
                    // 10.3.3 STORE ERROR MESSAGE
                    $errors[] = $result['message'];
                    // 10.3.4 LOG FAILED INSTALLATION
                    error_log("Installation failed: " . $result['message']);
                }
                break;
        }
    }

    // 11.0 INSTALLATION INTERFACE
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <!-- 11.1 PAGE METADATA -->
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Installation - Budget Control System</title>
        
        <!-- 11.2 SECURITY META TAGS -->
        <meta http-equiv="Content-Security-Policy" content="default-src 'self'; style-src 'self' 'unsafe-inline' https://stackpath.bootstrapcdn.com; img-src 'self' data:;">
        <meta http-equiv="X-Content-Type-Options" content="nosniff">
        <meta http-equiv="X-Frame-Options" content="DENY">
        
        <!-- 11.3 CSS INCLUDES -->
        <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" 
              integrity="sha384-JcKb8q3iqJ61gNV9KGb8thSsNjpSL0n8PARn9HuZOnIxN0hoP+VmmDGMN5t9UJ0Z" 
              crossorigin="anonymous">
        
        <!-- 11.4 INLINE STYLES -->
        <style>
            /* 11.4.1 BASE STYLES */
            body {
                background-color: #f5f5f7;
                font-family: 'Segoe UI', Arial, sans-serif;
                line-height: 1.6;
                color: #333;
                padding-top: 20px;
            }
            
            /* 11.4.2 CONTAINER STYLES */
            .container {
                max-width: 800px;
                background-color: #fff;
                border-radius: 10px;
                padding: 30px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                margin-bottom: 40px;
            }
            
            /* 11.4.3 STEP INDICATOR STYLES */
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
            
            /* 11.4.4 FORM STYLES */
            .form-group label {
                font-weight: bold;
            }
            
            /* 11.4.5 REQUIREMENT LIST STYLES */
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
            
            /* 11.4.6 ERROR MESSAGE STYLES */
            .error-list {
                background-color: #f8d7da;
                color: #721c24;
                padding: 15px;
                border-radius: 4px;
                margin-bottom: 20px;
            }
            
            /* 11.4.7 INSTRUCTION BOX STYLES */
            .instructions {
                background-color: #f0f7ff;
                padding: 15px;
                border-left: 4px solid #2062b7;
                border-radius: 4px;
                margin-bottom: 20px;
            }
            
            /* 11.4.8 LOGO PREVIEW STYLES */
            .logo-preview {
                max-width: 300px;
                max-height: 120px;
                margin-top: 10px;
                display: none;
                padding: 10px;
                border: 1px solid #ddd;
                border-radius: 4px;
            }
            
            /* 11.4.9 BUTTON STYLES */
            .step-buttons {
                display: flex;
                justify-content: space-between;
                margin-top: 30px;
            }
            
            /* 11.4.10 SUCCESS MESSAGE STYLES */
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
        <!-- 12.0 MAIN CONTAINER -->
        <div class="container">
            <!-- 12.1 HEADER SECTION -->
            <div class="step-header">
                <h1>Budget Control System Installation</h1>
                <p>Follow the steps to configure your installation</p>
            </div>
            
            <?php if (isset($lock_message)): ?>
                <!-- 12.2 INSTALLATION LOCK MESSAGE -->
                <div class="alert alert-warning">
                    <?php echo htmlspecialchars($lock_message, ENT_QUOTES, 'UTF-8'); ?>
                    <p>
                        <a href="index.php" class="btn btn-primary mt-3">Go to Home Page</a>
                    </p>
                </div>
            <?php else: ?>
                <!-- 12.3 STEP INDICATOR -->
                <div class="step-indicator">
                    <div class="step <?php echo $step >= 1 ? 'active' : ''; ?> <?php echo $step > 1 ? 'completed' : ''; ?>">1</div>
                    <div class="step <?php echo $step >= 2 ? 'active' : ''; ?> <?php echo $step > 2 ? 'completed' : ''; ?>">2</div>
                    <div class="step <?php echo $step >= 3 ? 'active' : ''; ?> <?php echo $step > 3 ? 'completed' : ''; ?>">3</div>
                    <div class="step <?php echo $step >= 4 ? 'active' : ''; ?> <?php echo $step > 4 ? 'completed' : ''; ?>">4</div>
                    <div class="step <?php echo $step >= 5 ? 'active' : ''; ?>">5</div>
                </div>
                
                <?php if (!empty($errors)): ?>
                    <!-- 12.4 ERROR DISPLAY -->
                    <div class="error-list">
                        <strong>Errors found:</strong>
                        <ul>
                            <?php foreach ($errors as $error): ?>
                                <li><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                
                <!-- 12.5 STEP CONTENT -->
                <div class="step-content">
                    <?php if ($step == 1): ?>
                        <!-- 12.5.1 STEP 1: REQUIREMENTS CHECK -->
                        <h2>Step 1: System Requirements</h2>
                        <div class="instructions">
                            <p>The system will verify the necessary requirements for installation. Make sure all requirements are met before proceeding.</p>
                        </div>
                        
                        <h4>System Requirements</h4>
                        
                        <div class="card mb-4">
                            <div class="card-body">
                                <div class="requirement-item">
                                    <span>PHP Version (>= <?php echo $requirements['php']; ?>)</span>
                                    <?php $php_check = check_php_version($requirements['php']); ?>
                                    <span class="requirement-status <?php echo $php_check ? 'status-ok' : 'status-error'; ?>">
                                        <?php echo $php_check ? 'OK ('.PHP_VERSION.')' : 'Error (Current: '.PHP_VERSION.')'; ?>
                                    </span>
                                </div>
                                
                                <?php foreach ($requirements['extensions'] as $extension): ?>
                                    <div class="requirement-item">
                                        <span>PHP Extension: <?php echo $extension; ?></span>
                                        <?php $ext_check = check_extension($extension); ?>
                                        <span class="requirement-status <?php echo $ext_check ? 'status-ok' : 'status-error'; ?>">
                                            <?php echo $ext_check ? 'OK' : 'Not installed'; ?>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                                
                                <div class="requirement-item">
                                    <span>Write permissions in current directory</span>
                                    <?php $write_check = is_writable('.'); ?>
                                    <span class="requirement-status <?php echo $write_check ? 'status-ok' : 'status-error'; ?>">
                                        <?php echo $write_check ? 'OK' : 'No permission'; ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="step-buttons">
                            <?php
                            $all_requirements_met = 
                                check_php_version($requirements['php']) && 
                                array_reduce($requirements['extensions'], function($carry, $extension) {
                                    return $carry && check_extension($extension);
                                }, true) &&
                                is_writable('.');
                            ?>
                            
                            <?php if ($all_requirements_met): ?>
                                <a href="?step=2" class="btn btn-primary">Next Step</a>
                            <?php else: ?>
                                <div class="alert alert-danger">
                                    Please resolve unmet requirements before continuing.
                                </div>
                            <?php endif; ?>
                        </div>
                        
                    <?php elseif ($step == 2): ?>
                        <!-- 12.5.2 STEP 2: DATABASE CONFIGURATION -->
                        <h2>Step 2: Database Configuration</h2>
                        <div class="instructions">
                            <p>Provide MySQL database connection information. The database will be created automatically if it doesn't exist.</p>
                        </div>
                        
                        <form method="post" action="?step=2">
                            <div class="form-group">
                                <label for="db_host">Database Host:</label>
                                <input type="text" class="form-control" id="db_host" name="db_host" 
                                       value="<?php echo htmlspecialchars($_SESSION['db_host'] ?? 'localhost', ENT_QUOTES, 'UTF-8'); ?>" required>
                                <small class="form-text text-muted">Usually "localhost" or database server IP address.</small>
                            </div>
                            
                            <div class="form-group">
                                <label for="db_name">Database Name:</label>
                                <input type="text" class="form-control" id="db_name" name="db_name" 
                                       value="<?php echo htmlspecialchars($_SESSION['db_name'] ?? 'budget_control', ENT_QUOTES, 'UTF-8'); ?>" required>
                                <small class="form-text text-muted">The database will be created automatically if it doesn't exist.</small>
                            </div>
                            
                            <div class="form-group">
                                <label for="db_user">Database Username:</label>
                                <input type="text" class="form-control" id="db_user" name="db_user" 
                                       value="<?php echo htmlspecialchars($_SESSION['db_user'] ?? 'root', ENT_QUOTES, 'UTF-8'); ?>" required>
                                <small class="form-text text-muted">User with permissions to create and modify databases.</small>
                            </div>
                            
                            <div class="form-group">
                                <label for="db_pass">Database Password:</label>
                                <input type="password" class="form-control" id="db_pass" name="db_pass" 
                                       value="<?php echo htmlspecialchars($_SESSION['db_pass'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                <small class="form-text text-muted">Leave blank if no password is set.</small>
                            </div>
                            
                            <div class="step-buttons">
                                <a href="?step=1" class="btn btn-secondary">Back</a>
                                <button type="submit" class="btn btn-primary">Next Step</button>
                            </div>
                        </form>
                        
                    <?php elseif ($step == 3): ?>
                        <!-- 12.5.3 STEP 3: SYSTEM CONFIGURATION -->
                        <h2>Step 3: System Configuration</h2>
                        <div class="instructions">
                            <p>Configure basic system and administrator information.</p>
                        </div>
                        
                        <form method="post" action="?step=3" enctype="multipart/form-data">
                            <div class="form-group">
                                <label for="admin_email">Admin Email:</label>
                                <input type="email" class="form-control" id="admin_email" name="admin_email" 
                                       value="<?php echo htmlspecialchars($_SESSION['admin_email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                                <small class="form-text text-muted">This email will be used for the first admin login.</small>
                            </div>
                            
                            <div class="form-group">
                                <label for="admin_password">Admin Password:</label>
                                <input type="password" class="form-control" id="admin_password" name="admin_password" required>
                                <small class="form-text text-muted">Minimum 8 characters.</small>
                            </div>
                            
                            <div class="form-group">
                                <label for="confirm_password">Confirm Password:</label>
                                <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="system_name">System Name:</label>
                                <input type="text" class="form-control" id="system_name" name="system_name" 
                                       value="<?php echo htmlspecialchars($_SESSION['system_name'] ?? 'Budget Control', ENT_QUOTES, 'UTF-8'); ?>" required>
                                <small class="form-text text-muted">Name that will appear in page headers and titles.</small>
                            </div>
                            
                            <div class="form-group">
                                <label for="logo">System Logo (optional):</label>
                                <input type="file" class="form-control-file" id="logo" name="logo" accept="image/jpeg,image/png,image/gif">
                                <small class="form-text text-muted">Max size: 1MB. Formats: JPEG, PNG, GIF. Image will be resized if needed.</small>
                                <img id="logo-preview" class="logo-preview" alt="Logo preview">
                            </div>
                            
                            <div class="step-buttons">
                                <a href="?step=2" class="btn btn-secondary">Back</a>
                                <button type="submit" class="btn btn-primary">Next Step</button>
                            </div>
                        </form>
                        
                    <?php elseif ($step == 4): ?>
                        <!-- 12.5.4 STEP 4: CONFIRMATION -->
                        <h2>Step 4: Confirmation</h2>
                        <div class="instructions">
                            <p>Review the information below and click "Complete Installation" to finish the process.</p>
                        </div>
    
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5>Database Information</h5>
                            </div>
                            <div class="card-body">
                                <p><strong>Host:</strong> <?php echo htmlspecialchars($_SESSION['db_host'] ?? 'Not set', ENT_QUOTES, 'UTF-8'); ?></p>
                                <p><strong>Database Name:</strong> <?php echo htmlspecialchars($_SESSION['db_name'] ?? 'Not set', ENT_QUOTES, 'UTF-8'); ?></p>
                                <p><strong>Username:</strong> <?php echo htmlspecialchars($_SESSION['db_user'] ?? 'Not set', ENT_QUOTES, 'UTF-8'); ?></p>
                            </div>
                        </div>
    
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5>System Information</h5>
                            </div>
                            <div class="card-body">
                                <p><strong>System Name:</strong> <?php echo htmlspecialchars($_SESSION['system_name'] ?? 'Not set', ENT_QUOTES, 'UTF-8'); ?></p>
                                <p><strong>Admin Email:</strong> <?php echo htmlspecialchars($_SESSION['admin_email'] ?? 'Not set', ENT_QUOTES, 'UTF-8'); ?></p>
                                <p><strong>Logo:</strong> <?php echo isset($_FILES['logo']) && $_FILES['logo']['error'] == 0 ? 'Custom' : 'Default'; ?></p>
                            </div>
                        </div>
    
                        <form method="post" action="?step=4">
                            <div class="alert alert-warning">
                                <p><strong>Warning:</strong> When completing installation, the following will be created:</p>
                                <ul>
                                    <li>Database and tables</li>
                                    <li>Configuration files</li>
                                    <li>Directories for uploaded files</li>
                                </ul>
                                <p>Make sure all information is correct.</p>
                            </div>
            
                            <div class="step-buttons">
                                <a href="?step=3" class="btn btn-secondary">Back</a>
                                <button type="submit" class="btn btn-success">Complete Installation</button>
                            </div>
                        </form>
                        
                    <?php elseif ($step == 5): ?>
                        <!-- 12.5.5 STEP 5: COMPLETION -->
                        <div class="success-message">
                            <div class="success-icon">✓</div>
                            <h2>Installation Completed Successfully!</h2>
                            <p>The Budget Control System has been installed correctly.</p>
                            <p>You can now access the system and start using it.</p>
                            
                            <div class="alert alert-info mt-4">
                                <p><strong>Login Information:</strong></p>
                                <p>Email: <?php echo htmlspecialchars($_SESSION['admin_email'] ?? '', ENT_QUOTES, 'UTF-8'); ?></p>
                                <p>Password: The password you set during installation</p>
                            </div>
                            
                            <div class="mt-4">
                                <p><strong>For security, the installer will be automatically disabled.</strong></p>
                                <p>If you need to reinstall, delete the "install_lock" file in the root directory.</p>
                            </div>
                            
                            <a href="login.php" class="btn btn-primary mt-4">Go to Login Page</a>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- 13.0 JAVASCRIPT INCLUDES -->
        <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js" 
                integrity="sha384-DfXdz2htPH0lsSSs5nCTpuj/zy4C+OGpamoFVy38MVBnE+IbbVYUew+OrCXaRkfj" 
                crossorigin="anonymous"></script>
        <script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js" 
                integrity="sha384-9/reFTGAW83EW2RDu2S0VKaIzap3H66lZH81PoYlFhbGU+6BZp6G7niu735Sk7lN" 
                crossorigin="anonymous"></script>
        <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js" 
                integrity="sha384-B4gt1jrGC7Jh4AgTPSdUtOBvfO8shuf57BaghqFfPlYxofvL8/KUEfYiJOMMV+rV" 
                crossorigin="anonymous"></script>
        
        <!-- 14.0 CUSTOM JAVASCRIPT -->
        <script>

            // 14.1 LOGO PREVIEW FUNCTIONALITY
            document.addEventListener('DOMContentLoaded', function() {
                // 14.1.1 GET ELEMENTS
                const logoInput = document.getElementById('logo');
                const logoPreview = document.getElementById('logo-preview');
                
                // 14.1.2 VALIDATE ELEMENTS EXIST
                if (!logoInput || !logoPreview) return;
                
                // 14.1.3 FILE INPUT CHANGE HANDLER
                logoInput.addEventListener('change', function(event) {
                    // 14.1.3.1 VALIDATE FILE SELECTION
                    if (!event.target.files || !event.target.files[0]) {
                        logoPreview.style.display = 'none';
                        return;
                    }
                    
                    // 14.1.3.2 VALIDATE FILE TYPE
                    const file = event.target.files[0];
                    const validTypes = ['image/jpeg', 'image/png', 'image/gif'];
                    if (!validTypes.includes(file.type)) {
                        alert('Invalid file type. Please select JPEG, PNG or GIF.');
                        event.target.value = '';
                        logoPreview.style.display = 'none';
                        return;
                    }
                    
                    // 14.1.3.3 VALIDATE FILE SIZE (client-side)
                    const maxSize = 1024 * 1024; // 1MB
                    if (file.size > maxSize) {
                        alert('File size exceeds 1MB limit. Please choose a smaller image.');
                        event.target.value = '';
                        logoPreview.style.display = 'none';
                        return;
                    }
                    
                    // 14.1.3.4 CREATE PREVIEW
                    const reader = new FileReader();
                    
                    // 14.1.3.5 READER LOAD HANDLER
                    reader.onload = function(e) {
                        logoPreview.src = e.target.result;
                        logoPreview.style.display = 'block';
                        
                        // 14.1.3.6 ADD STYLING TO PREVIEW
                        logoPreview.style.maxWidth = '100%';
                        logoPreview.style.height = 'auto';
                        logoPreview.style.marginTop = '10px';
                        logoPreview.style.borderRadius = '4px';
                    };
                    
                    // 14.1.3.7 ERROR HANDLER
                    reader.onerror = function() {
                        console.error('Error reading file');
                        logoPreview.style.display = 'none';
                    };
                    
                    // 14.1.3.8 READ FILE
                    reader.readAsDataURL(file);
                });
                
                // 14.1.4 DRAG AND DROP FUNCTIONALITY
                const dropArea = logoInput.closest('.form-group');
                
                // 14.1.4.1 PREVENT DEFAULT DRAG BEHAVIORS
                ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
                    dropArea.addEventListener(eventName, preventDefaults, false);
                });
                
                function preventDefaults(e) {
                    e.preventDefault();
                    e.stopPropagation();
                }
                
                // 14.1.4.2 HIGHLIGHT DROP AREA
                ['dragenter', 'dragover'].forEach(eventName => {
                    dropArea.addEventListener(eventName, highlight, false);
                });
                
                ['dragleave', 'drop'].forEach(eventName => {
                    dropArea.addEventListener(eventName, unhighlight, false);
                });
                
                function highlight() {
                    dropArea.classList.add('highlight');
                }
                
                function unhighlight() {
                    dropArea.classList.remove('highlight');
                }
                
                // 14.1.4.3 HANDLE DROP
                dropArea.addEventListener('drop', handleDrop, false);
                
                function handleDrop(e) {
                    const dt = e.dataTransfer;
                    const files = dt.files;
                    
                    if (files.length) {
                        logoInput.files = files;
                        const event = new Event('change');
                        logoInput.dispatchEvent(event);
                    }
                }
            });

            // 14.2 FORM VALIDATION
            document.addEventListener('DOMContentLoaded', function() {
                // 14.2.1 GET ALL FORMS
                const forms = document.querySelectorAll('form');
                
                // 14.2.2 ADD VALIDATION TO EACH FORM
                forms.forEach(form => {
                    // 14.2.2.1 PASSWORD MATCHING VALIDATION
                    const passwordInput = form.querySelector('#admin_password');
                    const confirmPasswordInput = form.querySelector('#confirm_password');
                    
                    if (passwordInput && confirmPasswordInput) {
                        // 14.2.2.1.1 REAL-TIME VALIDATION
                        confirmPasswordInput.addEventListener('input', function() {
                            if (passwordInput.value !== confirmPasswordInput.value) {
                                confirmPasswordInput.setCustomValidity('Passwords do not match');
                            } else {
                                confirmPasswordInput.setCustomValidity('');
                            }
                        });
                    }
                    
                    // 14.2.2.2 EMAIL VALIDATION
                    const emailInput = form.querySelector('#admin_email');
                    if (emailInput) {
                        emailInput.addEventListener('input', function() {
                            if (!emailInput.validity.valid) {
                                emailInput.setCustomValidity('Please enter a valid email address');
                            } else {
                                emailInput.setCustomValidity('');
                            }
                        });
                    }
                    
                    // 14.2.2.3 FORM SUBMISSION HANDLER
                    form.addEventListener('submit', function(event) {
                        // 14.2.2.3.1 VALIDATE REQUIRED FIELDS
                        const requiredFields = form.querySelectorAll('[required]');
                        let isValid = true;
                        
                        requiredFields.forEach(field => {
                            if (!field.value.trim()) {
                                field.reportValidity();
                                isValid = false;
                            }
                        });
                        
                        // 14.2.2.3.2 VALIDATE PASSWORD LENGTH
                        if (passwordInput && passwordInput.value.length < 8) {
                            passwordInput.setCustomValidity('Password must be at least 8 characters');
                            passwordInput.reportValidity();
                            isValid = false;
                        }
                        
                        // 14.2.2.3.3 PREVENT SUBMISSION IF INVALID
                        if (!isValid) {
                            event.preventDefault();
                            return false;
                        }
                        
                        // 14.2.2.3.4 SHOW LOADING STATE
                        const submitButton = form.querySelector('button[type="submit"]');
                        if (submitButton) {
                            submitButton.disabled = true;
                            submitButton.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Processing...';
                        }
                        
                        return true;
                    });
                });
                
                // 14.2.3 DYNAMIC REQUIREMENT CHECKING
                const requirementItems = document.querySelectorAll('.requirement-item');
                if (requirementItems.length) {
                    // 14.2.3.1 ADD TOOLTIPS
                    requirementItems.forEach(item => {
                        const status = item.querySelector('.requirement-status');
                        if (status) {
                            item.setAttribute('title', status.textContent);
                            item.style.cursor = 'help';
                        }
                    });
                }
            });

            // 14.3 PROGRESS INDICATOR ANIMATION
            document.addEventListener('DOMContentLoaded', function() {
                const stepIndicator = document.querySelector('.step-indicator');
                if (stepIndicator) {
                    // 14.3.1 ANIMATE PROGRESS BAR
                    const steps = stepIndicator.querySelectorAll('.step');
                    let activeFound = false;
                    
                    steps.forEach((step, index) => {
                        // 14.3.1.1 ADD CLICK HANDLERS FOR NAVIGATION
                        step.addEventListener('click', function() {
                            if (index < <?php echo $step; ?>) {
                                window.location.href = `?step=${index + 1}`;
                            }
                        });
                        
                        // 14.3.1.2 ADD HOVER EFFECTS
                        if (index < <?php echo $step; ?>) {
                            step.style.cursor = 'pointer';
                            step.addEventListener('mouseenter', function() {
                                this.style.transform = 'scale(1.1)';
                            });
                            step.addEventListener('mouseleave', function() {
                                this.style.transform = 'scale(1)';
                            });
                        }
                    });
                    
                    // 14.3.2 ANIMATE ACTIVE STEP
                    const activeStep = stepIndicator.querySelector('.step.active');
                    if (activeStep) {
                        activeStep.style.transition = 'all 0.3s ease';
                        activeStep.style.boxShadow = '0 0 0 5px rgba(32, 98, 183, 0.3)';
                        
                        setTimeout(() => {
                            activeStep.style.boxShadow = 'none';
                        }, 1000);
                    }
                }
            });

            // 14.4 ERROR DISPLAY ENHANCEMENTS
            document.addEventListener('DOMContentLoaded', function() {
                const errorList = document.querySelector('.error-list');
                if (errorList) {
                    // 14.4.1 ANIMATE ERROR APPEARANCE
                    errorList.style.opacity = '0';
                    errorList.style.transform = 'translateY(-20px)';
                    errorList.style.transition = 'all 0.3s ease';
                    
                    setTimeout(() => {
                        errorList.style.opacity = '1';
                        errorList.style.transform = 'translateY(0)';
                    }, 100);
                    
                    // 14.4.2 ADD DISMISS BUTTON
                    const dismissButton = document.createElement('button');
                    dismissButton.innerHTML = '&times;';
                    dismissButton.className = 'close';
                    dismissButton.style.position = 'absolute';
                    dismissButton.style.right = '10px';
                    dismissButton.style.top = '10px';
                    dismissButton.setAttribute('aria-label', 'Close');
                    
                    dismissButton.addEventListener('click', function() {
                        errorList.style.opacity = '0';
                        setTimeout(() => {
                            errorList.style.display = 'none';
                        }, 300);
                    });
                    
                    errorList.style.position = 'relative';
                    errorList.prepend(dismissButton);
                }
            });
        </script>
    </body>
    </html>