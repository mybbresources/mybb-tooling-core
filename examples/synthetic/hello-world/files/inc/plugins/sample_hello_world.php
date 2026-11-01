<?php
if (!defined('IN_MYBB')) {
    die('Direct initialization of this file is not allowed.');
}

function sample_hello_world_info(): array
{
    return [
        'name' => 'Synthetic Hello World',
        'description' => 'A no-op sample plugin for package installation tests.',
        'website' => 'https://plugins.mybbresources.com',
        'author' => 'MyBB Tooling Test Fixtures',
        'version' => '0.1.0',
        'compatibility' => '18*',
    ];
}

function sample_hello_world_install(): void {}
function sample_hello_world_is_installed(): bool { return true; }
function sample_hello_world_uninstall(): void {}
function sample_hello_world_activate(): void {}
function sample_hello_world_deactivate(): void {}
