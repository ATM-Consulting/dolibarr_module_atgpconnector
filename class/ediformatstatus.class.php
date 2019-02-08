<?php

dol_include_once('/atgpconnector/class/ediformat.class.php');

class EDIFormatSTATUS extends EDIFormat
{
	public static $remotePath = '/statutdemat/';
	public static $TSegments = array(
		'ATGP' => array(
			'required' => true
			, 'object' => '$object'
		)
		, 'ENT' => array(
			'required' => true
			, 'object' => '$object'
		)
		, 'PAR' => array(
			'required' => true
			, 'object' => '$object'
		)
		, 'STA' => array(
			'required' => true
			, 'object' => '$object->lines'
		)
	);
	
	public function __construct()
	{
		global $db;
		
		// Load status from dictionnary
		$this->status = array();
		
		$sql = 'SELECT rowid, code FROM '.MAIN_DB_PREFIX.'c_atgpconnector_status';
		$resql = $db->query($sql);
		while($obj = $db->fetch_object($resql)) {
			$this->status[$obj->code] = $obj->rowid;
		}
	}
	
	public function cronUpdateStatus() {
//		define('INC_FROM_DOLIBARR', true);
		define('INC_FROM_CRON_SCRIPT', true);
		dol_include_once('/atgpconnector/config.php');

		$this->output = '*** '.date('Y-m-d H:i:s')."\n";

		$files = $this->getFilesFromFTP();
		if ($files === false)
		{
			$this->output.= '- getFilesFromFTP return false';
		}
		else
		{
			$nbUpdate = 0;
			if(!empty($files)) {
				foreach ($files as $fileName) {
					if($this->updateDocStatusFromFile($fileName)) $nbUpdate++;
				}
			}

			$this->output.= count($files).' found. Updates made : '.$nbUpdate;
		}
	}
	
	public function getFilesFromFTP() {
		global $conf,$langs;
		// Récupération des fichiers statut du FTP
		if(empty($conf->global->ATGPCONNECTOR_FTP_DISABLE_ALL_TRANSFERS)) // conf cachée
		{
			$ftpPort = ! empty($conf->global->ATGPCONNECTOR_FTP_PORT) ? $conf->global->ATGPCONNECTOR_FTP_PORT : 21;

			$ftpHandle = ftp_connect($conf->global->ATGPCONNECTOR_FTP_HOST, $ftpPort);
			if($ftpHandle === false)
			{
				$this->output.= $langs->trans('ATGPC_CouldNotOpenFTPConnection')."\n";
				$this->appendError('ATGPC_CouldNotOpenFTPConnection');
				return false;
			}

			$ftpLogged = ftp_login($ftpHandle, $conf->global->ATGPCONNECTOR_FTP_USER, $conf->global->ATGPCONNECTOR_FTP_PASS);

			if(! $ftpLogged)
			{
				$this->output.= $langs->trans('ATGPC_FTPAuthentificationFailed')."\n";
				$this->appendError('ATGPC_FTPAuthentificationFailed');
				return false;
			}

			$tmpPath = DOL_DATA_ROOT . '/atgpconnector/temp/status/';
			ftp_chdir($ftpHandle, static::$remotePath);
			$localFiles = array();

			$files = ftp_nlist($ftpHandle, '.');
			if (!empty($files))
			{
				foreach ($files as $fname) {
					if ($fname == '.' || $fname == '..') continue;
					if(ftp_get($ftpHandle, $tmpPath.$fname, $fname, FTP_ASCII)) {
						$localFiles[] = $tmpPath.$fname;
						ftp_delete($ftpHandle, $fname);
					}
				}
			}

			ftp_close($ftpHandle);
			
			return $localFiles;
		}
	}

