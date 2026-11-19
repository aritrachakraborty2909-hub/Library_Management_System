<?php
// Module 0.1.2: Register Member (Writes to D1 Member Store)
function registerMember(PDO $pdo, array $data): array {
    // 0.1.2.1 Validate Registration Data
    if (empty($data['full_name']) || empty($data['email']) || empty($data['password'])) {
        return ['success' => false, 'message' => 'All required fields must be filled.'];
    }

    // Encrypt the password securely before saving (never store plain-text passwords!)
    $passwordHash = password_hash($data['password'], PASSWORD_DEFAULT);

    try {
        // 0.1.2.3 Save Member Profile (Default status is 'Active' for instant testing, or 'Pending' for Librarian approval)
        $sql = "INSERT INTO members (full_name, email, phone_number, password_hash, address, role, account_status)
                VALUES (:name, :email, :phone, :hash, :address, 'Member', 'Active')";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':name'    => trim($data['full_name']),
            ':email'   => trim($data['email']),
            ':phone'   => trim($data['phone_number']),
            ':hash'    => $passwordHash,
            ':address' => trim($data['address'])
        ]);

        return ['success' => true, 'message' => 'Registration successful! You can now log in.'];
    } catch (PDOException $e) {
        return ['success' => false, 'message' => 'Email already registered.'];
    }
}

// Module 0.1.1: Process Login (Reads from D1 Member Store)
function loginMember(PDO $pdo, string $email, string $password): array {
    // 0.1.1.1 Fetch Member Credentials
    $stmt = $pdo->prepare("SELECT * FROM members WHERE email = :email LIMIT 1");
    $stmt->execute([':email' => trim($email)]);
    $user = $stmt->fetch();

    // 0.1.1.2 Verify Login Credentials
    if ($user && password_verify($password, $user['password_hash'])) {
        if ($user['account_status'] === 'Locked') {
            return ['success' => false, 'message' => 'Your account is locked due to overdue items.'];
        }

        // 0.1.1.3 Generate Authentication Token (Save user details into PHP Session)
        $_SESSION['member_id'] = $user['member_id'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['role']      = $user['role'];

        return ['success' => true, 'message' => 'Welcome back!'];
    }

    return ['success' => false, 'message' => 'Invalid email or password.'];
}
// Step 1 of Recovery: Check if the email exists in D1 Member Store
function verifyMemberEmail(PDO $pdo, string $email): ?array {
    $stmt = $pdo->prepare("SELECT member_id, full_name, email FROM members WHERE email = :email LIMIT 1");
    $stmt->execute([':email' => trim($email)]);
    $user = $stmt->fetch();

    // Returns the user's array if found, or null if no such user exists
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