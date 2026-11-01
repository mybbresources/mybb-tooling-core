<?php
if (!defined('IN_MYBB')) {
    die('Direct initialization of this file is not allowed.');
}

if (empty($mybb->usergroup['cancp'])) {
    error_no_permission();
}

$page->add_breadcrumb_item('Synthetic Sample');
$page->output_header('Synthetic Sample');
echo '<p>This sample module is intentionally inert.</p>';
$page->output_footer();
