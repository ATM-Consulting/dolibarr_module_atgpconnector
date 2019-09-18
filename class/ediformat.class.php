<?php

abstract class EDIFormat
{
	public static $remotePath = '/';
	public static $TSegments = array();
	public $object;
	public $TErrors = array();

	public function __construct(CommonObject &$object)
	{
		$this->object = &$object;
		$this->afterObjectLoaded();
	}


	public abstract function afterObjectLoaded();


	public abstract function afterCSVGenerated($tmpPath);


	// TODO parse()


	public final function put()
	{
		global $conf, $mysoc;

		$filename = $this->object->ref . '_' . dol_print_date(dol_now(), '%Y%m%d_%H%M%S') . '.csv';
		$tmpCSVPath = DOL_DATA_ROOT . '/atgpconnector/temp/' . $filename;

		$csvHandle = fopen($tmpCSVPath, 'w+');

		if($csvHandle === false)
		{
			$this->appendError('ATGPC_CouldNotOpenTempCSVFile', $tmpCSVPath);
			return false;
		}

		$object = &$this->object; // NE PAS SUPPRIMER, est utilisé dans les eval()

		foreach(static::$TSegments as $segmentID => $TSegmentDescriptor)
		{
			$segmentObj = eval('return '.$TSegmentDescriptor['object'].';');
			$segmentClass = get_class($this) . 'Segment' . $segmentID;

			if(! class_exists($segmentClass))
			{
				$this->appendError('ATGPC_CouldNotGenerateCSVFileSegmentDescriptorNotFound', $tmpCSVPath, $segmentID);
				continue;
			}

			$segmentInstance = new $segmentClass;

			if(! empty($TSegmentDescriptor['multiple']))
			{
				if(is_array($segmentObj))
				{
					foreach ($segmentObj as $key => $segmentSubObj) {
						$TData = $segmentInstance->get($segmentSubObj, $key);
						fwrite($csvHandle, implode(ATGPCONNECTOR_CSV_SEPARATOR, $TData) . PHP_EOL);

						if (isset($TSegmentDescriptor['LID'])) {
							$val = eval('return ' . $TSegmentDescriptor['LID']['object'] . ';');
							$this->putLID($csvHandle, $TSegmentDescriptor['LID'], $val);
						}

						if (isset($TSegmentDescriptor['FTX'])) {
							$val = eval('return ' . $TSegmentDescriptor['FTX']['object'] . ';');
							$this->putFTX($csvHandle, $TSegmentDescriptor['FTX'], $val);
						}
					}
				}
			}
			else
			{
				$TData = $segmentInstance->get($segmentObj);
				fwrite($csvHandle, implode(ATGPCONNECTOR_CSV_SEPARATOR, $TData) . PHP_EOL);

				if (isset($TSegmentDescriptor['LID']))
				{
					$val = eval('return '.$TSegmentDescriptor['LID']['object'].';');
					$this->putLID($csvHandle, $TSegmentDescriptor['LID'], $val);
				}

                if (isset($TSegmentDescriptor['FTX']))
                {
                    $val = eval('return '.$TSegmentDescriptor['FTX']['object'].';');
                    $this->putFTX($csvHandle, $TSegmentDescriptor['FTX'], $val);
                }
			}
		}

		fwrite($csvHandle, 'END' . PHP_EOL);
		fwrite($csvHandle, '@ND' . PHP_EOL);

		fclose($csvHandle);

		$this->afterCSVGenerated($tmpCSVPath);

		// TODO A bouger dans une méthode send()
		if(empty($conf->global->ATGPCONNECTOR_FTP_DISABLE_ALL_TRANSFERS)) // conf cachée
		{
			$ftpPort = ! empty($conf->global->ATGPCONNECTOR_FTP_PORT) ? $conf->global->ATGPCONNECTOR_FTP_PORT : 21;

			$ftpHandle = ftp_connect($conf->global->ATGPCONNECTOR_FTP_HOST, $ftpPort);
			if($ftpHandle === false)
			{
				$this->appendError('ATGPC_CouldNotOpenFTPConnection');
				return false;
			}

			$ftpLogged = ftp_login($ftpHandle, $conf->global->ATGPCONNECTOR_FTP_USER, $conf->global->ATGPCONNECTOR_FTP_PASS);

			if(! $ftpLogged)
			{
				$this->appendError('ATGPC_FTPAuthentificationFailed');
				return false;
			}

			if (!empty($conf->global->ATGPCONNECTOR_FTP_PASSIVE_MODE))
			{
				ftp_pasv($ftpHandle, true);
			}

			$remoteFilePath =  static::$remotePath . basename($tmpCSVPath);

			$putWorked = ftp_put($ftpHandle, $remoteFilePath, $tmpCSVPath, FTP_ASCII);

			if(! $putWorked)
			{
				$this->appendError('ATGPC_FTPFailedToUploadFile', $tmpCSVPath, $remoteFilePath);
				return false;
			}

			ftp_close($ftpHandle);
		}

		return true;
	}

