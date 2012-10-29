<?php

/**
 * This file is part of the Nette Framework (http://nette.org)
 *
 * Copyright (c) 2004 David Grudl (http://davidgrudl.com)
 *
 * For the full copyright and license information, please view
 * the file license.txt that was distributed with this source code.
 */

namespace Nette\Diagnostics;

use Nette;



/**
 * Dumps a variable.
 *
 * @author     David Grudl
 */
class Dumper
{
	const DEPTH = 'depth', // how many nested levels of array/object properties display (defaults to 4)
		TRUNCATE = 'truncate', // how truncate long strings? (defaults to 150)
		COLLAPSE = 'collapse', // how big array/object are collapsed? (defaults to 7)
		LOCATION = 'location'; // show location string? (defaults to false)

	/** @var array */
	public static $terminalColors = array(
		'bool' => '1;33',
		'null' => '1;33',
		'number' => '1;32',
		'string' => '1;36',
		'array' => '1;31',
		'key' => '1;37',
		'object' => '1;31',
		'visibility' => '1;30',
		'resource' => '1;37',
		'indent' => '1;30',
	);

	/** @var array */
	public static $resources = array('stream' => 'stream_get_meta_data', 'stream-context' => 'stream_context_get_options', 'curl' => 'curl_getinfo');



	/**
	 * Dumps variable to the output.
	 * @return mixed  variable
	 */
	public static function dump($var, array $options = NULL)
	{
		if (preg_match('#^Content-Type: text/html#im', implode("\n", headers_list()))) {
			echo self::toHtml($var, $options);
		} elseif (self::$terminalColors && substr(getenv('TERM'), 0, 5) === 'xterm') {
			echo self::toTerminal($var, $options);
		} else {
			echo self::toText($var, $options);
		}
		return $var;
	}



	/**
	 * Dumps variable to HTML.
	 * @return string
	 */
	public static function toHtml($var, array $options = NULL)
	{
		list($file, $line, $code) = empty($options[self::LOCATION]) ? NULL : self::findLocation();
		return '<pre class="nette-dump"'
			. ($file ? ' title="' . htmlspecialchars("$code\nin file $file on line $line") . '">' : '>')
			. self::dumpVar($var, (array) $options + array(
				self::DEPTH => 4,
				self::TRUNCATE => 150,
				self::COLLAPSE => 7,
			))
			. ($file ? '<small>in <a href="editor://open/?file=' . rawurlencode($file) . "&amp;line=$line\">" . htmlspecialchars($file) . ":$line</a></small>" : '')
			. "</pre>\n";
	}



	/**
	 * Dumps variable to plain text.
	 * @return string
	 */
	public static function toText($var, array $options = NULL)
	{
		return htmlspecialchars_decode(strip_tags(self::toHtml($var, $options)), ENT_QUOTES);
	}



	/**
	 * Dumps variable to x-terminal.
	 * @return string
	 */
	public static function toTerminal($var, array $options = NULL)
	{
		return htmlspecialchars_decode(strip_tags(preg_replace_callback('#<span class="nette-dump-(\w+)">|</span>#', function($m) {
			return "\033[" . (isset($m[1], Dumper::$terminalColors[$m[1]]) ? Dumper::$terminalColors[$m[1]] : '0') . "m";
		}, self::toHtml($var, $options))), ENT_QUOTES);
	}



	/**
	 * Internal toHtml() dump implementation.
	 * @param  mixed  variable to dump
	 * @param  array  options
	 * @param  int    current recursion level
	 * @return string
	 */
	private static function dumpVar(&$var, array $options, $level = 0)
	{
		if (method_exists(__CLASS__, $m = 'dump' . gettype($var))) {
			return /**/self::$m($var, $options, $level);/**//*5.2*call_user_func_array(array(__CLASS__, $m), array(&$var, $options, $level));*/
		} else {
			return "<span>unknown type</span>\n";
		}
	}



	private static function dumpNull()
	{
		return "<span class=\"nette-dump-null\">NULL</span>\n";
	}



	private static function dumpBoolean(&$var)
	{
		return '<span class="nette-dump-bool">' . ($var ? 'TRUE' : 'FALSE') . "</span>\n";
	}



	private static function dumpInteger(&$var)
	{
		return "<span class=\"nette-dump-number\">$var</span>\n";
	}



	private static function dumpDouble(&$var)
	{
		$var = var_export($var, TRUE);
		return '<span class="nette-dump-number">' . $var . (strpos($var, '.') === FALSE ? '.0' : '') . "</span>\n";
	}



	private static function dumpString(&$var, $options)
	{
		return '<span class="nette-dump-string">'
			. self::encodeString($options[self::TRUNCATE] && strlen($var) > $options[self::TRUNCATE] ? substr($var, 0, $options[self::TRUNCATE]) . ' ... ' : $var)
			. '</span>' . (strlen($var) > 1 ? ' (' . strlen($var) . ')' : '') . "\n";
	}



