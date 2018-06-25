<?php


return [

	'path' => storage_path() . '/dumps/',

	'mysql' => [
		'dump_command_path' => '',
		'restore_command_path' => '',
	],

	's3' => [
		'key' => '',
		'secret' => '',
		'region' => '',
		'bucket' => '',
	],

	'mail' => [
		'to' => ''
	],

    'compress' => false,
];

