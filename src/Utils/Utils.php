<?php
namespace Swolley\Database\Utils;

use Swolley\Database\Exceptions\BadMethodCallException;
use Swolley\Database\Exceptions\UnexpectedValueException;

class Utils
{
	/**
	 * casts params in object format to array
	 * @param	array|object	$params	params to cast
	 * @return	array					converted object
	 */
	public static function castToArray($params): array
	{
		$paramsType = gettype($params);
		if ($paramsType !== 'array' && $paramsType !== 'object') {
			throw new UnexpectedValueException('$params can be only array or object');
		}

		return $paramsType === 'object' ? (array)$params : $params;
	}

	/**
	 * casts params in object format to array
	 * @param	array|object	$params	params to cast
	 * @return	object					converted array
	 */
	public static function castToObject($params): object
	{
		$paramsType = gettype($params);
		if ($paramsType !== 'array' && $paramsType !== 'object') {
			throw new UnexpectedValueException('$params can be only array or object');
		}

		return $paramsType === 'array' ? (object)$params : $params;
	}

	/**
	 * @param 	string	$query query string
	 * @return	string	trimmed query
	 */
	public static function trimQueryString(string $query): string
	{
		return preg_replace('/\s\s+/', ' ', $query);
	}
}
