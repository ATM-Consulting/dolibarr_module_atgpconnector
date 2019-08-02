<?php

dol_include_once('/atgpconnector/class/ediformat.class.php');

class EDIFormatOrders extends EDIFormat
{
    /**
     * @var DoliDB
     */
    public $db;

	public static $remotePath = '/commandes/';
	public static $TSegments = array(
		'ATGP' => array(
			'required' => true
			, 'object' => '$object'
		),
        'ENT' => array(
			'required' => true
			, 'object' => '$object'
		),
        'DTM' => array(
            'multiple' => true
			, 'object' => '$object'
		),
        'REF' => array(
            'multiple' => true
			, 'object' => '$object->thirdparty'
		),
        'COM' => array(
            'multiple' => true
			, 'object' => '$object->thirdparty'
		),
        'ETI' => array(
			'object' => '$mysoc'
		),
        'PAR' => array(
			'required' => false
			, 'multiple' => true
			, 'object' => '$object->_TCOM'
            , 'CTA' => array(
                'multiple' => true
                , 'object' => '$segmentSubObj'
            )
		),
        'TDT' => array(
			'object' => '$object'
		),
        'TOD' => array(
            'multiple' => true,
            'object' => '$object',
            'LOC' => array(
                'multiple' => true,
                'object' => '$segmentSubObj'
            )
		),
        'EXP' => array(
			'object' => '$object'
		),
        'LIG' => array(
			'required' => true,
            'multiple' => true,
            'object' => '$object',
            'IMD' => array(
                'multiple' => true,
                'object' => '$segmentSubObj'
            ),
            'RFF' => array(
                'multiple' => true,
                'object' => '$segmentSubObj'
            ),
            'FTX' => array(
                'multiple' => true,
                'object' => '$segmentSubObj'
            ),
            'PIA' => array(
                'multiple' => true,
                'object' => '$segmentSubObj'
            ),
            'QTY' => array(
                'multiple' => true,
                'object' => '$segmentSubObj'
            ),
            'PAC' => array(
                'object' => '$segmentSubObj',
                'PCI' => array(
                    'object' => '$segmentSubObj'
                )
            ),
            'IDC' => array(
                'multiple' => true,
                'object' => '$segmentSubObj'
            ),
            'COL' => array(
                'object' => '$segmentSubObj'
            ),
            'LID' => array(
                'multiple' => true,
                'object' => '$segmentSubObj'
            )
		),
        'PID' => array(
			'multiple' => true
			, 'object' => '$object'
		),
        'MOA' => array(
			'multiple' => true
			, 'object' => '$object'
		)
	);

	public function __construct() {
	    global $db;

	    $this->db = $db;
    }

    public function afterObjectLoaded()
	{
		global $conf, $mysoc;


		// RCS

		$this->parseRCS($this->object->thirdparty);
		$this->parseRCS($mysoc);


		// Code service

		$this->parseServiceCode();


		// RIB

		dol_include_once('/compta/bank/class/account.class.php');

		$mysoc->_iban = '';
		$mysoc->_bic = '';

		$bankid = empty($this->object->fk_account) ? $conf->global->FACTURE_RIB_NUMBER : $this->object->fk_account;

		if (! empty($this->object->fk_bank)) // For backward compatibility when object->fk_account is forced with object->fk_bank
		{
			$bankid = $this->object->fk_bank;
		}

		if(! empty($bankid))
		{
			$account = new Account($this->object->db);
			$account->fetch($bankid);

			$mysoc->_iban = $account->iban;
			$mysoc->_bic = $account->bic;
		}


		// Linked order
		if (!empty($this->object->linkedObjects['commande']))
        {
            reset($this->object->linkedObjects['commande']);
            $fkey = key($this->object->linkedObjects['commande']);
            $this->object->origin_object = $this->object->linkedObjects['commande'][$fkey];
        }
		elseif (!empty($this->object->linkedObjects['contrat']))
        {
            reset($this->object->linkedObjects['contrat']);
            $fkey = key($this->object->linkedObjects['contrat']);
            $this->object->origin_object = $this->object->linkedObjects['contrat'][$fkey];
            $this->object->origin_object->ref_client = $this->object->origin_object->ref_customer;
            $this->object->origin_object->date = $this->object->origin_object->date_contrat;
        }
		else
        {
            $this->object->origin_object = $this->object;
        }




        // Check required fields
        if (!empty($this->object->thirdparty->array_options['options_code_service']) && $this->object->thirdparty->array_options['options_code_service'] === '2')
        {
            if (empty($this->object->thirdparty->_chorusServiceCode) || ctype_space($this->object->thirdparty->_chorusServiceCode))
            {
                $this->appendError('ATGPC_ErrorRequiredField', $this->object->ref, 'Code service');
            }
        }
        if (!empty($this->object->thirdparty->array_options['options_n_eng']) && $this->object->thirdparty->array_options['options_n_eng'] === '2')
        {
            if (empty($this->object->origin_object->ref_client) || ctype_space($this->object->origin_object->ref_client))
            {
                $this->appendError('ATGPC_ErrorRequiredField', $this->object->ref, 'Numéro d\'engagement ');
            }
        }
        if (!empty($this->object->thirdparty->array_options['options_cs_engage']) && $this->object->thirdparty->array_options['options_cs_engage'] === '2')
        {

            if (
                (empty($this->object->thirdparty->_chorusServiceCode) || ctype_space($this->object->thirdparty->_chorusServiceCode))
                && (empty($this->object->origin_object->ref_client) || ctype_space($this->object->origin_object->ref_client))
            ) {
                $this->appendError('ATGPC_ErrorRequiredField', $this->object->ref, 'Code service ou Numéro d\'engagement');
            }
        }

		// TVA

		$TTVA = array();
		$TCOM = array(); // Gestion des titres

		$sign=1;
		if (isset($this->object->type) && $this->object->type == 2 && ! empty($conf->global->INVOICE_POSITIVE_CREDIT_NOTE)) $sign=-1;

		foreach($this->object->lines as $i => &$line)
		{
			if($line->product_type == 9) { // Gestion des titres
				$com = (!empty($line->label)) ? $line->label : '';
				$com.= (!empty($line->label) && (!empty($line->description))) ? ' - ' : '';
				$com.= (!empty($line->description)) ? $line->description : '';
				$com = str_replace(';', ' ', $com); // Suppression caractère ";"
				$com = str_replace("\n", ', ', $com); // Suppression des sauts de ligne
				$TCOM[$i] = new stdClass();
				$TCOM[$i]->commentaire = str_replace(';', ' ', $com);
				unset($this->object->lines[$i]);
				continue;
			}
			else
            {
                $line->TDesc = str_split(str_replace(array("\r\n", "\n\r", "\n", "\r"), ' ', strip_tags($line->description)), 350);
                foreach ($line->TDesc as $k => $v)
                {
                    if (ctype_space($v) || empty($v)) unset($line->TDesc[$k]);
                }
                if (!empty($line->date_start) && !empty($line->date_end))
                {
                    $line->TDesc[] = dol_print_date($line->date_start, '%d/%m/%Y').' - '.dol_print_date($line->date_end, '%d/%m/%Y');
                }
            }

			$total_ht = 0;
			if ($line->special_code != 3)
			{
				$total_ht = $sign * ($conf->multicurrency->enabled && $this->object->multicurrency_tx != 1 ? $line->multicurrency_total_ht : $line->total_ht);
			}

			// Collecte des totaux par valeur de tva dans $this->tva["taux"]=total_tva
			$prev_progress = $line->get_prev_progress($this->object->id);
			if ($prev_progress > 0 && !empty($line->situation_percent)) // Compute progress from previous situation
			{
				if ($conf->multicurrency->enabled && $this->object->multicurrency_tx != 1) $tvaligne = $sign * $line->multicurrency_total_tva * ($line->situation_percent - $prev_progress) / $line->situation_percent;
				else $tvaligne = $sign * $line->total_tva * ($line->situation_percent - $prev_progress) / $line->situation_percent;
			} else {
				if ($conf->multicurrency->enabled && $this->object->multicurrency_tx != 1) $tvaligne= $sign * $line->multicurrency_total_tva;
				else $tvaligne= $sign * $line->total_tva;
			}

			$localtax1ligne=$line->total_localtax1;
			$localtax2ligne=$line->total_localtax2;
			$localtax1_rate=$line->localtax1_tx;
			$localtax2_rate=$line->localtax2_tx;
			$localtax1_type=$line->localtax1_type;
			$localtax2_type=$line->localtax2_type;

			if ($this->object->remise_percent) $tvaligne-=($tvaligne*$this->object->remise_percent)/100;
			if ($this->object->remise_percent) $localtax1ligne-=($localtax1ligne*$this->object->remise_percent)/100;
			if ($this->object->remise_percent) $localtax2ligne-=($localtax2ligne*$this->object->remise_percent)/100;

			$vatrate=(string) $line->tva_tx;

			// Retrieve type from database for backward compatibility with old records
			if ((! isset($localtax1_type) || $localtax1_type=='' || ! isset($localtax2_type) || $localtax2_type=='') // if tax type not defined
					&& (! empty($localtax1_rate) || ! empty($localtax2_rate))) // and there is local tax
			{
				$localtaxtmp_array=getLocalTaxesFromRate($vatrate,0, $this->object->thirdparty, $mysoc);
				$localtax1_type = $localtaxtmp_array[0];
				$localtax2_type = $localtaxtmp_array[2];
			}

			// retrieve global local tax
			if ($localtax1_type && $localtax1ligne != 0)
				$this->localtax1[$localtax1_type][$localtax1_rate]+=$localtax1ligne;
			if ($localtax2_type && $localtax2ligne != 0)
				$this->localtax2[$localtax2_type][$localtax2_rate]+=$localtax2ligne;

			if (($line->info_bits & 0x01) == 0x01) $vatrate.='*';

			if (! isset($TTVA[$vatrate]))
			{
				$TTVA[$vatrate] = new stdClass();
				$TTVA[$vatrate]->totalHT = 0;
				$TTVA[$vatrate]->totalTVA = 0;
			}

			$TTVA[$vatrate]->totalHT += $total_ht;
			$TTVA[$vatrate]->totalTVA += $tvaligne;
		}

		$this->object->lines = array_values($this->object->lines); // Pour redémarrer la numérotation des lignes

		$this->object->_TTVA = $TTVA;
		$this->object->_TCOM = $TCOM;
	}


