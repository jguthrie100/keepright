<?php

/*
create the full keepright_errors dump file for download on the web page

the errors dump is a copy of the error_view plus comments given by users

*/

require('helpers.php');
require('../config/config.php');

$results=$config['results_dir'];

// update and download the comments file from the web server
system ('php webUpdateClient.php --remote --export_comments');
file_put_contents($results . 'comments.txt.bz2', file_get_contents('http://keepright.ipax.at/comments.txt.bz2'));
system ('bunzip2 -f ' . $results . 'comments.txt.bz2');



$dst_filename=$results . 'keepright_errors.txt';


if (!$dst=fopen($dst_filename, 'w')) {
	echo "could not open $dst_filename for writing\n";
	exit;
}


$co_filename=$results . 'comments.txt';
if (!$co=fopen($co_filename, 'r')) {
	echo "could not open $co_filename for reading\n";
	exit;
}

$ev_filenames=glob($results . "error_view_*.txt");
sort($ev_filenames);

$co_schema=0;
$co_error_id=0;



// header line
fwrite($dst, "schema\terror_id\terror_type\terror_name\tobject_type\tobject_id" .
	"\tstate\tfirst_occurrence\tlast_checked\tobject_timestamp\tuser_name" .
	"\tlat\tlon\tcomment\tcomment_timestamp\tmsgid\ttxt1\ttxt2\ttxt3\ttxt4\ttxt5\n");



// read error_view*.txt files (one for each schema) and dump them
foreach ($ev_filenames as $ev_filename) {


	// note that build_errorfile takes a reasonable amount of time,
	// the glob() call for finding the file list is executed just once
	// in the beginning; updates to error_view files may happen anytime
	// so files might disappear. In this rare case stop the script,
	// the next run will hopefully be ok
	if (!$ev=fopen($ev_filename, 'r')) {
		echo "could not open $ev_filename for reading. Maybe the file was updated during export process\n";
		exit;
	}

	echo "$ev_filename\n";
	while(!feof($ev)) {
		$ev_line=trim(fgets($ev));
		list($ev_schema, $ev_error_id, $error_type, $error_name, $object_type, $object_id, $ev_state, $fo, $lc, $ot, $user_name, $lat, $lon, $msgid, $txt1, $txt2, $txt3, $txt4, $txt5) = split("\t", $ev_line);


		while (!feof($co) && (
			's'.$co_schema<'s'.$ev_schema ||
			($co_schema==$ev_schema && $co_error_id<$ev_error_id)
		)) {

			$co_line = trim(fgets($co));
			list($co_schema, $co_error_id, $co_state, $co_comment, $co_tstamp) = split("\t",  $co_line);
		}

		if (feof($co)) {
			$co_line="";
			$co_schema=0;
			$co_error_id=0;
		}


		if ($ev_error_id) {

			fwrite($dst, "$ev_schema\t$ev_error_id\t$error_type\t$error_name\t$object_type\t$object_id");

			if ($ev_schema==$co_schema && $ev_error_id==$co_error_id) {
				if (strlen(trim($co_state))>0) fwrite($dst, "\t$co_state"); else fwrite($dst, "\t$ev_state");
				fwrite($dst, "\t$fo\t$lc\t$ot\t$user_name\t$lat\t$lon\t$co_comment\t$co_tstamp\t$msgid\t$txt1\t$txt2\t$txt3\t$txt4\t$txt5" . "\n");
			} else {
				fwrite($dst, "\t$ev_state\t$fo\t$lc\t$ot\t$user_name\t$lat\t$lon\t\N\t\N\t$msgid\t$txt1\t$txt2\t$txt3\t$txt4\t$txt5" . "\n");
			}
		}
	}
	fclose($ev);
}
fclose($co);
fclose($dst);


$remote_file="${dst_filename}.bz2";

system ("bzip2 -k -f $dst_filename");
upload($remote_file, 'keepright_errors.txt.bz2');



//-------------
// pack and upload nodecounts

$dst_filename=$results . 'nodecount.txt';
$remote_file='nodecount.txt.bz2';


if (!$dst=fopen($dst_filename, 'w')) {
        echo "could not open $dst_filename for writing\n";
        exit;
}


fwrite($dst, "schema\tlat\tlon\tcount\n");

foreach (array_keys($schemas) as $k=>$schema) {
	if ($schema!='at' && $schema!='md')
	fwrite($dst, file_get_contents($results . "nodes_$schema.txt"));
}

fclose($dst);

system ("bzip2 -k -f $dst_filename");
upload($dst_filename . '.bz2', $remote_file);




// upload a file to ftp site
// destination dir is hardcoded with $config['upload']['ftp_path']
function upload($local_file, $remote_file) {
	global $config;

	// set up basic connection
	$conn_id = ftp_connect($config['upload']['ftp_host']);

	if (!$conn_id) {
		echo "couldn't conect to ftp server " . $config['upload']['ftp_host'] . "\n";
		return;
	}

	// login with username and password
	if (!ftp_login($conn_id, $config['upload']['ftp_user'], $config['upload']['ftp_password'])) {
		echo "Couldn't login to " . $config['upload']['ftp_host'] . " as " . $config['upload']['ftp_user'] ."\n";

		ftp_close($conn_id);
		return;
	}

	// change to destination directory
	if (!ftp_chdir($conn_id, '/' . $config['upload']['ftp_path'])) {
		echo "Couldn't change directory on ftp server\n";

		ftp_close($conn_id);
		return;
	}

	// upload the file
	if (ftp_put($conn_id, $remote_file . '.part', $local_file, FTP_BINARY)) {
		echo "successfully uploaded $remote_file\n";
	} else {
		echo "There was a problem while uploading $remote_file\n";
	}

	// replace old file with new file
	ftp_delete($conn_id, $remote_file);
	ftp_rename($conn_id, $remote_file . '.part', $remote_file);

	// close the connection
	ftp_close($conn_id);
}

/*

schema  error_id        error_type      error_name      object_type     object_id
state   first_occurrence        last_checked    object_timestamp    user_name
lat     lon     comment comment_timestamp	msgid	txt1	txt2	txt3
txt4	txt5


DROP TABLE IF EXISTS `keepright_errors`;

CREATE TABLE IF NOT EXISTS `keepright_errors` (
  `schema` varchar(6) NOT NULL default '',
  `error_id` int(11) NOT NULL,
  `error_type` int(11) NOT NULL,
  `error_name` varchar(100) NOT NULL,
  `object_type` enum('node','way','relation') NOT NULL,
  `object_id` bigint(64) NOT NULL,
  `state` enum('new','reopened','ignore_temporarily','ignore') NOT NULL,
  `first_occurrence` datetime NOT NULL,
  `last_checked` datetime NOT NULL,
  `object_timestamp` datetime NOT NULL,
  `user_name` text NOT NULL,
  `lat` int(11) NOT NULL,
  `lon` int(11) NOT NULL,
  `comment` text,
  `comment_timestamp` datetime
  `msgid` text,
  `txt1` text,
  `txt2` text,
  `txt3` text,
  `txt4` text,
  `txt5` text
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

LOAD DATA LOCAL INFILE 'keepright_errors.txt' INTO TABLE keepright_errors IGNORE 1 LINES;

*/

?>
