<?php

/**
 * HTTP Response from HttpSocket.
 *
 * CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @link          https://cakephp.org CakePHP(tm) Project
 * @since         CakePHP(tm) v 2.0.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */

/**
 * HTTP Response from HttpSocket.
 *
 * @package       Cake.Network.Http
 */
class HttpSocketResponse implements ArrayAccess
{

	/**
	 * Body content
	 *
	 * @var string
	 */
	public $body = '';

	/**
	 * Headers
	 *
	 * @var array
	 */
	public $headers = array();

	/**
	 * Cookies
	 *
	 * @var array
	 */
	public $cookies = array();

	/**
	 * HTTP version
	 *
	 * @var string
	 */
	public $httpVersion = 'HTTP/1.1';

	/**
	 * Response code
	 *
	 * @var int
	 */
	public $code = 0;

	/**
	 * Reason phrase
	 *
	 * @var string
	 */
	public $reasonPhrase = '';

	/**
	 * Pure raw content
	 *
	 * @var string
	 */
	public $raw = '';

	/**
	 * Context data in the response.
	 * Contains SSL certificates for example.
	 *
	 * @var array
	 */
	public $context = array();

	/**
	 * Constructor
	 *
	 * @param string $message Message to parse.
	 */
	public function __construct($message = null)
	{
		if ($message !== null) {
			$this->parseResponse($message);
		}
	}

	/**
	 * Body content
	 *
	 * @return string
	 */
	public function body()
	{
		return (string)$this->body;
	}

	/**
	 * Get header in case insensitive
	 *
	 * @param string $name Header name.
	 * @param array $headers Headers to format.
	 * @return mixed String if header exists or null
	 */
	public function getHeader($name, $headers = null)
	{
		if (!is_array($headers)) {
			$headers = &$this->headers;
		}
		if (isset($headers[$name])) {
			return $headers[$name];
		}
		foreach ($headers as $key => $value) {
			if (strcasecmp($key, $name) === 0) {
				return $value;
			}
		}
		return null;
	}

	/**
	 * If return is 200 (OK)
	 *
	 * @return bool
	 */
	public function isOk()
	{
		return in_array($this->code, array(200, 201, 202, 203, 204, 205, 206));
	}

	/**
	 * If return is a valid 3xx (Redirection)
	 *
	 * @return bool
	 */
	public function isRedirect()
	{
		return in_array($this->code, array(301, 302, 303, 307)) && $this->getHeader('Location') !== null;
	}

	/**
	 * Parses the given message and breaks it down in parts.
	 *
	 * @param string $message Message to parse
	 * @return void
	 * @throws SocketException
	 */
	public function parseResponse($message)
	{
		if (!is_string($message)) {
			throw new SocketException(__d('cake_dev', 'Invalid response.'));
		}

		if (!preg_match("/^(.+\r\n)(.*)(?<=\r\n)\r\n/Us", $message, $match)) {
			throw new SocketException(__d('cake_dev', 'Invalid HTTP response.'));
		}

		list(, $statusLine, $header) = $match;
		$this->raw = $message;
		$this->body = (string)substr($message, strlen($match[0]));

		if (preg_match("/(.+) ([0-9]{3})(?:\s+(\w.+))?\s*\r\n/DU", $statusLine, $match)) {
			$this->httpVersion = $match[1];
			$this->code = $match[2];
			if (isset($match[3])) {
				$this->reasonPhrase = $match[3];
			}
		}

		$this->headers = $this->_parseHeader($header);
		$transferEncoding = $this->getHeader('Transfer-Encoding');
		$decoded = $this->_decodeBody($this->body, $transferEncoding);
		$this->body = $decoded['body'];

		if (!empty($decoded['header'])) {
			$this->headers = $this->_parseHeader($this->_buildHeader($this->headers) . $this->_buildHeader($decoded['header']));
		}

		if (!empty($this->headers)) {
			$this->cookies = $this->parseCookies($this->headers);
		}
	}