	protected function parseRCS(&$societe)
	{
		$rcsRaw = $societe->idprof4;
		$TRCSRaw = preg_split("/\s+/", $rcsRaw);

		$rcsCode = '';
		$rcsCodeLength = 0;

		while($rcsCodeLength < 10 && ! empty($TRCSRaw))
		{
			$rcsCodeChunk = array_pop($TRCSRaw);
			$rcsCode = $rcsCodeChunk . $rcsCode;
			$rcsCodeLength += strlen($rcsCodeChunk);
		}

		$rcsCity = implode(' ', $TRCSRaw);

		if(strlen($rcsCode) > 10)
		{
			$TMatches = array();

			preg_match('/^(.+)(.{10})$/', $rcsCode, $TMatches);

			$rcsCity .= ' ' . $TMatches[1];
			$rcsCode = $TMatches[2];
		}

		$societe->_rcsCity = $rcsCity;
		$societe->_rcsCode = $rcsCode;
	}


	protected function parseServiceCode()
	{
		foreach($this->object->_TContacts as $contactDescriptor)
		{
			if($contactDescriptor['code'] == 'CHORUS_SERVICE')
			{
				$this->object->thirdparty->_chorusServiceCode = $contactDescriptor['_contact']->array_options['options_service_code'];

				break;
			}
		}
	}


	public function afterCSVGenerated($tmpPath)
	{
		$fileName = basename($tmpPath);

		$this->object->attachedfiles = array(
			'paths' => array($tmpPath)
			, 'names' => array($fileName)
		);
	}

	public function cronCreateOrders() {
	    global $langs;

	    define('INC_FROM_DOLIBARR', true);
	    require_once DOL_DOCUMENT_ROOT.'/commande/class/commande.class.php';
	    dol_include_once('/atgpconnector/config.php');
	    dol_include_once('/atgpconnector/class/ediformatstatus.class.php');

        $langs->load('atgpconnector@atgpconnector');

        $TFile = $this->getFilesFromFTP();  // Not tested yet
        /*$TFile = array(
            DOL_DATA_ROOT.'/atgp.csv'    // Just for tests
        );*/
        if($TFile === false) {
            $this->output .= '';
        }
        else {
            $nbUpdate = 0;
            foreach($TFile as $filepath) $nbUpdate += $this->createOrdersFromFile($filepath);
        }
    }