	private static function dumpArray(&$var, $options, $level)
	{
		static $marker;
		if ($marker === NULL) {
			$marker = uniqid("\x00", TRUE);
		}

		$out = '<span class="nette-dump-array">array</span> (';

		if (empty($var)) {
			return $out . "0)\n";

		} elseif (isset($var[$marker])) {
			return $out . (count($var) - 1) . ") [ <i>RECURSION</i> ]\n";

		} elseif (!$options[self::DEPTH] || $level < $options[self::DEPTH]) {
			$collapsed = count($var) >= $options[self::COLLAPSE];
	 		$out = '<span class="nette-toggle' . ($collapsed ? '-collapsed">' : '">') . $out . count($var) . ")</span>\n<div" . ($collapsed ? ' class="nette-collapsed"' : '') . ">";
			$var[$marker] = TRUE;
			foreach ($var as $k => &$v) {
				if ($k !== $marker) {
					$out .= '<span class="nette-dump-indent">   ' . str_repeat('|  ', $level) . '</span>'
						. '<span class="nette-dump-key">' . (preg_match('#^\w+\z#', $k) ? $k : self::encodeString($k)) . '</span> => '
						. self::dumpVar($v, $options, $level + 1);
				}
			}
			unset($var[$marker]);
			return $out . '</div>';

		} else {
			return $out . count($var) . ") [ ... ]\n";
		}
	}



	private static function dumpObject(&$var, $options, $level)
	{
		if ($var instanceof \Closure) {
			$rc = new \ReflectionFunction($var);
			$fields = array();
			foreach ($rc->getParameters() as $param) {
				$fields[] = '$' . $param->getName();
			}
			$fields = array('file' => $rc->getFileName(), 'line' => $rc->getStartLine(), 'parameters' => implode(', ', $fields));
		} else {
			$fields = (array) $var;
		}

		static $list = array();
		$out = '<span class="nette-dump-object">' . get_class($var) . "</span> (" . count($fields) . ')';

		if (empty($fields)) {
			return $out . "\n";

		} elseif (in_array($var, $list, TRUE)) {
			return $out . " { <i>RECURSION</i> }\n";

		} elseif (!$options[self::DEPTH] || $level < $options[self::DEPTH] || $var instanceof \Closure) {
			$collapsed = count($fields) >= $options[self::COLLAPSE];
	 		$out = '<span class="nette-toggle' . ($collapsed ? '-collapsed">' : '">') . $out . "</span>\n<div" . ($collapsed ? ' class="nette-collapsed"' : '') . ">";
			$list[] = $var;
			foreach ($fields as $k => &$v) {
				$vis = '';
				if ($k[0] === "\x00") {
					$vis = ' <span class="nette-dump-visibility">' . ($k[1] === '*' ? 'protected' : 'private') . '</span>';
					$k = substr($k, strrpos($k, "\x00") + 1);
				}
				$out .= '<span class="nette-dump-indent">   ' . str_repeat('|  ', $level) . '</span>'
					. '<span class="nette-dump-key">' . (preg_match('#^\w+\z#', $k) ? $k : self::encodeString($k)) . "</span>$vis => "
					. self::dumpVar($v, $options, $level + 1);
			}
			array_pop($list);
			return $out . '</div>';

		} else {
			return $out . " { ... }\n";
		}
	}



	private static function dumpResource(&$var, $options, $level)
	{
		$type = get_resource_type($var);
		$out = '<span class="nette-dump-resource">' . htmlSpecialChars($type) . ' resource</span>';
		if (isset(self::$resources[$type])) {
		    $out = "<span class=\"nette-toggle-collapsed\">$out</span>\n<div class=\"nette-collapsed\">";
			foreach (call_user_func(self::$resources[$type], $var) as $k => $v) {
				$out .= '<span class="nette-dump-indent">   ' . str_repeat('|  ', $level) . '</span>'
					. '<span class="nette-dump-key">' . htmlSpecialChars($k) . "</span> => " . self::dumpVar($v, $options, $level + 1);
			}
			return $out . '</div>';
		}
		return "$out\n";
	}



	private static function encodeString($s)
	{
		static $utf, $binary;
		if ($utf === NULL) {
			foreach (range("\x00", "\xFF") as $ch) {
				if (ord($ch) < 32 && strpos("\r\n\t", $ch) === FALSE) {
					$utf[$ch] = $binary[$ch] = '\\x' . str_pad(dechex(ord($ch)), 2, '0', STR_PAD_LEFT);
				} elseif (ord($ch) < 127) {
					$utf[$ch] = $binary[$ch] = $ch;
				} else {
					$utf[$ch] = $ch; $binary[$ch] = '\\x' . dechex(ord($ch));
				}
			}
			$binary["\\"] = '\\\\';
			$binary["\r"] = '\\r';
			$binary["\n"] = '\\n';
			$binary["\t"] = '\\t';
			$utf['\\x'] = $binary['\\x'] = '\\\\x';
		}

		$s = strtr($s, preg_match('#[^\x09\x0A\x0D\x20-\x7E\xA0-\x{10FFFF}]#u', $s) || preg_last_error() ? $binary : $utf);
		return '"' . htmlSpecialChars($s, ENT_NOQUOTES) . '"';
	}



	/**
	 * Finds the location where dump was called.
	 * @return array [file, line, code]
	 */
	private static function findLocation()
	{
		foreach (/*5.2*PHP_VERSION_ID < 50205 ? debug_backtrace() : */debug_backtrace(FALSE) as $item) {
			if (isset($item['file']) && strpos($item['file'], __DIR__) === 0) {
				continue;

			} elseif (!isset($item['file'], $item['line']) || !is_file($item['file'])) {
				break;

			} else {
				$lines = file($item['file']);
				$line = $lines[$item['line'] - 1];
				return array(
					$item['file'],
					$item['line'],
					preg_match('#\w*dump(er::\w+)?\(.*\)#i', $line, $m) ? $m[0] : $line
				);
			}
		}
	}

}
