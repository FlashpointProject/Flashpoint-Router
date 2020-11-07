<?php
// to ensure Redirector compatibility, please set display_errors to Off in php.ini

// constants
// all constants, globals, function names are prefixed with "router" to avoid any potential name collisions in included scripts
const ROUTER_MAD4FP = false;
const ROUTER_HTDOCS = 'htdocs';
const ROUTER_CGI_BIN = 'cgi-bin';
const ROUTER_BUILD_HTTP_QUERY = true;
const ROUTER_ROUTE_PATHNAMES_FROM_HTDOCS_CONTENT = true;
const ROUTER_ALLOW_CROSSDOMAIN = true;
const ROUTER_SSL = true;
const ROUTER_MKDIR_MODE = 0755;
const ROUTER_FILE_HEADER_STATUS_PATTERN = '/^\s*http\s*\/\s*\d+\s*\.\s*\d+\s+(\d+)/i';
const ROUTER_FILE_HEADER_PATTERN = '/^[^:\S]*([^:\s]+)[^:\S]*:\s*(\S+(?:\s+\S+)*)\s*$/i';
const ROUTER_FILE_READ_LENGTH = 8192;
const ROUTER_RETRY_SLEEP = 10000;
const ROUTER_KBSIZE = 1024;
const ROUTER_WARN_PERCENTAGE = 50;
const ROUTER_TAB = "\t";
const ROUTER_NEWLINE = "\n";

$router_base_urls = false;
// the html extension is first for legacy reasons
$router_index_extensions = array('html', 'htm');
$router_script_extensions = array('php', 'php5', 'phtml');
$router_extension_mimetypes = array(
	'htm' => 'text/html',
	'html' => 'text/html',
	'css' => 'text/css',
	'js' => 'text/javascript',
	'xml' => 'application/xml',
	'wasm' => 'application/wasm',
	'swf' => 'application/x-shockwave-flash',
	'dir' => 'application/x-director',
	'dxr' => 'application/x-director',
	'dcr' => 'application/x-director',
	'cst' => 'application/x-director',
	'cxt' => 'application/x-director',
	'cct' => 'application/x-director',
	'swa' => 'application/x-director',
	'w3d' => 'application/x-director',
	'aam' => 'application/x-authorware-map',
	'class' => 'application/java',
	'jar' => 'application/java-archive',
	'cmo' => 'application/x-virtools',
	'vmo' => 'application/x-virtools',
	'nmo' => 'application/x-virtools',
	'nms' => 'application/x-virtools',
	'unity3d' => 'application/vnd.unity',
	'xap' => 'application/x-silverlight-app',
	'wrl' => 'model/vrml',
	'cnc' => 'application/x-cnc',
	'vrt' => 'x-world/x-vrt',
	'svr' => 'x-world/x-svr',
	'xvr' => 'x-world/x-xvr',
	'pwc' => 'application/x-pulse-player',
	'pwn' => 'application/x-pulse-download',
	'pw3' => 'application/x-pulse-player-32',
	'pws' => 'application/x-pulse-stream'
);
// URL Reserved Characters (RFC1738)
$router_url_reserved_characters = array(';', '/', '?', ':', '@', '=', '&');

ignore_user_abort(true);
set_time_limit(0);

// this is the stream where errors will be output
$router_file_pointer_resource_stdout = @fopen('php://stdout', 'w');

function router_output($message) {
	global $router_file_pointer_resource_stdout;
	
	if ($router_file_pointer_resource_stdout !== false) {
		@fwrite($router_file_pointer_resource_stdout, $message . ROUTER_NEWLINE);
	}
}

function router_get_base_urls() {
	// open file in same directory
	// we don't use flags to trim newline characters because PHP 4 doesn't support it
	$base_urls = false;
	$base_urls_file = @file(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'router_base_urls.txt');

	if ($base_urls_file === false) {
		router_output('Failed to Get Base URLs File');
		return $base_urls;
	}
	
	$base_urls = array();
	
	foreach ($base_urls_file as $base_url) {
		// comments may be created by using a pound sign
		// everything after is ignored
		$base_url_exploded = explode('#', $base_url, 2);
		
		if (isset($base_url_exploded[0]) === true) {
			$base_url = $base_url_exploded[0];
		}
		
		// we trim the newlines off the end, then read this as a space delimited associative array
		$base_url_matches = array();
		$base_url_match = preg_match('/^\s*(\S+)\s+(\S.*)$/', rtrim($base_url, "\r\n"), $base_url_matches);
		
		// ignore lines where the Base URL is invalid
		if ($base_url_match === 1) {
			$base_urls[$base_url_matches[1]] = $base_url_matches[2];
		}
	}
	return $base_urls;
}

// get the date specified (or if unspecified, the current date) in HTTP-Date format (RFC1123)
function router_get_http_date($timestamp = null) {
	if (is_null($timestamp) === true) {
		$timestamp = time();
	}
	return gmdate('D, d M Y H:i:s', $timestamp) . ' GMT';
}

// gets a pathname that may be reliably compared to another pathname
function router_get_pathname_comparable($pathname) {
	$pathname_comparable = @realpath($pathname);
	
	if ($pathname_comparable === false) {
		return false;
	}
	return strtolower($pathname_comparable);
}

// compares if one pathname has another pathname
function router_has_pathname_comparable($pathname_top, $pathname) {
	$pathname_top_comparable = router_get_pathname_comparable($pathname_top);
	$pathname_comparable = router_get_pathname_comparable($pathname);
	
	if ($pathname_top_comparable === false || $pathname_comparable === false) {
		return false;
	}
	
	if (strpos($pathname_comparable, $pathname_top_comparable) !== 0 || $pathname_top_comparable === $pathname_comparable) {
		return false;
	}
	return true;
}

// this will check if any extension in basename $pathname_info_basename
// matches any extension in array $extensions, case-insensitively