    /**
     * @return array|bool
     */
    public function getFilesFromFTP() {
        global $conf, $langs;

        // Récupération des fichiers statut du FTP
        if(empty($conf->global->ATGPCONNECTOR_FTP_DISABLE_ALL_TRANSFERS)) // conf cachée
        {
            $ftpPort = ! empty($conf->global->ATGPCONNECTOR_FTP_PORT) ? $conf->global->ATGPCONNECTOR_FTP_PORT : 21;

            $ftpHandle = ftp_connect($conf->global->ATGPCONNECTOR_FTP_HOST, $ftpPort);
            if($ftpHandle === false) {
                $this->error++;
                $this->output .= $langs->trans('ATGPC_CouldNotOpenFTPConnection')."\n";
                $this->appendError('ATGPC_CouldNotOpenFTPConnection');
                return false;
            }

            $ftpLogged = ftp_login($ftpHandle, $conf->global->ATGPCONNECTOR_FTP_USER, $conf->global->ATGPCONNECTOR_FTP_PASS);

            if(! $ftpLogged) {
                $this->error++;
                $this->output .= $langs->trans('ATGPC_FTPAuthentificationFailed')."\n";
                $this->appendError('ATGPC_FTPAuthentificationFailed');
                return false;
            }

            if(! empty($conf->global->ATGPCONNECTOR_FTP_PASSIVE_MODE)) {
                ftp_pasv($ftpHandle, true);
            }

            $tmpPath = DOL_DATA_ROOT.'/atgpconnector/temp/status/';
            ftp_chdir($ftpHandle, static::$remotePath);
            $localFiles = array();

            $files = ftp_nlist($ftpHandle, '.');
            if(! empty($files)) {
                foreach($files as $fname) {
                    if($fname == '.' || $fname == '..') continue;

                    if(ftp_get($ftpHandle, $tmpPath.$fname, $fname, FTP_ASCII)) {
                        $localFiles[] = $tmpPath.$fname;
                        ftp_delete($ftpHandle, $fname);
                    }
                }
            }

            ftp_close($ftpHandle);

            return $localFiles;
        }
        else {
            $this->output .= $langs->trans('ATGPC_FTP_is_disable')."\n";
            return false;
        }
    }

    /**
     * @param string $filepath
     */
    public function createOrdersFromFile($filepath) {
        global $db, $user;


        $syslogContext = ' / launched by ' . __FILE__ . '  ' . __CLASS__ . '::' . __METHOD__;

        $f = fopen($filepath, 'r');
        if($f !== false) {

            while($line = fgetcsv($f, 0, ATGPCONNECTOR_CSV_SEPARATOR)) {

                if($line[0]=='@GP'){
                    $commande = new Commande($this->db);
                    // Numéro de document
                    $commande->import_key = $line[8];
                }

                if($line[0]=='@ND'){
                    $commande = false;
                    continue;
                }

                // print definition of col and data
                //self::dumpLineDataLibelle($line);

                if(!empty($commande)){



                    if($line[0] == 'ENT') {
                        // Numéro de document
                        $commande->ref_client = $line[2];

                        // check if already imported
                        if(self::fetchCommandeImported($commande) > 0)
                        {
                            $this->output.= 'Skip already imported : '.$commande->ref_client."\n";
                            $commande = false;
                            continue;
                        }

                        // Date du document
                        $date = DateTime::createFromFormat('d/m/Y', $line[3]);
                        $commande->date = $date->getTimestamp();

                        // Date livraison
                        $date = DateTime::createFromFormat('d/m/Y H:i', $line[6].' '.$line[7]);
                        $commande->date_livraison = $date->getTimestamp();

                    }

                    // TIERS
                    if($line[0] == 'PAR') {
                        if($line[1] == 'SU') {

                        }
                        elseif($line[1] == 'BY') {
                            // Customer
                            $thirdparty = self::getThirdpartyFromPAR($line, true,$this->output);
                            $commande->thirdparty = $thirdparty;
                            $commande->socid = $thirdparty->id;

                            $commande->cond_reglement_id = $thirdparty->cond_reglement_id;
                            $commande->mode_reglement_id = $thirdparty->mode_reglement_id;
                        }
                        elseif($line[1] == 'DP') {
                            // delivery to
                            $thirdpartyExp = self::getThirdpartyFromPAR($line, true, $this->output);
                        }
                    }

                    // COMMANDE LINES
                    if($line[0] == 'LIG') {

                        if(empty($commande->id)){
                            $resCreate = $commande->create($user);
                            if($resCreate>1)
                            {
                                $this->output.= 'Create order '.$commande->error."\n";

                                if(!empty($commande->import_key)){
                                    // Update request because create dont add import key
                                    $sql = "UPDATE ".MAIN_DB_PREFIX."commande SET";
                                    $sql.= " import_key='".$commande->db->escape($commande->import_key)."'";
                                    $sql.= " WHERE rowid=".$commande->id;
                                    $commande->db->query($sql);
                                }
                            }
                            else
                            {
                                $this->output.= 'Create order Error : '.$commande->error."\n";
                                dol_syslog('Create order Error : '.$commande->error.$syslogContext,LOG_WARNING);
                            }
                        }

                        if(!empty($commande->id)){
                            $productFetched = 0;
                            $product = new Product($db);
                            // Fetch by barcode
                            if(!empty($line[2]) && $product->fetchObjectFrom($product->table_element, 'barcode', $line[2])){
                                $productFetched = 1;
                            }

                            // Fetch by ref
                            if(!$productFetched && !empty($line[2]) && $product->fetchObjectFrom($product->table_element, 'ref', $line[5])){
                                $productFetched = 1;
                            }



                            $desc                   = $line[9];
                            $pu_ht                  = $line[8];
                            $qty                    = $line[6];

                            $txtva                  = 0; // apparement la TVA n'est pas envoyée. ça va être pratique pour la suite
                            $txlocaltax1 = 0;
                            $txlocaltax2 = 0;
                            $fk_product = 0;
                            $remise_percent = 0;

                            $info_bits = 0;
                            $fk_remise_except = 0;
                            $price_base_type = 'HT';
                            $pu_ttc = 0;
                            $date_start = '';
                            $date_end = '';
                            $type = 0;
                            $rang = -1;
                            $special_code = 0;
                            $fk_parent_line = 0;
                            $fk_fournprice = null;
                            $pa_ht = 0;
                            $label = '';
                            $array_options = 0;
                            $fk_unit = null;
                            $origin = '';
                            $origin_id = 0;
                            $pu_ht_devise = 0;

                            if($productFetched)
                            {
                                $desc                   = '';
                                $fk_product = $product->id;
                                $type = $product->type;
                            }



                            $res = $commande->addline($desc, $pu_ht, $qty, $txtva, $txlocaltax1, $txlocaltax2, $fk_product, $remise_percent, $info_bits, $fk_remise_except, $price_base_type, $pu_ttc, $date_start, $date_end, $type, $rang, $special_code, $fk_parent_line, $fk_fournprice, $pa_ht, $label, $array_options, $fk_unit, $origin, $origin_id, $pu_ht_devise);

                        }
                    }
                }
            }

            return 0;
        }
    }

    /**
     *  Load object from specific field
     *
     *  @param	Commande	$commande
     *	@return	int					<0 if KO, Id>0 if OK
     */
    public function fetchCommandeImported(Commande $commande)
    {
        global $conf, $db;

        $result=false;

        $sql = "SELECT rowid FROM ".MAIN_DB_PREFIX.$commande->table_element;
        $sql.= " WHERE ref_client = '".$db->escape($commande->ref_client)."' AND import_key = '".$db->escape($commande->import_key)."' ";
        $sql.= " AND entity = ".$conf->entity;


        dol_syslog(__CLASS__.'::'.__METHOD__, LOG_DEBUG);
        $resql = $db->query($sql);
        if ($resql)
        {
            $row = $db->fetch_row($resql);
            // Test for avoid error -1
            if ($row[0] > 0) {
                $result = $row[0];
            }
        }

        return $result;
    }


