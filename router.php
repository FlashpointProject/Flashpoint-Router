<?php
// php -S 127.0.0.1:22500 router.php
// to ensure Redirector compatibility, please set display_errors to Off in php.ini

// constants
// all constants, globals, function names are prefixed with "router" to avoid any potential name collisions in included scripts
const ROUTER_HTDOCS = 'htdocs';
const ROUTER_CGI_BIN = 'cgi-bin';
const ROUTER_MKDIR_MODE = 0755;
const ROUTER_FILE_HEADER_STATUS_PATTERN = '/http\s*\/\s*[0-9]+\s*.\s*[0-9]+\s+([0-9]+)/i';
const ROUTER_FILE_HEADER_PATTERN = '/(\S+)\s*:\s*(\S+)/i';
const ROUTER_FILE_READ_LENGTH = 32768;
const ROUTER_RETRY_SLEEP = 10000;
const ROUTER_KBSIZE = 1024;
const ROUTER_WARN_PERCENTAGE = 50;
const ROUTER_TAB = "\t";
const ROUTER_NEWLINE = "\n";

// TODO: make user able to set this in launcher options!
$router_base_urls = array(
	'Dri0m' => 'https://unstable.life/Flashpoint/Server/htdocs',
	'Archive.org' => 'http://archive.org/download/FP61Data/FP61Data.zip/htdocs',
	'BlueMaxima' => 'http://bluemaxima.org/htdocs'
);
$router_index_extensions = array('htm', 'html');
$router_script_extensions = array('php', 'php5', 'phtml');
$router_extension_mimetypes = array(
	'htm' => 'text/html',
	'html' => 'text/html',
	'css' => 'text/css',
	'js' => 'text/javascript',
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
	'xap' => 'application/x-silverlight-app'
);

set_time_limit(0);

// this is the stream where errors will be output
$router_stderr = fopen('php://stderr', 'w');

function router_warn($message) {
	global $router_stderr;
	fwrite($router_stderr, $message . ROUTER_NEWLINE);
}

// get the date specified (or if unspecified, the current date) in HTTP-Date format (RFC1123)
function router_get_http_date($timestamp = null) {
	if (is_null($timestamp) === true) {
		$timestamp = time();
	}
	
	return gmdate('D, d M Y H:i:s', $timestamp) . ' GMT';
}

// send the specified file headers
function router_send_file_headers($file_headers) {
	//router_warn(ROUTER_TAB . 'Sending File Headers');
	
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
			//router_warn(ROUTER_TAB . 'Status Header Sent: ' . $file_headers[$i]);
			header($file_headers[$i]);
		} else {
			// not a HTTP Status header - so let's check if this message is valid and allowed
			$file_header_lower_matches = array();
			$file_header_lower_match = preg_match(ROUTER_FILE_HEADER_PATTERN, $file_header_lower, $file_header_lower_matches);
			
			if ($file_header_lower_match === 1) {
				// this is a valid HTTP header
				// disallow the application/x-octet-stream mimetype
				// also disallow closed connections because this causes a Redirector bug
				// and Flash dislikes Content-Disposition
				// we also disallow Date because PHP already is sending it (in the wrong format but w/e)
				if (($file_header_lower_matches[1] !== 'connection' || $file_header_lower_matches[2] !== 'close') &&
				($file_header_lower_matches[1] !== 'content-type' || $file_header_lower_matches[2] !== 'application/x-octet-stream') &&
				$file_header_lower_matches[1] !== 'content-disposition' &&
				$file_header_lower_matches[1] !== 'date') {
					//router_warn(ROUTER_TAB . 'Header Sent: ' . $file_headers[$i]);
					header($file_headers[$i]);
				}
			}
		}
	}
}