// more specifically, it will check the basename for any instance of
// a) a period, followed by the extension, and then another period
// b) a period, followed by the extension at the end of the basename
// it will return true if an instance of the pattern is found
// where the basename is $pathname_info_basename and the extension
// is any extension in array $extensions

// this is an intentional design - conventionally, extensions
// may only be at the end of the basename. However, in Apache,
// a file may have multiple extensions, each seperated by
// a period, not including the text before the first period.
// This is taken into consideration here
function router_compare_extensions($pathname_info_basename, $extensions) {
	// notice this adds a period to the end of the basename
	$pathname_info_basename_period = $pathname_info_basename . '.';
	$extensions_count = count($extensions);
	
	for ($i = 0; $i < $extensions_count; $i++) {
		// since the basename has a period on the end, we don't need two conditions
		if (stripos($pathname_info_basename_period, '.' . $extensions[$i] . '.') !== false) {
			return true;
		}
	}
	return false;
}

function router_get_mimetype($pathname_info_basename, $mimetype_default = 'application/octet-stream') {
	global $router_extension_mimetypes;
	
	// first, get all the extensions as an array
	// we slice off the first element as it's not an extension
	// if the file has no extension, this leaves us with an empty array
	$pathname_info_basename_extensions = array_slice(explode('.', $pathname_info_basename), 1);
	
	// we go from the last extension to first, since the last extension takes precedence
	for ($i = count($pathname_info_basename_extensions) - 1; $i >= 0; $i--) {
		foreach ($router_extension_mimetypes as $extension => $mimetype) {
			// both lowercase, for a case-insensitive compare
			if (strtolower($pathname_info_basename_extensions[$i]) === strtolower($extension)) {
				return $mimetype;
			}
		}
	}
	return $mimetype_default;
}

// this function will read a file in chunks
// the individual chunks being read are significantly
// smaller than PHP's memory limit, the caller
// then echoes the read chunk and flushes the memory
// so we can read large files without hitting any memory limit
// if the read fails, the caller doesn't continue attempting
// to read the file, but sometimes the download
// just needs to catch up so we sleep the program
// and try again
function router_read_file($file_pointer_resource) {
	//router_output(ROUTER_TAB . 'Reading File');
	
	if ($file_pointer_resource === false) {
		// error, caller does not continue
		return false;
	}
	
	$read_file = @fread($file_pointer_resource, ROUTER_FILE_READ_LENGTH);
	$read_file_length = strlen($read_file);

	if ($read_file === false || $read_file_length <= 0) {
		if (@feof($file_pointer_resource) === true) {
			// error, caller does not continue
			return false;
		}
		
		usleep(ROUTER_RETRY_SLEEP);
		// no error, caller continues
		return true;
	}
	// no error, caller read the file
	return $read_file;
}

// this will safely close and delete the file if the write fails
// this prevents corrupt files from getting saved
function router_write_file($file_pointer_resource, $pathname, $wrote_file) {
	//router_output(ROUTER_TAB . 'Writing File');
	
	if ($file_pointer_resource === false) {
		// error, caller does not continue
		return false;
	}
	
	if (@fwrite($file_pointer_resource, $wrote_file) === false) {
		if (@fclose($file_pointer_resource) === false) {
			router_output(ROUTER_TAB . 'Failed to Close File');
			// error, caller does not continue
			return false;
		}
		
		if ($pathname !== false) {
			if (@unlink($pathname) === false) {
				router_output(ROUTER_TAB . 'Failed to Unlink File');
				// error, caller does not continue
				return false;
			}
		}
		// no error, caller continues
		return true;
	}
	// no error, caller wrote the file
	return $wrote_file;
}

// send the specified file headers
function router_send_file_headers($file_headers) {
	//router_output(ROUTER_TAB . 'Sending File Headers');
	
	if (headers_sent() === true) {
		return;
	}
	
	// prevent PHP from sending a text/html header by default
	// we do this here specifically because, it's a PHP default behaviour
	// we want to prevent on ANY status code
	header('Content-Type:');
	
	// if we're using PHP 5.3.0 or greater, we can remove the header altogether instead of sending a blank one
	// (we can safely assume we're using at least PHP 4)
	if (version_compare(PHP_VERSION, '5.3.0', '>=') === true) {
		header_remove('Content-Type');
	}
	
	$file_headers_count = count($file_headers);

	// for each file header...
	for ($i = 0; $i < $file_headers_count; $i++) {
		// even though the regex is case-insensitive, we need to string compare it after
		// so we make the header lowercase
		$file_header_lower = strtolower($file_headers[$i]);
		// check if this is a Status (like HTTP/1.1 200 OK for example)
		$file_header_lower_status_match = preg_match(ROUTER_FILE_HEADER_STATUS_PATTERN, $file_header_lower);
		
		// if it's a Status, just send it
		if ($file_header_lower_status_match === 1) {
			//router_output(ROUTER_TAB . 'Status Header Sent: ' . $file_headers[$i]);
			header($file_headers[$i]);
		} else {
			// not a HTTP Status header - so let's check if this message is valid and allowed
			$file_header_lower_matches = array();
			$file_header_lower_match = preg_match(ROUTER_FILE_HEADER_PATTERN, $file_header_lower, $file_header_lower_matches);
			
			if ($file_header_lower_match === 1) {
				// this is a valid HTTP header
				// disallow closed connections because this causes a Redirector bug
				// also disallow the application/octet-stream mimetype
				// and Flash dislikes Content-Disposition
				// we also disallow Date because PHP already is sending it (in the wrong format but w/e)
				if (($file_header_lower_matches[1] !== 'connection' || $file_header_lower_matches[2] !== 'close') &&
				($file_header_lower_matches[1] !== 'content-type' || $file_header_lower_matches[2] !== 'application/octet-stream') &&
				$file_header_lower_matches[1] !== 'content-disposition' &&
				$file_header_lower_matches[1] !== 'date') {
					//router_output(ROUTER_TAB . 'Header Sent: ' . $file_headers[$i]);
					header($file_headers[$i]);
				}
			}
		}
	}
	
	// this was moved here
	// it's specifically here so it'll always be sent with any status code
	if (ROUTER_ALLOW_CROSSDOMAIN === true) {
		header('Access-Control-Allow-Origin: *');
	}
}

