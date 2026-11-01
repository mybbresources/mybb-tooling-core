<?php
declare(strict_types=1);
if (!class_exists('MyBB\\Tooling\\Manifest')) require_once __DIR__.'/src/Manifest.php';
if (!class_exists('MyBB\\Tooling\\Compatibility')) require_once __DIR__.'/src/Compatibility.php';
if (!class_exists('MyBB\\Tooling\\PackageManager')) require_once __DIR__.'/src/PackageManager.php';
if (!class_exists('MyBB\\Tooling\\CatalogException')) require_once __DIR__.'/src/CatalogException.php';
if (!class_exists('MyBB\\Tooling\\CatalogClient')) require_once __DIR__.'/src/CatalogClient.php';
if (!class_exists('MyBB\\Tooling\\OfficialMyBBRelease')) require_once __DIR__.'/src/OfficialMyBBRelease.php';
if (!class_exists('MyBB\\Tooling\\OfficialMyBBArchive')) require_once __DIR__.'/src/OfficialMyBBArchive.php';
if (!class_exists('MyBB\\Tooling\\DatabaseTools')) require_once __DIR__.'/src/DatabaseTools.php';
if (!class_exists('MyBB\\Tooling\\PluginInventory')) require_once __DIR__.'/src/PluginInventory.php';
if (!class_exists('MyBB\\Tooling\\PluginLifecycle')) require_once __DIR__.'/src/PluginLifecycle.php';
if (!class_exists('MyBB\\Tooling\\OfficialMyBBUpgrade')) require_once __DIR__.'/src/OfficialMyBBUpgrade.php';
if (!class_exists('MyBB\\Tooling\\CatalogPagination')) require_once __DIR__.'/src/CatalogPagination.php';
if (!class_exists('MyBB\\Tooling\\RepositoryMetadataCache')) require_once __DIR__.'/src/RepositoryMetadataCache.php';
if (!class_exists('MyBB\\Tooling\\TextTable')) require_once __DIR__.'/src/TextTable.php';
if (!class_exists('MyBB\\Tooling\\CoreRuntime')) require_once __DIR__.'/src/CoreRuntime.php';
if (!class_exists('MyBB\\Tooling\\BootstrapSet')) require_once __DIR__.'/src/BootstrapSet.php';
if (!class_exists('MyBB\\Tooling\\CoreMigrationRecovery')) require_once __DIR__.'/src/CoreMigrationRecovery.php';
