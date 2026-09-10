<?php

function user_can(array $user, string $permissionCode): bool
{
    return in_array($permissionCode, $user['permissions'] ?? [], true);
}

function require_permission(array $user, string $permissionCode): void
{
    if (!user_can($user, $permissionCode)) {
        http_response_code(403);
        exit("You don't have permission to do that.");
    }
}
