<?php
// 1.0 INITIALIZATION AND SECURITY
session_start();

// 1.1 SECURITY HEADERS
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");

// 1.2 FILE INCLUDES
require_once '../config/db.php';
require_once '../includes/functions.php';

// 1.3 AUTHENTICATION CHECK
verifyLogin();

// 1.4 ADMIN PRIVILEGE CHECK
if (!isAdmin()) {
    header('Location: ../dashboard.php');
    exit;
}

// 2.0 VARIABLE DECLARATIONS
$message = '';
$action_performed = false;

// 3.0 POST REQUEST HANDLING
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // 3.1 CSRF TOKEN VALIDATION
    if (!verifyCSRFToken($_POST['csrf_token'])) {
        $message = "Security token invalid. Please try again.";
    } else {
        // 3.2 PROMOTE USER TO ADMIN
        if (isset($_POST['promote']) {
            $user_id = (int)$_POST['user_id'];
            try {
                $stmt = $pdo->prepare("UPDATE usuarios SET tipo_conta = 'admin' WHERE id = :id");
                $stmt->bindParam(':id', $user_id, PDO::PARAM_INT);
                $stmt->execute();
                $message = "User successfully promoted to administrator.";
                $action_performed = true;
            } catch (PDOException $e) {
                error_log("Promote user error: " . $e->getMessage());
                $message = "Error promoting user.";
            }
        }
        // 3.3 DEMOTE ADMIN TO NORMAL USER
        elseif (isset($_POST['demote'])) {
            $user_id = (int)$_POST['user_id'];
            $current_admin = $_SESSION['usuario_id'];
            
            if ($user_id == $current_admin) {
                $message = "You cannot demote yourself.";
            } else {
                try {
                    $stmt = $pdo->prepare("UPDATE usuarios SET tipo_conta = 'normal' WHERE id = :id");
                    $stmt->bindParam(':id', $user_id, PDO::PARAM_INT);
                    $stmt->execute();
                    $message = "Administrator privileges successfully removed.";
                    $action_performed = true;
                } catch (PDOException $e) {
                    error_log("Demote user error: " . $e->getMessage());
                    $message = "Error removing admin privileges.";
                }
            }
        }
        // 3.4 ADD NEW USER
        elseif (isset($_POST['add_user'])) {
            $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
            $password = $_POST['password'];
            $account_type = $_POST['account_type'];
            
            // 3.4.1 INPUT VALIDATION
            if (empty($email) || empty($password)) {
                $message = "Please fill in all fields.";
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $message = "Please enter a valid email address.";
            } elseif (strlen($password) < 8) {
                $message = "Password must be at least 8 characters long.";
            } else {
                // 3.4.2 CHECK FOR EXISTING EMAIL
                $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE email = :email");
                $stmt->bindParam(':email', $email, PDO::PARAM_STR);
                $stmt->execute();
                
                if ($stmt->rowCount() > 0) {
                    $message = "This email is already registered.";
                } else {
                    // 3.4.3 CREATE NEW USER
                    $password_hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
                    $activation_token = bin2hex(random_bytes(32));
                    
                    try {
                        $stmt = $pdo->prepare("INSERT INTO usuarios 
                            (email, senha, tipo_conta, ativo, token_ativacao, data_registo) 
                            VALUES (:email, :password, :account_type, 1, :token, NOW())");
                        
                        $stmt->bindParam(':email', $email, PDO::PARAM_STR);
                        $stmt->bindParam(':password', $password_hash, PDO::PARAM_STR);
                        $stmt->bindParam(':account_type', $account_type, PDO::PARAM_STR);
                        $stmt->bindParam(':token', $activation_token, PDO::PARAM_STR);
                        $stmt->execute();
                        
                        $message = "User successfully added!";
                        $action_performed = true;
                    } catch (PDOException $e) {
                        error_log("Add user error: " . $e->getMessage());
                        $message = "Error adding new user.";
                    }
                }
            }
        }
        // 3.5 RESET USER PASSWORD
        elseif (isset($_POST['reset_password'])) {
            $user_id = (int)$_POST['user_id'];
            $new_password = $_POST['new_password'];
            
            if (empty($new_password) || strlen($new_password) < 8) {
                $message = "Password must be at least 8 characters long.";
            } else {
                $password_hash = password_hash($new_password, PASSWORD_BCRYPT, ['cost' => 12]);
                try {
                    $stmt = $pdo->prepare("UPDATE usuarios SET senha = :password WHERE id = :id");
                    $stmt->bindParam(':password', $password_hash, PDO::PARAM_STR);
                    $stmt->bindParam(':id', $user_id, PDO::PARAM_INT);
                    $stmt->execute();
                    
                    $message = "Password successfully reset.";
                    $action_performed = true;
                } catch (PDOException $e) {
                    error_log("Password reset error: " . $e->getMessage());
                    $message = "Error resetting password.";
                }
            }
        }
    }
    
    // 3.6 REDIRECT AFTER POST TO PREVENT RESUBMISSION
    if ($action_performed) {
        header("Location: usuarios.php");
        exit;
    }
}

// 4.0 GET ALL USERS FROM DATABASE
try {
    $stmt = $pdo->prepare("SELECT id, email, tipo_conta, data_criacao FROM usuarios ORDER BY data_criacao DESC");
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Get users error: " . $e->getMessage());
    $message = "Error loading user list.";
    $users = [];
}

// 5.0 GENERATE CSRF TOKEN
$csrf_token = generateCSRFToken();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <!-- 6.0 PAGE METADATA -->
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="User Management">
    
    <!-- 6.1 SECURITY META TAGS -->
    <meta http-equiv="Content-Security-Policy" content="default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:;">
    
    <!-- 6.2 TITLE AND LINKS -->
    <title>User Management - Event System</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    
    <!-- 6.3 INLINE STYLES -->
    <style>
        /* 6.3.1 USER TYPE STYLING */
        .admin { color: #0056b3; font-weight: bold; }
        .normal { color: #6c757d; }
        
        /* 6.3.2 FORM STYLING */
        #add-user-form, #reset-password-form {
            display: none;
            margin-top: 20px;
            padding: 20px;
            border: 1px solid #ddd;
            border-radius: 4px;
            background-color: #f9f9f9;
        }
        
        /* 6.3.3 TABLE STYLING */
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        th {
            background-color: #f2f2f2;
        }
    </style>
</head>

<body>
    <!-- 7.0 HEADER INCLUSION -->
    <?php include '../includes/header.php'; ?>

    <!-- 8.0 MAIN CONTAINER -->
    <div class="container">
        <h1>User Management</h1>

        <!-- 8.1 MESSAGE DISPLAY -->
        <?php if (!empty($message)): ?>
            <div class="alert <?php echo $action_performed ? 'alert-success' : 'alert-info'; ?>">
                <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <!-- 8.2 ADD USER SECTION -->
        <div class="actions">
            <button id="show-add-user" class="btn btn-primary">Add New User</button>
        </div>

        <!-- 8.2.1 ADD USER FORM -->
        <div id="add-user-form">
            <h3>Add New User</h3>
            <form method="post" action="">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                
                <div class="form-group">
                    <label for="email">Email:</label>
                    <input type="email" id="email" name="email" required>
                </div>
                
                <div class="form-group">
                    <label for="password">Password:</label>
                    <input type="password" id="password" name="password" required minlength="8">
                    <small>Minimum 8 characters with at least 1 uppercase and 1 number</small>
                </div>
                
                <div class="form-group">
                    <label for="account_type">Account Type:</label>
                    <select id="account_type" name="account_type">
                        <option value="normal">Normal User</option>
                        <option value="admin">Administrator</option>
                    </select>
                </div>
                
                <button type="submit" name="add_user" class="btn btn-primary">Add User</button>
                <button type="button" id="cancel-add-user" class="btn btn-secondary">Cancel</button>
            </form>
        </div>

        <!-- 8.2.2 RESET PASSWORD FORM -->
        <div id="reset-password-form">
            <h3>Reset Password</h3>
            <form method="post" action="">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" id="reset_user_id" name="user_id">
                
                <div class="form-group">
                    <label>User:</label>
                    <div id="user_email"></div>
                </div>
                
                <div class="form-group">
                    <label for="new_password">New Password:</label>
                    <input type="password" id="new_password" name="new_password" required minlength="8">
                    <small>Minimum 8 characters with at least 1 uppercase and 1 number</small>
                </div>
                
                <button type="submit" name="reset_password" class="btn btn-primary">Reset Password</button>
                <button type="button" id="cancel-reset-password" class="btn btn-secondary">Cancel</button>
            </form>
        </div>

        <!-- 8.3 USER LIST SECTION -->
        <h2>User List</h2>
        <?php if (count($users) > 0): ?>
            <table>
                <thead>
                    <tr>
                        <th>Email</th>
                        <th>Account Type</th>
                        <th>Created Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="<?php echo $user['tipo_conta']; ?>">
                                <?php echo ucfirst($user['tipo_conta']); ?>
                            </td>
                            <td><?php echo date('Y-m-d H:i', strtotime($user['data_criacao'])); ?></td>
                            <td>
                                <?php if ($user['tipo_conta'] == 'normal'): ?>
                                    <form method="post" action="" class="inline-form">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                        <button type="submit" name="promote" class="btn btn-sm">Promote to Admin</button>
                                    </form>
                                <?php elseif ($user['id'] != $_SESSION['usuario_id']): ?>
                                    <form method="post" action="" class="inline-form">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                        <button type="submit" name="demote" class="btn btn-sm">Demote to Normal</button>
                                    </form>
                                <?php endif; ?>
                                
                                <button class="btn btn-sm reset-password" 
                                        data-id="<?php echo $user['id']; ?>" 
                                        data-email="<?php echo htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8'); ?>">
                                    Reset Password
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p>No users found.</p>
        <?php endif; ?>
    </div>
    
    <!-- 9.0 JAVASCRIPT -->
    <script>
        // 9.1 ADD USER FORM TOGGLE
        document.getElementById('show-add-user').addEventListener('click', function() {
            document.getElementById('add-user-form').style.display = 'block';
            document.getElementById('reset-password-form').style.display = 'none';
        });
        
        document.getElementById('cancel-add-user').addEventListener('click', function() {
            document.getElementById('add-user-form').style.display = 'none';
        });

        // 9.2 RESET PASSWORD FORM TOGGLE
        document.querySelectorAll('.reset-password').forEach(function(element) {
            element.addEventListener('click', function() {
                const id = this.getAttribute('data-id');
                const email = this.getAttribute('data-email');
                
                document.getElementById('reset_user_id').value = id;
                document.getElementById('user_email').textContent = email;
                
                document.getElementById('reset-password-form').style.display = 'block';
                document.getElementById('add-user-form').style.display = 'none';
                
                document.getElementById('reset-password-form').scrollIntoView({
                    behavior: 'smooth'
                });
            });
        });
        
        document.getElementById('cancel-reset-password').addEventListener('click', function() {
            document.getElementById('reset-password-form').style.display = 'none';
        });
    </script>
    
    <!-- 10.0 FOOTER INCLUSION -->
    <?php include '../includes/footer.php'; ?>
</body>
</html>