// serve a file from cgi-bin as a script
function router_serve_file_from_cgi_bin($pathname_cgi_bin, $pathname_cgi_bin_info, $pathname_search_hash, $pathname_trailing_slash, $index_script_extensions = array(), $index_script_extension_cgi_bin = -1) {
	router_output('Serving File From CGI-BIN: ' . $pathname_cgi_bin);
	
	if ($index_script_extension_cgi_bin >= count($index_script_extensions)) {
		return false;
	}
	
	// get last modified date so we can send that header for Shockwave
	$filemtime = @filemtime($pathname_cgi_bin);

	if ($filemtime === false) {
		router_output(ROUTER_TAB . 'File Locked With Only Readers');
		return false;
	}
	
	$dirname_file = dirname(__FILE__);

	// go to the working directory of THAT script
	if (@chdir($pathname_cgi_bin_info['dirname']) === false) {
		router_output(ROUTER_TAB . 'Failed to Change Directory: ' . $pathname_cgi_bin_info['dirname']);
		return false;
	}
	
	// redirect if we're going to an index.htm/.html file
	if ($index_script_extension_cgi_bin !== -1 && $pathname_trailing_slash === '/') {
		$file_headers = array(
			$_SERVER['SERVER_PROTOCOL'] . ' 301 Moved Permanently',
			'Location: ' . $_SERVER['SCRIPT_NAME'] . '/index.' . $index_script_extensions[$index_script_extension_cgi_bin] . $pathname_search_hash
		);
		router_send_file_headers($file_headers);
		return true;
	}
	
	// send these headers before the other script starts potentially echoing stuff
	$file_headers = array(
		$_SERVER['SERVER_PROTOCOL'] . ' 200 OK',
		'Last-Modified: ' . router_get_http_date($filemtime)
	);
	
	router_send_file_headers($file_headers);

	// include the script
	// careful! include is a language construct not a function
	// if you change these parenthesis, it will blow up on you
	if ((include $pathname_cgi_bin_info['basename']) === false) {
		router_output(ROUTER_TAB . 'Failed to Include: ' . $pathname_cgi_bin_info['basename']);
		return false;
	}

	// return to the working directory of THIS script afterwards
	if (@chdir($dirname_file) === false) {
		router_output(ROUTER_TAB . 'Failed to Change Directory after Including: ' . $dirname_file);
		return false;
	}
	return true;
}

// serve a local file from htdocs
function router_serve_file_from_htdocs($pathname_htdocs, $pathname_search_hash, $pathname_trailing_slash, $pathname_htdocs_info_basename, $index_extension_htdocs = -1) {
	global $router_index_extensions;
	
	router_output('Serving File From HTDOCS: ' . $pathname_htdocs);
	
	// this function always returns filesize, and a filesize of zero is a fail
	// however when this function fails we just continue to try and download
	// the file from the internet
	$filesize = 0;
	
	if ($index_extension_htdocs >= count($router_index_extensions)) {
		return $filesize;
	}
	
	// if we fail to get any of these, bail
	$filesize = @filesize($pathname_htdocs);
	$filemtime = @filemtime($pathname_htdocs);
	$file_pointer_resource_htdocs = @fopen($pathname_htdocs, 'rb');

	if ($filesize === false || $filemtime === false || $file_pointer_resource_htdocs === false) {
		router_output(ROUTER_TAB . 'File Locked With Only Readers');
		$filesize = 0;
		return $filesize;
	}

	// empty files are ignored as if they don't exist (because Archive.org serves empty files instead of 404'ing)
	if ($filesize <= 0) {
		router_output(ROUTER_TAB . 'Empty File');
		// in case filesize is somehow negative, we clamp it to zero bytes size
		$filesize = 0;
		return $filesize;
	}
	
	// if redirecting to an index.htm/.html page, send the headers now that we've confirmed we can load the file successfully
	if ($index_extension_htdocs !== -1 && $pathname_trailing_slash === '/') {
		$file_headers = array(
			$_SERVER['SERVER_PROTOCOL'] . ' 301 Moved Permanently',
			'Location: ' . $_SERVER['SCRIPT_NAME'] . '/index.' . $router_index_extensions[$index_extension_htdocs] . $pathname_search_hash
		);
		router_send_file_headers($file_headers);
		return $filesize;
	}
	
	// determine the mimetype of the local file based on its extension
	$header_mimetype = router_get_mimetype($pathname_htdocs_info_basename);
	
	// we have to make up all the headers to send since we're serving this from a local file
	$file_headers = array(
		$_SERVER['SERVER_PROTOCOL'] . ' 200 OK',
		'Content-Length: ' . $filesize,
		'Content-Type: ' . $header_mimetype,
		'Last-Modified: ' . router_get_http_date($filemtime)
	);
	
	// send those headers
	router_send_file_headers($file_headers);
	
	// and now the file contents
	// we use less memory by reading the file in chunks
	// this allows us to output large files without eating up all that memory
	while (@feof($file_pointer_resource_htdocs) === false) {
		$read_file = router_read_file($file_pointer_resource_htdocs);
		
		if ($read_file === false) {
			break;
		} else {
			if ($read_file === true) {
				continue;
			}
		}
		
		echo($read_file);
		// clean out the buffer memory so less memory is used
		flush();
	}
	
	if (@fclose($file_pointer_resource_htdocs) === false) {
		router_output(ROUTER_TAB . 'Failed to Close File');
		$filesize = 0;
	}
	return $filesize;
}

