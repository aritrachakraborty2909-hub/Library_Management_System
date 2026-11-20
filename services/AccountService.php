<?php
// Module 0.1.2: Register Member (Writes to D1 Member Store)
function registerMember(PDO $pdo, array $data): array {
    // 0.1.2.1 Validate Registration Data
    $fullName    = trim($data['full_name'] ?? '');
    $email       = strtolower(trim($data['email'] ?? ''));
    $phoneNumber = trim($data['phone_number'] ?? '');
    $password    = $data['password'] ?? '';

    if ($fullName === '' || $email === '' || $password === '') {
        return ['success' => false, 'message' => 'All required fields must be filled.'];
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => 'Please enter a valid email address.'];
    }

    try {
        // 0.1.2.2 Check explicitly if the email already exists (case-insensitive)
        $checkStmt = $pdo->prepare("SELECT member_id FROM members WHERE LOWER(email) = :email LIMIT 1");
        $checkStmt->execute([':email' => $email]);
        if ($checkStmt->fetch()) {
            return ['success' => false, 'message' => 'Email already registered.'];
        }

        // Encrypt the password securely before saving
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        // 0.1.2.3 Save Member Profile (Default status is 'Active', 1-year validity, max_borrowing_limit is 5)
        $sql = "INSERT INTO members (full_name, email, phone_number, password_hash, role, account_status, expiry_date, max_borrowing_limit)
                VALUES (:name, :email, :phone, :hash, 'Member', 'Active', CURRENT_DATE + INTERVAL '1 year', 5)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':name'  => $fullName,
            ':email' => $email,
            ':phone' => $phoneNumber !== '' ? $phoneNumber : null,
            ':hash'  => $passwordHash
        ]);

        return ['success' => true, 'message' => 'Registration successful! You can now log in.'];
    } catch (PDOException $e) {
        if ($e->getCode() === '23505') {
            if (stripos($e->getMessage(), 'phone') !== false) {
                return ['success' => false, 'message' => 'Phone number already registered.'];
            }
            if (stripos($e->getMessage(), 'email') !== false) {
                return ['success' => false, 'message' => 'Email already registered.'];
            }
        }
        return ['success' => false, 'message' => 'Registration error: ' . $e->getMessage()];
    }
}

// Module 0.1.1: Process Login (Reads from D1 Member Store)
function loginMember(PDO $pdo, string $email, string $password): array {
    // 0.1.1.1 Fetch Member Credentials
    $stmt = $pdo->prepare("SELECT * FROM members WHERE LOWER(email) = :email LIMIT 1");
    $stmt->execute([':email' => strtolower(trim($email))]);
    $user = $stmt->fetch();

    // 0.1.1.2 Verify Login Credentials
    if ($user && password_verify($password, $user['password_hash'])) {
        if ($user['account_status'] === 'Locked') {
            return ['success' => false, 'message' => 'Your account is locked due to overdue items.'];
        }

        // 0.1.1.3 Generate Authentication Token (Save user details into PHP Session)
        $_SESSION['member_id']   = $user['member_id'];
        $_SESSION['full_name']   = $user['full_name'];
        $_SESSION['role']        = $user['role'];
        $_SESSION['profile_pic'] = $user['profile_pic'] ?? null;

        return ['success' => true, 'message' => 'Welcome back!'];
    }

    return ['success' => false, 'message' => 'Invalid email or password.'];
}

// Step 1 of Recovery: Check if the email exists in D1 Member Store
function verifyMemberEmail(PDO $pdo, string $email): ?array {
    $stmt = $pdo->prepare("SELECT member_id, full_name, email FROM members WHERE LOWER(email) = :email LIMIT 1");
    $stmt->execute([':email' => strtolower(trim($email))]);
    $user = $stmt->fetch();

    return $user ?: null;
}

// Step 2 of Recovery: Update password hash in D1 Member Store (Module 0.1.4.3 Save Account Details)
function updateMemberPassword(PDO $pdo, int $memberId, string $newPassword): bool {
    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

    $stmt = $pdo->prepare("UPDATE members SET password_hash = :hash WHERE member_id = :id");
    return $stmt->execute([
        ':hash' => $newHash,
        ':id'   => $memberId
    ]);
}
?>