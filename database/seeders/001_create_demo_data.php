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


    // 4. System permissions — kept in lockstep with onboard-client.php's
    //    own list (and with every migration that's added a permission
    //    code since) so a local dev setup and a real customer install
    //    always end up with the same permission set.
    $permissions = [
        ['Dashboard', 'dashboard.view'],
        ['View job cards', 'job_cards.view'],
        ['Manage job cards', 'job_cards.manage'],
        ['Restore job cards', 'job_cards.restore'],
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
        ['Manage users', 'users.manage'],
        ['Manage attendance', 'attendance.manage'],
        ['Manage payroll', 'payroll.manage'],
        ['View own attendance', 'attendance.view_own'],
        ['View finance', 'finance.view'],
        ['Manage finance', 'finance.manage'],
        ['View audit logs', 'audit.view']
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

        $permissionIds[$code] = $statement->fetchColumn();
    }


    // 5. Standard roles — the same six roles and permission matrix
    //    onboard-client.php seeds for a real customer, so RBAC can be
    //    exercised locally exactly as it works in production (see
    //    docs/project-documentation.md §7).
    $roles = [
        'OWNER' => [
            'name' => 'Owner',
            'description' => 'Full access to the organization',
            'permissions' => array_keys($permissionIds)
        ],
        'MANAGER' => [
            'name' => 'Manager',
            'description' => 'Full access except organization settings, user management and the audit log',
            'permissions' => array_diff(array_keys($permissionIds), ['settings.manage', 'users.manage', 'audit.view'])
        ],
        'ADVISOR' => [
            'name' => 'Service Advisor',
            'description' => 'Front desk: job cards, customers, vehicles, invoices',
            'permissions' => [
                'dashboard.view', 'job_cards.view', 'job_cards.manage',
                'invoices.view', 'invoices.manage',
                'customers.view', 'customers.manage',
                'vehicles.view', 'vehicles.manage',
                'parts.view', 'attendance.view_own'
            ]
        ],
        'TECHNICIAN' => [
            'name' => 'Technician',
            'description' => 'Workshop floor: sees assigned job cards only',
            'permissions' => ['dashboard.view', 'job_cards.view', 'attendance.view_own']
        ],
        'ACCOUNTANT' => [
            'name' => 'Accountant',
            'description' => 'Billing and financial reporting',
            'permissions' => [
                'dashboard.view', 'job_cards.view',
                'invoices.view', 'invoices.manage',
                'parts.view', 'purchases.view',
                'reports.view', 'attendance.view_own',
                'finance.view', 'finance.manage'
            ]
        ],
        'PARTS_MANAGER' => [
            'name' => 'Parts Manager',
            'description' => 'Inventory, purchases and suppliers',
            'permissions' => [
                'dashboard.view', 'job_cards.view',
                'parts.view', 'parts.manage',
                'purchases.view', 'purchases.manage',
                'suppliers.view', 'suppliers.manage',
                'reports.view', 'attendance.view_own'
            ]
        ]
    ];

    $roleStatement = $pdo->prepare("
        INSERT INTO roles (
            organization_id,
            name,
            code,
            description
        )
        VALUES (
            :organization_id,
            :name,
            :code,
            :description
        )
        RETURNING id
    ");

    $rolePermissionStatement = $pdo->prepare("
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

    $ownerRoleId = null;

    foreach ($roles as $code => $role) {

        $roleStatement->execute([
            'organization_id' => $organizationId,
            'name' => $role['name'],
            'code' => $code,
            'description' => $role['description']
        ]);

        $roleId = $roleStatement->fetchColumn();

        if ($code === 'OWNER') {
            $ownerRoleId = $roleId;
        }

        foreach ($role['permissions'] as $permissionCode) {
            $rolePermissionStatement->execute([
                'role_id' => $roleId,
                'permission_id' => $permissionIds[$permissionCode]
            ]);
        }
    }


    // 6. Assign Owner role to Administrator
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
        'role_id' => $ownerRoleId
    ]);


    $pdo->commit();

    echo "\nInitial GarageOS setup completed successfully!\n";
    echo "Organization: GarageOS Demo Workshop\n";
    echo "Branch: Main Branch\n";
    echo "Admin: {$adminEmail}\n";
    echo "Roles seeded: Owner, Manager, Service Advisor, Technician, Accountant, Parts Manager\n";
    echo "\nNext: php database/seeders/002_seed_service_catalog.php\n";

} catch (Throwable $e) {

    $pdo->rollBack();

    echo "\nSetup failed:\n";
    echo $e->getMessage() . "\n";

    exit(1);
}
