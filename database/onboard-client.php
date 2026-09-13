<?php

// Creates a brand new, ready-to-use workshop: organization, branch, admin
// user, the standard role set with permissions, and a starter service
// catalog.
//
// Each customer gets their own dedicated database (one organization per
// database, not many organizations sharing one) — so this script runs
// exactly once, against that customer's own fresh database, as one step
// of scripts/install-garageos.sh. It is not run against a shared
// database across multiple customers.
//
// Usage: php database/onboard-client.php

$pdo = require __DIR__ . '/../config/database.php';

function prompt(string $label, ?string $default = null): string
{
    echo $default !== null ? "{$label} [{$default}]: " : "{$label}: ";

    $value = trim(fgets(STDIN));

    return $value === '' && $default !== null ? $default : $value;
}

function promptPassword(string $label): string
{
    echo "{$label}: ";

    // Hide the typed password where the terminal supports it (stty is
    // available on Linux/macOS; falls back to visible input elsewhere,
    // e.g. if run under a shell without a real tty).
    $hasStty = stripos(PHP_OS, 'WIN') !== 0 && shell_exec('stty -a 2>/dev/null');

    if ($hasStty) {
        shell_exec('stty -echo');
    }

    $value = trim(fgets(STDIN));

    if ($hasStty) {
        shell_exec('stty echo');
        echo "\n";
    }

    return $value;
}

echo "GarageOS — New Client Onboarding\n";
echo "=================================\n\n";

$organizationName = prompt('Workshop name (e.g. "Highway Motors")');
$organizationCode = strtoupper(prompt('Short code (e.g. "HIGHWAY")'));
$currency = prompt('Currency', 'INR');
$timezone = prompt('Timezone', 'Asia/Kolkata');

echo "\n";

$branchName = prompt('First branch name', 'Main Branch');
$branchCode = strtoupper(prompt('Branch code', 'MAIN'));

echo "\n";

$adminName = prompt('Admin full name', 'Administrator');
$adminEmail = prompt('Admin email');
$adminPassword = promptPassword('Admin password');

if ($organizationName === '' || $organizationCode === '' || $adminEmail === '' || $adminPassword === '') {
    exit("\nWorkshop name, short code, admin email and password are all required.\n");
}

// This database belongs to exactly one customer, so any existing
// organization row here means onboarding already ran — re-running it
// would create a second, unexpected organization in a database the rest
// of the app assumes holds only one.
$statement = $pdo->query('SELECT name, code FROM organizations LIMIT 1');
$existing = $statement->fetch(PDO::FETCH_ASSOC);

if ($existing) {
    exit("\nThis database is already onboarded for '{$existing['name']}' ({$existing['code']}). " .
        "Each customer gets one database — onboarding a second workshop here isn't supported.\n");
}

$pdo->beginTransaction();

