<?php

abstract class EDIFormat
{
	public static $remotePath = '/';
	public static $TSegments = array();

	public final function put(CommonObject $object)
	{
		global $conf;

		$tmpCSVPath = DOL_DATA_ROOT . '/atgpconnector/temp/' . dol_print_date(dol_now(), '%Y%m%d%H%M%S') . '.csv';

		$csvHandle = fopen($tmpCSVPath, 'w+');

		if($csvHandle === false)
		{
			return false;
		}

		fputcsv($csvHandle, array('@GP', 'WEB@EDI', 'INVOIC', 'STANDARD'), ATGPCONNECTOR_CSV_SEPARATOR); // TODO Extract it

		foreach(static::$TSegments as $segmentID => $TSegmentDescriptor)
		{
			$segmentObj = eval('return '.$TSegmentDescriptor['object'].';');
			$segmentClass = static::class . 'Segment' . $segmentID;

			$segmentInstance = new $segmentClass;

			$TData = $segmentInstance->get($segmentObj);

			fputcsv($csvHandle, $TData, ATGPCONNECTOR_CSV_SEPARATOR);
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

	public final function get(CommonObject $object)
	{
		$TData = array();

		foreach(static::$TFields as $index => $TFieldDescritor)
		{
			$TData[] = substr(eval('return ' . $TFieldDescritor['data'] . ';'), 0, $TFieldDescritor['maxLength']);
		}

		return $TData;
	}
}