    public static function getThirdpartyFromPAR($line, $autoCreate = false, &$output = '')
    {
        global $db, $conf, $user;

        require_once DOL_DOCUMENT_ROOT .'/core/lib/company.lib.php';

        $syslogContext = ' / launched by ' . __FILE__ . '  ' . __CLASS__ . '::' . __METHOD__;

        //BY acheteur = commandé par
        //SU fournisseur
        //DP livré à
        //OY donneur d'ordre
        //UC destinataire final
        //FG Acheteur officiel
        //IC destinataire intermédiaire
        //UD Dépot/Magasin/Consommateur Final
        //SF Enlèvement
        //IV Facturer à
        //CN consignataire
        //ST expédier à
        //FW transporteur

        $thirdparty = new Societe($db);
        $fetched = 0;

        // Fetch from EAN
        $sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."societe WHERE barcode = '".$db->escape(trim($line[2]))."'";
        $resql=$db->query($sql);
        if ($resql) {
            $num = $db->num_rows($resql);
            if ($num) {
                $obj = $db->fetch_object($resql);
                $fetchRes = $thirdparty->fetch($obj->rowid);
                if($fetchRes>0){
                    $fetched = 1;
                    return $thirdparty;
                }
                else{
                    $fetched = -1;

					$output.= 'Fetch third party from EAN Error : '.$obj->rowid."\n";
                    dol_syslog('Fetch third party from EAN Error : '.$obj->rowid.$syslogContext,LOG_WARNING);
                }
            }
        }
        else{
            $fetched = -1;
            dol_syslog('Fetch thirdparty from EAN Sql Error : '.$sql.$syslogContext, LOG_WARNING);
        }

        // Fetch from Customer/Supplier code
        if($line[3] == 'SU')
        {
            $sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."societe WHERE code_fournisseur = '".$db->escape(trim($line[11]))."'";
        }
        else{
            $sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."societe WHERE code_client = '".$db->escape(trim($line[3]))."'";
        }

        $resql=$db->query($sql);
        if ($resql) {
            $num = $db->num_rows($resql);
            if ($num) {
                $obj = $db->fetch_object($resql);
                $fetchRes = $thirdparty->fetch($obj->rowid);
                if($fetchRes>0){
                    $fetched = 1;
                    return $thirdparty;
                }
                else{
                    $fetched = -1;
					$output.= 'Fetch thirdparty from thirdparty code Error : '.$obj->rowid."\n";
                    dol_syslog('Fetch thirdparty from thirdparty code Error : '.$obj->rowid.$syslogContext,LOG_WARNING);
                }
            }
        }
        else{
            $fetched = -1;
			$output.= 'Fetch thirdparty from thirdparty code Sql Error : '.$sql."\n";
            dol_syslog('Fetch thirdparty from thirdparty code Sql Error : '.$sql.$syslogContext, LOG_WARNING);
        }

        // Fetch from NAME
        if($fetched === 0){

            $sql = " SELECT rowid FROM ".MAIN_DB_PREFIX."societe ";
            $sql.= " WHERE (nom = '".$db->escape(trim($line[4]))."' OR name_alias = '".$db->escape(trim($line[4]))."' )";
            $sql.= " AND zip = '".$db->escape(trim($line[8]))."' ";
            $resql=$db->query($sql);
            if ($resql) {
                $num = $db->num_rows($resql);
                if ($num) {
                    $obj = $db->fetch_object($resql);
                    $fetchRes = $thirdparty->fetch($obj->rowid);
                    if($fetchRes>0){
                        $fetched = 1;
                        return $thirdparty;
                    }
                    else{
                        $fetched = -1;
                        $output.= 'Fetch thirdparty from NAME Error : '.$obj->rowid."\n";
                        dol_syslog('Fetch thirdparty from NAME Error : '.$obj->rowid.$syslogContext,LOG_WARNING);
                    }
                }
            }
            else{
                $fetched = -1;
                $output.= 'Fetch thirdparty from NAME Sql Error : '.$sql."\n";
                dol_syslog('Fetch thirdparty from NAME Sql Error : '.$sql.$syslogContext, LOG_WARNING);
            }
        }


        if($fetched === 0){

            $thirdparty->name               = trim($line[4]);
            $thirdparty->barcode            = trim($line[2]);
            $thirdparty->zip                = trim($line[8]);
            $thirdparty->address            = trim($line[5]);
            $thirdparty->town               = trim($line[9]);
            $thirdparty->client             = 1;



            $country = getCountry($line[9],'all');
            if(!is_array($country)){
                $fetched = -1;
                dol_syslog('Fetch country id '.$line[9].$syslogContext, LOG_WARNING);
            }
            else{
                $thirdparty->country_id = $country['id'];
            }



            // Load object modCodeTiers
            $module=(! empty($conf->global->SOCIETE_CODECLIENT_ADDON)?$conf->global->SOCIETE_CODECLIENT_ADDON:'mod_codeclient_leopard');
            if (substr($module, 0, 15) == 'mod_codeclient_' && substr($module, -3) == 'php')
            {
                $module = substr($module, 0, dol_strlen($module)-4);
            }
            $dirsociete=array_merge(array('/core/modules/societe/'),$conf->modules_parts['societe']);
            foreach ($dirsociete as $dirroot)
            {
                $res=dol_include_once($dirroot.$module.'.php');
                if ($res) break;
            }
            $modCodeClient = new $module;


            // Affectation des codes clients
            $thirdparty->code_client = trim($line[3]);

            if(empty($thirdparty->code_client))
            {
                $thirdparty->code_client = $modCodeClient->getNextValue($thirdparty,0);
            }


            $res = $thirdparty->create($user);
            if($res > 0)
            {
                return $thirdparty;
            }
            else{
                return -1;
            }
        }
    }




    public static function dumpLineDataLibelle($line)
    {
        $line[0] = str_replace("@GP", "ATGP", "$line[0]");

        $lineDesc = array();

        // print definition of col
        $classname = 'EDIFormatOrdersSegment'.$line[0];
        if(class_exists($classname))
        {

            $lineDesc[0] = $line[0];

            foreach ($line as $index => $data) {
                if(isset($classname::$TFields[$index]))
                {
                    $lineDesc[$index] = array(
                        'index' => $index,
                        'label' => $classname::$TFields[$index+1]['label'],
                        'data' => $data
                    );
                }
            }
        }

        var_dump($lineDesc);
    }
}


