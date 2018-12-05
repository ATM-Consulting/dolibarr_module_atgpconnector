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
			$segmentClass = static::class . 'Segment' . $segmentID;

			if(! class_exists($segmentClass))
			{
				$this->appendError('ATGPC_CouldNotGenerateCSVFileSegmentDescriptorNotFound', $tmpCSVPath, $segmentID);
				continue;
			}

			$segmentInstance = new $segmentClass;

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
			$data = eval('return ' . $TFieldDescritor['data'] . ';');
			$data = trim($data);
			$data = str_replace(ATGPCONNECTOR_CSV_SEPARATOR, ' ', $data);
			$data = substr($data, 0, $TFieldDescritor['maxLength']);
			$TData[] = $data;
		}

		// TODO Gestion d'erreurs

		return $TData;
	}
}