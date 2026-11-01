<?php
if (!defined('IN_MYBB')) {
    die('Direct initialization of this file is not allowed.');
}

function sample_admin_panel_info(): array
{
    return [
        'name' => 'Synthetic Admin Panel',
        'description' => 'A sample package with an Admin CP module.',
        'website' => 'https://plugins.mybbresources.com',
        'author' => 'MyBB Tooling Test Fixtures',
        'version' => '0.1.0',
        'compatibility' => '18*',
    ];
}

function sample_admin_panel_install(): void {}
function sample_admin_panel_is_installed(): bool { return true; }
function sample_admin_panel_uninstall(): void {}
function sample_admin_panel_activate(): void {}
function sample_admin_panel_deactivate(): void {}