try {

    // 1. Organization
    $statement = $pdo->prepare("
        INSERT INTO organizations (name, code, email, currency, timezone, status)
        VALUES (:name, :code, :email, :currency, :timezone, 'active')
        RETURNING id
    ");
    $statement->execute([
        'name' => $organizationName,
        'code' => $organizationCode,
        'email' => $adminEmail,
        'currency' => $currency,
        'timezone' => $timezone
    ]);
    $organizationId = $statement->fetchColumn();

    // 2. Branch
    $statement = $pdo->prepare("
        INSERT INTO branches (organization_id, name, code, status)
        VALUES (:organization_id, :name, :code, 'active')
        RETURNING id
    ");
    $statement->execute([
        'organization_id' => $organizationId,
        'name' => $branchName,
        'code' => $branchCode
    ]);
    $branchId = $statement->fetchColumn();

    // 3. Admin user
    $statement = $pdo->prepare("
        INSERT INTO users (organization_id, branch_id, name, email, password_hash, status)
        VALUES (:organization_id, :branch_id, :name, :email, :password_hash, 'active')
        RETURNING id
    ");
    $statement->execute([
        'organization_id' => $organizationId,
        'branch_id' => $branchId,
        'name' => $adminName,
        'email' => $adminEmail,
        'password_hash' => password_hash($adminPassword, PASSWORD_DEFAULT)
    ]);
    $adminUserId = $statement->fetchColumn();

    // 4. System permissions (shared codes, org-independent)
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
        INSERT INTO permissions (name, code, description)
        VALUES (:name, :code, :description)
        ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name
        RETURNING id
    ");

    foreach ($permissions as [$name, $code]) {
        $statement->execute(['name' => $name, 'code' => $code, 'description' => $name]);
        $permissionIds[$code] = $statement->fetchColumn();
    }

    // 5. Standard roles for this organization, matching the documented
    //    roles & permissions matrix (docs/project-documentation.md §7).
    $roles = [
        'OWNER' => [
            'name' => 'Owner',
            'description' => 'Full access to the organization',
            'permissions' => array_keys($permissionIds)
        ],
        'MANAGER' => [
            'name' => 'Manager',
            'description' => 'Full access except organization settings and user management',
            'permissions' => array_diff(array_keys($permissionIds), ['settings.manage', 'users.manage'])
        ],
        'ADVISOR' => [
            'name' => 'Service Advisor',
            'description' => 'Front desk: job cards, customers, vehicles, invoices',
            'permissions' => [
                'dashboard.view', 'job_cards.view', 'job_cards.manage',
                'invoices.view', 'invoices.manage',
                'customers.view', 'customers.manage',
                'vehicles.view', 'vehicles.manage',
                'parts.view'
            ]
        ],
        'TECHNICIAN' => [
            'name' => 'Technician',
            'description' => 'Workshop floor: sees assigned job cards only',
            'permissions' => ['dashboard.view', 'job_cards.view']
        ],
        'ACCOUNTANT' => [
            'name' => 'Accountant',
            'description' => 'Billing and financial reporting',
            'permissions' => [
                'dashboard.view', 'job_cards.view',
                'invoices.view', 'invoices.manage',
                'parts.view', 'purchases.view',
                'reports.view'
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
                'reports.view'
            ]
        ]
    ];

    $roleStatement = $pdo->prepare("
        INSERT INTO roles (organization_id, name, code, description)
        VALUES (:organization_id, :name, :code, :description)
        RETURNING id
    ");

    $rolePermissionStatement = $pdo->prepare("
        INSERT INTO role_permissions (role_id, permission_id)
        VALUES (:role_id, :permission_id)
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

    // 6. Assign Owner role to the admin
    $statement = $pdo->prepare("
        INSERT INTO user_roles (user_id, role_id)
        VALUES (:user_id, :role_id)
        ON CONFLICT DO NOTHING
    ");
    $statement->execute(['user_id' => $adminUserId, 'role_id' => $ownerRoleId]);

    // 7. Starter service catalog — every workshop needs these to start
    $categories = [
        'PERIODIC' => 'Periodic Maintenance',
        'ELECTRICAL' => 'Electrical',
        'BRAKES' => 'Brakes & Suspension',
        'AC' => 'Air Conditioning'
    ];

    $categoryStatement = $pdo->prepare("
        INSERT INTO service_categories (organization_id, name, code)
        VALUES (:organization_id, :name, :code)
        RETURNING id
    ");

    $categoryIds = [];

    foreach ($categories as $code => $name) {
        $categoryStatement->execute(['organization_id' => $organizationId, 'name' => $name, 'code' => $code]);
        $categoryIds[$code] = $categoryStatement->fetchColumn();
    }

    $services = [
        ['PERIODIC', 'OIL_CHANGE', 'Oil & filter change', 899, 30],
        ['PERIODIC', 'GENERAL_SERVICE', 'General service', 1999, 90],
        ['BRAKES', 'BRAKE_PAD', 'Brake pad replacement (per axle)', 1499, 45],
        ['BRAKES', 'WHEEL_ALIGNMENT', 'Wheel alignment & balancing', 799, 40],
        ['ELECTRICAL', 'BATTERY_REPLACE', 'Battery replacement', 499, 20],
        ['AC', 'AC_SERVICE', 'AC gas top-up & service', 1299, 60]
    ];

    $serviceStatement = $pdo->prepare("
        INSERT INTO services (organization_id, service_category_id, name, code, standard_price, estimated_minutes)
        VALUES (:organization_id, :service_category_id, :name, :code, :standard_price, :estimated_minutes)
    ");

    foreach ($services as [$categoryCode, $code, $name, $price, $minutes]) {
        $serviceStatement->execute([
            'organization_id' => $organizationId,
            'service_category_id' => $categoryIds[$categoryCode],
            'name' => $name,
            'code' => $code,
            'standard_price' => $price,
            'estimated_minutes' => $minutes
        ]);
    }

    $pdo->commit();

    echo "\nGarageOS is ready for {$organizationName}.\n";
    echo "------------------------------------------\n";
    echo "Organization: {$organizationName} ({$organizationCode})\n";
    echo "Branch:       {$branchName} ({$branchCode})\n";
    echo "Admin login:  {$adminEmail}\n";
    echo "Roles seeded: Owner, Manager, Service Advisor, Technician, Accountant, Parts Manager\n";
    echo "Catalog:      " . count($categories) . " categories, " . count($services) . " services\n";
    echo "\nNext: add real suppliers and parts, invite staff, and point the client's\n";
    echo "landing page login button at this install.\n";

} catch (Throwable $e) {

    $pdo->rollBack();

    echo "\nOnboarding failed:\n";
    echo $e->getMessage() . "\n";

    exit(1);
}