	public final function putLID($csvHandle, $TSegmentDescriptor, $segmentObj)
	{
		$lidClassName = get_class($this) . 'SegmentLID';
		$segmentInstance = new $lidClassName();

		if(! empty($TSegmentDescriptor['multiple'])) {
			if (is_array($segmentObj)) {
				foreach ($segmentObj as $key => $segmentSubObj) {
					$TData = $segmentInstance->get($segmentSubObj, $key);
					fwrite($csvHandle, implode(ATGPCONNECTOR_CSV_SEPARATOR, $TData) . PHP_EOL);
				}
			}
		}
		else
		{
			$TData = $segmentInstance->get($segmentObj);
			fwrite($csvHandle, implode(ATGPCONNECTOR_CSV_SEPARATOR, $TData) . PHP_EOL);
		}
	}

	public final function putFTX($csvHandle, $TSegmentDescriptor, $segmentObj)
    {
	    $ftxClassName = get_class($this) . 'SegmentFTX';
        $segmentInstance = new $ftxClassName();

        if(! empty($TSegmentDescriptor['multiple']))
        {
            foreach($segmentObj as $key => $segmentSubObj)
            {
                $TData = $segmentInstance->get($segmentSubObj, $key);
                fwrite($csvHandle, implode(ATGPCONNECTOR_CSV_SEPARATOR, $TData) . PHP_EOL);
            }
        }
        else
        {
            $TData = $segmentInstance->get($segmentObj);
            fwrite($csvHandle, implode(ATGPCONNECTOR_CSV_SEPARATOR, $TData) . PHP_EOL);
        }
    }

	public final function read($csvHandle)
	{
		global $conf;

		$line = fgetcsv($csvHandle, 0, ATGPCONNECTOR_CSV_SEPARATOR);

		// fgetcsv peut retourner NULL, FALSE, ou un array() vide
		if(! is_array($line) && empty($line))
		{
			return false;
		}

		return array_map(array($this, 'sanitizeCell'), $line);
	}


	public final function sanitizeCell($cell)
	{
		global $conf;

		$encodingIn = mb_detect_encoding($cell);

		if($encodingIn === false)
		{
			$encodingIn = 'ASCII';
		}

		return trim(iconv($encodingIn, $conf->file->character_set_client, $cell));
	}


	/**
	 * A utiliser comme Translate::trans()
	 */
	protected function appendError()
	{
		global $langs;

		$this->TErrors[] = call_user_func_array(array($langs, 'trans'), func_get_args());
	}
}


abstract class EDIFormatSegment
{
	public static $TFields = array();
	public $TErrors = array();

	public final function get($object, $key = null)
	{
		global $mysoc, $conf;

		$TData = array();

		foreach(static::$TFields as $index => $TFieldDescritor)
		{
			$data = eval('return ' . $TFieldDescritor['data'] . ';'); // Peut utiliser $object, $key, $conf et $mysoc
			$data = trim($data);
			$data = str_replace(ATGPCONNECTOR_CSV_SEPARATOR, ' ', $data);
			$data = substr($data, 0, $TFieldDescritor['maxLength']);
			$data = mb_convert_encoding($data, 'ISO-8859-1', mb_detect_encoding($data));
			$TData[] = $data;
		}

		// TODO Gestion d'erreurs

		return $TData;
	}
}

