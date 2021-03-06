<?php
declare(strict_types=1);

namespace Swolley\YardBird\Utils;

use Swolley\YardBird\Exceptions\BadMethodCallException;
use Swolley\YardBird\Exceptions\UnexpectedValueException;

class Utils
{
	/**
	 * @param 	string	$query query string
	 * @return	string	trimmed query
	 */
	public static function trimQueryString(string $query): string
	{
		return rtrim(preg_replace('/\s\s+/', ' ', $query), ';');
	}

	/**
	 * @param	mixed	$data	data to hash
	 * @return	string	hashed data
	 */
	public static function hash($data): string
	{
		return hash('sha1', serialize($data));
	}

	public static function toCamelCase(string $string): string
	{
		return $string !== null ? str_replace("_", '', ucwords(preg_replace("/-|\s/", '_', $string), '_')) : '';
	}

	public static function toPascalCase(string $string): string 
	{
		return $string !== null ? lcfirst(self::toCamelCase($string)) : '';
	}
}
