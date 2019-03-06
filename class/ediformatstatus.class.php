<?php

dol_include_once('/atgpconnector/class/ediformat.class.php');

class EDIFormatSTATUS extends EDIFormat
{
	/** @var DoliDB */
	public $db;

	/** @var integer count error */
	public $error = 0;

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

	/** @var array Table of status id from c_atgpconnector_status dictionary by code */
	public $TStatusIdByCode = array();

	public function __construct()
	{
		global $db;

		$this->db = $db;

		$sql = 'SELECT rowid, code FROM '.MAIN_DB_PREFIX.'c_atgpconnector_status';
		$resql = $this->db->query($sql);
		while($obj = $this->db->fetch_object($resql)) {
			$this->TStatusIdByCode[$obj->code] = $obj->rowid;
		}
	}
	
	public function cronUpdateStatus()
	{
		global $langs;

		define('INC_FROM_DOLIBARR', true);
//		define('INC_FROM_CRON_SCRIPT', true);
		dol_include_once('/atgpconnector/config.php');

		$langs->load('atgpconnector@atgpconnector');

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
					$nbUpdate+= $this->updateDocStatusFromFile($fileName);
				}
			}

			$this->output.= count($files).' found. Updates made : '.$nbUpdate;
		}

		if ($this->error > 0) $this->output.= "\n\n".'NB error = '.$this->error;

		return 0;
	}


	public function cronUpdateStatusFromLocalFile($filename)
	{
		$action = GETPOST('action');
		// Pour éviter de déclancher le comportement via la tache cron, je veux exécuter ce code que s'il s'agit d'un lancement manuel
		if ($action == 'confirm_execute')
		{
			define('INC_FROM_DOLIBARR', true);
			dol_include_once('/atgpconnector/config.php');

			$tmpPath = DOL_DATA_ROOT . '/atgpconnector/temp/status/';

			if ($filename == 'all')
			{
				$files=array();
				$dir = opendir($tmpPath);
				while(false != ($file = readdir($dir))) {
					if(($file != ".") && ($file != "..") && !is_dir($tmpPath.$file)) {
						$files[] = $file; // put in array.
					}
				}

				usort($files, function($a, $b) {
					$date_a = explode('_', $a);
					$date_a = $date_a[2];
					$date_b = explode('_', $b);
					$date_b = $date_b[2];

					if ($date_a < $date_b) return -1;
					elseif ($date_a > $date_b) return 1;
					else return 0;
				});

				$nbUpdate = 0;
				$this->db->begin();
				foreach ($files as $filename)
				{
					$this->output.= 'Intégration de '.$filename."\n";
					$nbUpdate+= $this->updateDocStatusFromFile($tmpPath.$filename);
				}

				$this->output.= "\n\n".'$nbUpdate = '.$nbUpdate."\n";
				if ($this->error > 0)
				{
					$this->db->rollBack();
					$this->output.= 'Error ('.$this->error.') => ROLLBACK';
				}
				else
				{
					$this->db->commit();
					$this->output.= 'OK => COMMIT';
				}
			}
			elseif (is_file($tmpPath.$filename))
			{
				$nbUpdate = $this->updateDocStatusFromFile($tmpPath.$filename);
				$this->output.= "\n\n".'$nbUpdate = '.$nbUpdate;
			}
			else
			{
				$this->output = 'Fichier introuvable : '.$tmpPath.$filename;
			}
		}

		return 0;
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
				$this->error++;
				$this->output.= $langs->trans('ATGPC_CouldNotOpenFTPConnection')."\n";
				$this->appendError('ATGPC_CouldNotOpenFTPConnection');
				return false;
			}

			$ftpLogged = ftp_login($ftpHandle, $conf->global->ATGPCONNECTOR_FTP_USER, $conf->global->ATGPCONNECTOR_FTP_PASS);

			if(! $ftpLogged)
			{
				$this->error++;
				$this->output .= $langs->trans('ATGPC_FTPAuthentificationFailed')."\n";
				$this->appendError('ATGPC_FTPAuthentificationFailed');
				return false;
			}

			if (!empty($conf->global->ATGPCONNECTOR_FTP_PASSIVE_MODE))
			{
				ftp_pasv($ftpHandle, true);
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
		else
		{
			$this->output .= $langs->trans('ATGPC_FTP_is_disable')."\n";
			return false;
		}
	}

	private function updateDocStatusFromFile($filePath) {
		$docRef = '';
		$docType = '';
		$docStatus = '';
		$nbUpdate = 0;
		
		$fh = fopen($filePath, 'r');
		if ($fh)
		{
			while($line = fgetcsv($fh, 0, ATGPCONNECTOR_CSV_SEPARATOR)) {
				if($line[0] == 'ENT') {
					$docRef = trim($line[1]);
					$docType = trim($line[3]);
				}
				elseif($line[0] == 'STA') {
					$docStatus = trim($line[1]);
					$docStatus = (!empty($this->TStatusIdByCode[$docStatus])) ? $this->TStatusIdByCode[$docStatus] : '';
				}
				// Plusieurs factures présentes dans le fichier statut, plusieurs statuts présents également
				// On met à jour pour chaque facture une fois la fin d'enregistrement atteinte
				if($line[0] == 'END') {
					if(!empty($docRef) && !empty($docType) && !empty($docStatus)) {
						if($this->updateDocStatus($docRef, $docType, $docStatus)) $nbUpdate++;
						// Réinitialisation pour éviter les potentiels effets de bord
						$docRef = '';
						$docType = '';
						$docStatus = '';
					}
				}
			}
			fclose($fh);
		}
		else
		{
			$this->error++;
			$this->output.= '- updateDocStatusFromFile : fopen $filePath FAILED'."\n";
		}

		//unlink($filePath);
		return $nbUpdate;
	}

	private function updateDocStatus($docRef, $docType, $docStatus) {

		if($docType == 'facture')
		{
			require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';

			$facture = new Facture($this->db);
			if ($facture->fetch(0, $docRef) > 0)
			{
				$facture->array_options['options_atgp_status'] = $docStatus;
				$res = $facture->insertExtraFields();
				if ($res == 0)
				{
					$this->error++;
					$this->output.= '- updateDocStatus : Facture [$docRef] échec maj du statut (insertExtraFields a retourné la valeur 0 => array_options est vide)'."\n";
				}
				elseif ($res < 0)
				{
					$this->error++;
					$this->output.= '- updateDocStatus : Facture [$docRef] erreur maj du statut (lasterror = '.$facture->error.')'."\n";
				}
				else
				{
					return true;
				}
			}
			else
			{
				$this->error++;
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
