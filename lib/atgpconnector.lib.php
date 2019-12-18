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
 *	\file		lib/atgpconnector.lib.php
 *	\ingroup	atgpconnector
 *	\brief		This file is an example module library
 *				Put some comments here
 */

function atgpconnectorAdminPrepareHead()
{
    global $langs, $conf;

    $langs->load("atgpconnector@atgpconnector");

    $h = 0;
    $head = array();

    $head[$h][0] = dol_buildpath("/atgpconnector/admin/atgpconnector_setup.php", 1);
    $head[$h][1] = $langs->trans("Parameters");
    $head[$h][2] = 'settings';
    $h++;
    $head[$h][0] = dol_buildpath("/atgpconnector/admin/atgpconnector_about.php", 1);
    $head[$h][1] = $langs->trans("About");
    $head[$h][2] = 'about';
    $h++;

    // Show more tabs from modules
    // Entries must be declared in modules descriptor with line
    //$this->tabs = array(
    //	'entity:+tabname:Title:@atgpconnector:/atgpconnector/mypage.php?id=__ID__'
    //); // to add new tab
    //$this->tabs = array(
    //	'entity:-tabname:Title:@atgpconnector:/atgpconnector/mypage.php?id=__ID__'
    //); // to remove a tab
    complete_head_from_modules($conf, $langs, $object, $head, $h, 'atgpconnector');

    return $head;
}

/**
 * Return array of tabs to used on pages for third parties cards.
 *
 * @param 	atgpconnector	$object		Object company shown
 * @return 	array				Array of tabs
 */
function atgpconnector_prepare_head(atgpconnector $object)
{
    global $db, $langs, $conf, $user;
    $h = 0;
    $head = array();
    $head[$h][0] = dol_buildpath('/atgpconnector/card.php', 1).'?id='.$object->id;
    $head[$h][1] = $langs->trans("atgpconnectorCard");
    $head[$h][2] = 'card';
    $h++;
	
	// Show more tabs from modules
    // Entries must be declared in modules descriptor with line
    // $this->tabs = array('entity:+tabname:Title:@atgpconnector:/atgpconnector/mypage.php?id=__ID__');   to add new tab
    // $this->tabs = array('entity:-tabname:Title:@atgpconnector:/atgpconnector/mypage.php?id=__ID__');   to remove a tab
    complete_head_from_modules($conf,$langs,$object,$head,$h,'atgpconnector');
	
	return $head;
}

function getFormConfirmatgpconnector(&$PDOdb, &$form, &$object, $action)
{
    global $langs,$conf,$user;

    $formconfirm = '';

    if ($action == 'validate' && !empty($user->rights->atgpconnector->write))
    {
        $text = $langs->trans('ConfirmValidateatgpconnector', $object->ref);
        $formconfirm = $form->formconfirm($_SERVER['PHP_SELF'] . '?id=' . $object->id, $langs->trans('Validateatgpconnector'), $text, 'confirm_validate', '', 0, 1);
    }
    elseif ($action == 'delete' && !empty($user->rights->atgpconnector->write))
    {
        $text = $langs->trans('ConfirmDeleteatgpconnector');
        $formconfirm = $form->formconfirm($_SERVER['PHP_SELF'] . '?id=' . $object->id, $langs->trans('Deleteatgpconnector'), $text, 'confirm_delete', '', 0, 1);
    }
    elseif ($action == 'clone' && !empty($user->rights->atgpconnector->write))
    {
        $text = $langs->trans('ConfirmCloneatgpconnector', $object->ref);
        $formconfirm = $form->formconfirm($_SERVER['PHP_SELF'] . '?id=' . $object->id, $langs->trans('Cloneatgpconnector'), $text, 'confirm_clone', '', 0, 1);
    }

    return $formconfirm;
}


function atgpConnectorGetNumericField($value, $maxLength, $maxPrecision)
{
	$valueIntegerPart = strval(floor($value));
	
	$integerPartLength = strlen($valueIntegerPart);

	$truePrecision = $maxPrecision;
	
	if ($integerPartLength + $maxPrecision + 1 > $maxLength)
	{
		$truePrecision = $maxLength - $integerPartLength - 1;
	}
	
	$integerPartLength = max($maxLength - $truePrecision - 1, $integerPartLength);

	return sprintf('%' . $integerPartLength . '.' . $truePrecision . 'f', $value);
}