class EDIFormatOrdersSegmentATGP extends EDIFormatSegment
{
    public static $TFields = array(
        1 => array(
            'label' => 'Etiquette de segment "@GP"'
            , 'data' => '"@GP"'
            , 'maxLength' => 3
            , 'required' => true
        ),
        2 => array(
            'label' => 'Logiciel'
            , 'data' => '"WEB@EDI"'
            , 'maxLength' => 7
            , 'required' => true
        ),
        3 => array(
            'label' => 'Données contenues'
            , 'data' => '"ORDERS"'
            , 'maxLength' => 6
            , 'required' => true
        ),
        4 => array(
            'label' => 'Format de fichier'
            , 'data' => '"STANDARD"'
            , 'maxLength' => 8
            , 'required' => true
        ),
        5 => array(
            'label' => 'Code émetteur'
            , 'data' => ''
            , 'maxLength' => 35
        ),
        6 => array(
            'label' => 'Qualifiant émetteur'
            , 'data' => ''
            , 'maxLength' => 2
        ),
        7 => array(
            'label' => 'Code destinataire'
            , 'data' => ''
            , 'maxLength' => 35
        ),
        8 => array(
            'label' => 'Qualifiant destinataire'
            , 'data' => ''
            , 'maxLength' => 2
        ),
        9 => array(
            'label' => 'Numéro d\'interchange'
            , 'data' => ''
            , 'maxLength' => 14
        ),
        10 => array(
            'label' => 'Date d\'interchange'
            , 'data' => ''
            , 'maxLength' => 10
        ),
        11 => array(
            'label' => 'Heure d\'interchange'
            , 'data' => ''
            , 'maxLength' => 5
        )
    );
}


class EDIFormatOrdersSegmentENT extends EDIFormatSegment
{
	public static $TFields = array (
		1 => array (
			'label' => 'Étiquette de segment "ENT"'
			, 'data' => '"ENT"'
			, 'maxLength' => 3
			, 'required' => true
		),
        2 => array (
			'label' => 'Type de message'
			, 'data' => ''
			, 'maxLength' => 3
		),
        3 => array (
			'label' => 'Numéro de document'
			, 'data' => ''
			, 'maxLength' => 35
            , 'required' => true
		),
        4 => array (
			'label' => 'Date du document'
			, 'data' => ''
			, 'maxLength' => 10
            , 'required' => true
		),
        5 => array (
			'label' => 'Heure du document'
			, 'data' => ''
			, 'maxLength' => 5
		),
        6 => array (
			'label' => 'Code monnaie'
			, 'data' => ''
			, 'maxLength' => 3
		),
        7 => array (
			'label' => 'Date de livraison'
			, 'data' => ''
			, 'maxLength' => 10
		),
        8 => array (
			'label' => 'Heure de livraison'
			, 'data' => ''
			, 'maxLength' => 5
		),
        9 => array (
			'label' => 'Date d\'expédition'
			, 'data' => ''
			, 'maxLength' => 10
		),
        10 => array (
			'label' => 'Heure d\'expédition'
			, 'data' => ''
			, 'maxLength' => 5
		),
        11 => array (
			'label' => 'Code test'
			, 'data' => ''
			, 'maxLength' => 1
		),
        12 => array (
			'label' => 'Type de commande'
			, 'data' => ''
			, 'maxLength' => 8
		),
        13 => array (
			'label' => 'Fonction du message'
			, 'data' => ''
			, 'maxLength' => 1
		)
	);
}

class EDIFormatOrdersSegmentDTM extends EDIFormatSegment
{
    public static $TFields = array (
        1 => array (
            'label' => 'Étiquette de segment "DTM"'
            , 'data' => '"DTM"'
            , 'maxLength' => 3
            , 'required' => true
        ),
        2 => array (
            'label' => 'Type de date'
            , 'data' => ''
            , 'maxLength' => 3
            , 'required' => true
        ),
        3 => array (
            'label' => 'Date JJ/MM/AAAA'
            , 'data' => ''
            , 'maxLength' => 10
            , 'required' => true
        ),
        4 => array (
            'label' => 'Heure'
            , 'data' => ''
            , 'maxLength' => 5
        ),
        5 => array (
            'label' => 'Semaine/Année'
            , 'data' => ''
            , 'maxLength' => 7
        )
    );
}

class EDIFormatOrdersSegmentREF extends EDIFormatSegment
{
    public static $TFields = array (
        1 => array (
            'label' => 'Étiquette de segment "REF"'
            , 'data' => '"REF"'
            , 'maxLength' => 3
            , 'required' => true
        ),
        2 => array (
            'label' => 'Code référence'
            , 'data' => ''
            , 'maxLength' => 3
            , 'required' => true
        ),
        3 => array (
            'label' => 'Référence'
            , 'data' => ''
            , 'maxLength' => 35
            , 'required' => true
        ),
        4 => array (
            'label' => 'Date'
            , 'data' => ''
            , 'maxLength' => 10
            , 'required' => true
        )
    );
}

class EDIFormatOrdersSegmentCOM extends EDIFormatSegment
{
    public static $TFields = array(
        1 => array(
            'label' => 'Etiquette de segment "COM"'
            , 'data' => '"COM"'
            , 'maxLength' => 3
            , 'required' => true
        ),
        2 => array(
            'label' => 'Type de commentaire'
            , 'data' => ''
            , 'maxLength' => 3
            , 'required' => true
        ),
        3 => array(
            'label' => 'Commentaire'
            , 'data' => 'substr($object->commentaire,0,512)'
            , 'maxLength' => 512
            , 'required' => true
        )
    );
}

class EDIFormatOrdersSegmentETI extends EDIFormatSegment
{
    public static $TFields = array(
        1 => array(
            'label' => 'Etiquette de segment "ETI"'
            , 'data' => '"ETI"'
            , 'maxLength' => 3
            , 'required' => true
        ),
        2 => array(
            'label' => 'Groupe logistique'
            , 'data' => ''
            , 'maxLength' => 35
        ),
        3 => array(
            'label' => 'Direction/Tournée'
            , 'data' => ''
            , 'maxLength' => 35
        ),
        4 => array(
            'label' => 'Navette'
            , 'data' => ''
            , 'maxLength' => 35
        )
    );
}