	/**
	 * Generic function to decode a $body with a given $encoding. Returns either an array with the keys
	 * 'body' and 'header' or false on failure.
	 *
	 * @param string $body A string containing the body to decode.
	 * @param string|bool $encoding Can be false in case no encoding is being used, or a string representing the encoding.
	 * @return mixed Array of response headers and body or false.
	 */
	protected function _decodeBody($body, $encoding = 'chunked')
	{
		if (!is_string($body)) {
			return false;
		}
		if (empty($encoding)) {
			return array('body' => $body, 'header' => false);
		}
		$decodeMethod = '_decode' . Inflector::camelize(str_replace('-', '_', $encoding)) . 'Body';

		if (!is_callable(array(&$this, $decodeMethod))) {
			return array('body' => $body, 'header' => false);
		}
		return $this->{$decodeMethod}($body);
	}

	/**
	 * Decodes a chunked message $body and returns either an array with the keys 'body' and 'header' or false as
	 * a result.
	 *
	 * @param string $body A string containing the chunked body to decode.
	 * @return mixed Array of response headers and body or false.
	 * @throws SocketException
	 */
	protected function _decodeChunkedBody($body)
	{
		if (!is_string($body)) {
			return false;
		}

		$decodedBody = null;
		$chunkLength = null;

		while ($chunkLength !== 0) {
			if (!preg_match('/^([0-9a-f]+)[ ]*(?:;(.+)=(.+))?(?:\r\n|\n)/iU', $body, $match)) {
				// Handle remaining invalid data as one big chunk.
				preg_match('/^(.*?)\r\n/', $body, $invalidMatch);
				$length = isset($invalidMatch[1]) ? strlen($invalidMatch[1]) : 0;
				$match = array(
					0 => '',
					1 => dechex($length)
				);
			}
			$chunkSize = 0;
			$hexLength = 0;
			if (isset($match[0])) {
				$chunkSize = $match[0];
			}
			if (isset($match[1])) {
				$hexLength = $match[1];
			}

			$chunkLength = hexdec($hexLength);
			$body = substr($body, strlen($chunkSize));

			$decodedBody .= substr($body, 0, $chunkLength);
			if ($chunkLength) {
				$body = substr($body, $chunkLength + strlen("\r\n"));
			}
		}

		$entityHeader = false;
		if (!empty($body)) {
			$entityHeader = $this->_parseHeader($body);
		}
		return array('body' => $decodedBody, 'header' => $entityHeader);
	}

	/**
	 * Parses an array based header.
	 *
	 * @param array $header Header as an indexed array (field => value)
	 * @return array|bool Parsed header
	 */
	protected function _parseHeader($header)
	{
		if (is_array($header)) {
			return $header;
		} elseif (!is_string($header)) {
			return false;
		}

		preg_match_all("/(.+):(.+)(?:\r\n|\$)/Uis", $header, $matches, PREG_SET_ORDER);
		$lines = explode("\r\n", $header);

		$header = array();
		foreach ($lines as $line) {
			if (strlen($line) === 0) {
				continue;
			}
			$continuation = false;
			$first = substr($line, 0, 1);

			// Multi-line header
			if ($first === ' ' || $first === "\t") {
				$value .= preg_replace("/\s+/", ' ', $line);
				$continuation = true;
			} elseif (strpos($line, ':') !== false) {
				list($field, $value) = explode(':', $line, 2);
				$field = $this->_unescapeToken($field);
			}

			$value = trim($value);
			if (!isset($header[$field]) || $continuation) {
				$header[$field] = $value;
			} else {
				$header[$field] = array_merge((array)$header[$field], (array)$value);
			}
		}
		return $header;
	}

