<?php
// 1.0 AUTHENTICATION FUNCTIONS

/**
 * 1.1 VERIFY USER LOGIN STATUS
 * Starts secure session with full security validations and checks
 * @return void
 * @throws RuntimeException If security checks fail
 */
function verifyLogin() {
    // 1.1.1 VALIDATE SESSION CONFIGURATION
    if (session_status() === PHP_SESSION_NONE) {
        // 1.1.1.1 SET SECURE SESSION PARAMETERS
        $session_params = [
            'cookie_lifetime' => 86400, // 24 hours
            'cookie_secure'   => true,
            'cookie_httponly' => true,
            'use_strict_mode' => true,
            'cookie_samesite' => 'Strict',
            'sid_length'      => 128,
            'sid_bits_per_character' => 6
        ];

        // 1.1.1.2 VALIDATE COOKIE PARAMETERS
        if (ini_get('session.cookie_secure') !== '1' || 
            ini_get('session.cookie_httponly') !== '1' ||
            ini_get('session.use_strict_mode') !== '1') {
            throw new RuntimeException('Insecure session configuration detected');
        }

        // 1.1.1.3 START SECURE SESSION
        if (!session_start($session_params)) {
            throw new RuntimeException('Failed to start secure session');
        }
    }

    // 1.1.2 SESSION FIXATION PROTECTION
    if (!isset($_SESSION['initiated'])) {
        session_regenerate_id(true);
        $_SESSION['initiated'] = true;
        $_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'];
        $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'];
    } else {
        // 1.1.2.1 VALIDATE SESSION CONSISTENCY
        if ($_SESSION['ip_address'] !== $_SERVER['REMOTE_ADDR'] ||
            $_SESSION['user_agent'] !== $_SERVER['HTTP_USER_AGENT']) {
            session_unset();
            session_destroy();
            throw new RuntimeException('Session hijacking detected');
        }
    }

    // 1.1.3 CSRF PROTECTION FOR SENSITIVE OPERATIONS
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $sensitive_paths = [
            '/admin/usuarios.php',
            '/orcamento/editar.php',
            '/despesas/excluir.php'
        ];
        
        if (in_array($_SERVER['SCRIPT_NAME'], $sensitive_paths)) {
            if (empty($_POST['csrf_token']) || 
                !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
                throw new RuntimeException('CSRF token validation failed');
            }
        }
    }

    // 1.1.4 VERIFY AUTHENTICATION STATUS
    if (empty($_SESSION['usuario_id'])) {
        session_regenerate_id(true);
        header("Location: " . getBaseURL() . "/login.php");
        exit;
    }

    // 1.1.5 UPDATE LAST ACTIVITY
    $_SESSION['last_activity'] = time();
    
    // 1.1.6 SESSION TIMEOUT (30 minutes)
    if (isset($_SESSION['last_activity']) && 
        (time() - $_SESSION['last_activity'] > 1800)) {
        session_unset();
        session_destroy();
        header("Location: " . getBaseURL() . "/login.php?timeout=1");
        exit;
    }
}

/**
 * 1.2 GET APPLICATION BASE URL
 * Constructs base URL from server variables if not configured
 * @return string Base application URL
 */
function getBaseURL() {
    global $base_url;
    if (empty($base_url)) {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'];
        $script = dirname($_SERVER['SCRIPT_NAME']);
        return rtrim($protocol . $host . $script, '/');
    }
    return $base_url;
}

/**
 * 1.3 CHECK ADMIN PRIVILEGES
 * Verifies if current user has admin privileges
 * @return bool True if admin, false otherwise
 */
function isAdmin() {
    return (isset($_SESSION['tipo_conta']) && $_SESSION['tipo_conta'] === 'admin');
}

// 2.0 SECURITY UTILITIES

/**
 * 2.1 GENERATE CSRF TOKEN
 * Creates and stores CSRF token for form validation
 * @return string Generated CSRF token
 */
function generateCSRFToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * 2.2 VALIDATE CSRF TOKEN
 * Verifies submitted token matches session token
 * @param string $token Token to validate
 * @return bool True if valid, false otherwise
 */
function verifyCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// 3.0 CATEGORY AND BUDGET FUNCTIONS

/**
 * 3.1 INSERT NEW CATEGORY
 * Creates new budget category with hierarchical structure
 * @param PDO $pdo Database connection
 * @param int $project_id Associated project ID
 * @param string $account_number Category identifier (e.g. 1.1.2)
 * @param string $description Category name/description
 * @param float $budget Allocated budget amount
 * @param int|null $parent_id Parent category ID (null for top-level)
 * @param int $level Hierarchy depth level
 * @return int ID of newly created category
 */
