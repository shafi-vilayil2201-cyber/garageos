<?php

$pdo = require __DIR__ . '/../../config/database.php';

echo "GarageOS initial setup\n";
echo "=======================\n\n";

echo "Admin email: ";
$adminEmail = trim(fgets(STDIN));

echo "Admin password: ";
$adminPassword = trim(fgets(STDIN));

if ($adminEmail === '' || $adminPassword === '') {
    exit("Email and password are required.\n");
}

$pdo->beginTransaction();

try {

    // 1. Create the organization
    $statement = $pdo->prepare("
        INSERT INTO organizations (
            name,
            code,
            email,
            currency,
            timezone,
            status
        )
        VALUES (
            :name,
            :code,
            :email,
            'INR',
            'Asia/Kolkata',
            'active'
        )
        RETURNING id
    ");

    $statement->execute([
        'name' => 'GarageOS Demo Workshop',
        'code' => 'DEMO',
        'email' => $adminEmail
    ]);

    $organizationId = $statement->fetchColumn();


    // 2. Create the main branch
    $statement = $pdo->prepare("
        INSERT INTO branches (
            organization_id,
            name,
            code,
            status
        )
        VALUES (
            :organization_id,
            'Main Branch',
            'MAIN',
            'active'
        )
        RETURNING id
    ");

    $statement->execute([
        'organization_id' => $organizationId
    ]);

    $branchId = $statement->fetchColumn();


    // 3. Create the admin user
    $passwordHash = password_hash(
        $adminPassword,
        PASSWORD_DEFAULT
    );

    $statement = $pdo->prepare("
        INSERT INTO users (
            organization_id,
            branch_id,
            name,
            email,
            password_hash,
            status
        )
        VALUES (
            :organization_id,
            :branch_id,
            'Administrator',
            :email,
            :password_hash,
            'active'
        )
        RETURNING id
    ");

    $statement->execute([
        'organization_id' => $organizationId,
        'branch_id' => $branchId,
        'email' => $adminEmail,
        'password_hash' => $passwordHash
    ]);

    $userId = $statement->fetchColumn();


    // 4. Create the Owner role
    $statement = $pdo->prepare("
        INSERT INTO roles (
            organization_id,
            name,
            code,
            description
        )
        VALUES (
            :organization_id,
            'Owner',
            'OWNER',
            'Full access to the organization'
        )
        RETURNING id
    ");

    $statement->execute([
        'organization_id' => $organizationId
    ]);

    $roleId = $statement->fetchColumn();


    // 5. Create system permissions
    $permissions = [
        ['Dashboard', 'dashboard.view'],
        ['View job cards', 'job_cards.view'],
        ['Manage job cards', 'job_cards.manage'],
        ['View invoices', 'invoices.view'],
        ['Manage invoices', 'invoices.manage'],
        ['View customers', 'customers.view'],
        ['Manage customers', 'customers.manage'],
        ['View vehicles', 'vehicles.view'],
        ['Manage vehicles', 'vehicles.manage'],
        ['View parts', 'parts.view'],
        ['Manage parts', 'parts.manage'],
        ['View purchases', 'purchases.view'],
        ['Manage purchases', 'purchases.manage'],
        ['View suppliers', 'suppliers.view'],
        ['Manage suppliers', 'suppliers.manage'],
        ['View reports', 'reports.view'],
        ['Manage settings', 'settings.manage'],
        ['Manage users', 'users.manage']
    ];

    $permissionIds = [];

    $statement = $pdo->prepare("
        INSERT INTO permissions (
            name,
            code,
            description
        )
        VALUES (
            :name,
            :code,
            :description
        )
        ON CONFLICT (code)
        DO UPDATE SET name = EXCLUDED.name
        RETURNING id
    ");

    foreach ($permissions as [$name, $code]) {

        $statement->execute([
            'name' => $name,
            'code' => $code,
            'description' => $name
        ]);

        $permissionIds[] = $statement->fetchColumn();
    }


    // 6. Give Owner every permission
    $statement = $pdo->prepare("
        INSERT INTO role_permissions (
            role_id,
            permission_id
        )
        VALUES (
            :role_id,
            :permission_id
        )
        ON CONFLICT DO NOTHING
    ");

    foreach ($permissionIds as $permissionId) {

        $statement->execute([
            'role_id' => $roleId,
            'permission_id' => $permissionId
        ]);
    }


    // 7. Assign Owner role to Administrator
    $statement = $pdo->prepare("
        INSERT INTO user_roles (
            user_id,
            role_id
        )
        VALUES (
            :user_id,
            :role_id
        )
        ON CONFLICT DO NOTHING
    ");

    $statement->execute([
        'user_id' => $userId,
        'role_id' => $roleId
    ]);


    $pdo->commit();

    echo "\nInitial GarageOS setup completed successfully!\n";
    echo "Organization: GarageOS Demo Workshop\n";
    echo "Branch: Main Branch\n";
    echo "Admin: {$adminEmail}\n";
    echo "\nNext: php database/seeders/002_seed_service_catalog.php\n";

} catch (Throwable $e) {

    $pdo->rollBack();

    echo "\nSetup failed:\n";
    echo $e->getMessage() . "\n";

    exit(1);
}
