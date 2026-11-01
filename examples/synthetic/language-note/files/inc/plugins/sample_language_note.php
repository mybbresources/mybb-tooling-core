<?php
if (!defined('IN_MYBB')) {
    die('Direct initialization of this file is not allowed.');
}

function sample_language_note_info(): array
{
    return [
        'name' => 'Synthetic Language Note',
        'description' => 'A sample package with a language resource.',
        'website' => 'https://plugins.mybbresources.com',
        'author' => 'MyBB Tooling Test Fixtures',
        'version' => '0.1.0',
        'compatibility' => '18*',
    ];
}

function sample_language_note_install(): void {}
function sample_language_note_is_installed(): bool { return true; }
function sample_language_note_uninstall(): void {}
function sample_language_note_activate(): void {}
function sample_language_note_deactivate(): void {}