// get a file pointer resource (which can be read) from a URL
function router_create_file_pointer_resource_from_url($url) {
	global $router_url_reserved_characters;
	
	router_output(ROUTER_TAB . 'Creating File Pointer Resource From URL: ' . $url);
	
	// this makes URLs with special characters in them acceptable to the Infinity servers
	$url = rawurlencode($url);
	
	// but reserved characters, such as : or /, need exceptions so the URL is still valid
	$router_url_reserved_characters_count = count($router_url_reserved_characters);
	
	for ($i = 0; $i < $router_url_reserved_characters_count; $i++) {
		$url = str_ireplace(rawurlencode($router_url_reserved_characters[$i]), $router_url_reserved_characters[$i], $url);
	}
	
	// unused now, this has been resolved server side
	$file_pointer_resource = false;
	
	if (ROUTER_SSL === true || version_compare(PHP_VERSION, '5.0.0', '<') === true) {
		$file_pointer_resource = @fopen($url, 'rb');
	} else {
		$file_pointer_resource = @fopen($url, 'rb', false, stream_context_create(array(
			'ssl' => array(
				'verify_peer' => false,
				'verify_peer_name' => false
			)
		)));
	}

	if ($file_pointer_resource === false) {
		router_output(ROUTER_TAB . 'Failed to Open File');
		return $file_pointer_resource;
	}

	// if we're already at the end of the file - it's empty
	if (@feof($file_pointer_resource) === true) {
		router_output(ROUTER_TAB . 'Empty File');
		$file_pointer_resource = false;
	}
	return $file_pointer_resource;
}

function router_destroy_file_pointer_resource_from_url($file_pointer_resource) {
	//router_output(ROUTER_TAB . 'Destroying File Pointer Resource From URL');

	if (@fclose($file_pointer_resource) === false) {
		router_output(ROUTER_TAB . 'Failed to Close File');
		return false;
	}
	return true;
}

// specifically for downloaded files, send the correct headers
// this function is only called when we've downloaded at least a bit of the file so we know we can successfully download it
function router_send_downloaded_file_headers($file_headers, $file_location, $file_contents_length, $pathname_search_hash, $pathname_trailing_slash, $index_extension = -1) {
	global $router_index_extensions;
	
	//router_output(ROUTER_TAB . 'Sending Downloaded File Headers');
	
	if ($index_extension >= count($router_index_extensions)) {
		// error, caller does not continue
		return false;
	}
	
	// if we are going to an index.htm/.html file...
	if ($file_location === '' && $index_extension !== -1 && $pathname_trailing_slash === '/') {
		// now that we've determined we can successfully download the file
		// and since we're redirecting, send the redirection header
		$file_location = $_SERVER['SCRIPT_NAME'] . '/index.' . $router_index_extensions[$index_extension] . $pathname_search_hash;
	}
	
	// if we're supposed to redirect...
	if ($file_location !== '') {
		// send the location to redirect to
		$file_headers = array(
			$_SERVER['SERVER_PROTOCOL'] . ' 301 Moved Permanently',
			'Location: ' . $file_location
		);
		router_send_file_headers($file_headers);
		// no error, caller returns this length
		return $file_contents_length;
	}
	
	// otherwise just send the normal 200 OK header
	// edit: this is now done after writing the file
	//router_send_file_headers($file_headers);
	// no error, caller continues
	return true;
}