	private function updateDocStatusFromFile($filePath) {
		$docRef = '';
		$docType = '';
		$docStatus = '';
		
		$fh = fopen($filePath, 'r');
		if ($fh)
		{
			while($line = fgetcsv($fh, 0, ATGPCONNECTOR_CSV_SEPARATOR)) {
				if($line[0] == 'ENT') {
					$docRef = trim($line[1]);
					$docType = trim($line[3]);
				}
				if($line[0] == 'STA') {
					$docStatus = trim($line[1]);
					$docStatus = (!empty($this->status[$docStatus])) ? $this->status[$docStatus] : '';
				}
			}
			fclose($fh);
		}
		else
		{
			$this->output.= '- updateDocStatusFromFile : fopen $filePath FAILED'."\n";
		}

		//unlink($filePath);
		if(!empty($docRef) && !empty($docType) && !empty($docStatus)) {
			return $this->updateDocStatus($docRef, $docType, $docStatus);
		}
	}
	
	private function updateDocStatus($docRef, $docType, $docStatus) {
		global $db;
		
		if($docType == 'facture') {
			dol_include_once('/compta/facture/class/facture.class.php');
			$tmpFac = new Facture($db);
			if($tmpFac->fetch(0, $docRef) > 0) {
				$tmpFac->array_options['options_atgp_status'] = $docStatus;
				$tmpFac->insertExtraFields();
				return true;
			}
			else
			{
				$this->output.= '- updateDocStatus : fetch invoice by ref ['.$docRef.'] FAILED'."\n";
			}
		}

		return false;
	}

	public function afterObjectLoaded()
	{
		global $conf, $mysoc;
	}


	public function afterCSVGenerated($tmpPath)
	{
		$fileName = basename($tmpPath);

		$this->object->attachedfiles = array(
			'paths' => array($tmpPath)
			, 'names' => array($fileName)
		);
	}
}


class EDIFormatSTATUSSegmentATGP extends EDIFormatSegment
{
	public static $TFields = array(
		1 => array(
			'label' => 'Etiquette de segment "@GP"'
			, 'data' => '"@GP"'
			, 'maxLength' => 3
			, 'required' => true
		)
		, 2 => array(
			'label' => 'Logiciel'
			, 'data' => '"WEB@EDI"'
			, 'maxLength' => 7
			, 'required' => true
		)
		, 3 => array(
			'label' => 'Données contenues'
			, 'data' => '"STATUS"'
			, 'maxLength' => 6
			, 'required' => true
		)
		, 4 => array(
			'label' => 'Format de fichier'
			, 'data' => '"STANDARD"'
			, 'maxLength' => 8
			, 'required' => true
		)
		, 5 => array(
			'label' => 'Code émetteur'
			, 'data' => ''
			, 'maxLength' => 35
		)
		, 6 => array(
			'label' => 'Qualifiant émetteur'
			, 'data' => ''
			, 'maxLength' => 5
		)
		, 7 => array(
			'label' => 'Code destinataire'
			, 'data' => ''
			, 'maxLength' => 35
		)
		, 8 => array(
			'label' => 'Qualifiant destinataire'
			, 'data' => ''
			, 'maxLength' => 5
		)
		, 9 => array(
			'label' => 'Numéro interchange'
			, 'data' => ''
			, 'maxLength' => 10000
		)
		, 10 => array(
			'label' => 'Date création'
			, 'data' => ''
			, 'maxLength' => 10
		)
		, 11 => array(
			'label' => 'Heures'
			, 'data' => ''
			, 'maxLength' => 5
		)
		, 12 => array(
			'label' => 'Application du code'
			, 'data' => ''
			, 'maxLength' => 35
		)
	);
}


