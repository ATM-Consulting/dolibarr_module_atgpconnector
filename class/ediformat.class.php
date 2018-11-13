<?php

abstract class EDIFormat
{
	public static $remotePath = '/';
	public static $TSegments = array();
	public $object;

	public function __construct(CommonObject &$object)
	{
		$this->object = &$object;
		$this->afterObjectLoaded();
	}


	public abstract function afterObjectLoaded();

	// TODO parse()

	public final function put()
	{
		global $conf;

		$filename = $this->object->ref . '_' . dol_print_date(dol_now(), '%Y%m%d_%H%M%S') . '.csv';
		$tmpCSVPath = DOL_DATA_ROOT . '/atgpconnector/temp/' . $filename;

		$csvHandle = fopen($tmpCSVPath, 'w+');

		if($csvHandle === false)
		{
			return false;
		}

		$object = &$this->object; // NE PAS SUPPRIMER, est utilisé dans les eval()

		foreach(static::$TSegments as $segmentID => $TSegmentDescriptor)
		{
			$segmentObj = eval('return '.$TSegmentDescriptor['object'].';');
			$segmentClass = static::class . 'Segment' . $segmentID;

			if(! class_exists($segmentClass))
			{
				continue; // TODO gestion d'erreur
			}

			$segmentInstance = new $segmentClass;

			if(! empty($TSegmentDescriptor['multiple']))
			{
				foreach($segmentObj as $key => $segmentSubObj)
				{
					$TData = $segmentInstance->get($segmentSubObj, $key);
					fputcsv($csvHandle, $TData, ATGPCONNECTOR_CSV_SEPARATOR);
				}
			}
			else
			{
				$TData = $segmentInstance->get($segmentObj);
				fputcsv($csvHandle, $TData, ATGPCONNECTOR_CSV_SEPARATOR);
			}
		}

		fputcsv($csvHandle, array('END'), ATGPCONNECTOR_CSV_SEPARATOR);

		fclose($csvHandle);

		$ftpPort = ! empty($conf->global->ATGPCONNECTOR_FTP_PORT) ? $conf->global->ATGPCONNECTOR_FTP_PORT : 21;

		$ftpHandle = ftp_connect($conf->global->ATGPCONNECTOR_FTP_HOST, $ftpPort);

		$ftpLogged = ftp_login($ftpHandle, $conf->global->ATGPCONNECTOR_FTP_USER, $conf->global->ATGPCONNECTOR_FTP_PASS);

		$putReturn = ftp_put($ftpHandle, static::$remotePath . basename($tmpCSVPath), $tmpCSVPath, FTP_ASCII);

		// TODO Gestion d'erreur

		ftp_close($ftpHandle);

		return true;
	}
}


abstract class EDIFormatSegment
{
	public static $TFields = array();

	public final function get($object, $key = null)
	{
		global $mysoc, $conf;

		$TData = array();

		foreach(static::$TFields as $index => $TFieldDescritor)
		{
			$TData[] = substr(trim(eval('return ' . $TFieldDescritor['data'] . ';')), 0, $TFieldDescritor['maxLength']);
		}

		return $TData;
	}
}