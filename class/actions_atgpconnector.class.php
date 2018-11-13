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
		if ($this->_canHandleEDIFACChorus($parameters, $object, $action, $hookmanager) && $action === 'send-to-chorus')
		{
			define('INC_FROM_DOLIBARR', true);
			dol_include_once('/atgpconnector/config.php');
			dol_include_once('/atgpconnector/class/ediformatfac.class.php');

			$this->_sendOneInvoiceToChorus($object);

			// TODO Gestion d'erreurs
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

		if ($this->_canHandleEDIFACChorus($parameters, $object, $action, $hookmanager))
		{
			$langs->load('atgpconnector@atgpconnector');

			$url = $_SERVER['PHP_SELF'] .'?id=' . $object->id . '&action=send-to-chorus';

			print '<div class="inline-block divButAction"><a class="butAction" href="'. $url .'">' . $langs->trans('ATGPC_SendToChorus') . '</a></div>';
		}

		return 0;
	}


	/**
	 * Overloading the addMoreMassActions function : replacing the parent's function with the one below
	 *
	 * @param   array()         $parameters     Hook metadatas (context, etc...)
	 * @param   CommonObject    &$object        The object to process (an invoice if you are in invoice module, a propale in propale's module, etc...)
	 * @param   string          &$action        Current action (if set). Generally create or edit or null
	 * @param   HookManager     $hookmanager    Hook manager propagated to allow calling another hook
	 * @return  int                             < 0 on error, 0 on success, 1 to replace standard code
	 */
	function addMoreMassActions($parameters, &$object, &$action, $hookmanager)
	{
		global $langs;

		if ($this->_canHandleEDIFACChorus($parameters, $object, $action, $hookmanager, 'invoicelist', true))
		{
			$langs->load('atgpconnector@atgpconnector');

			$this->resprints = '<option value="send-to-chorus">' . $langs->trans('ATGPC_SendToChorus') . '</option>';
		}

		return 0;
	}


	/**
	 * Overloading the doMassActions function : replacing the parent's function with the one below
	 *
	 * @param   array()         $parameters     Hook metadatas (context, etc...)
	 * @param   CommonObject    &$object        The object to process (an invoice if you are in invoice module, a propale in propale's module, etc...)
	 * @param   string          &$action        Current action (if set). Generally create or edit or null
	 * @param   HookManager     $hookmanager    Hook manager propagated to allow calling another hook
	 * @return  int                             < 0 on error, 0 on success, 1 to replace standard code
	 */
	function doMassActions($parameters, &$object, &$action, $hookmanager)
	{
		$massaction = GETPOST('massaction','alpha');

		if ($this->_canHandleEDIFACChorus($parameters, $object, $action, $hookmanager, 'invoicelist', true) && $massaction === 'send-to-chorus')
		{
			define('INC_FROM_DOLIBARR', true);
			dol_include_once('/atgpconnector/config.php');
			dol_include_once('/atgpconnector/class/ediformatfac.class.php');

			$TIDInvoices = $parameters['toselect'];

			foreach($TIDInvoices as $invoiceID)
			{
				$invoice = new Facture($object->db);
				$invoice->fetch($invoiceID);

				if($this->_canHandleEDIFACChorus($parameters, $invoice, $action, $hookmanager, 'invoicelist'))
				{
					$this->_sendOneInvoiceToChorus($invoice);
				}
			}

			// TODO Gestion d'erreurs
		}
	}


	function _canHandleEDI($parameters, &$object, &$action, $hookmanager)
	{
		global $conf;

		if(! empty($object->id) && empty($object->thirdparty) && method_exists($object, 'fetch_thirdparty')) // $object->id vide si massaction
		{
			$object->fetch_thirdparty();
		}

		return ! empty($conf->global->ATGPCONNECTOR_FTP_HOST) && ! empty($conf->global->ATGPCONNECTOR_FTP_USER);
	}


	function _canHandleEDIFACChorus($parameters, &$object, &$action, $hookmanager, $targetContext = 'invoicecard', $massAction = false)
	{
		global $conf, $db;

		if(
				! $this->_canHandleEDI($parameters, $object, $action, $hookmanager)
			||	! in_array($targetContext, explode(':', $parameters['context']))
			||	empty($conf->global->ATGPCONNECTOR_FORMAT_FAC)
			||	empty($conf->global->ATGPCONNECTOR_FORMAT_FAC_CHORUS)
		)
		{
			return false;
		}

		if(! $massAction)
		{
			dol_include_once('/categories/class/categorie.class.php');

			if($conf->global->ATGPCONNECTOR_FORMAT_FAC_CHORUS_CATEGORY <= 0)
			{
				return false;
			}

			$category = new Categorie($db);
			$categoryFetchReturn = $category->fetch($conf->global->ATGPCONNECTOR_FORMAT_FAC_CHORUS_CATEGORY);

			if($categoryFetchReturn <= 0)
			{
				return false;
			}
		}

		return $massAction || (
				$object->statut > Facture::STATUS_DRAFT
			&&	! empty($object->thirdparty->idprof2)
			&&	$category->containsObject('customer', $object->thirdparty->id) > 0
		);
	}


	function _sendOneInvoiceToChorus(Facture $invoice)
	{
		$formatFAC = new EDIFormatFAC($invoice);
		$formatFAC->put();

		// TODO envois groupés
	}
}