	/**
	 * Parses cookies in response headers.
	 *
	 * @param array $header Header array containing one ore more 'Set-Cookie' headers.
	 * @return mixed Either false on no cookies, or an array of cookies received.
	 */
	public function parseCookies($header)
	{
		$cookieHeader = $this->getHeader('Set-Cookie', $header);
		if (!$cookieHeader) {
			return false;
		}

		$cookies = array();
		foreach ((array)$cookieHeader as $cookie) {
			if (strpos($cookie, '";"') !== false) {
				$cookie = str_replace('";"', "{__cookie_replace__}", $cookie);
				$parts = str_replace("{__cookie_replace__}", '";"', explode(';', $cookie));
			} else {
				$parts = preg_split('/\;[ \t]*/', $cookie);
			}

			$nameParts = explode('=', array_shift($parts), 2);
			if (count($nameParts) < 2) {
				$nameParts = array('', $nameParts[0]);
			}
			list($name, $value) = $nameParts;
			$cookies[$name] = compact('value');

			foreach ($parts as $part) {
				if (strpos($part, '=') !== false) {
					list($key, $value) = explode('=', $part);
				} else {
					$key = $part;
					$value = true;
				}

				$key = strtolower($key);
				if (!isset($cookies[$name][$key])) {
					$cookies[$name][$key] = $value;
				}
			}
		}
		return $cookies;
	}

	/**
	 * Unescapes a given $token according to RFC 2616 (HTTP 1.1 specs)
	 *
	 * @param string $token Token to unescape.
	 * @param array $chars Characters to unescape.
	 * @return string Unescaped token
	 */
	protected function _unescapeToken($token, $chars = null)
	{
		$regex = '/"([' . implode('', $this->_tokenEscapeChars(true, $chars)) . '])"/';
		$token = preg_replace($regex, '\\1', $token);
		return $token;
	}

	/**
	 * Gets escape chars according to RFC 2616 (HTTP 1.1 specs).
	 *
	 * @param bool $hex True to get them as HEX values, false otherwise.
	 * @param array $chars Characters to uescape.
	 * @return array Escape chars
	 */
	protected function _tokenEscapeChars($hex = true, $chars = null)
	{
		if (!empty($chars)) {
			$escape = $chars;
		} else {
			$escape = array('"', "(", ")", "<", ">", "@", ",", ";", ":", "\\", "/", "[", "]", "?", "=", "{", "}", " ");
			for ($i = 0; $i <= 31; $i++) {
				$escape[] = chr($i);
			}
			$escape[] = chr(127);
		}

		if (!$hex) {
			return $escape;
		}
		foreach ($escape as $key => $char) {
			$escape[$key] = '\\x' . str_pad(dechex(ord($char)), 2, '0', STR_PAD_LEFT);
		}
		return $escape;
	}

	/**
	 * ArrayAccess - Offset Exists
	 *
	 * @param mixed $offset Offset to check.
	 * @return bool
	 */
	public function offsetExists($offset): bool
	{
		return in_array($offset, array('raw', 'status', 'header', 'body', 'cookies'));
	}

	/**
	 * ArrayAccess - Offset Get
	 *
	 * @param mixed $offset Offset to get.
	 * @return mixed
	 */
	public function offsetGet($offset)
	{
		switch ($offset) {
			case 'raw':
				$firstLineLength = strpos($this->raw, "\r\n") + 2;
				if ($this->raw[$firstLineLength] === "\r") {
					$header = null;
				} else {
					$header = substr($this->raw, $firstLineLength, strpos($this->raw, "\r\n\r\n") - $firstLineLength) . "\r\n";
				}
				return array(
					'status-line' => $this->httpVersion . ' ' . $this->code . ' ' . $this->reasonPhrase . "\r\n",
					'header' => $header,
					'body' => $this->body,
					'response' => $this->raw
				);
			case 'status':
				return array(
					'http-version' => $this->httpVersion,
					'code' => $this->code,
					'reason-phrase' => $this->reasonPhrase
				);
			case 'header':
				return $this->headers;
			case 'body':
				return $this->body;
			case 'cookies':
				return $this->cookies;
		}
		return null;
	}

	/**
	 * ArrayAccess - Offset Set
	 *
	 * @param mixed $offset Offset to set.
	 * @param mixed $value Value.
	 * @return void
	 */
	public function offsetSet($offset, $value)
	{
	}

	/**
	 * ArrayAccess - Offset Unset
	 *
	 * @param string $offset Offset to unset.
	 * @return void
	 */
	public function offsetUnset($offset)
	{
	}

	/**
	 * Instance as string
	 *
	 * @return string
	 */
	public function __tostring()
	{
		return $this->body();
	}
}
