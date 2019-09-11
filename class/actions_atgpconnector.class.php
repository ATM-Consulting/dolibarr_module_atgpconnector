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
		if ($this->_canHandleEDIFAC($parameters, $object, $action, $hookmanager) && $action === 'send-to-customer')
		{
			global $langs;

			define('INC_FROM_DOLIBARR', true);
			dol_include_once('/atgpconnector/config.php');
			dol_include_once('/atgpconnector/class/ediformatfac.class.php');

			$langs->load('atgpconnector@atgpconnector');
			$documentSent = $this->_sendOneInvoice($object);

			if($documentSent)
			{
				setEventMessage($langs->trans('ATGPC_FACUploadSuccess', $object->ref));
			}

			return $documentSent ? 0 : -1;
		}

		if ($this->_canHandleEDIFACChorus($parameters, $object, $action, $hookmanager) && $action === 'send-to-chorus')
		{
			global $langs;

			define('INC_FROM_DOLIBARR', true);
			dol_include_once('/atgpconnector/config.php');
			dol_include_once('/atgpconnector/class/ediformatfacchorus.class.php');

			$langs->load('atgpconnector@atgpconnector');
			$documentSent = $this->_sendOneInvoiceToChorus($object);

			if($documentSent)
			{
				setEventMessage($langs->trans('ATGPC_FACUploadSuccess', $object->ref));
			}

			return $documentSent ? 0 : -1;
		}

		return 0;
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

		if ($this->_canHandleEDIFAC($parameters, $object, $action, $hookmanager))
		{
			$langs->load('atgpconnector@atgpconnector');

			$url = $_SERVER['PHP_SELF'] .'?id=' . $object->id . '&action=send-to-customer';

			print '<div class="inline-block divButAction"><a class="butAction" href="'. $url .'">' . $langs->trans('ATGPC_SendViaAtGP') . '</a></div>';
		}

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

		if ($this->_canHandleEDIFAC($parameters, $object, $action, $hookmanager, 'invoicelist', true))
		{
			$langs->load('atgpconnector@atgpconnector');

			$this->resprints = '<option value="send-to-customer">' . $langs->trans('ATGPC_SendViaAtGP') . '</option>';
		}

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

		if ($this->_canHandleEDIFAC($parameters, $object, $action, $hookmanager, 'invoicelist', true) && $massaction === 'send-to-customer')
		{
			global $langs;

			$langs->load('atgpconnector@atgpconnector');

			define('INC_FROM_DOLIBARR', true);
			dol_include_once('/atgpconnector/config.php');
			dol_include_once('/atgpconnector/class/ediformatfac.class.php');

			$TIDInvoices = $parameters['toselect'];
			$nbUploadsDone = 0;

			foreach($TIDInvoices as $invoiceID)
			{
				$invoice = new Facture($object->db);
				$invoice->fetch($invoiceID);

				if($this->_canHandleEDIFAC($parameters, $invoice, $action, $hookmanager, 'invoicelist'))
				{
					if($this->_sendOneInvoice($invoice))
					{
						$nbUploadsDone++;
					}
				} else {
					setEventMessage($langs->trans('ATGPC_InvoiceNotEligible', $invoice->ref), 'warnings');
				}
			}

			if($nbUploadsDone > 0)
			{
				setEventMessage($langs->trans('ATGPC_NInvoicesSuccesfullySent', $nbUploadsDone));
			}
		}

		if ($this->_canHandleEDIFACChorus($parameters, $object, $action, $hookmanager, 'invoicelist', true) && $massaction === 'send-to-chorus')
		{
			global $langs;

			$langs->load('atgpconnector@atgpconnector');

			define('INC_FROM_DOLIBARR', true);
			dol_include_once('/atgpconnector/config.php');
			dol_include_once('/atgpconnector/class/ediformatfacchorus.class.php');

			$TIDInvoices = $parameters['toselect'];
			$nbUploadsDone = 0;

			foreach($TIDInvoices as $invoiceID)
			{
				$invoice = new Facture($object->db);
				$invoice->fetch($invoiceID);

				if($this->_canHandleEDIFACChorus($parameters, $invoice, $action, $hookmanager, 'invoicelist'))
				{
					if($this->_sendOneInvoiceToChorus($invoice))
					{
						$nbUploadsDone++;
					}
				} else {
					setEventMessage($langs->trans('ATGPC_InvoiceNotChorusEligible', $invoice->ref), 'warnings');
				}
			}

			if($nbUploadsDone > 0)
			{
				setEventMessage($langs->trans('ATGPC_NInvoicesSuccesfullySent', $nbUploadsDone));
			}
		}

		return 0;
	}


	function _canHandleEDI($parameters, &$object, &$action, $hookmanager)
	{
		global $conf;

		if(! empty($object->id)) // $object->id vide si massaction
		{
			$this->_loadAllInvoiceData($object);
		}

		return ! empty($conf->global->ATGPCONNECTOR_FTP_HOST) && ! empty($conf->global->ATGPCONNECTOR_FTP_USER);
	}


	function _canHandleEDIFAC($parameters, &$object, &$action, $hookmanager, $targetContext = 'invoicecard', $massAction = false)
	{
		global $conf, $db;

		if(
			! $this->_canHandleEDI($parameters, $object, $action, $hookmanager)
			||	! in_array($targetContext, explode(':', $parameters['context']))
			||	empty($conf->global->ATGPCONNECTOR_FORMAT_FAC)
		)
		{
			return false;
		}

		return $massAction || (
			$object->statut > Facture::STATUS_DRAFT
			&&	empty($object->array_options['options_atgp_status'])
			&&	! empty($object->thirdparty->idprof2)
		);
	}


	function _canHandleEDIFACChorus($parameters, &$object, &$action, $hookmanager, $targetContext = 'invoicecard', $massAction = false)
	{
		global $conf, $db;

		if(
				! $this->_canHandleEDI($parameters, $object, $action, $hookmanager)
			||	! in_array($targetContext, explode(':', $parameters['context']))
			||	empty($conf->global->ATGPCONNECTOR_FORMAT_FAC_CHORUS)
			||  empty($conf->global->ATGPCONNECTOR_MYSOC_GLN_CODE)
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
			&&	empty($object->array_options['options_atgp_status'])
			&&	! empty($object->thirdparty->idprof2)
			&&	$category->containsObject('customer', $object->thirdparty->id) > 0
		);
	}


	function _sendOneInvoice(Facture &$invoice)
	{
		global $conf, $langs;

		$this->_loadAllInvoiceData($invoice);

		if(! empty($conf->global->ATGPCONNECTOR_FORMAT_FAC_PATH))
		{
			EDIFormatFAC::$remotePath = $conf->global->ATGPCONNECTOR_FORMAT_FAC_PATH;
		}

		$formatFAC = new EDIFormatFAC($invoice);

		if (! empty($formatFAC->TErrors))
		{
			setEventMessage($formatFAC->TErrors, 'errors');
			return false;
		}

		$documentUploaded = $formatFAC->put();

		if($documentUploaded)
		{
			if(empty($conf->global->ATGPCONNECTOR_FTP_DISABLE_ALL_TRANSFERS))
			{
				$this->_insertAutomaticActionComm($invoice, 'INVOICE_SENT_TO_ATGP');
				$invoice->array_options['options_atgp_status'] = 201; // Déposée
				// $invoice->insertExtraFields();
			}
		}
		else
		{
			setEventMessages($langs->trans('ATGPC_ErrorForInvoice', $invoice->ref), $formatFAC->TErrors, 'errors');
		}

		// TODO envois groupés

		return $documentUploaded;
	}


	function _sendOneInvoiceToChorus(Facture &$invoice)
	{
		global $conf, $langs;

		$this->_loadAllInvoiceData($invoice);

		if(! empty($conf->global->ATGPCONNECTOR_FORMAT_FAC_CHORUS_PATH))
		{
			EDIFormatFACChorus::$remotePath = $conf->global->ATGPCONNECTOR_FORMAT_FAC_CHORUS_PATH;
		}

		$formatFAC = new EDIFormatFACChorus($invoice);

		if (!empty($formatFAC->TErrors))
        {
            setEventMessage($formatFAC->TErrors, 'errors');
        }
		else
        {
            $documentUploaded = $formatFAC->put();

            if($documentUploaded)
            {
                if(empty($conf->global->ATGPCONNECTOR_FTP_DISABLE_ALL_TRANSFERS))
                {
                    $this->_insertAutomaticActionComm($invoice, 'INVOICE_SENT_TO_CHORUS');
                    $invoice->array_options['options_atgp_status'] = 201; // Déposée
                    $invoice->insertExtraFields();
                }
            }
            else
            {
                setEventMessages($langs->trans('ATGPC_ErrorForInvoice', $invoice->ref), $formatFAC->TErrors, 'errors');
            }

		    // TODO envois groupés

            return $documentUploaded;
        }

		return false;
	}


	function _loadAllInvoiceData(Facture &$invoice)
	{
		if(empty($invoice->thirdparty) && method_exists($invoice, 'fetch_thirdparty'))
		{
			$invoice->fetch_thirdparty();
		}

		if(empty($invoice->array_options) && method_exists($invoice, 'fetch_optionals'))
		{
			$invoice->fetch_optionals();
		}

		if(empty($invoice->linkedObjects) && method_exists($invoice, 'fetchObjectLinked'))
		{
			$invoice->fetchObjectLinked();
		}

		if(empty($invoice->_TContacts))
		{
			$invoice->_TContacts = $invoice->liste_contact(-1, 'external', 0);

			foreach ($invoice->_TContacts as &$contactDescriptor)
			{
				dol_include_once('/contact/class/contact.class.php');

				$contactDescriptor['_contact'] = new Contact($invoice->db);
				$contactDescriptor['_contact']->fetch($contactDescriptor['id']);
			}
		}
	}


	function _insertAutomaticActionComm(&$object, $actionKey)
	{
		global $user;

		$object->call_trigger($actionKey, $user);
	}
}