function insertCategory($pdo, $project_id, $account_number, $description, $budget, $parent_id, $level) {
    $stmt = $pdo->prepare("INSERT INTO categorias 
                          (projeto_id, numero_conta, descricao, budget, categoria_pai_id, nivel) 
                          VALUES (:projeto_id, :numero_conta, :descricao, :budget, :categoria_pai_id, :nivel)");
    $stmt->bindParam(':projeto_id', $project_id, PDO::PARAM_INT);
    $stmt->bindParam(':numero_conta', $account_number, PDO::PARAM_STR);
    $stmt->bindParam(':descricao', $description, PDO::PARAM_STR);
    $stmt->bindParam(':budget', $budget, PDO::PARAM_STR);
    $stmt->bindParam(':categoria_pai_id', $parent_id, PDO::PARAM_INT);
    $stmt->bindParam(':nivel', $level, PDO::PARAM_INT);
    $stmt->execute();
    return $pdo->lastInsertId();
}

/**
 * 3.2 UPDATE CATEGORY BUDGET
 * Modifies budget amount and records change in history
 * @param PDO $pdo Database connection
 * @param int $category_id Category to update
 * @param float $new_value New budget amount
 * @param int $user_id User making the change
 * @param string $reason Optional change reason
 * @return bool True on success
 */
function updateCategoryBudget($pdo, $category_id, $new_value, $user_id, $reason = '') {
    // 3.2.1 GET CURRENT VALUE
    $stmt = $pdo->prepare("SELECT budget FROM categorias WHERE id = :id");
    $stmt->bindParam(':id', $category_id, PDO::PARAM_INT);
    $stmt->execute();
    $category = $stmt->fetch();
    $previous_value = $category['budget'];
    
    // 3.2.2 VALIDATE INPUTS
    if (!is_numeric($new_value)) {
        throw new InvalidArgumentException("Budget value must be numeric");
    }
    if ($new_value < 0) {
        throw new InvalidArgumentException("Budget cannot be negative");
    }
    
    // 3.2.3 UPDATE DATABASE
    $stmt = $pdo->prepare("UPDATE categorias SET budget = :budget WHERE id = :id");
    $stmt->bindParam(':budget', $new_value, PDO::PARAM_STR);
    $stmt->bindParam(':id', $category_id, PDO::PARAM_INT);
    $stmt->execute();
    
    // 3.2.4 RECORD CHANGE HISTORY
    $stmt = $pdo->prepare("INSERT INTO historico_budget 
                          (categoria_id, valor_anterior, valor_novo, alterado_por, motivo) 
                          VALUES (:categoria_id, :valor_anterior, :valor_novo, :alterado_por, :motivo)");
    $stmt->bindParam(':categoria_id', $category_id, PDO::PARAM_INT);
    $stmt->bindParam(':valor_anterior', $previous_value, PDO::PARAM_STR);
    $stmt->bindParam(':valor_novo', $new_value, PDO::PARAM_STR);
    $stmt->bindParam(':alterado_por', $user_id, PDO::PARAM_INT);
    $stmt->bindParam(':motivo', $reason, PDO::PARAM_STR);
    $stmt->execute();
    
    return true;
}

/**
 * 3.3 PROCESS CSV IMPORT
 * Handles secure import of budget data from CSV files with enhanced validation
 * @param PDO $pdo Database connection
 * @param string $file Temporary file path of uploaded CSV
 * @param int $project_id Project ID to associate categories with
 * @param int $user_id User ID performing the import
 * @return bool True on success, false on failure
 * @throws InvalidArgumentException On invalid input parameters
 * @throws RuntimeException On file handling or database errors
 */
function processCSVImport($pdo, $file, $project_id, $user_id) {
    // 3.3.1 INPUT VALIDATION
    if (!is_readable($file)) {
        throw new InvalidArgumentException("Cannot read CSV file");
    }

    // 3.3.2 FILE TYPE VALIDATION
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file);
    $allowed_mimes = ['text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel'];
    if (!in_array($mime, $allowed_mimes)) {
        throw new InvalidArgumentException("Invalid file type. Only CSV files are allowed");
    }

    // 3.3.3 FILE SIZE CHECK (max 5MB)
    $max_size = 5 * 1024 * 1024;
    if (filesize($file) > $max_size) {
        throw new RuntimeException("CSV file exceeds maximum size of 5MB");
    }

    // 3.3.4 SET TRANSACTION ISOLATION LEVEL
    $pdo->exec("SET TRANSACTION ISOLATION LEVEL SERIALIZABLE");
    $pdo->beginTransaction();
    
    $categories = [];
    $line_number = 0;
    $max_rows = 1000; // Maximum allowed rows

    try {
        // 3.3.5 OPEN FILE HANDLE
        $handle = fopen($file, 'r');
        if ($handle === false) {
            throw new RuntimeException("Failed to open CSV file");
        }

        // 3.3.6 PROCESS HEADER ROW
        $header = fgetcsv($handle, 0, $this->detectDelimiter($file));
        if ($header === false || count($header) < 3) {
            throw new RuntimeException("Invalid CSV header format");
        }

        // 3.3.7 PROCESS DATA ROWS
        while (($data = fgetcsv($handle, 0, $this->detectDelimiter($file))) !== false) {
            $line_number++;
            
            // 3.3.7.1 ENFORCE ROW LIMIT
            if ($line_number > $max_rows) {
                throw new RuntimeException("CSV file exceeds maximum allowed rows ($max_rows)");
            }

            // 3.3.7.2 VALIDATE ROW FORMAT
            if (count($data) < 3) {
                throw new RuntimeException("Invalid data format on line $line_number");
            }

            // 3.3.7.3 SANITIZE AND VALIDATE DATA
            $account_number = preg_replace('/[^0-9.]/', '', trim($data[0]));
            $description = filter_var(trim($data[1]), FILTER_SANITIZE_STRING, FILTER_FLAG_STRIP_LOW);
            $budget_str = preg_replace('/[^\d,\.\-]/', '', trim($data[2]));

            // Validate account number format (e.g. 1.2.3)
            if (!preg_match('/^[0-9]+(\.[0-9]+)*$/', $account_number)) {
                throw new RuntimeException("Invalid account number format on line $line_number");
            }

            // Validate description
            if (empty($description)) {
                throw new RuntimeException("Empty description on line $line_number");
            }

            // Validate budget value
            $budget_str = str_replace(',', '.', $budget_str);
            if (!is_numeric($budget_str)) {
                throw new RuntimeException("Invalid budget value on line $line_number");
            }

            $budget = (float)$budget_str;
            if ($budget < 0) {
                throw new RuntimeException("Negative budget value on line $line_number");
            }

            // 3.3.7.4 PROCESS CATEGORY HIERARCHY
            $level = substr_count($account_number, '.') + 1;
            $parent_id = null;

            if ($level > 1) {
                $parent_account = implode('.', array_slice(explode('.', $account_number), 0, -1));
                if (!isset($categories[$parent_account])) {
                    throw new RuntimeException("Missing parent category for $account_number on line $line_number");
                }
                $parent_id = $categories[$parent_account];
            }

            // 3.3.7.5 INSERT CATEGORY
            $id = insertCategory($pdo, $project_id, $account_number, $description, $budget, $parent_id, $level);
            $categories[$account_number] = $id;
        }

        // 3.3.8 FINAL VALIDATION
        if ($line_number === 0) {
            throw new RuntimeException("No valid data rows found in CSV");
        }

        $pdo->commit();
        return true;

    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("CSV Import Error: " . $e->getMessage());
        throw $e;
    } finally {
        if (isset($handle) && is_resource($handle)) {
            fclose($handle);
        }
    }
}

/**
 * Helper function to detect CSV delimiter
 */
private function detectDelimiter($file) {
    $delimiters = [',' => 0, ';' => 0, "\t" => 0];
    $first_line = '';
    $handle = fopen($file, 'r');
    if ($handle) {
        $first_line = fgets($handle);
        fclose($handle);
    }
    
    foreach ($delimiters as $delimiter => &$count) {
        $count = count(str_getcsv($first_line, $delimiter));
    }
    
    return array_search(max($delimiters), $delimiters);
}

// 4.0 EXPENSE MANAGEMENT FUNCTIONS

/**
 * 4.1 RECORD NEW EXPENSE
 * Creates a new expense record with enhanced validation and audit logging
 * @param PDO $pdo Database connection
 * @param int $project_id Associated project ID
 * @param int $category_id Budget category ID
 * @param string $supplier_name Supplier/vendor name
 * @param string $type Expense type (goods/services)
 * @param float $amount Expense amount
 * @param string $description Expense description
 * @param string $expense_date Date of expense (YYYY-MM-DD format)
 * @param int $user_id User ID recording the expense
 * @param string|null $attachment_path Path to attached document
 * @return int ID of created expense record
 * @throws InvalidArgumentException On invalid input parameters
 * @throws RuntimeException On database errors
 */
function recordExpense($pdo, $project_id, $category_id, $supplier_name, 
                      $type, $amount, $description, $expense_date, $user_id, 
                      $attachment_path = null) {
    
    // 4.1.1 INPUT VALIDATION
    if (!is_numeric($project_id) || $project_id <= 0) {
        throw new InvalidArgumentException("Invalid project ID");
    }

    if (!is_numeric($category_id) || $category_id <= 0) {
        throw new InvalidArgumentException("Invalid category ID");
    }

    $supplier_name = trim($supplier_name);
    if (empty($supplier_name)) {
        throw new InvalidArgumentException("Supplier name cannot be empty");
    }
    if (strlen($supplier_name) > 100) {
        throw new InvalidArgumentException("Supplier name exceeds maximum length (100 chars)");
    }

    $valid_types = ['goods', 'services'];
    if (!in_array(strtolower($type), $valid_types)) {
        throw new InvalidArgumentException("Invalid expense type");
    }

    if (!is_numeric($amount) || $amount <= 0) {
        throw new InvalidArgumentException("Amount must be positive number");
    }

    $description = trim($description);
    if (empty($description)) {
        throw new InvalidArgumentException("Description cannot be empty");
    }

    if (!DateTime::createFromFormat('Y-m-d', $expense_date)) {
        throw new InvalidArgumentException("Invalid date format (YYYY-MM-DD required)");
    }

    if (!is_numeric($user_id) || $user_id <= 0) {
        throw new InvalidArgumentException("Invalid user ID");
    }

    // 4.1.2 ATTACHMENT VALIDATION
    if ($attachment_path !== null) {
        if (!file_exists($attachment_path)) {
            throw new InvalidArgumentException("Attachment file not found");
        }
        
        $max_file_size = 10 * 1024 * 1024; // 10MB
        if (filesize($attachment_path) > $max_file_size) {
            throw new InvalidArgumentException("Attachment exceeds maximum size of 10MB");
        }
        
        $allowed_mimes = ['application/pdf', 'image/jpeg', 'image/png'];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($attachment_path);
        if (!in_array($mime, $allowed_mimes)) {
            throw new InvalidArgumentException("Invalid attachment type. Only PDF, JPEG and PNG allowed");
        }
    }

    // 4.1.3 CHECK FOR DUPLICATE EXPENSE
    $duplicate_check_sql = "
        SELECT COUNT(*) 
        FROM despesas 
        WHERE projeto_id = :project_id
          AND categoria_id = :category_id
          AND valor = :amount
          AND data_despesa = :expense_date
          AND descricao = :description
        LIMIT 1
    ";
    
    $stmt = $pdo->prepare($duplicate_check_sql);
    $stmt->bindParam(':project_id', $project_id, PDO::PARAM_INT);
    $stmt->bindParam(':category_id', $category_id, PDO::PARAM_INT);
    $stmt->bindParam(':amount', $amount, PDO::PARAM_STR);
    $stmt->bindParam(':expense_date', $expense_date, PDO::PARAM_STR);
    $stmt->bindParam(':description', $description, PDO::PARAM_STR);
    $stmt->execute();
    
    if ($stmt->fetchColumn() > 0) {
        throw new InvalidArgumentException("Duplicate expense detected");
    }

    // 4.1.4 HANDLE SUPPLIER
    try {
        $supplier_id = getOrCreateSupplier($pdo, $supplier_name);
    } catch (Exception $e) {
        throw new RuntimeException("Failed to process supplier: " . $e->getMessage());
    }

    // 4.1.5 RECORD EXPENSE WITH AUDIT INFO
    try {
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("INSERT INTO despesas 
                              (projeto_id, categoria_id, fornecedor_id, tipo, valor, 
                               descricao, data_despesa, registrado_por, anexo_path, data_registro,
                               ip_address, user_agent) 
                              VALUES (:projeto_id, :categoria_id, :fornecedor_id, :tipo, 
                                      :valor, :descricao, :data_despesa, :registrado_por, 
                                      :anexo_path, NOW(),
                                      :ip_address, :user_agent)");
        
        $stmt->bindValue(':projeto_id', $project_id, PDO::PARAM_INT);
        $stmt->bindValue(':categoria_id', $category_id, PDO::PARAM_INT);
        $stmt->bindValue(':fornecedor_id', $supplier_id, PDO::PARAM_INT);
        $stmt->bindValue(':tipo', $type, PDO::PARAM_STR);
        $stmt->bindValue(':valor', $amount, PDO::PARAM_STR);
        $stmt->bindValue(':descricao', $description, PDO::PARAM_STR);
        $stmt->bindValue(':data_despesa', $expense_date, PDO::PARAM_STR);
        $stmt->bindValue(':registrado_por', $user_id, PDO::PARAM_INT);
        $stmt->bindValue(':anexo_path', $attachment_path, PDO::PARAM_STR);
        $stmt->bindValue(':ip_address', $_SERVER['REMOTE_ADDR'] ?? null, PDO::PARAM_STR);
        $stmt->bindValue(':user_agent', $_SERVER['HTTP_USER_AGENT'] ?? null, PDO::PARAM_STR);
        
        if (!$stmt->execute()) {
            throw new RuntimeException("Failed to insert expense record");
        }

        $expense_id = $pdo->lastInsertId();
        
        // 4.1.6 LOG AUDIT TRAIL
        $audit_sql = "
            INSERT INTO expense_audit_log
            (expense_id, action, action_by, action_at, ip_address, user_agent)
            VALUES (:expense_id, 'CREATE', :user_id, NOW(), :ip_address, :user_agent)
        ";
        
        $audit_stmt = $pdo->prepare($audit_sql);
        $audit_stmt->bindValue(':expense_id', $expense_id, PDO::PARAM_INT);
        $audit_stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
        $audit_stmt->bindValue(':ip_address', $_SERVER['REMOTE_ADDR'] ?? null, PDO::PARAM_STR);
        $audit_stmt->bindValue(':user_agent', $_SERVER['HTTP_USER_AGENT'] ?? null, PDO::PARAM_STR);
        $audit_stmt->execute();
        
        $pdo->commit();
        return $expense_id;

    } catch (Exception $e) {
        $pdo->rollBack();
        throw new RuntimeException("Database error: " . $e->getMessage());
    }
}

/**
 * 4.2 GET OR CREATE SUPPLIER
 * Finds existing supplier or creates new one
 * @param PDO $pdo Database connection
 * @param string $name Supplier name
 * @return int Supplier ID
 */
function getOrCreateSupplier($pdo, $name) {
    // 4.2.1 CHECK EXISTING
    $stmt = $pdo->prepare("SELECT id FROM fornecedores WHERE LOWER(nome) = LOWER(:name)");
    $stmt->bindParam(':name', $name, PDO::PARAM_STR);
    $stmt->execute();
    $supplier = $stmt->fetch();
    
    if ($supplier) {
        return $supplier['id'];
    }
    
    // 4.2.2 CREATE NEW
    $stmt = $pdo->prepare("INSERT INTO fornecedores (nome) VALUES (:name)");
    $stmt->bindParam(':name', $name, PDO::PARAM_STR);
    $stmt->execute();
    return $pdo->lastInsertId();
}

// 5.0 REPORTING FUNCTIONS

/**
 * 5.1 GET CATEGORIES WITH EXPENSES
 * Retrieves all categories with their expense totals
 * @param PDO $pdo Database connection
 * @param int $project_id Project ID
 * @return array Categories with expense data
 */
function getCategoriesWithExpenses($pdo, $project_id) {
    $stmt = $pdo->prepare("
        SELECT c.id, c.numero_conta, c.descricao, c.budget, c.nivel, c.categoria_pai_id,
               COALESCE(SUM(d.valor), 0) as total_expenses
        FROM categorias c
        LEFT JOIN despesas d ON c.id = d.categoria_id
        WHERE c.projeto_id = :project_id
        GROUP BY c.id
        ORDER BY c.numero_conta
    ");
    $stmt->bindParam(':project_id', $project_id, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * 5.2 CALCULATE CATEGORY TOTALS
 * Computes hierarchical totals for categories
 * @param array $categories Categories from getCategoriesWithExpenses()
 * @return array Categories with calculated totals
 */
function calculateCategoryTotals($categories) {
    // 5.2.1 ORGANIZE DATA
    $by_id = [];
    $by_parent = [];
    $max_level = 1;
    
    foreach ($categories as $cat) {
        $by_id[$cat['id']] = $cat;
        $by_id[$cat['id']]['delta'] = $cat['budget'] - $cat['total_expenses'];
        
        if ($cat['categoria_pai_id'] !== null) {
            $by_parent[$cat['categoria_pai_id']][] = $cat['id'];
        }
        
        if ($cat['nivel'] > $max_level) {
            $max_level = $cat['nivel'];
        }
    }
    
    // 5.2.2 BOTTOM-UP CALCULATION
    for ($level = $max_level; $level >= 1; $level--) {
        foreach ($by_id as $id => $cat) {
            if ($cat['nivel'] == $level && isset($by_parent[$id])) {
                $total_expenses = 0;
                $total_budget = 0;
                
                foreach ($by_parent[$id] as $child_id) {
                    $total_expenses += $by_id[$child_id]['total_expenses'];
                    $total_budget += $by_id[$child_id]['budget'];
                }
                
                $by_id[$id]['total_expenses'] = $total_expenses;
                $by_id[$id]['budget'] = $total_budget;
                $by_id[$id]['delta'] = $total_budget - $total_expenses;
            }
        }
    }
    
    // 5.2.3 ADD GLOBAL TOTAL
    $global_total = [
        'id' => 0,
        'numero_conta' => 'TOTAL',
        'descricao' => 'GLOBAL TOTAL',
        'nivel' => 0,
        'budget' => 0,
        'total_expenses' => 0,
        'delta' => 0
    ];
    
    foreach ($by_id as $cat) {
        if ($cat['nivel'] == 1) {
            $global_total['budget'] += $cat['budget'];
            $global_total['total_expenses'] += $cat['total_expenses'];
        }
    }
    $global_total['delta'] = $global_total['budget'] - $global_total['total_expenses'];
    $by_id[0] = $global_total;
    
    return $by_id;
}

/**
 * 5.3 GENERATE EXPENSE REPORT CSV
 * Creates and outputs a CSV report with enhanced memory and output handling
 * @param PDO $pdo Database connection
 * @param int $project_id Project ID to generate report for
 * @param array $categories_expenses Array of categories with expense data
 * @return void Outputs CSV directly to browser
 * @throws RuntimeException On output generation failures
 */
function generateExpenseReportCSV($pdo, $project_id, $categories_expenses) {
    // 5.3.1 VALIDATE INPUTS
    if (!is_numeric($project_id) || $project_id <= 0) {
        throw new InvalidArgumentException("Invalid project ID");
    }

    if (!is_array($categories_expenses) || empty($categories_expenses)) {
        throw new InvalidArgumentException("Invalid categories data");
    }

    // 5.3.2 MEMORY LIMIT CHECK
    $memory_limit = ini_get('memory_limit');
    $memory_usage = memory_get_usage(true);
    $allowed_memory = convertToBytes($memory_limit) * 0.8; // Use 80% of allowed memory
    
    if ($memory_usage > $allowed_memory) {
        throw new RuntimeException("Insufficient memory available for report generation");
    }

    // 5.3.3 OUTPUT BUFFER CONTROL
    if (ob_get_level() > 0) {
        ob_end_clean(); // Clear any existing output buffers
    }
    ob_start();

    // 5.3.4 PREPARE OUTPUT HEADERS
    if (headers_sent()) {
        throw new RuntimeException("Cannot send CSV - headers already sent");
    }

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="budget_report_' . 
           htmlspecialchars($project_id, ENT_QUOTES, 'UTF-8') . '_' . 
           date('Y-m-d') . '.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');

    // 5.3.5 CREATE OUTPUT STREAM
    try {
        $output = fopen('php://output', 'w');
        if ($output === false) {
            throw new RuntimeException("Failed to open output stream");
        }

        // 5.3.6 WRITE UTF-8 BOM
        fwrite($output, "\xEF\xBB\xBF");

        // 5.3.7 WRITE COLUMN HEADERS
        $headers = [
            'Account Number',
            'Category Description',
            'Budget Amount',
            'Expenses Total',
            'Remaining Budget',
            'Utilization %'
        ];
        
        if (fputcsv($output, $headers, ';') === false) {
            throw new RuntimeException("Failed to write CSV headers");
        }

        // 5.3.8 PROCESS DATA ROWS WITH VALIDATION
        foreach ($categories_expenses as $category) {
            // 5.3.8.1 VALIDATE AND SANITIZE CATEGORY DESCRIPTION
            $description = $category['descricao'] ?? '';
            $description = preg_replace('/[\x00-\x1F\x7F]/', '', $description); // Remove control chars
            $description = substr($description, 0, 255); // Truncate to 255 chars
            
            // 5.3.8.2 CALCULATE UTILIZATION
            $utilization = 0;
            if (isset($category['budget']) && $category['budget'] > 0) {
                $utilization = ($category['total_expenses'] / $category['budget']) * 100;
            }

            // 5.3.8.3 PREPARE ROW DATA
            $row = [
                $category['numero_conta'] ?? '',
                $description,
                isset($category['budget']) ? number_format($category['budget'], 2, ',', '.') : '0,00',
                isset($category['total_expenses']) ? number_format($category['total_expenses'], 2, ',', '.') : '0,00',
                isset($category['delta']) ? number_format($category['delta'], 2, ',', '.') : '0,00',
                number_format($utilization, 2, ',', '.') . '%'
            ];

            // 5.3.8.4 WRITE ROW
            if (fputcsv($output, $row, ';') === false) {
                throw new RuntimeException("Failed to write CSV row");
            }

            // 5.3.8.5 FLUSH OUTPUT PERIODICALLY
            if ($line_number % 100 === 0) {
                ob_flush();
                flush();
            }
        }

        // 5.3.9 WRITE SUMMARY FOOTER
        $global_total = $categories_expenses[0] ?? null;
        if ($global_total && $global_total['numero_conta'] === 'TOTAL') {
            $footer_utilization = 0;
            if ($global_total['budget'] > 0) {
                $footer_utilization = ($global_total['total_expenses'] / $global_total['budget']) * 100;
            }

            $footer = [
                '',
                'TOTAL',
                number_format($global_total['budget'], 2, ',', '.'),
                number_format($global_total['total_expenses'], 2, ',', '.'),
                number_format($global_total['delta'], 2, ',', '.'),
                number_format($footer_utilization, 2, ',', '.') . '%'
            ];

            if (fputcsv($output, $footer, ';') === false) {
                throw new RuntimeException("Failed to write CSV footer");
            }
        }

    } catch (Exception $e) {
        // 5.3.10 ERROR HANDLING
        if (isset($output) && is_resource($output)) {
            fclose($output);
        }
        ob_end_clean();
        throw new RuntimeException("CSV generation failed: " . $e->getMessage());
    } finally {
        // 5.3.11 CLEANUP RESOURCES
        if (isset($output) && is_resource($output)) {
            fclose($output);
        }
        ob_end_flush();
    }
}

/**
 * Helper function to convert memory limit string to bytes
 */
function convertToBytes($memory_limit) {
    if (preg_match('/^(\d+)(.)$/', $memory_limit, $matches)) {
        switch (strtoupper($matches[2])) {
            case 'G': return $matches[1] * 1024 * 1024 * 1024;
            case 'M': return $matches[1] * 1024 * 1024;
            case 'K': return $matches[1] * 1024;
        }
    }
    return (int)$memory_limit;
}

/**
 * 5.4 GET EXPENSES BY CATEGORY
 * Retrieves all expenses for a specific category
 * @param PDO $pdo Database connection
 * @param int $category_id Category ID
 * @return array Expense records
 */
function getExpensesByCategory($pdo, $category_id) {
    $stmt = $pdo->prepare("
        SELECT d.id, d.data_despesa, d.tipo, f.nome as supplier, 
               d.descricao, d.valor, d.anexo_path
        FROM despesas d
        JOIN fornecedores f ON d.fornecedor_id = f.id
        WHERE d.categoria_id = :category_id
        ORDER BY d.data_despesa DESC
    ");
    $stmt->bindParam(':category_id', $category_id, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * 6.1 GET PROJECT SUMMARY
 * Compiles comprehensive statistics with enhanced caching and pagination
 * @param PDO $pdo Database connection
 * @param int $project_id Project ID to summarize
 * @param array $options Additional options:
 *        - 'page' => int (current page for pagination)
 *        - 'per_page' => int (items per page)
 *        - 'timezone' => string (timezone for date display)
 * @return array Structured summary data
 * @throws InvalidArgumentException For invalid parameters
 * @throws RuntimeException For database errors
 */
function getProjectSummary($pdo, $project_id, $options = []) {
    // 6.1.1 INPUT VALIDATION
    if (!is_numeric($project_id) || $project_id <= 0) {
        throw new InvalidArgumentException("Invalid project ID");
    }

    // 6.1.2 SETUP PAGINATION
    $page = max(1, $options['page'] ?? 1);
    $per_page = min(100, max(5, $options['per_page'] ?? 10));
    $offset = ($page - 1) * $per_page;

    // 6.1.3 TIMEZONE HANDLING
    $timezone = new DateTimeZone($options['timezone'] ?? 'UTC');
    $now = new DateTime('now', $timezone);

    // 6.1.4 INITIALIZE RESULT STRUCTURE
    $summary = [
        'project_info' => null,
        'budget_stats' => [
            'total_budget' => 0,
            'total_expenses' => 0,
            'remaining_budget' => 0,
            'utilization_percent' => 0
        ],
        'category_stats' => [
            'total_categories' => 0,
            'critical_categories' => []
        ],
        'expense_stats' => [
            'total_expenses' => 0,
            'recent_expenses' => [],
            'frequent_suppliers' => []
        ],
        'pagination' => [
            'current_page' => $page,
            'per_page' => $per_page,
            'total_items' => 0
        ],
        'timestamps' => [
            'generated_at' => $now->format('Y-m-d H:i:s'),
            'timezone' => $timezone->getName()
        ]
    ];

    try {
        // 6.1.5 CHECK CACHE FIRST
        $cache_key = "project_summary_{$project_id}_page{$page}_per{$per_page}";
        if (function_exists('apcu_exists') && apcu_exists($cache_key)) {
            $cached = apcu_fetch($cache_key);
            if ($cached !== false) {
                return $cached;
            }
        }

        // 6.1.6 GET BASIC PROJECT INFO WITH ARCHIVED CHECK
        $stmt = $pdo->prepare("
            SELECT id, nome as name, descricao as description, 
                   data_criacao as created_at, arquivado as archived
            FROM projetos 
            WHERE id = :project_id
        ");
        $stmt->bindParam(':project_id', $project_id, PDO::PARAM_INT);
        $stmt->execute();
        
        $project = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$project) {
            throw new RuntimeException("Project not found");
        }

        // 6.1.7 CHECK FOR ARCHIVED PROJECT
        if ($project['archived']) {
            throw new InvalidArgumentException("Cannot generate summary for archived project");
        }

        // Convert dates to specified timezone
        $created_at = new DateTime($project['created_at']);
        $created_at->setTimezone($timezone);
        $project['created_at'] = $created_at->format('Y-m-d H:i:s');
        
        $summary['project_info'] = $project;

        // 6.1.8 GET BUDGET STATISTICS
        $categories = getCategoriesWithExpenses($pdo, $project_id);
        $categories_totals = calculateCategoryTotals($categories);
        
        if (isset($categories_totals[0])) {
            $global_total = $categories_totals[0];
            $summary['budget_stats'] = [
                'total_budget' => (float)$global_total['budget'],
                'total_expenses' => (float)$global_total['total_expenses'],
                'remaining_budget' => (float)$global_total['delta'],
                'utilization_percent' => $global_total['budget'] > 0 ? 
                    round(($global_total['total_expenses'] / $global_total['budget']) * 100, 2) : 0
            ];
        }

        // 6.1.9 GET CATEGORY STATISTICS
        $summary['category_stats']['total_categories'] = count($categories);
        
        // Identify critical categories (>90% utilization)
        foreach ($categories_totals as $id => $cat) {
            if ($id !== 0 && $cat['budget'] > 0) {
                $utilization = ($cat['total_expenses'] / $cat['budget']) * 100;
                if ($utilization >= 90) {
                    $summary['category_stats']['critical_categories'][] = [
                        'id' => $cat['id'],
                        'account_number' => $cat['numero_conta'],
                        'description' => $cat['descricao'],
                        'budget' => (float)$cat['budget'],
                        'expenses' => (float)$cat['total_expenses'],
                        'utilization_percent' => round($utilization, 2)
                    ];
                }
            }
        }

        // 6.1.10 GET PAGINATED RECENT EXPENSES
        $stmt = $pdo->prepare("
            SELECT SQL_CALC_FOUND_ROWS
                   d.id, d.data_despesa as expense_date, d.tipo as type,
                   f.nome as supplier, d.descricao as description,
                   d.valor as amount, c.numero_conta as category_code
            FROM despesas d
            JOIN fornecedores f ON d.fornecedor_id = f.id
            JOIN categorias c ON d.categoria_id = c.id
            WHERE d.projeto_id = :project_id
            ORDER BY d.data_registro DESC
            LIMIT :offset, :per_page
        ");
        $stmt->bindValue(':project_id', $project_id, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->bindValue(':per_page', $per_page, PDO::PARAM_INT);
        $stmt->execute();
        
        $recent_expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Convert dates to specified timezone
        foreach ($recent_expenses as &$expense) {
            $date = new DateTime($expense['expense_date']);
            $date->setTimezone($timezone);
            $expense['expense_date'] = $date->format('Y-m-d H:i:s');
        }
        
        $summary['expense_stats']['recent_expenses'] = $recent_expenses;

        // Get total count for pagination
        $stmt = $pdo->query("SELECT FOUND_ROWS()");
        $summary['pagination']['total_items'] = (int)$stmt->fetchColumn();

        // 6.1.11 GET FREQUENT SUPPLIERS (top 5 by spending)
        $stmt = $pdo->prepare("
            SELECT f.id, f.nome as name, 
                   COUNT(d.id) as transaction_count,
                   SUM(d.valor) as total_amount
            FROM despesas d
            JOIN fornecedores f ON d.fornecedor_id = f.id
            WHERE d.projeto_id = :project_id
            GROUP BY f.id
            ORDER BY total_amount DESC
            LIMIT 5
        ");
        $stmt->bindParam(':project_id', $project_id, PDO::PARAM_INT);
        $stmt->execute();
        $summary['expense_stats']['frequent_suppliers'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 6.1.12 CALCULATE TOTAL EXPENSE COUNT
        $stmt = $pdo->prepare("
            SELECT COUNT(id) as total 
            FROM despesas 
            WHERE projeto_id = :project_id
        ");
        $stmt->bindParam(':project_id', $project_id, PDO::PARAM_INT);
        $stmt->execute();
        $summary['expense_stats']['total_expenses'] = (int)$stmt->fetchColumn();

        // 6.1.13 CACHE THE RESULTS (5 minute TTL)
        if (function_exists('apcu_store')) {
            apcu_store($cache_key, $summary, 300);
        }

        return $summary;

    } catch (PDOException $e) {
        throw new RuntimeException("Database error while generating summary: " . $e->getMessage());
    }
}

// 7.0 SEARCH AND UTILITY FUNCTIONS

/**
 * 7.1 SEARCH SUPPLIERS
 * Finds suppliers matching search term
 * @param PDO $pdo Database connection
 * @param string $term Search term
 * @return array Matching suppliers
 */
function searchSuppliers($pdo, $term) {
    $term = "%$term%";
    $stmt = $pdo->prepare("
        SELECT id, nome as name 
        FROM fornecedores 
        WHERE nome LIKE :term 
        ORDER BY nome 
        LIMIT 10
    ");
    $stmt->bindParam(':term', $term, PDO::PARAM_STR);
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * 7.2 SEARCH CATEGORIES
 * Finds categories by description or account number
 * @param PDO $pdo Database connection
 * @param int $project_id Project ID
 * @param string $term Search term
 * @return array Matching categories
 */
function searchCategories($pdo, $project_id, $term) {
    $term = "%$term%";
    $stmt = $pdo->prepare("
        SELECT id, numero_conta as account_number, descricao as description 
        FROM categorias 
        WHERE projeto_id = :project_id 
          AND (descricao LIKE :term OR numero_conta LIKE :term) 
        ORDER BY numero_conta 
        LIMIT 10
    ");
    $stmt->bindParam(':project_id', $project_id, PDO::PARAM_INT);
    $stmt->bindParam(':term', $term, PDO::PARAM_STR);
    $stmt->execute();
    return $stmt->fetchAll();
}

// 8.0 HISTORY AND AUDIT FUNCTIONS

/**
 * 8.1 GET EXPENSE EDIT HISTORY
 * Retrieves complete audit trail with enhanced security and performance
 * @param PDO $pdo Database connection
 * @param int $expense_id Expense record ID to audit
 * @param int $limit Maximum number of history entries to return (default: 50)
 * @param string|null $timezone Timezone for timestamp conversion (default: UTC)
 * @return array Chronological list of modifications
 * @throws InvalidArgumentException For invalid parameters
 * @throws RuntimeException For database errors
 */
function getExpenseEditHistory($pdo, $expense_id, $limit = 50, $timezone = null) {
    // 8.1.1 INPUT VALIDATION
    if (!is_numeric($expense_id) || $expense_id <= 0) {
        throw new InvalidArgumentException("Invalid expense ID");
    }

    if (!is_numeric($limit) || $limit <= 0 || $limit > 1000) {
        $limit = 50; // Enforce reasonable default
    }

    // 8.1.2 TIMEZONE SETUP
    $timezone = $timezone ?? 'UTC';
    try {
        $dtz = new DateTimeZone($timezone);
    } catch (Exception $e) {
        throw new InvalidArgumentException("Invalid timezone specified");
    }

    // 8.1.3 QUERY HISTORY RECORDS WITH PERFORMANCE OPTIMIZATIONS
    try {
        $stmt = $pdo->prepare("
            SELECT /*+ INDEX(h historico_edicoes_registro_idx) */
                h.data_edicao AS edit_timestamp,
                u.email AS editor_email,
                h.campo_alterado AS field_changed,
                h.valor_anterior AS old_value,
                h.valor_novo AS new_value,
                h.motivo AS change_reason,
                h.ip_address AS ip_address,
                h.categoria_id_anterior AS old_category_id,
                h.categoria_id_novo AS new_category_id,
                c_ant.numero_conta AS old_category_code,
                c_novo.numero_conta AS new_category_code
            FROM 
                historico_edicoes h USE INDEX (historico_edicoes_registro_idx)
            JOIN 
                usuarios u FORCE INDEX (PRIMARY) ON h.editado_por = u.id
            LEFT JOIN
                categorias c_ant FORCE INDEX (PRIMARY) ON h.categoria_id_anterior = c_ant.id
            LEFT JOIN
                categorias c_novo FORCE INDEX (PRIMARY) ON h.categoria_id_novo = c_novo.id
            WHERE 
                h.tipo_registro = 'despesa'
                AND h.registro_id = :expense_id
            ORDER BY 
                h.data_edicao DESC
            LIMIT :limit
        ");

        $stmt->bindValue(':expense_id', $expense_id, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        // 8.1.4 FORMAT RESULTS WITH VALIDATION
        $history = [];
        while ($record = $stmt->fetch(PDO::FETCH_ASSOC)) {
            // 8.1.4.1 VALIDATE EDITOR EMAIL FORMAT
            if (!filter_var($record['editor_email'], FILTER_VALIDATE_EMAIL)) {
                $record['editor_email'] = 'invalid-email@domain';
            }

            // 8.1.4.2 CONVERT TIMESTAMP TO SPECIFIED TIMEZONE
            $timestamp = new DateTime($record['edit_timestamp']);
            $timestamp->setTimezone($dtz);
            $formatted_timestamp = $timestamp->format('Y-m-d H:i:s');

            // 8.1.4.3 SANITIZE IP ADDRESS
            $ip_address = filter_var($record['ip_address'], FILTER_VALIDATE_IP) ? 
                $record['ip_address'] : '0.0.0.0';

            // 8.1.4.4 HANDLE CATEGORY ID CHANGES
            if ($record['field_changed'] === 'categoria_id') {
                $record['old_value'] = $record['old_category_code'] ?? 'N/A';
                $record['new_value'] = $record['new_category_code'] ?? 'N/A';
            }

            $history[] = [
                'timestamp' => $formatted_timestamp,
                'editor' => $record['editor_email'],
                'field' => $record['field_changed'],
                'from' => $record['old_value'] ?? 'N/A',
                'to' => $record['new_value'] ?? 'N/A',
                'reason' => $record['change_reason'] ?? '',
                'ip_address' => $ip_address,
                'timezone' => $timezone
            ];
        }

        return $history;

    } catch (PDOException $e) {
        throw new RuntimeException("Failed to retrieve edit history: " . $e->getMessage());
    }
}

/**
 * 8.2 GET DELETION HISTORY
 * Retrieves log of deleted items
 * @param PDO $pdo Database connection
 * @param int $project_id Project ID
 * @param string|null $type Optional item type filter
 * @return array Deletion records
 */
function getDeletionHistory($pdo, $project_id, $type = null) {
    $sql = "
        SELECT h.*, u.email as user_email
        FROM historico_exclusoes h
        JOIN usuarios u ON h.excluido_por = u.id
        WHERE h.projeto_id = :project_id
    ";
    
    if ($type) {
        $sql .= " AND h.tipo_registro = :type";
    }
    
    $sql .= " ORDER BY h.data_exclusao DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':project_id', $project_id, PDO::PARAM_INT);
    
    if ($type) {
        $stmt->bindParam(':type', $type, PDO::PARAM_STR);
    }
    
    $stmt->execute();
    return $stmt->fetchAll();
}