class EDIFormatOrdersSegmentPAR extends EDIFormatSegment
{
	public static $TFields = array (
		1 => array (
			'label' => 'Étiquette de segment "PAR"'
			, 'data' => '"PAR"'
			, 'maxLength' => 3
			, 'required' => true
		),
        2 => array (
			'label' => 'Type partenaire'
			, 'data' => ''
			, 'maxLength' => 2
			, 'required' => true
		),
        3 => array (
			'label' => 'Code EAN partenaire'
			, 'data' => ''
			, 'maxLength' => 13
            , 'required' => true
		),
        4 => array (
			'label' => 'Code interne partenaire'
			, 'data' => ''
			, 'maxLength' => 50
		),
        5 => array (
			'label' => 'Raison Sociale Partenaire'
			, 'data' => ''
			, 'maxLength' => 35
		),
        6 => array (
			'label' => 'Adresse'
			, 'data' => ''
			, 'maxLength' => 99
		),
        7 => array (
			'label' => 'Adresse 2'
			, 'data' => ''
			, 'maxLength' => 9
		),
        8 => array (
			'label' => 'Adresse 3'
			, 'data' => ''
			, 'maxLength' => 99
		),
        9 => array (
			'label' => 'Code postal'
			, 'data' => ''
			, 'maxLength' => 99
		),
        10 => array (
			'label' => 'Ville'
			, 'data' => ''
			, 'maxLength' => 99
		),
        11 => array (
			'label' => 'Code pays'
			, 'data' => ''
			, 'maxLength' => 3
		),
        12 => array (
			'label' => 'Référence interne client chez fournisseur'
			, 'data' => ''
			, 'maxLength' => 35
		),
        13 => array (
			'label' => 'Raison sociale 2'
			, 'data' => ''
			, 'maxLength' => 35
		)
	);
}

class EDIFormatOrdersSegmentCTA extends EDIFormatSegment
{
    public static $TFields = array (
        1 => array (
            'label' => 'Étiquette de segment "CTA"'
            , 'data' => '"CTA"'
            , 'maxLength' => 3
            , 'required' => true
        ),
        2 => array (
            'label' => 'Type contact'
            , 'data' => ''
            , 'maxLength' => 2
            , 'required' => true
        ),
        3 => array (
            'label' => 'Nom prénom'
            , 'data' => ''
            , 'maxLength' => 20
        ),
        4 => array (
            'label' => 'Téléphone'
            , 'data' => ''
            , 'maxLength' => 50
        ),
        5 => array (
            'label' => 'Email'
            , 'data' => ''
            , 'maxLength' => 250
        ),
        6 => array (
            'label' => 'Fax'
            , 'data' => ''
            , 'maxLength' => 50
        )
    );
}

class EDIFormatOrdersSegmentTDT extends EDIFormatSegment
{
    public static $TFields = array (
        1 => array (
            'label' => 'Étiquette de segment "TDT"'
            , 'data' => '"TDT"'
            , 'maxLength' => 3
            , 'required' => true
        ),
        2 => array (
            'label' => 'Type transport'
            , 'data' => ''
            , 'maxLength' => 2
            , 'required' => true
        ),
        3 => array (
            'label' => 'Numéro expédition'
            , 'data' => ''
            , 'maxLength' => 35
        ),
        4 => array (
            'label' => 'Code transporteur'
            , 'data' => ''
            , 'maxLength' => 35
        ),
        5 => array (
            'label' => 'Nom transporteur'
            , 'data' => ''
            , 'maxLength' => 35
        ),
        6 => array (
            'label' => 'Code mode transport'
            , 'data' => ''
            , 'maxLength' => 2
        ),
        7 => array (
            'label' => 'Mode de transport en clair'
            , 'data' => ''
            , 'maxLength' => 35
        ),
        8 => array (
            'label' => 'Organisme code transporteur'
            , 'data' => ''
            , 'maxLength' => 35
        )
    );
}

class EDIFormatOrdersSegmentTOD extends EDIFormatSegment
{
    public static $TFields = array (
        1 => array (
            'label' => 'Étiquette de segment "TOD"'
            , 'data' => '"TOD"'
            , 'maxLength' => 3
            , 'required' => true
        ),
        2 => array (
            'label' => 'Type condition'
            , 'data' => ''
            , 'maxLength' => 3
            , 'required' => true
        ),
        3 => array (
            'label' => 'Code condition'
            , 'data' => ''
            , 'maxLength' => 3
        ),
        4 => array (
            'label' => 'Condition en clair'
            , 'data' => ''
            , 'maxLength' => 35
        ),
        5 => array (
            'label' => 'Condition en clair 2'
            , 'data' => ''
            , 'maxLength' => 35
        ),
        6 => array (
            'label' => 'Mode paiement'
            , 'data' => ''
            , 'maxLength' => 3
        )
    );
}

class EDIFormatOrdersSegmentLOC extends EDIFormatSegment
{
    public static $TFields = array (
        1 => array (
            'label' => 'Étiquette de segment "LOC"'
            , 'data' => '"LOC"'
            , 'maxLength' => 3
            , 'required' => true
        ),
        2 => array (
            'label' => 'Type lieux'
            , 'data' => ''
            , 'maxLength' => 1
            , 'required' => true
        ),
        3 => array (
            'label' => 'Code lieu'
            , 'data' => ''
            , 'maxLength' => 70
        ),
        4 => array (
            'label' => 'Lieu en clair'
            , 'data' => ''
            , 'maxLength' => 70
        )
    );
}

class EDIFormatOrdersSegmentEXP extends EDIFormatSegment
{
    public static $TFields = array (
        1 => array (
            'label' => 'Étiquette de segment "EXP"'
            , 'data' => '"EXP"'
            , 'maxLength' => 3
            , 'required' => true
        ),
        2 => array (
            'label' => 'Nombre de colis'
            , 'data' => ''
            , 'maxLength' => 17
            , 'required' => true
        ),
        3 => array (
            'label' => 'Poids brut total'
            , 'data' => ''
            , 'maxLength' => 17
        ),
        4 => array (
            'label' => 'Unité du poids'
            , 'data' => ''
            , 'maxLength' => 3
        ),
        5 => array (
            'label' => 'Volume total'
            , 'data' => ''
            , 'maxLength' => 17
        ),
        6 => array (
            'label' => 'Unité du volume'
            , 'data' => ''
            , 'maxLength' => 3
        )
    );
}