// serve a file from cgi-bin as a script
function router_serve_file_from_cgi_bin($pathname_cgi_bin, $pathname_cgi_bin_info, $pathname_trailing_slash, $index_extension_cgi_bin = -1) {
	global $router_index_extensions;
	
	router_warn('Serving File From CGI-BIN: ' . $pathname_cgi_bin);
	
	if ($index_extension_cgi_bin >= count($router_index_extensions)) {
		return false;
	}
	
	// get last modified date so we can send that header for Shockwave
	$filemtime = @filemtime($pathname_cgi_bin);

	if ($filemtime === false) {
		router_warn(ROUTER_TAB . 'File Locked With Only Readers');
		return false;
	}
	
	$dirname_file = dirname(__FILE__);

	// go to the working directory of THAT script
	if (chdir($pathname_cgi_bin_info['dirname']) === false) {
		router_warn(ROUTER_TAB . 'Failed to Change Directory: ' . $pathname_cgi_bin_info['dirname']);
		return false;
	}
	
	// redirect if we're going to an index.htm/.html file
	if ($index_extension_cgi_bin !== -1) {
		$file_headers = array(
			$_SERVER['SERVER_PROTOCOL'] . ' 301 Moved Permanently',
			'Location: ' . $_SERVER['SCRIPT_NAME'] . $pathname_trailing_slash . 'index.' . $router_index_extensions[$index_extension_cgi_bin]
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
		router_warn(ROUTER_TAB . 'Failed to Include: ' . $pathname_cgi_bin_info['basename']);
		return false;
	}

	// return to the working directory of THIS script afterwards
	if (chdir($dirname_file) === false) {
		router_warn(ROUTER_TAB . 'Failed to Change Directory after Including: ' . $dirname_file);
		return false;
	}
	return true;
}

// serve a local file from htdocs
function router_serve_file_from_htdocs($pathname_htdocs, $pathname_trailing_slash, $pathname_info_extension, $index_extension_htdocs = -1) {
	global $router_index_extensions;
	global $router_extension_mimetypes;
	
	router_warn('Serving File From ROUTER_HTDOCS: ' . $pathname_htdocs);
	
	if ($index_extension_htdocs >= count($router_index_extensions)) {
		return false;
	}
	
	// if we fail to get any of these, bail
	$filesize = @filesize($pathname_htdocs);
	$filemtime = @filemtime($pathname_htdocs);
	$file_contents = @file_get_contents($pathname_htdocs);

	if ($filesize === false || $filemtime === false || $file_contents === false) {
		router_warn(ROUTER_TAB . 'File Locked With Only Readers');
		return false;
	}

	// empty files are ignored as if they don't exist (because Archive.org serves empty files instead of 404'ing)
	if ($filesize <= 0) {
		router_warn(ROUTER_TAB . 'Empty File');
		return $filesize;
	}
	
	// if redirecting to an index.htm/.html page, send the headers now that we've confirmed we can load the file successfully
	if ($index_extension_htdocs !== -1) {
		$file_headers = array(
			$_SERVER['SERVER_PROTOCOL'] . ' 301 Moved Permanently',
			'Location: ' . $_SERVER['SCRIPT_NAME'] . $pathname_trailing_slash . 'index.' . $router_index_extensions[$index_extension_htdocs]
		);
		router_send_file_headers($file_headers);
		return $filesize;
	}
	
	// we have to make up all the headers to send since we're serving this from a local file
	$file_headers = array(
		$_SERVER['SERVER_PROTOCOL'] . ' 200 OK',
		'Content-Length: ' . $filesize
	);
	// determine the mimetype of the local file based on its extension
	$header_mimetype = 'application/x-octet-stream';

	foreach($router_extension_mimetypes as $extension => $mimetype) {
		if ($pathname_info_extension === $extension) {
			$header_mimetype = $mimetype;
			break;
		}
	}
	
	array_push($file_headers, 'Content-Type: ' . $header_mimetype);
	array_push($file_headers, 'Last-Modified: ' . router_get_http_date($filemtime));
	// send those headers
	router_send_file_headers($file_headers);
	// and now the file contents
	echo($file_contents);
	return $filesize;
}

// get a file pointer resource (which can be read) from a URL
function router_get_file_pointer_resource_from_url($url) {
	router_warn(ROUTER_TAB . 'Getting File Pointer Resource From URL: ' . $url);
	
	// please Archive.org's API but also keep other servers happy
	$url = str_ireplace('%3A', ':', str_ireplace('%2F', '/', rawurlencode($url)));

	try {
		// I would say data is a terrible variable name
		// but at least davidar's router worked the first time
		// so I can't rightly throw any shade around now can I
		$file_pointer_resource = @fopen($url, 'rb');

		if ($file_pointer_resource === false) {
			router_warn(ROUTER_TAB . 'Failed to Open File');
			return false;
		}

		// if we're already at the end of the file - it's empty
		if (feof($file_pointer_resource) === true) {
			router_warn(ROUTER_TAB . 'Empty File');
			return false;
		}
		return $file_pointer_resource;
	} catch (Exception $e) {
		//router_warn(ROUTER_TAB . 'Failed to Get File Pointer Resource From URL: ' . $e);
		// Fail silently.
	}
	return false;
}

// specifically for downloaded files, send the correct headers
// this function is only called when we've downloaded at least a bit of the file so we know we can successfully download it
function router_send_downloaded_file_headers($file_headers, $file_location, $file_contents_length, $pathname_trailing_slash, $index_extension = -1) {
	global $router_index_extensions;
	
	//router_warn(ROUTER_TAB . 'Sending Downloaded File Headers');
	
	if ($index_extension >= count($router_index_extensions)) {
		return $file_contents_length;
	}
	
	// if we are going to an index.htm/.html file...
	if (empty($file_location) === true && $index_extension !== -1) {
		// now that we've determined we can successfully download the file
		// and since we're redirecting, send the redirection header
		$file_location = $_SERVER['SCRIPT_NAME'] . $pathname_trailing_slash . 'index.' . $router_index_extensions[$index_extension];
	}
	
	// if we're supposed to redirect...
	if (empty($file_location) === false) {
		// send the location to redirect to
		$file_headers = array(
			$_SERVER['SERVER_PROTOCOL'] . ' 301 Moved Permanently',
			'Location: ' . $file_location
		);
		router_send_file_headers($file_headers);
		return $file_contents_length;
	}
	
	// otherwise just send the normal 200 OK header
	router_send_file_headers($file_headers);
	return true;
}

// download the file (it's pretty self explanatory dude)
function router_download_file($file_pointer_resource, $file_headers, $file_location, $file_content_length, $pathname_trailing_slash, $pathname_htdocs, $index_extension = 1) {
	global $router_index_extensions;
	
	router_warn(ROUTER_TAB . 'Downloading File To: ' . $pathname_htdocs);
	
	$file_content_length_kb = intval(ceil($file_content_length / ROUTER_KBSIZE));
	$file_contents = '';
	$file_contents_length = 0;
	$sent_downloaded_file_headers = false;
	$read_file = '';
	$read_file_length = 0;
	
	if ($index_extension >= count($router_index_extensions)) {
		return $file_contents_length;
	}

	$next_warn_percentage = ROUTER_WARN_PERCENTAGE;
	$prev_warn_length = 0;
	$next_warn_length = $file_content_length * ($next_warn_percentage / 100);
	$next_warn_iteration = 0;

	if ($file_content_length === -1) {
		//router_warn(ROUTER_TAB . 'Filesize: Unknown');
	} else {
		//router_warn(ROUTER_TAB . 'Filesize: ' . $file_content_length_kb . ' KB');
	}

	// while there's still more to download
	while (feof($file_pointer_resource) === false) {
		$read_file = @fread($file_pointer_resource, ROUTER_FILE_READ_LENGTH);

		if ($read_file === false) {
			usleep(ROUTER_RETRY_SLEEP);
			continue;
		}

		$read_file_length = strlen($read_file);

		if ($read_file_length <= 0) {
			usleep(ROUTER_RETRY_SLEEP);
			continue;
		}

		$file_contents .= $read_file;
		$file_contents_length += $read_file_length;

		// if we know the length
		if ($file_content_length !== -1) {
			// stream it!
			if ($file_contents_length !== 0) {
				// send the headers, but only if we have not already
				if ($sent_downloaded_file_headers === false) {
					$sent_downloaded_file_headers = router_send_downloaded_file_headers($file_headers, $file_location, $file_contents_length, $pathname_trailing_slash, $index_extension);
					
					if ($sent_downloaded_file_headers !== true) {
						return $sent_downloaded_file_headers;
					}
				}
				
				echo($read_file);
			}

			// progress meter
			while ($file_contents_length >= $next_warn_length && $prev_warn_length < $next_warn_length) {
				router_warn(ROUTER_TAB . 'Downloaded and Streamed ' . $next_warn_percentage . '% of ' . $file_content_length_kb . ' KB');
				$next_warn_percentage += ROUTER_WARN_PERCENTAGE;

				if ($next_warn_percentage > 100) {
					$next_warn_percentage = 100;
				}

				$prev_warn_length = $next_warn_length;
				$next_warn_length = $file_content_length * ($next_warn_percentage / 100);
			}
		} else {
			// we don't have enough info to stream the file... we have to download it whole
			$next_warn_iteration++;

			// progress meter - except we don't know what percent we're at
			// so just warn every few iterations
			if ($next_warn_iteration > ROUTER_WARN_PERCENTAGE) {
				$file_content_length_kb = intval(ceil($file_contents_length / ROUTER_KBSIZE));
				router_warn(ROUTER_TAB . 'Downloaded ' . $file_content_length_kb . ' KB');
				$next_warn_iteration = 0;
			}
		}
	}

	// empty file means fail
	if ($file_contents_length === 0) {
		router_warn(ROUTER_TAB . 'Empty File');
		return $file_contents_length;
	}
	
	$pathname_htdocs_index = false;
	
	if ($index_extension !== -1) {
		// treat as directory name
		if (is_dir($pathname_htdocs) !== false || @mkdir($pathname_htdocs, ROUTER_MKDIR_MODE, true) !== false) {
			// do NOT use $pathname_trailing_slash here because $pathname_htdocs may be $pathname_htdocs_index
			$pathname_htdocs_index = $pathname_htdocs . (substr($pathname_htdocs, -1) === '/' ? '' : '/') . 'index.' . $router_index_extensions[$index_extension];
		}
	} else {
		// do NOT use $pathname_trailing_slash here because $pathname_htdocs may be $pathname_htdocs_index
		if (substr($pathname_htdocs, -1) === '/') {
			// treat as directory name because even though there is no file extension
			// and we weren't redirected to an index, it has a trailing slash
			if (count($router_index_extensions) !== 0) {
				if (is_dir($pathname_htdocs) !== false || @mkdir($pathname_htdocs, ROUTER_MKDIR_MODE, true) !== false) {
					// yes, it does have a trailing slash already
					$pathname_htdocs_index = $pathname_htdocs . 'index.' . $router_index_extensions[0];
				}
			}
		} else {
			// treat as filename
			$pathname_htdocs_info = pathinfo($pathname_htdocs);
			
			if (is_dir($pathname_htdocs_info['dirname']) !== false || @mkdir($pathname_htdocs_info['dirname'], ROUTER_MKDIR_MODE, true) !== false) {
				$pathname_htdocs_index = $pathname_htdocs;
			}
		}
	}
	
	// if we managed to make the directory, save the file in htdocs if it isn't locked - but don't stop if we error in doing so
	if ($pathname_htdocs_index) {
		if (@file_put_contents($pathname_htdocs_index, $file_contents) === false) {
			router_warn(ROUTER_TAB . 'File Locked With Only Readers');
		}
	} else {
		router_warn(ROUTER_TAB . 'Failed to Make Directory');
	}

	// if we didn't know the length - well, the download finished so we do now, so handle for that
	if ($file_content_length === -1) {
		$file_content_length_kb = intval(ceil($file_contents_length / ROUTER_KBSIZE));
		router_warn(ROUTER_TAB . 'Downloaded 100% of ' . $file_content_length_kb . ' KB');
		
		if ($sent_downloaded_file_headers === false) {
			$sent_downloaded_file_headers = router_send_downloaded_file_headers($file_headers, $file_location, $file_contents_length, $pathname_trailing_slash, $index_extension);
			
			if ($sent_downloaded_file_headers !== true) {
				return $sent_downloaded_file_headers;
			}
		}
		
		header('Content-Length: ' . $file_contents_length);
		echo($file_contents);
	}
	return $file_contents_length;
}

// the internet is a series of tubes
function router_serve_file_from_base_urls($pathname, $pathname_trailing_slash, $pathname_info_extension, $pathname_no_extension, $pathname_htdocs, $index_extension = -1) {
	global $router_index_extensions;
	global $router_base_urls;
	global $router_extension_mimetypes;
	
	router_warn('Serving File From Base URLs: ' . $pathname);

	$pathname_index = $pathname;
	
	if ($index_extension >= count($router_index_extensions)) {
		return false;
	}
	
	if ($index_extension !== -1) {
		$pathname_index = $pathname . $pathname_trailing_slash . 'index.' . $router_index_extensions[$index_extension];
	}
	
	// the point of this next section is mainly to obtain four important pieces of information
	// - the file location - if we get redirected somewhere else
	// - the file status code - so we know if it exists
	// - the file's content length - so we know which percentage of it is downloaded when we stream it
	// - the last modifed date - because Shockwave requires it
	$file_pointer_resource = false;
	$stream_meta_data = array();
	$file_header = '';
	$file_header_lower = '';
	$file_header_lower_status_matches = array();
	$file_header_lower_status_match = false;
	$file_header_lower_matches = array();
	$file_header_lower_match = false;
	$file_headers = array();
	$file_header_pathname_index_pos = false;
	$file_location = '';
	$file_status_code = -1;
	$file_content_length = -1;
	$file_last_modified = false;
	$file_contents_length = 0;

	// we loop through every Base URL
	// if there's an error, we proceed to the next one
	// if we get an empty file, we also try for index.htm/.html
	// (at which point which extension we're currently attempting is defined by $index_extensions)
	foreach ($router_base_urls as $base => $url) {
		router_warn(ROUTER_TAB . 'Using Base: ' . $base);
		$file_pointer_resource = router_get_file_pointer_resource_from_url($url . $pathname_index);

		if ($file_pointer_resource !== false) {
			// sometimes there is a 301 Redirect, in which case two headers are found this way
			// (one for the redirect and the other for OK)
			$stream_meta_data = stream_get_meta_data($file_pointer_resource);
			$file_headers = array();
			$file_status_code = -1;

			if (isset($stream_meta_data) === true && isset($stream_meta_data['wrapper_data']) === true) {
				$wrapper_data = $stream_meta_data['wrapper_data'];
				$wrapper_data_count = count($wrapper_data);

				for ($i = 0; $i < $wrapper_data_count; $i++) {
					//router_warn('Header: ' . $wrapper_data[$i]);
					$file_header = $wrapper_data[$i];
					// we'll be comparing the headers ourselves after, and they're case-insensitive
					$file_header_lower = strtolower($file_header);
					$file_header_lower_status_matches = array();
					// is this a Status message according to our regex?
					$file_header_lower_status_match = preg_match(ROUTER_FILE_HEADER_STATUS_PATTERN, $file_header_lower, $file_header_lower_status_matches);
					
					if ($file_header_lower_status_match === 1) {
						// yes
						$file_status_code = intval($file_header_lower_status_matches[1]);
						
						if ($file_status_code === 200) {
							// ensure the HTTP version of the response matches that of the request (for Shockwave)
							array_push($file_headers, $_SERVER['SERVER_PROTOCOL'] . ' 200 OK');
						}
					} else {
						// this isn't a Status message, but is it a valid HTTP header?
						$file_header_lower_matches = array();
						$file_header_lower_match = preg_match(ROUTER_FILE_HEADER_PATTERN, $file_header_lower, $file_header_lower_matches);
					
						if ($file_header_lower_match === 1) {
							// yes
							// first, if there's no extension, be on the lookout for a redirect indicating this is actually a directory
							if ($pathname_no_extension === true && $file_status_code >= 300 && $file_status_code < 400 && $file_header_lower_matches[1] === 'location') {
								// location header - we're redirecting elsewhere
								// but where, relative to our current path?
								$file_header_pathname_index_pos = strrpos($file_header, $pathname_index);
								
								if ($file_header_pathname_index_pos !== false) {
									// $file_location will contain the redirect to forward
									$file_location = $_SERVER['SCRIPT_NAME'];
									
									if ($index_extension !== -1) {
										// factor in index
										$file_location .= $pathname_trailing_slash . 'index.' . $router_index_extensions[$index_extension];
									}
									
									$file_location .= substr($file_header, $file_header_pathname_index_pos + strlen($pathname_index));
								}
							}
							
							if ($file_status_code === 200) {
								// only in the scenario where the status is already OK, check for the length of the response and last modified date
								switch ($file_header_lower_matches[1]) {
									case 'content-length':
									$file_content_length = intval($file_header_lower_matches[2]);
									break;
									case 'last-modified':
									$file_last_modified = true;
									break;
									case 'content-type':
									// replace the mimetype if we know a different one for this file extension
									foreach($router_extension_mimetypes as $extension => $mimetype) {
										if ($pathname_info_extension === $extension) {
											$file_header = 'Content-Type: ' . $mimetype;
											break;
										}
									}
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
				$file_contents_length = router_download_file($file_pointer_resource, $file_headers, $file_location, $file_content_length, $pathname_trailing_slash, $pathname_htdocs, $index_extension);
				
				// and if we get a file with an actual length back, we can finally end this loop
				if ($file_contents_length !== 0) {
					break;
				}
			}
		}
	}

	// did we get an actual file with a length?
	if ($file_contents_length === 0) {
		// no? well, if the file has no extension, maybe it's actually a directory containing an index.htm/.html file
		// so try that next
		if ($pathname_no_extension === true) {
			if (router_serve_file_from_base_urls($pathname, $pathname_trailing_slash, $pathname_info_extension, $pathname_no_extension, $pathname_htdocs, $index_extension + 1) === true) {
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

// the main function of the program which decides how to serve the file
function router_route_pathname($pathname) {
	global $router_script_extensions;
	global $router_index_extensions;
	
	//router_warn('Routing Pathname: ' . $pathname);
	
	// in situations where we need a trailing slash, this will be appended to the pathname
	$pathname_trailing_slash = substr($pathname, -1) === '/' ? '' : '/';
	$pathname_info = pathinfo($pathname);
	$pathname_info_extension = isset($pathname_info['extension']) ? strtolower($pathname_info['extension']) : '';
	$pathname_no_extension = empty($pathname_info_extension) === true;
	$http_host_lower = strtolower($_SERVER['HTTP_HOST']);

	if ($http_host_lower === 'localhost' || strpos($http_host_lower, 'localhost:') === 0) {
		if (empty($_SERVER['SCRIPT_NAME']) === true || $_SERVER['SCRIPT_NAME'] === '/') {
			phpinfo();
			return true;
		}

		$pathname = $_SERVER['SCRIPT_NAME'];
	}

	$pathname_cgi_bin = ROUTER_CGI_BIN . $pathname;
	$pathname_cgi_bin_info = pathinfo($pathname_cgi_bin);
	$pathname_cgi_bin_index = '';
	
	// if the file being downloaded is a script, include it instead
	$router_script_extensions_count = count($router_script_extensions);

	for ($i = 0; $i < $router_script_extensions_count; $i++) {
		if ($pathname_info_extension === $router_script_extensions[$i]) {
			// never allow scripts to be served anywhere except from cgi-bin
			// if the file doesn't exist - it's an error
			if (is_file($pathname_cgi_bin) === false) {
				return false;
			}
			
			return router_serve_file_from_cgi_bin($pathname_cgi_bin, $pathname_cgi_bin_info, $pathname_trailing_slash);
		}
	}
	
	$router_index_extensions_count = count($router_index_extensions);

	// if the file exists in cgi-bin - even if it's empty - serve it from there
	if (is_file($pathname_cgi_bin) === true) {
		return router_serve_file_from_cgi_bin($pathname_cgi_bin, $pathname_cgi_bin_inf, $pathname_trailing_slasho);
	} else {
		// also check directories for index files
		if (is_dir($pathname_cgi_bin) === true) {
			$index_extension_cgi_bin = -1;

			for ($i = 0; $i < $router_index_extensions_count; $i++) {
				$pathname_cgi_bin_index = $pathname_cgi_bin . '/index.' . $router_index_extensions[$i];
				if (is_file($pathname_cgi_bin_index) === true) {
					$pathname_cgi_bin = $pathname_cgi_bin_index;
					$pathname_cgi_bin_info = pathinfo($pathname_cgi_bin);
					$index_extension_cgi_bin = $i;
					return router_serve_file_from_cgi_bin($pathname_cgi_bin, $pathname_cgi_bin_info, $pathname_trailing_slash, $index_extension_cgi_bin);
				}
			}
		}
	}

	$pathname_htdocs = ROUTER_HTDOCS . $pathname;
	$pathname_htdocs_index = '';

	// has the file been downloaded and saved locally before?
	if (is_file($pathname_htdocs) === true) {
		// serve the local file
		$filesize = router_serve_file_from_htdocs($pathname_htdocs, $pathname_trailing_slash, $pathname_info_extension);
			
		/*
		if ($filesize === false) {
			router_warn(ROUTER_TAB . 'Failed to Serve File From ROUTER_HTDOCS');
		}
		*/
		
		// if file is real and has a size, serve it - but otherwise, we need to download it
		if ($filesize !== 0) {
			return true;
		}
	} else {
		// if the path is to a directory, we need to handle for index HTML files
		if (is_dir($pathname_htdocs) === true) {
			$index_extension_htdocs = -1;

			for ($i = 0; $i < $router_index_extensions_count; $i++) {
				$pathname_htdocs_index = $pathname_htdocs . '/index.' . $router_index_extensions[$i];
				if (is_file($pathname_htdocs_index) === true) {
					$pathname_htdocs = $pathname_htdocs_index;
					$index_extension_htdocs = $i;
					$filesize = router_serve_file_from_htdocs($pathname_htdocs, $pathname_trailing_slash, $pathname_info_extension, $index_extension_htdocs);
					
					/*
					if ($filesize === false) {
						router_warn(ROUTER_TAB . 'Failed to Serve File From ROUTER_HTDOCS');
					}
					*/
					
					if ($filesize !== 0) {
						return true;
					}
				}
			}
		}
	}

	// TAKE A SPIN NOW YOU'RE IN WITH THE TECHNO SET
	// WE'RE GOING SURFING ON THE INTERNET
	if (router_serve_file_from_base_urls($pathname, $pathname_trailing_slash, $pathname_info_extension, $pathname_no_extension, $pathname_htdocs) === false) {
		return false;
	}
	return true;
}

// start the program...
if (router_route_pathname('/' . $_SERVER['HTTP_HOST'] . $_SERVER['SCRIPT_NAME']) === false) {
	//router_warn(ROUTER_TAB . 'Failed to Route Pathname');
	return false;
}
?>