<?php
/* <one line to give the program's name and a brief idea of what it does.>
 * Copyright (C) 2015 ATM Consulting <support@atm-consulting.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * 	\file		admin/atgpconnector.php
 * 	\ingroup	atgpconnector
 * 	\brief		This file is an example module setup page
 * 				Put some comments here
 */
// Dolibarr environment
$res = @include("../../main.inc.php"); // From htdocs directory
if (! $res) {
    $res = @include("../../../main.inc.php"); // From "custom" directory
}

// Libraries
require_once DOL_DOCUMENT_ROOT . "/core/lib/admin.lib.php";
require_once '../lib/atgpconnector.lib.php';
dol_include_once('/abricot/includes/lib/admin.lib.php');

// Translations
$langs->load('atgpconnector@atgpconnector');

// Access control
if (! $user->admin) {
    accessforbidden();
}

// Parameters
$action = GETPOST('action', 'alpha');

/*
 * Actions
 */
if (preg_match('/set_(.*)/',$action,$reg))
{
	$code=$reg[1];
	if (dolibarr_set_const($db, $code, GETPOST($code), 'chaine', 0, '', $conf->entity) > 0)
	{
		header("Location: ".$_SERVER["PHP_SELF"]);
		exit;
	}
	else
	{
		dol_print_error($db);
	}
}
	
if (preg_match('/del_(.*)/',$action,$reg))
{
	$code=$reg[1];
	if (dolibarr_del_const($db, $code, 0) > 0)
	{
		header("Location: ".$_SERVER["PHP_SELF"]);
		exit;
	}
	else
	{
		dol_print_error($db);
	}
}

/*
 * View
 */
$page_name = "atgpconnectorSetup";
llxHeader('', $langs->trans($page_name));

// Subheader
$linkback = '<a href="' . DOL_URL_ROOT . '/admin/modules.php">'
    . $langs->trans("BackToModuleList") . '</a>';
print_fiche_titre($langs->trans($page_name), $linkback);

// Configuration header
$head = atgpconnectorAdminPrepareHead();
dol_fiche_head(
    $head,
    'settings',
    $langs->trans("Module104068Name"),
    0,
    "atgpconnector@atgpconnector"
);

// Setup page goes here
$form=new Form($db);
$var=false;
print '<table class="noborder" width="100%">';

setup_print_title('ATGPC_FTPConnectionParams');

setup_print_input_form_part('ATGPCONNECTOR_FTP_HOST');
setup_print_input_form_part('ATGPCONNECTOR_FTP_PORT', false, '', array('placeholder' => 21));
setup_print_input_form_part('ATGPCONNECTOR_FTP_USER');
setup_print_input_form_part('ATGPCONNECTOR_FTP_PASS', false, 'ATGPCONNECTOR_FTP_PASS_desc', array(), 'password');

setup_print_title('ATGPC_ActivatedModes');

setup_print_on_off('ATGPCONNECTOR_DEV_MODE');
setup_print_on_off('ATGPCONNECTOR_FORMAT_FAC');

print '</table>';

llxFooter();

$db->close();