class EDIFormatOrdersSegmentLIG extends EDIFormatSegment
{
    public static $TFields = array (
        1 => array (
            'label' => 'Étiquette de segment "LIG"'
            , 'data' => '"LIG"'
            , 'maxLength' => 3
            , 'required' => true
        ),
        2 => array (
            'label' => 'Numéro de ligne (de 1 à n remis à 0 pour chaque facture)'
            , 'data' => ''
            , 'maxLength' => 3
            , 'required' => true
        ),
        3 => array (
            'label' => 'Code EAN produit'
            , 'data' => '$object->ref'
            , 'maxLength' => 14
            , 'required' => true
        ),
        4 => array (
            'label' => 'Code interne produit chez le fournisseur'
            , 'data' => '$object->ref_fourn'
            , 'maxLength' => 35
        ),
        5 => array (
            'label' => 'Code interne produit chez le client'
            , 'data' => ''
            , 'maxLength' => 35
        ),
        6 => array (
            'label' => 'Par combien (multiple de commande)'
            , 'data' => ''
            , 'maxLength' => 14
        ),
        7 => array (
            'label' => 'Quantité commandée'
            , 'data' => ''
            , 'maxLength' => 14
            , 'required' => true
        ),
        8 => array (
            'label' => 'Unité de quantité'
            , 'data' => ''
            , 'maxLength' => 3
            , 'required' => true
        ),
        9 => array (
            'label' => 'Prix unitaire net'
            , 'data' => 'sprintf("%17.6f", price2num($object->qty > 0 ? $object->total_ht / $object->qty : 0))'
            , 'maxLength' => 24
        ),
        10 => array (
            'label' => 'Libellé produit'
            , 'data' => ''
            , 'maxLength' => 250
        ),
        11 => array (
            'label' => 'Poids'
            , 'data' => ''
            , 'maxLength' => 15
        ),
        12 => array (
            'label' => 'Unité poids'
            , 'data' => ''
            , 'maxLength' => 15
        ),
        13 => array (
            'label' => 'Volume'
            , 'data' => ''
            , 'maxLength' => 15
        ),
        14 => array (
            'label' => 'Unité volume'
            , 'data' => ''
            , 'maxLength' => 3
        ),
        15 => array (
            'label' => 'Numéro contrat'
            , 'data' => ''
            , 'maxLength' => 15
        ),
        16 => array (
            'label' => 'Code couleur'
            , 'data' => ''
            , 'maxLength' => 15
        ),
        17 => array (
            'label' => 'Code taille'
            , 'data' => ''
            , 'maxLength' => 15
        ),
        18 => array (
            'label' => 'Commentaire ligne'
            , 'data' => ''
            , 'maxLength' => 255
        ),
        19 => array (
            'label' => 'Date livraison à la ligne'
            , 'data' => ''
            , 'maxLength' => 10
        ),
        20 => array (
            'label' => 'Prix unitaire brut'
            , 'data' => ''
            , 'maxLength' => 15
        ),
        21 => array (
            'label' => 'Prix de vente'
            , 'data' => ''
            , 'maxLength' => 35
        ),
        22 => array (
            'label' => 'Date DLUO'
            , 'data' => ''
            , 'maxLength' => 10
        ),
        23 => array (
            'label' => 'Numéro de lot'
            , 'data' => ''
            , 'maxLength' => 15
        ),
        24 => array (
            'label' => 'Base du prix'
            , 'data' => ''
            , 'maxLength' => 9
        ),
        25 => array (
            'label' => 'Montant HT ligne'
            , 'data' => ''
            , 'maxLength' => 15
        ),
        26 => array (
            'label' => 'Quantité gratuite comprise'
            , 'data' => ''
            , 'maxLength' => 35
        ),
        27 => array (
            'label' => 'Unité de prix'
            , 'data' => ''
            , 'maxLength' => 3
        ),
        28 => array (
            'label' => 'Fonction ligne'
            , 'data' => ''
            , 'maxLength' => 2
        ),
        29 => array (
            'label' => 'GLN livré à'
            , 'data' => ''
            , 'maxLength' => 14
        )
    );
}

class EDIFormatOrdersSegmentIMD extends EDIFormatSegment
{
    public static $TFields = array (
        1 => array (
            'label' => 'Étiquette de segment "IMD"'
            , 'data' => '"IMD"'
            , 'maxLength' => 3
            , 'required' => true
        ),
        2 => array (
            'label' => 'Type de détail'
            , 'data' => ''
            , 'maxLength' => 1
            , 'required' => true
        ),
        3 => array (
            'label' => 'Détail de la ligne'
            , 'data' => ''
            , 'maxLength' => 255
            , 'required' => true
        ),
        4 => array (
            'label' => 'Code du détail'
            , 'data' => ''
            , 'maxLength' => 3
        )
    );
}

class EDIFormatOrdersSegmentRFF extends EDIFormatSegment
{
    public static $TFields = array (
        1 => array (
            'label' => 'Étiquette de segment "RFF"'
            , 'data' => '"RFF"'
            , 'maxLength' => 3
            , 'required' => true
        ),
        2 => array (
            'label' => 'Type de référence'
            , 'data' => ''
            , 'maxLength' => 2
            , 'required' => true
        ),
        3 => array (
            'label' => 'Référence'
            , 'data' => ''
            , 'maxLength' => 35
            , 'required' => true
        )
    );
}

class EDIFormatOrdersSegmentFTX extends EDIFormatSegment
{
    public static $TFields = array (
        1 => array (
            'label' => 'Étiquette de segment "FTX"'
            , 'data' => '"FTX"'
            , 'maxLength' => 3
            , 'required' => true
        ),
        2 => array (
            'label' => 'Type commentaire'
            , 'data' => ''
            , 'maxLength' => 3
            , 'required' => true
        ),
        3 => array (
            'label' => 'Commentaire'
            , 'data' => ''
            , 'maxLength' => 512
            , 'required' => true
        )
    );
}

class EDIFormatOrdersSegmentPIA extends EDIFormatSegment
{
    public static $TFields = array (
        1 => array (
            'label' => 'Étiquette de segment "PIA"'
            , 'data' => '"PIA"'
            , 'maxLength' => 3
            , 'required' => true
        ),
        2 => array (
            'label' => 'Type'
            , 'data' => ''
            , 'maxLength' => 3
            , 'required' => true
        ),
        3 => array (
            'label' => 'Informations'
            , 'data' => ''
            , 'maxLength' => 35
            , 'required' => true
        )
    );
}

class EDIFormatOrdersSegmentQTY extends EDIFormatSegment
{
    public static $TFields = array (
        1 => array (
            'label' => 'Étiquette de segment "QTY"'
            , 'data' => '"QTY"'
            , 'maxLength' => 3
            , 'required' => true
        ),
        2 => array (
            'label' => 'Type de quantité'
            , 'data' => ''
            , 'maxLength' => 3
            , 'required' => true
        ),
        3 => array (
            'label' => 'Quantité'
            , 'data' => ''
            , 'maxLength' => 35
            , 'required' => true
        ),
        4 => array (
            'label' => 'Unité de quantité'
            , 'data' => ''
            , 'maxLength' => 5
        )
    );
}

class EDIFormatOrdersSegmentPAC extends EDIFormatSegment
{
    public static $TFields = array (
        1 => array (
            'label' => 'Étiquette de segment "PAC"'
            , 'data' => '"PAC"'
            , 'maxLength' => 3
            , 'required' => true
        ),
        2 => array (
            'label' => 'Type'
            , 'data' => ''
            , 'maxLength' => 3
            , 'required' => true
        ),
        3 => array (
            'label' => 'Nombre'
            , 'data' => ''
            , 'maxLength' => 17
            , 'required' => true
        ),
        4 => array (
            'label' => 'Code EAN de l\'UL ou Numéro de marquage Colis'
            , 'data' => ''
            , 'maxLength' => 14
        )
    );
}