class EDIFormatSTATUSSegmentENT extends EDIFormatSegment
{
	public static $TFields = array (
		1 => array (
			'label' => 'Étiquette de segment "ENT"'
			, 'data' => '"ENT"'
			, 'maxLength' => 3
			, 'required' => true
		)
		, 2 => array (
			'label' => 'Numéro de document'
			, 'data' => '' // TODO
			, 'maxLength' => 35
			, 'required' => true
		)
		, 3 => array (
			'label' => 'Date document'
			, 'data' => '' // TODO
			, 'maxLength' => 10
			, 'required' => true
		)
		, 4 => array (
			'label' => 'Type de document'
			, 'data' => ''
			, 'maxLength' => 10
		)
		, 5 => array (
			'label' => 'Montant TTC'
			, 'data' => ''
			, 'maxLength' => 13
			, 'required' => true
		)
		, 6 => array (
			'label' => 'Code monnaie (EUR pour Euro)'
			, 'data' => ''
			, 'maxLength' => 3
			, 'required' => true
		)
		, 7 => array (
			'label' => 'Date d\'intégration'
			, 'data' => ''
			, 'maxLength' => 10
		)
	);
}


class EDIFormatSTATUSSegmentPAR extends EDIFormatSegment
{
	public static $TFields = array (
		1 => array (
			'label' => 'Étiquette de segment "PAR"'
			, 'data' => '"PAR"'
			, 'maxLength' => 3
			, 'required' => true
		)
		, 2 => array (
			'label' => 'Type de partenaire'
			, 'data' => ''
			, 'maxLength' => 2
			, 'required' => true
		)
		, 3 => array (
			'label' => 'Code GLN'
			, 'data' => ''
			, 'maxLength' => 13
		)
		, 4 => array (
			'label' => 'Raison sociale'
			, 'data' => '' // TODO
			, 'maxLength' => 35
			, 'required' => true
		)
		, 5 => array (
			'label' => 'Code interne'
			, 'data' => '' // TODO
			, 'maxLength' => 35
		)
		, 6 => array (
			'label' => 'Adresse ligne 1'
			, 'data' => ''
			, 'maxLength' => 35
		)
		, 7 => array (
			'label' => 'Adresse ligne 2'
			, 'data' => ''
			, 'maxLength' => 35
		)
		, 8 => array (
			'label' => 'Adresse ligne 3'
			, 'data' => ''
			, 'maxLength' => 35
		)
		, 9 => array (
			'label' => 'Code postal'
			, 'data' => ''
			, 'maxLength' => 17
		)
		, 10 => array (
			'label' => 'Ville'
			, 'data' => ''
			, 'maxLength' => 35
		)
		, 11 => array (
			'label' => 'Code EAN factor (obligatoire si factor)'
			, 'data' => ''
			, 'maxLength' => 13
		)
		, 12 => array (
			'label' => 'Code pays'
			, 'data' => ''
			, 'maxLength' => 3
		)
		, 13 => array (
			'label' => 'Numéro d\'identification TVA'
			, 'data' => ''
			, 'maxLength' => 35
		)
		, 14 => array (
			'label' => 'SIRET'
			, 'data' => ''
			, 'maxLength' => 35
		)
		, 15 => array (
			'label' => 'SIREN'
			, 'data' => ''
			, 'maxLength' => 35
		)
		, 16 => array (
			'label' => 'Code interne chez le partenaire'
			, 'data' => ''
			, 'maxLength' => 35
		)
	);
}


class EDIFormatSTATUSSegmentSTA extends EDIFormatSegment
{
	public static $TFields = array (
		1 => array (
			'label' => 'Étiquette de segment "STA"'
			, 'data' => '"STA"'
			, 'maxLength' => 3
			, 'required' => true
		)
		, 2 => array (
			'label' => 'Statut'
			, 'data' => ''
			, 'maxLength' => 35
			, 'required' => true
		)
		, 3 => array (
			'label' => 'Date heure du statut'
			, 'data' => ''
			, 'maxLength' => 16
			, 'required' => true
		)
		, 4 => array (
			'label' => 'Informations'
			, 'data' => ''
			, 'maxLength' => 255
		)
		, 5 => array (
			'label' => 'Catégorie'
			, 'data' => '' // TODO
			, 'maxLength' => 35
			, 'required' => true
		)
		, 6 => array (
			'label' => 'Test'
			, 'data' => ''
			, 'maxLength' => 1
		)
	);
}
