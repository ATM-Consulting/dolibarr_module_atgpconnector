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
 * \file    class/actions_atgpconnector.class.php
 * \ingroup atgpconnector
 * \brief   This file is an example hook overload class file
 *          Put some comments here
 */

/**
 * Class Actionsatgpconnector
 */
class Actionsatgpconnector
{
	/**
	 * @var array Hook results. Propagated to $hookmanager->resArray for later reuse
	 */
	public $results = array();

	/**
	 * @var string String displayed by executeHook() immediately after return
	 */
	public $resprints;

	/**
	 * @var array Errors
	 */
	public $errors = array();

	/**
	 * Constructor
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Overloading the doActions function : replacing the parent's function with the one below
	 *
	 * @param   array()         $parameters     Hook metadatas (context, etc...)
	 * @param   CommonObject    &$object        The object to process (an invoice if you are in invoice module, a propale in propale's module, etc...)
	 * @param   string          &$action        Current action (if set). Generally create or edit or null
	 * @param   HookManager     $hookmanager    Hook manager propagated to allow calling another hook
	 * @return  int                             < 0 on error, 0 on success, 1 to replace standard code
	 */
	function doActions($parameters, &$object, &$action, $hookmanager)
	{
		if ($this->_canHandleEDI($parameters, $object, $action, $hookmanager) && $action === 'send-to-chorus')
		{
			dol_include_once('/atgpconnector/class/ediformatfac.class.php');
			
			$formatFAC = new EDIFormatFAC;
			$formatFAC->put($object);
		}
	}


	/**
	 * Overloading the addMoreActionsButtons function : replacing the parent's function with the one below
	 *
	 * @param   array()         $parameters     Hook metadatas (context, etc...)
	 * @param   CommonObject    &$object        The object to process (an invoice if you are in invoice module, a propale in propale's module, etc...)
	 * @param   string          &$action        Current action (if set). Generally create or edit or null
	 * @param   HookManager     $hookmanager    Hook manager propagated to allow calling another hook
	 * @return  int                             < 0 on error, 0 on success, 1 to replace standard code
	 */
	function addMoreActionsButtons($parameters, &$object, &$action, $hookmanager)
	{
		global $langs;

		if ($this->_canHandleEDI($parameters, $object, $action, $hookmanager))
		{
			$langs->load('atgpconnector@atgpconnector');

			$url = $_SERVER['PHP_SELF'] .'?id=' . $object->id . '&action=send-to-chorus';

			print '<div class="inline-block divButAction"><a class="butAction" href="'. $url .'">' . $langs->trans('ATGPC_SendToChorus') . '</a></div>';
		}

		return 0;
	}


	function _canHandleEDI($parameters, &$object, &$action, $hookmanager)
	{
		global $conf;

		return in_array('invoicecard', explode(':', $parameters['context'])) && ! empty($conf->global->ATGPCONNECTOR_FORMAT_FAC) && $object->statut > Facture::STATUS_DRAFT;
	}
}