class EDIFormatOrdersSegmentPCI extends EDIFormatSegment
{
    public static $TFields = array (
        1 => array (
            'label' => 'Étiquette de segment "PCI"'
            , 'data' => '"PCI"'
            , 'maxLength' => 3
            , 'required' => true
        ),
        2 => array (
            'label' => 'Type'
            , 'data' => ''
            , 'maxLength' => 2
            , 'required' => true
        ),
        3 => array (
            'label' => 'Marquage 1'
            , 'data' => ''
            , 'maxLength' => 35
            , 'required' => true
        ),
        4 => array (
            'label' => 'Marquage 2'
            , 'data' => ''
            , 'maxLength' => 35
        ),
        5 => array (
            'label' => 'Marquage 3'
            , 'data' => ''
            , 'maxLength' => 35
        ),
        6 => array (
            'label' => 'Marquage 4'
            , 'data' => ''
            , 'maxLength' => 35
        ),
        7 => array (
            'label' => 'Marquage 5'
            , 'data' => ''
            , 'maxLength' => 35
        )
    );
}

class EDIFormatOrdersSegmentIDC extends EDIFormatSegment
{
    public static $TFields = array (
        1 => array (
            'label' => 'Étiquette de segment "PCI"'
            , 'data' => '"PCI"'
            , 'maxLength' => 3
            , 'required' => true
        ),
        2 => array (
            'label' => 'Type'
            , 'data' => ''
            , 'maxLength' => 2
            , 'required' => true
        ),
        3 => array (
            'label' => 'Numéro colis'
            , 'data' => ''
            , 'maxLength' => 35
            , 'required' => true
        ),
        4 => array (
            'label' => 'Suivi colis'
            , 'data' => ''
            , 'maxLength' => 35
        ),
        5 => array (
            'label' => 'Code barre colis'
            , 'data' => ''
            , 'maxLength' => 35
        )
    );
}

class EDIFormatOrdersSegmentCOL extends EDIFormatSegment
{
    public static $TFields = array (
        1 => array (
            'label' => 'Étiquette de segment "COL"'
            , 'data' => '"COL"'
            , 'maxLength' => 3
            , 'required' => true
        ),
        2 => array (
            'label' => 'Numéro SSCC'
            , 'data' => ''
            , 'maxLength' => 35
            , 'required' => true
        ),
        3 => array (
            'label' => 'Rang'
            , 'data' => ''
            , 'maxLength' => 35
            , 'required' => true
        )
    );
}

class EDIFormatOrdersSegmentLID extends EDIFormatSegment
{
    public static $TFields = array (
        1 => array (
            'label' => 'Étiquette de segment "LID"'
            , 'data' => '"LID"'
            , 'maxLength' => 3
            , 'required' => true
        ),
        2 => array (
            'label' => 'Numéro de ligne'
            , 'data' => ''
            , 'maxLength' => 3
            , 'required' => true
        ),
        3 => array (
            'label' => 'Type'
            , 'data' => ''
            , 'maxLength' => 15
            , 'required' => true
        ),
        4 => array (
            'label' => 'Numéro de séquence de calcul'
            , 'data' => ''
            , 'maxLength' => 2
        ),
        5 => array (
            'label' => 'Code type de dégression tarifaire'
            , 'data' => ''
            , 'maxLength' => 3
        ),
        6 => array (
            'label' => 'Libellé descriptif de la degression tarifaire'
            , 'data' => ''
            , 'maxLength' => 50
        ),
        7 => array (
            'label' => 'Pourcentage'
            , 'data' => ''
            , 'maxLength' => 5
        ),
        8 => array (
            'label' => 'Code taxe parafiscale'
            , 'data' => ''
            , 'maxLength' => 13
        ),
        9 => array (
            'label' => 'Taux de TVA de la dégression tarifaire ou taxe parafiscale'
            , 'data' => ''
            , 'maxLength' => 5
        ),
        10 => array (
            'label' => 'Montant de la dégression'
            , 'data' => ''
            , 'maxLength' => 10
        )
    );
}

class EDIFormatOrdersSegmentPID extends EDIFormatSegment
{
    public static $TFields = array (
        1 => array (
            'label' => 'Étiquette de segment "PID"'
            , 'data' => '"PID"'
            , 'maxLength' => 3
            , 'required' => true
        ),
        2 => array (
            'label' => 'Numéro de ligne'
            , 'data' => ''
            , 'maxLength' => 3
            , 'required' => true
        ),
        3 => array (
            'label' => 'Type'
            , 'data' => ''
            , 'maxLength' => 15
            , 'required' => true
        ),
        4 => array (
            'label' => 'Numéro de séquence de calcul'
            , 'data' => ''
            , 'maxLength' => 2
        ),
        5 => array (
            'label' => 'Code type de dégression tarifaire'
            , 'data' => ''
            , 'maxLength' => 3
        ),
        6 => array (
            'label' => 'Libellé descriptif de la degression tarifaire'
            , 'data' => ''
            , 'maxLength' => 50
        ),
        7 => array (
            'label' => 'Pourcentage'
            , 'data' => ''
            , 'maxLength' => 5
        ),
        8 => array (
            'label' => 'Code taxe parafiscale'
            , 'data' => ''
            , 'maxLength' => 13
        ),
        9 => array (
            'label' => 'Taux de TVA de la dégression tarifaire ou taxe parafiscale'
            , 'data' => ''
            , 'maxLength' => 5
        ),
        10 => array (
            'label' => 'Montant de la dégression'
            , 'data' => ''
            , 'maxLength' => 10
        )
    );
}

class EDIFormatOrdersSegmentMOA extends EDIFormatSegment
{
    public static $TFields = array (
        1 => array (
            'label' => 'Étiquette de segment "MOA"'
            , 'data' => '"MOA"'
            , 'maxLength' => 3
            , 'required' => true
        ),
        2 => array (
            'label' => 'Type'
            , 'data' => ''
            , 'maxLength' => 3
            , 'required' => true
        ),
        3 => array (
            'label' => 'Montant'
            , 'data' => ''
            , 'maxLength' => 15
            , 'required' => true
        )
    );
}

class EDIFormatOrdersSegmentEND extends EDIFormatSegment
{
    public static $TFields = array (
        1 => array (
            'label' => 'Étiquette de segment "END"'
            , 'data' => '"END"'
            , 'maxLength' => 3
            , 'required' => true
        )
    );
}

class EDIFormatOrdersSegmentATND extends EDIFormatSegment
{
    public static $TFields = array (
        1 => array (
            'label' => 'Étiquette de segment "@ND"'
            , 'data' => '"@ND"'
            , 'maxLength' => 3
            , 'required' => true
        )
    );
}