// downloads a file to a specific location, in htdocs
// if it fails to download it as a file, the function
// continues to download the rest of the file and echo it
// (e.g., if the user is out of disk space or the file is locked)
function router_download_file_htdocs($file_pointer_resource, $file_headers, $file_location, $file_content_length, $file_pointer_resource_htdocs_index, $pathname_search_hash, $pathname_trailing_slash, $pathname_htdocs_index, $index_extension = -1) {
	global $router_index_extensions;
	
	//router_output(ROUTER_TAB . 'Downloading File To HTDOCS');
	
	// this function always returns the length, if it's zero, it is a fail
	$file_contents_length = 0;
	
	if ($index_extension >= count($router_index_extensions)) {
		return $file_contents_length;
	}
	
	$file_content_length_kb = intval(ceil($file_content_length / ROUTER_KBSIZE));
	$file_contents = '';
	$file_contents_length = 0;
	$sent_file_headers = false;
	$sent_downloaded_file_headers = false;
	$read_file = false;
	$wrote_file = false;
	$next_warn_percentage = ROUTER_WARN_PERCENTAGE;
	$prev_warn_length = 0;
	$next_warn_length = $file_content_length * ($next_warn_percentage / 100);
	$next_warn_iteration = 0;
	
	/*
	if ($file_content_length === -1) {
		router_output(ROUTER_TAB . 'Filesize: Unknown');
	} else {
		router_output(ROUTER_TAB . 'Filesize: ' . $file_content_length_kb . ' KB');
	}
	*/
	
	// while there's still more to download
	while (@feof($file_pointer_resource) === false) {
		$read_file = router_read_file($file_pointer_resource);
		
		if ($read_file === false) {
			break;
		} else {
			if ($read_file === true) {
				continue;
			}
		}
		
		$file_contents_length += strlen($read_file);

		// if we know the length
		if ($file_content_length !== -1) {
			// stream it!
			if ($file_contents_length !== 0) {
				// send the downloaded file headers, but only if we have not already
				if ($sent_downloaded_file_headers === false) {
					$sent_downloaded_file_headers = router_send_downloaded_file_headers($file_headers, $file_location, $file_contents_length, $pathname_search_hash, $pathname_trailing_slash, $index_extension);
					
					if ($sent_downloaded_file_headers === false) {
						$file_contents_length = 0;
						return $file_contents_length;
					} else {
						if ($sent_downloaded_file_headers === $file_contents_length) {
							return $file_contents_length;
						}
					}
				}
				
				// attempt to write the file BEFORE headers
				// if it fails, at least we didn't send bad headers
				if ($file_pointer_resource_htdocs_index !== false) {
					$wrote_file = router_write_file($file_pointer_resource_htdocs_index, $pathname_htdocs_index, $read_file);
					
					if ($wrote_file === false) {
						//$file_contents_length = 0;
						return $file_contents_length;
					} else {
						if ($wrote_file === true) {
							$file_pointer_resource_htdocs_index = false;
						}
					}
				}
				
				if ($sent_file_headers === false) {
					router_send_file_headers($file_headers);
					$sent_file_headers = true;
				}
				
				echo($read_file);
				flush();
			}

			// progress meter
			while ($file_contents_length >= $next_warn_length && $prev_warn_length < $next_warn_length) {
				router_output(ROUTER_TAB . 'Downloaded and Streamed ' . $next_warn_percentage . '% of ' . $file_content_length_kb . ' KB');
				$next_warn_percentage += ROUTER_WARN_PERCENTAGE;

				if ($next_warn_percentage > 100) {
					$next_warn_percentage = 100;
				}

				$prev_warn_length = $next_warn_length;
				$next_warn_length = $file_content_length * ($next_warn_percentage / 100);
			}
		} else {
			// we don't have enough info to stream the file... we have to download it whole
			$file_contents .= $read_file;
			$next_warn_iteration++;

			// progress meter - except we don't know what percent we're at
			// so just warn every few iterations
			if ($next_warn_iteration > ROUTER_WARN_PERCENTAGE) {
				$file_content_length_kb = intval(ceil($file_contents_length / ROUTER_KBSIZE));
				router_output(ROUTER_TAB . 'Downloaded ' . $file_content_length_kb . ' KB');
				$next_warn_iteration = 0;
			}
		}
	}

	// empty file means fail
	if ($file_contents_length === 0) {
		router_output(ROUTER_TAB . 'Empty File');
		return $file_contents_length;
	}

	// if we didn't know the length - well, the download finished so we do now, so handle for that
	if ($file_content_length === -1) {
		$file_content_length_kb = intval(ceil($file_contents_length / ROUTER_KBSIZE));
		router_output(ROUTER_TAB . 'Downloaded 100% of ' . $file_content_length_kb . ' KB');
		array_push($file_headers, 'Content-Length: ' . $file_contents_length);
		
		if ($sent_downloaded_file_headers === false) {
			$sent_downloaded_file_headers = router_send_downloaded_file_headers($file_headers, $file_location, $file_contents_length, $pathname_search_hash, $pathname_trailing_slash, $index_extension);
			
			if ($sent_downloaded_file_headers === false) {
				$file_contents_length = 0;
				return $file_contents_length;
			} else {
				if ($sent_downloaded_file_headers === $file_contents_length) {
					return $file_contents_length;
				}
			}
		}
		
		// attempt to write the file BEFORE headers
		// if it fails, at least we didn't send bad headers
		if ($file_pointer_resource_htdocs_index !== false) {
			$wrote_file = router_write_file($file_pointer_resource_htdocs_index, $pathname_htdocs_index, $file_contents);
			
			if ($wrote_file === false) {
				//$file_contents_length = 0;
				return $file_contents_length;
			} else {
				if ($wrote_file === true) {
					$file_pointer_resource_htdocs_index = false;
				}
			}
		}
		
		if ($sent_file_headers === false) {
			router_send_file_headers($file_headers);
			$sent_file_headers = true;
		}
		
		echo($file_contents);
		flush();
	}
	return $file_contents_length;
}

// download the file (it's pretty self explanatory dude)
function router_download_file($file_pointer_resource, $file_headers, $file_location, $file_content_length, $file_last_modified, $pathname_search_hash, $pathname_trailing_slash, $pathname_htdocs, $index_extension = 1) {
	global $router_index_extensions;
	
	router_output(ROUTER_TAB . 'Downloading File To: ' . $pathname_htdocs);
	
	// this function always returns the length, if it's zero, it is a fail
	$file_contents_length = 0;
	
	if ($index_extension >= count($router_index_extensions)) {
		return $file_contents_length;
	}
	
	// this is where we handle creating the file to download in htdocs
	$pathname_htdocs_index = false;
	$file_pointer_resource_htdocs_index = false;
	
	if ($index_extension !== -1) {
		// treat as directory name
		if (@is_dir($pathname_htdocs) === true || @mkdir($pathname_htdocs, ROUTER_MKDIR_MODE, true) === true) {
			// do NOT use $pathname_trailing_slash here because $pathname_htdocs may be $pathname_htdocs_index
			$pathname_htdocs_index = $pathname_htdocs . (substr($pathname_htdocs, -1) === '/' ? '' : '/') . 'index.' . $router_index_extensions[$index_extension];
		}
	} else {
		// do NOT use $pathname_trailing_slash here because $pathname_htdocs may be $pathname_htdocs_index
		if (substr($pathname_htdocs, -1) === '/') {
			// treat as directory name because even though there is no file extension
			// and we weren't redirected to an index, it has a trailing slash
			if (count($router_index_extensions) > 0) {
				if (@is_dir($pathname_htdocs) === true || @mkdir($pathname_htdocs, ROUTER_MKDIR_MODE, true) === true) {
					// yes, it does have a trailing slash already
					$pathname_htdocs_index = $pathname_htdocs . 'index.' . $router_index_extensions[0];
				}
			}
		} else {
			// treat as filename
			$pathname_htdocs_info = pathinfo($pathname_htdocs);
			
			if (@is_dir($pathname_htdocs_info['dirname']) === true || @mkdir($pathname_htdocs_info['dirname'], ROUTER_MKDIR_MODE, true) === true) {
				$pathname_htdocs_index = $pathname_htdocs;
			}
		}
	}
	
	// if we managed to make the directory, save the file in htdocs if it isn't locked - but don't stop if we error in doing so
	if ($pathname_htdocs_index !== false) {
		$file_pointer_resource_htdocs_index = @fopen($pathname_htdocs_index, 'wb');
		
		if ($file_pointer_resource_htdocs_index === false) {
			router_output(ROUTER_TAB . 'File Locked With Only Readers');
		}
	} else {
		router_output(ROUTER_TAB . 'Failed to Make Directory');
	}
	
	// now we actually download it to htdocs
	$file_contents_length = router_download_file_htdocs($file_pointer_resource, $file_headers, $file_location, $file_content_length, $file_pointer_resource_htdocs_index, $pathname_search_hash, $pathname_trailing_slash, $pathname_htdocs_index, $index_extension);
	
	if ($file_pointer_resource_htdocs_index !== false) {
		if (@fclose($file_pointer_resource_htdocs_index) === false) {
			router_output(ROUTER_TAB . 'Failed to Close File');
			$file_contents_length = 0;
			return $file_contents_length;
		}
		
		if ($pathname_htdocs_index !== false) {
			if ($file_last_modified !== false) {
				if (touch($pathname_htdocs_index, $file_last_modified) === false) {
					router_output(ROUTER_TAB . 'Failed to Touch File');
					$file_contents_length = 0;
					return $file_contents_length;
				}
			}
			
			// if the file is empty, delete it
			if ($file_contents_length === 0) {
				if (@unlink($pathname_htdocs_index) === false) {
					router_output(ROUTER_TAB . 'Failed to Unlink File');
					return $file_contents_length;
				}
			}
		}
	}
	
	// if the parent directory exists and is empty, remove it
	// this prevents router leaving empty directories in htdocs
	if ($file_contents_length === 0 && $pathname_htdocs_index !== false) {
		// get the comparable pathname of the file's parent directory
		$dirname_htdocs_index_comparable = router_get_pathname_comparable(dirname($pathname_htdocs_index));
		
		// while the pathname of the directory is within htdocs, remove the directory, then get the pathname of the parent directory
		// rmdir only removes empty directories
		while ($dirname_htdocs_index_comparable !== false && router_has_pathname_comparable(ROUTER_HTDOCS, $dirname_htdocs_index_comparable) === true && @is_dir($dirname_htdocs_index_comparable) === true && @rmdir($dirname_htdocs_index_comparable) === true) {
			$dirname_htdocs_index_comparable = router_get_pathname_comparable(dirname($dirname_htdocs_index_comparable));
		}
	}
	return $file_contents_length;
}

