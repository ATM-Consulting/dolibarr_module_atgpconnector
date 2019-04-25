<?php

dol_include_once('/atgpconnector/class/ediformat.class.php');

class EDIFormatPARTINChorus extends EDIFormat
{
	/** @var DoliDB */
	public $db;

	/** @var integer count error */
	public $error = 0;

	public static $remotePath = '/partin/'; // TODO
	public static $TSegments = array(
		'ATGP' => array(
			'required' => true
			, 'object' => '$object'
		)
		, 'PAR' => array(
			'required' => true
			, 'object' => '$object'
		)
		, 'CHR' => array(
			'required' => true
			, 'object' => '$object'
		)
		, 'SER' => array(
			'object' => '$object'
		)
	);


	public function __construct()
	{
		global $db;

		$this->db = $db;
	}
	
	public function cronUpdateCompanies()
	{
		global $langs, $conf;

		if(empty($conf->global->ATGPCONNECTOR_FORMAT_PARTIN_CHORUS))
		{
			$this->output = 'No update : PARTIN Chorus format is disabled';
			return 0;
		}

		if(! empty($conf->global->ATGPCONNECTOR_FORMAT_PARTIN_CHORUS_PATH))
		{
			static::$remotePath = $conf->global->ATGPCONNECTOR_FORMAT_PARTIN_CHORUS_PATH;
		}

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
					$nbUpdate+= $this->updateCompaniesFromFile($fileName);
				}
			}

			$this->output.= count($files).' file(s) found. Updates made : '.$nbUpdate;
		}

		if ($this->error > 0) $this->output.= "\n\n".'NB error = '.$this->error;

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

			$tmpPath = DOL_DATA_ROOT . '/atgpconnector/temp/partinchorus/';
			ftp_chdir($ftpHandle, static::$remotePath);
			$localFiles = array();

			$files = ftp_nlist($ftpHandle, '.');
			if (!empty($files))
			{
				foreach ($files as $fname) {
					if ($fname == '.' || $fname == '..') continue;
					$newFileName = $tmpPath . str_replace('.csv', '_' . dol_print_date(dol_now(), '%d%m%Y_%H%M%S') . '.csv', $fname); 
					if(ftp_get($ftpHandle, $newFileName, $fname, FTP_ASCII)) {
						$localFiles[] = $newFileName;
						ftp_delete($ftpHandle, $fname);
					}
					else
					{
						$this->output .= 'Cannot download file "' . $fname . '"' . "\n";
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

	private function updateCompaniesFromFile($filePath)
	{
		global $user;

		$nbUpdate = 0;

		$currentCompany = null;
		$idProfType = '';
		$idProfValue = '';
		
		$csvHandle = fopen($filePath, 'r');

		if($csvHandle === false)
		{
			$this->error++;
			$this->output.= '- updateDocStatusFromFile : fopen ' . $filePath . ' FAILED'."\n";
			return $nbUpdate;
		}

		while($line = $this->read($csvHandle))
		{
			switch($line[0])
			{
				case '@GP':
					if($line[2] != 'PARTIN')
					{
						$this->error++;
						$this->output.= 'Wrong file format : "PARTIN" expected, "' . $line[2] . '" given' . "\n";
						return 0;
					}

					break;

				case 'PAR':
					$currentCompany = new Societe($this->db);

					$idProfType = $line[1];

					switch($idProfType)
					{
						case 'SIREN':
							$idProfValue = $line[14];
							$currentCompany->fetch('', '', '', '', $idProfValue);
							break;

						case 'SIRET':
							$idProfValue = $line[13];
							$currentCompany->fetch('', '', '', '', '', $idProfValue);
							break;

						// TODO others
					}

					if($currentCompany->id > 0)
					{
						$currentCompany->fetch_optionals();
					}

					break;

				case 'CHR':
					if($currentCompany->id > 0)
					{
						// 1 => non, 2 => oui
						$currentCompany->array_options['options_code_service'] = $line[10] + 1;
						$currentCompany->array_options['options_n_eng'] = $line[9] + 1;
						$currentCompany->array_options['options_cs_engage'] = $line[11] + 1;
						$currentCompany->array_options['options_moa_pub'] = $line[7] + 1;
						$currentCompany->array_options['options_moa'] = $line[8] + 1;
						$currentCompany->array_options['options_active'] = $line[1] + 1;

						$ret = $currentCompany->update(0, $user);

						if($ret > 0)
						{
							$nbUpdate++;
							$this->output.= 'Thirdparty ' . $currentCompany->name . ' found with ' . $idProfType . ' ' . $idProfValue . ' and updated successfully'  . "\n";
						}
						else
						{
							$this->error++;
							$this->output.= 'Thirdparty ' . $currentCompany->name . ' found with ' . $idProfType . ' ' . $idProfValue . ', but error on update : ' . $currentCompany->error . "\n";
						}
					}

					break;
			}
		}


		fclose($csvHandle);

		return $nbUpdate;
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


class EDIFormatPARTINChorusSegmentATGP extends EDIFormatSegment
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
			, 'data' => '"PARTIN"'
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
			'label' => 'Type émetteur (GLN, TVA, CODEINTERNE, SIREN, SIRET)'
			, 'data' => ''
			, 'maxLength' => 11
		)
		, 7 => array(
			'label' => 'Code destinataire'
			, 'data' => ''
			, 'maxLength' => 35
		)
		, 8 => array(
			'label' => 'Type destinataire (GLN, TVA, CODEINTERNE, SIREN, SIRET)'
			, 'data' => ''
			, 'maxLength' => 11
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
			'label' => 'Heure d\'interchange'
			, 'data' => ''
			, 'maxLength' => 5
		)
		, 12 => array(
			'label' => 'Suppression des contacts non présents'
			, 'data' => ''
			, 'maxLength' => 1
		)
	);
}


class EDIFormatPARTINChorusSegmentPAR extends EDIFormatSegment
{
	public static $TFields = array (
		1 => array (
			'label' => 'Étiquette de segment "PAR"'
			, 'data' => '"PAR"'
			, 'maxLength' => 3
			, 'required' => true
		)
		, 2 => array (
			'label' => 'Type identifiant (GLN, TVA, CODEINTERNE, SIREN, SIRET)'
			, 'data' => ''
			, 'maxLength' => 11
			, 'required' => true
		)
		, 3 => array (
			'label' => 'Code GLN'
			, 'data' => ''
			, 'maxLength' => 13
		)
		, 4 => array (
			'label' => 'Type partenaires (Fournisseur, Client)'
			, 'data' => ''
			, 'maxLength' => 35
		)
		, 5 => array (
			'label' => 'Code interne chez partenaire'
			, 'data' => ''
			, 'maxLength' => 35
		)
		, 6 => array (
			'label' => 'Raison sociale'
			, 'data' => ''
			, 'maxLength' => 35
			, 'required' => true
		)
		, 7 => array (
			'label' => 'Adresse 1'
			, 'data' => ''
			, 'maxLength' => 35
			, 'required' => true
		)
		, 8 => array (
			'label' => 'Adresse 2'
			, 'data' => ''
			, 'maxLength' => 35
		)
		, 9 => array (
			'label' => 'Adresse 3'
			, 'data' => ''
			, 'maxLength' => 35
		)
		, 10 => array (
			'label' => 'Code postal'
			, 'data' => ''
			, 'maxLength' => 17
		)
		, 11 => array (
			'label' => 'Ville'
			, 'data' => ''
			, 'maxLength' => 35
		)
		, 12 => array (
			'label' => 'Code pays'
			, 'data' => ''
			, 'maxLength' => 3
		)
		, 13 => array (
			'label' => 'Numéro TVA'
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
			'label' => 'Numéro RCS'
			, 'data' => ''
			, 'maxLength' => 35
		)
		, 17 => array (
			'label' => 'Ville RCS'
			, 'data' => ''
			, 'maxLength' => 35
		)
		, 18 => array (
			'label' => 'Forme juridique'
			, 'data' => ''
			, 'maxLength' => 35
		)
		, 19 => array (
			'label' => 'Capital social'
			, 'data' => ''
			, 'maxLength' => 35
		)
		, 20 => array (
			'label' => 'Devise capital social'
			, 'data' => ''
			, 'maxLength' => 3
		)
		, 21 => array (
			'label' => 'Modèle société (alias de la société modèle créée par @GP)'
			, 'data' => ''
			, 'maxLength' => 35
		)
	);
}


class EDIFormatPARTINChorusSegmentCHR extends EDIFormatSegment
{
	public static $TFields = array (
		1 => array (
			'label' => 'Étiquette de segment "CHR"'
			, 'data' => '"PAR"'
			, 'maxLength' => 3
			, 'required' => true
		)
		, 2 => array (
			'label' => 'Structure active ? (0/1)'
			, 'data' => ''
			, 'maxLength' => 1
		)
		, 3 => array (
			'label' => 'Téléphone'
			, 'data' => ''
			, 'maxLength' => 20
		)
		, 4 => array (
			'label' => 'Email'
			, 'data' => ''
			, 'maxLength' => 255
		)
		, 5 => array (
			'label' => 'Pays'
			, 'data' => ''
			, 'maxLength' => 100
		)
		, 6 => array (
			'label' => 'Est émetteur EDI ? (0/1)'
			, 'data' => ''
			, 'maxLength' => 1
		)
		, 7 => array (
			'label' => 'Est récepteur EDI ? (0/1)'
			, 'data' => ''
			, 'maxLength' => 1
		)
		, 8 => array (
			'label' => 'Est MOA ? (0/1)'
			, 'data' => ''
			, 'maxLength' => 1
		)
		, 9 => array (
			'label' => 'MOA uniquement ? (0/1)'
			, 'data' => ''
			, 'maxLength' => 1
		)
		, 10 => array (
			'label' => 'Gère code engagement ? (0/1)'
			, 'data' => ''
			, 'maxLength' => 1
		)
		, 11 => array (
			'label' => 'Gère Service ? (0/1)'
			, 'data' => ''
			, 'maxLength' => 1
		)
		, 12 => array (
			'label' => 'Gère engagement service ? (0/1)'
			, 'data' => ''
			, 'maxLength' => 1
		)
		, 13 => array (
			'label' => 'Mise en paiement ? (0/1)'
			, 'data' => ''
			, 'maxLength' => 1
		)
	);
}


class EDIFormatPARTINChorusSegmentSER extends EDIFormatSegment
{
	public static $TFields = array (
		1 => array (
			'label' => 'Étiquette de segment "SER"'
			, 'data' => '"STA"'
			, 'maxLength' => 3
			, 'required' => true
		)
		, 2 => array (
			'label' => 'Code service'
			, 'data' => ''
			, 'maxLength' => 100
		)
		, 3 => array (
			'label' => 'Nom service'
			, 'data' => ''
			, 'maxLength' => 100
		)
		, 4 => array (
			'label' => 'Gère engagement ? (0/1)'
			, 'data' => ''
			, 'maxLength' => 1
		)
		, 5 => array (
			'label' => 'Service actif ? (0/1)'
			, 'data' => ''
			, 'maxLength' => 1
		)
	);
}
