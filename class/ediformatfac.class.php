<?php

dol_include_once('/atgpconnector/class/ediformat.class.php');

class EDIFormatFAC extends EDIFormat
{
	public static $remotePath = '/factures/';
	public static $TSegments = array(
		'ENT' => array(
			'required' => true
			, 'object' => '$object'
		)
	);
}


class EDIFormatFACSegmentENT extends EDIFormatSegment
{
	public static $TFields = array(
		1 => array(
			'label' => 'Test'
			, 'data' => '$object->ref'
			, 'maxLength' => 35
		)
	);
}