// all your base are belong to us
function router_serve_file_from_base_urls($pathname, $pathname_search_hash, $pathname_trailing_slash, $pathname_no_extension, $pathname_htdocs, $index_extension = -1) {
	global $router_index_extensions;
	global $router_base_urls;
	
	router_output('Serving File From Base URLs: ' . $pathname);
	
	// the function stream_get_meta_data that we must use is in 4.3.0 minimum
	// we assume at least PHP 4 is in use elsewhere in the script
	if (version_compare(PHP_VERSION, '4.3.0', '<') === true) {
		router_output('Unsupported PHP Version: ' . PHP_VERSION);
		return false;
	}

	$pathname_index = $pathname;
	
	if ($index_extension >= count($router_index_extensions)) {
		return false;
	}
	
	if ($index_extension !== -1) {
		$pathname_index = $pathname . $pathname_trailing_slash . 'index.' . $router_index_extensions[$index_extension];
	}
	
	$pathname_info_index = pathinfo($pathname_index);
	$pathname_info_basename_index = $pathname_info_index['basename'];
	$pathname_index_search_hash = $pathname_index . $pathname_search_hash;
	
	if ($router_base_urls === false) {
		return false;
	}
	
	// the point of this next section is mainly to obtain four important pieces of information
	// - the file status code - so we know if it exists
	// - the file location - if we get redirected somewhere else
	// - the file's content length - so we know which percentage of it is downloaded when we stream it
	// - the file's last modified date - because Shockwave requires it
	$file_pointer_resource = false;
	$stream_meta_data = array();
	$file_header = '';
	$file_header_status_matches = array();
	$file_header_status_match = false;
	$file_header_matches = array();
	$file_header_match = false;
	$file_headers = array();
	$file_header_pathname_index_pos = false;
	$file_status_code = -1;
	$file_location = '';
	$file_content_length = -1;
	$file_last_modified = false;
	$file_contents_length = 0;

	// we loop through every Base URL
	// if there's an error, we proceed to the next one
	// if we get an empty file, we also try for index.htm/.html
	// (at which point which extension we're currently attempting is defined by $index_extensions)
	foreach ($router_base_urls as $base => $url) {
		router_output(ROUTER_TAB . 'Using Base: ' . $base);
		$file_pointer_resource = router_create_file_pointer_resource_from_url($url . $pathname_index_search_hash);

		if ($file_pointer_resource !== false) {
			// sometimes there is a 301 Redirect, in which case two headers are found this way
			// (one for the redirect and the other for OK)
			$stream_meta_data = stream_get_meta_data($file_pointer_resource);
			$file_headers = array();
			// reset these so they aren't carried over from the previous base loop
			$file_status_code = -1;
			$file_location = '';
			$file_content_length = -1;
			$file_last_modified = false;
			$file_contents_length = 0;

			if (isset($stream_meta_data) === true && isset($stream_meta_data['wrapper_data']) === true) {
				$wrapper_data = $stream_meta_data['wrapper_data'];
				$wrapper_data_count = count($wrapper_data);

				for ($i = 0; $i < $wrapper_data_count; $i++) {
					//router_output('Header: ' . $wrapper_data[$i]);
					$file_header = $wrapper_data[$i];
					// we'll be comparing the headers ourselves after, and they're case-insensitive
					$file_header_status_matches = array();
					// is this a Status message according to our regex?
					$file_header_status_match = preg_match(ROUTER_FILE_HEADER_STATUS_PATTERN, $file_header, $file_header_status_matches);
					
					if ($file_header_status_match === 1) {
						// yes
						$file_status_code = intval($file_header_status_matches[1]);
						
						if ($file_status_code === 200) {
							// ensure the HTTP version of the response matches that of the request (for Shockwave)
							array_push($file_headers, $_SERVER['SERVER_PROTOCOL'] . ' 200 OK');
						}
					} else {
						// this isn't a Status message, but is it a valid HTTP header?
						$file_header_matches = array();
						$file_header_match = preg_match(ROUTER_FILE_HEADER_PATTERN, $file_header, $file_header_matches);
					
						if ($file_header_match === 1) {
							// yes
							// first, if there's no extension, be on the lookout for a redirect indicating this is actually a directory
							if ($pathname_no_extension === true && $file_status_code >= 300 && $file_status_code < 400 && strtolower($file_header_matches[1]) === 'location') {
								// location header - we're redirecting elsewhere
								// but where, relative to our current path?
								$file_header_pathname_index_pos = strrpos($file_header_matches[2], strval($pathname_index));
								
								if ($file_header_pathname_index_pos !== false) {
									// $file_location will contain the redirect to forward
									$file_location = substr($file_header_matches[2], $file_header_pathname_index_pos + strlen($pathname_index));
									
									if ($file_location !== '') {
										if ($index_extension !== -1) {
											// factor in index
											$file_location = $pathname_trailing_slash . 'index.' . $router_index_extensions[$index_extension] . $file_location;
										}
										
										$file_location = $_SERVER['SCRIPT_NAME'] . $file_location;
									}
								}
							}
							
							if ($file_status_code === 200) {
								// only in the scenario where the status is already OK, check for the length of the response and last modified date
								switch (strtolower($file_header_matches[1])) {
									case 'content-length':
									$file_content_length = intval($file_header_matches[2]);
									break;
									case 'last-modified':
									$file_last_modified = @strtotime($file_header_matches[2]);
									
									if (version_compare(PHP_VERSION, '5.1.0', '<') === true) {
										if ($file_last_modified === -1) {
											$file_last_modified = false;
										}
									}
									break;
									case 'content-type':
									// replace the mimetype if we know a different one for this file extension
									$file_header = 'Content-Type: ' . router_get_mimetype($pathname_info_basename_index, $file_header_matches[2]);
								}
								
								// we'll put through the header to the game
								array_push($file_headers, $file_header);
							}
						}
					}
				}
			}
			
			if ($file_status_code === 200) {
				if ($file_last_modified === false) {
					// Shockwave requires last modified date
					array_push($file_headers, 'Last-Modified: ' . router_get_http_date());
				}
				
				// we attempt to download the file
				$file_contents_length = router_download_file($file_pointer_resource, $file_headers, $file_location, $file_content_length, $file_last_modified, $pathname_search_hash, $pathname_trailing_slash, $pathname_htdocs, $index_extension);
				
				// and if we get a file with an actual length back, we can finally end this loop
				if ($file_contents_length !== 0) {
					if (router_destroy_file_pointer_resource_from_url($file_pointer_resource) === false) {
						router_output(ROUTER_TAB . 'Failed to Destroy File Pointer Resource From URL');
						return false;
					}
					break;
				}
			}
			
			if (router_destroy_file_pointer_resource_from_url($file_pointer_resource) === false) {
				router_output(ROUTER_TAB . 'Failed to Destroy File Pointer Resource From URL');
				return false;
			}
		}
	}

	// did we get an actual file with a length?
	if ($file_contents_length === 0) {
		// no? well, if the file has no extension, maybe it's actually a directory containing an index.htm/.html file
		// so try that next
		if ($pathname_no_extension === true) {
			if (router_serve_file_from_base_urls($pathname, $pathname_search_hash, $pathname_trailing_slash, $pathname_no_extension, $pathname_htdocs, $index_extension + 1) === true) {
				// that was it? good
				return true;
			}
		}
		// no! bad!
		return false;
	}
	// yes! we served a real file by now!
	return true;
}

function router_route_pathname_from_htdocs($pathname_htdocs, $pathname_search_hash, $pathname_trailing_slash, $pathname_info_basename) {
	global $router_index_extensions;
	
	//router_output('Routing Pathname from HTDOCS: ' . $pathname_htdocs);
	
	$pathname_htdocs_index = '';
	
	// has the file been downloaded and saved locally before?
	if (@is_file($pathname_htdocs) === true) {
		// serve the local file
		$filesize = router_serve_file_from_htdocs($pathname_htdocs, $pathname_search_hash, $pathname_trailing_slash, $pathname_info_basename);
			
		/*
		if ($filesize === false) {
			router_output(ROUTER_TAB . 'Failed to Serve File From ROUTER_HTDOCS');
		}
		*/
		
		// if file is real and has a size, serve it - but otherwise, we need to download it
		if ($filesize !== 0) {
			return true;
		}
	} else {
		// if the path is to a directory, we need to handle for index HTML files
		if (@is_dir($pathname_htdocs) === true) {
			$router_index_extensions_count = count($router_index_extensions);
			$index_extension_htdocs = -1;

			for ($i = 0; $i < $router_index_extensions_count; $i++) {
				$pathname_htdocs_index = $pathname_htdocs . '/index.' . $router_index_extensions[$i];
				$pathname_htdocs_info_index = pathinfo($pathname_htdocs_index);
				$pathname_htdocs_info_basename_index = $pathname_htdocs_info_index['basename'];
				
				if (@is_file($pathname_htdocs_index) === true) {
					// let's pretend this never happened if we can't actually serve the file
					//$pathname_htdocs = $pathname_htdocs_index;
					$index_extension_htdocs = $i;
					$filesize = router_serve_file_from_htdocs($pathname_htdocs_index, $pathname_search_hash, $pathname_trailing_slash, $pathname_htdocs_info_basename_index, $index_extension_htdocs);
					
					/*
					if ($filesize === false) {
						router_output(ROUTER_TAB . 'Failed to Serve File From ROUTER_HTDOCS');
					}
					*/
					
					if ($filesize !== 0) {
						return true;
					}
				}
			}
		}
	}
	return false;
}

// the main function of the program which decides how to serve the file
function router_route_pathname($pathname) {
	global $router_script_extensions;
	global $router_index_extensions;
	
	//router_output('Routing Pathname: ' . $pathname);
	
	$pathname = '/' . $pathname;
	$pathname_search_hash = '';
	
	if (ROUTER_MAD4FP === true && ROUTER_BUILD_HTTP_QUERY === true) {
		// $_SERVER['QUERY_STRING'] does not work in this environment
		$pathname_search_hash = http_build_query($_GET);
		
		if ($pathname_search_hash !== '') {
			$pathname_search_hash = '?' . $pathname_search_hash;
		}
	}
	
	// in situations where we need a trailing slash, this will be appended to the pathname
	$pathname_trailing_slash = substr($pathname, -1) === '/' ? '' : '/';
	$pathname_info = pathinfo($pathname);
	$pathname_info_basename = $pathname_info['basename'];
	$pathname_no_extension = (isset($pathname_info['extension']) === false || $pathname_trailing_slash === '');
	$http_host_lower = strtolower($_SERVER['HTTP_HOST']);

	if ($http_host_lower === 'localhost' || stripos($http_host_lower, 'localhost:') === 0) {
		if ($_SERVER['SCRIPT_NAME'] === '' || $_SERVER['SCRIPT_NAME'] === '/') {
			phpinfo();
			return true;
		}

		$pathname = $_SERVER['SCRIPT_NAME'];
	}
	
	// if this is not specifically MAD4FP, script files should never be downloaded
	if (ROUTER_MAD4FP !== true) {
		$pathname_cgi_bin = ROUTER_CGI_BIN . $pathname;
		$pathname_cgi_bin_info = pathinfo($pathname_cgi_bin);
		$pathname_cgi_bin_index = '';
		$pathname_cgi_bin_info_index = array();
		
		// if the file being downloaded is a script, include it instead
		if (router_compare_extensions($pathname_info_basename, $router_script_extensions) === true) {
			// never allow scripts to be served anywhere except from cgi-bin
			// if the file doesn't exist - it's an error
			if (@is_file($pathname_cgi_bin) === false) {
				router_output('Not A File From CGI-BIN: ' . $pathname_cgi_bin);
				return false;
			}
			return router_serve_file_from_cgi_bin($pathname_cgi_bin, $pathname_cgi_bin_info, $pathname_search_hash, $pathname_trailing_slash);
		}

		// if the file exists in cgi-bin - even if it's empty - serve it from there
		if (@is_file($pathname_cgi_bin) === true) {
			return router_serve_file_from_cgi_bin($pathname_cgi_bin, $pathname_cgi_bin_info, $pathname_search_hash, $pathname_trailing_slash);
		} else {
			// also check directories for index files
			if (@is_dir($pathname_cgi_bin) === true) {
				$index_script_extensions = array_merge($router_index_extensions, $router_script_extensions);
				$index_script_extensions_count = count($index_script_extensions);
				$index_script_extension_cgi_bin = -1;

				for ($i = 0; $i < $index_script_extensions_count; $i++) {
					$pathname_cgi_bin_index = $pathname_cgi_bin . '/index.' . $index_script_extensions[$i];
					$pathname_cgi_bin_info_index = pathinfo($pathname_cgi_bin_index);
					
					if (@is_file($pathname_cgi_bin_index) === true) {
						$index_script_extension_cgi_bin = $i;
						return router_serve_file_from_cgi_bin($pathname_cgi_bin_index, $pathname_cgi_bin_info_index, $pathname_search_hash, $pathname_trailing_slash, $index_script_extensions, $index_script_extension_cgi_bin);
					}
				}
			}
		}
	}

	$pathname_htdocs_content = ROUTER_HTDOCS . '/content' . $pathname;
	$pathname_htdocs = ROUTER_HTDOCS . $pathname;
	
	if (ROUTER_ROUTE_PATHNAMES_FROM_HTDOCS_CONTENT === true) {
		if (router_route_pathname_from_htdocs($pathname_htdocs_content, $pathname_search_hash, $pathname_trailing_slash, $pathname_info_basename) === true) {
			return true;
		}
	}
	
	if (router_route_pathname_from_htdocs($pathname_htdocs, $pathname_search_hash, $pathname_trailing_slash, $pathname_info_basename) === true) {
		return true;
	}
	
	if (ROUTER_MAD4FP === true && ROUTER_ROUTE_PATHNAMES_FROM_HTDOCS_CONTENT === true) {
		// MAD4FP files should go to curation testing folder
		$pathname_htdocs = $pathname_htdocs_content;
	}

	// TAKE A SPIN NOW YOU'RE IN WITH THE TECHNO SET
	// WE'RE GOING SURFING ON THE INTERNET
	if (router_serve_file_from_base_urls($pathname, $pathname_search_hash, $pathname_trailing_slash, $pathname_no_extension, $pathname_htdocs) === true) {
		return true;
	}
	// we're out of ways to route the file
	return false;
}

// if this is MAD4FP - the Base URL should be the "real internet"
// the Launcher should not change this!!
if (ROUTER_MAD4FP === true) {
	$router_base_urls = array('MAD4FP' => 'http:/');
} else {
	$router_base_urls = router_get_base_urls();
}

// start the program...
if (router_route_pathname(strtok($_SERVER['HTTP_HOST'], ':') . $_SERVER['SCRIPT_NAME']) === false) {
	//router_output(ROUTER_TAB . 'Failed to Route Pathname');
	// let's send this header explicitly
	router_send_file_headers(array($_SERVER['SERVER_PROTOCOL'] . ' 404 Not Found'));
}

if ($router_file_pointer_resource_stdout !== false) {
	@fclose($router_file_pointer_resource_stdout);
}
?>