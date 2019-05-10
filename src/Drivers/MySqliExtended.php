<?php
namespace Swolley\Database\Drivers;

use Swolley\Database\DBFactory;
use Swolley\Database\Interfaces\IRelationalConnectable;
use Swolley\Database\Utils\TraitUtils;
use Swolley\Database\Exceptions\ConnectionException;
use Swolley\Database\Exceptions\QueryException;
use Swolley\Database\Exceptions\BadMethodCallException;
use Swolley\Database\Exceptions\UnexpectedValueException;

set_error_handler(function($errno, $errstr, $errfile, $errline, array $errcontext) {
    // error was suppressed with the @-operator
    if (0 === error_reporting()) {
        return false;
    }

    throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
});


class MySqliExtended extends \mysqli implements IRelationalConnectable
{
	use TraitUtils;

	/**
	 * @param	array	$params	connection parameters
	 */
	public function __construct(array $params)
	{
		$params = self::validateConnectionParams($params);
		try {
			parent::__construct($params['host'], $params['user'], $params['password'], $params['dbName'], $params['port']);
			$this->set_charset($params['charset']);
		} catch(\ErrorException $e) {
			throw new ConnectionException($e->getMessage(), $e->getCode());
		}
	}

	public static function validateConnectionParams($params): array
	{
		if (!isset($params['host'], $params['user'], $params['password'])) {
			throw new BadMethodCallException("host, user, password are required");
		} elseif (empty($params['host']) || empty($params['user']) || empty($params['password'])) {
			throw new UnexpectedValueException("host, user, password can't be empty");
		}

		//default ports
		if (!isset($params['port'])) {
			$params['port'] = 3306;
		}

		//default charset
		if (!isset($params['charset'])) {
			$params['charset'] = 'UTF8';
		}

		/////////////////////////////////////////////////////////////
		if (!isset($params['dbName'])) {
			throw new BadMethodCallException("dbName is required");
		} elseif (empty($params['dbName'])) {
			throw new UnexpectedValueException("dbName can't be empty");
		}

		return $params;
	}

	public static function composeConnectionParams(array $params, array $init_arr = []): array
	{
		return [
			$params['host'], 
			$params['user'], 
			$params['password'], 
			$params['dbName'], 
			$params['port']
		];
	}

	public function sql(string $query, $params = [], int $fetchMode = DBFactory::FETCH_ASSOC, $fetchModeParam = 0, array $fetchPropsLateParams = []): array
	{
		$query = self::trimCr($query);
		$params = self::castToArray($params);

		//ksort($params);
		//TODO it should be tested that if colon placeholders are passed the $params array needs to be associative, either simple array can be accepted
		self::colonsToQuestionMarksPlaceholders($query, $params);			
		
		$st = $this->prepare($query);
		if(!$st) {
			throw new QueryException("Cannot prepare query. Check the syntax.");
		}
		if(!self::bindParams($params, $st)) {
			throw new UnexpectedValueException('Cannot bind parameters');
		}
		if(!$st->execute()) {
			throw new QueryException("{$this->errno}: {$this->error}", $this->errno);
		}

		$result = self::fetch($st, $fetchMode, $fetchModeParam, $fetchPropsLateParams);
		$st->close();
		return $result;
	}

	public function select(string $table, array $fields = [], array $where = [], int $fetchMode = DBFactory::FETCH_ASSOC, $fetchModeParam = 0, array $fetchPropsLateParams = []): array
	{
		$stringed_fields = '`' . join('`, `', $fields) . '`';

		//ksort($where);
		$values = '';
		foreach ($where as $key => $value) {
			$values .= "`$key`=? AND ";
		}
		$stringed_where = rtrim($values, 'AND ');

		$st = $this->prepare("SELECT {$stringed_fields} FROM {$table} WHERE {$stringed_where}");
		if(!$st) {
			throw new QueryException("Cannot prepare query. Check the syntax.");
		}
		if(!self::bindParams($where, $st)) {
			throw new UnexpectedValueException('Cannot bind parameters');
		}
		if(!$st->execute()) {
			throw new QueryException("{$this->errno}: {$this->error}", $this->errno);
		}

		$result = self::fetch($st, $fetchMode, $fetchModeParam, $fetchPropsLateParams);
		$st->close();
		return $result;
	}

	public function insert(string $table, $params, bool $ignore = false)
	{
		$params = self::castToArray($params);

		//ksort($params);
		$keys_list = array_keys($params);
		$keys = '`' . implode('`, `', $keys_list) . '`';
		$values = rtrim(str_repeat("?, ", count($keys_list)), ', ');

		$st = $this->prepare('INSERT ' . ($ignore ? 'IGNORE ' : '') . "INTO `{$table}` ({$keys}) VALUES ({$values})");
		if(!$st) {
			throw new QueryException("Cannot prepare query. Check the syntax.");
		}
		if(!self::bindParams($params, $st)) {
			throw new UnexpectedValueException('Cannot bind parameters');
		}
		if(!$st->execute()) {
			throw new QueryException("{$this->errno}: {$this->error}", $this->errno);
		}
		$inserted_id = $st->insert_id;
		$total_inserted = $st->num_rows;
		
		return $inserted_id !== '0' ? $inserted_id : $total_inserted > 0;
	}

	public function update(string $table, $params, string $where = null): bool
	{
		$params = self::castToArray($params);

		//TODO it should be tested that if colon placeholders are passed the $params array needs to be associative, either simple array can be accepted

		//TODO how to bind where clause?

		//ksort($params);
		$values = '';
		foreach ($params as $key => $value) {
			$values .= "`$key`=?, ";
		}
		$values = rtrim($values, ', ');

		if(!is_null($where)) {
			self::colonsToQuestionMarksPlaceholders($where, $params);
		}

		$st = $this->prepare("UPDATE `{$table}` SET {$values}" . (!is_null($where) ? " WHERE {$where}" : ''));
		if(!$st) {
			throw new QueryException("Cannot prepare query. Check the syntax.");
		}
		if(!self::bindParams($params, $st)) {
			throw new UnexpectedValueException('Cannot bind parameters');
		}
		if(!$st->execute()) {
			throw new QueryException("{$this->errno}: {$this->error}", $this->errno);
		}

		return $st->num_rows > 0;
	}

	public function delete(string $table, array $params, string $where = null): bool
	{
		//TODO it should be tested that if colon placeholders are passed the $params array needs to be associative, either simple array can be accepted
		if(!is_null($where)) {
			self::colonsToQuestionMarksPlaceholders($where, $params);
		}

		//ksort($params);
		$st = $this->prepare("DELETE FROM {$table} WHERE {$where}");
		if(!$st) {
			throw new QueryException("Cannot prepare query. Check the syntax.");
		}
		if(!self::bindParams($params, $st)) {
			throw new UnexpectedValueException('Cannot bind parameters');
		}
		if(!$st->execute()) {
			throw new QueryException("{$this->errno}: {$this->error}", $this->errno);
		}

		return $st->num_rows > 0;
	}

	public function procedure(string $name, array $inParams = [], array $outParams = [], int $fetchMode = DBFactory::FETCH_ASSOC, $fetchModeParam = 0, array $fetchPropsLateParams = []): array
	{
		//input params
		$procedure_in_params = '';
		foreach ($inParams as $key => $value) {
			$procedure_in_params .= "?, ";
		}
		$procedure_in_params = rtrim($procedure_in_params, ', ');

		//TODO to undestrand how to handle procedure out params
		//output params
		$procedure_out_params = '';
		foreach ($outParams as $value) {
			$procedure_out_params .= ":$value, ";
		}
		$procedure_out_params = rtrim($procedure_out_params, ', ');

		$st = $this->prepare($this->constructProcedureString($name, $procedure_in_params, $procedure_out_params));
		if(!$st) {
			throw new QueryException("Cannot prepare query. Check the syntax.");
		}
		if(!self::bindParams($inParams, $st)) {
			throw new UnexpectedValueException('Cannot bind parameters');
		}
		$outResult = [];
		if(!$st->execute()) {
			throw new QueryException("{$this->errno}: {$this->error}", $this->errno);
		}
		self::bindOutParams($outParams, $st, $outResult);
		
		if (count($outParams) > 0) {
			return $outResult;
		} else {
			return self::fetch($st, $fetchMode, $fetchModeParam, $fetchPropsLateParams);
		}
	}

	public static function fetch($st, int $fetchMode = DBFactory::FETCH_ASSOC, $fetchModeParam = 0, array $fetchPropsLateParams = []): array
	{
		$meta = $st->result_metadata();
		$response = [];
		if ($fetchMode === DBFactory::FETCH_COLUMN && is_int($fetchModeParam)) {
			while ($row = $meta->fetch_field_direct($fetchModeParam)) {
				array_push($response, $row);
			}
		} elseif ($fetchMode & DBFactory::FETCH_CLASS && is_string($fetchModeParam)) {
			while ($row = !empty($fetchPropsLateParams) ? $meta->fetch_object($fetchModeParam, $fetchPropsLateParams) : $meta->fetch_object($fetchModeParam)) {
				array_push($response, $row);
			}
		} elseif($fetchMode & DBFactory::FETCH_OBJ) {
			while ($row = $meta->fetch_object()) {
				array_push($response, $row);
			}
		} else {
			$response = $meta->fetch_all(MYSQLI_ASSOC);
		}

		return $response;
	}

	public static function bindParams(array &$params, &$st = null): bool
	{
		// if(preg_match_all('/:[\S]*/', $st->queryString) > count($params)) {
		// 	throw new BadMethodCallException("Not enough values to bind placeholders");
		// }

		$varTypes = '';
		foreach ($params as $value) {
			$varTypes .= is_bool($value) || is_int($value) ? 'i' : is_float($value) || is_double($value) ? 'd' : 's';
        }
		if (!$st->bind_param($varTypes, ...$params)) {
			return false;
		}

        return true;
	}

	public static function bindOutParams(&$params, &$st, &$outResult, int $maxLength = 40000): void
	{
		if (gettype($params) === 'array' && gettype($outResult) === 'array') {
			foreach ($params as $value) {
				$outResult[$value] = null;
			}
			$st->bind_result(...$outResult);
		} elseif (gettype($params) === 'string') {
			$outResult = null;
			$st->bind_result($outResult);
		} else {
			throw new BadMethodCallException('$params and $outResult must have same type');
		}
		
	}

	/**
	 * @param	string	$name	procedure name
	 * @param	string	$in		stringed input parameters
	 * @param	string	$out	stringed output parameters
	 * @return	string	composed procedure query string
	 */
	protected function constructProcedureString(string $name, string $in = '', string $out = ''): string
	{
		$parameters_string = $in . (strlen($in) > 0 && strlen($out) > 0 ? ', ' : '') . $out;
		$procedure_string = null;
		switch ($this->getAttribute(self::ATTR_DRIVER_NAME)) {
			case 'pgsql':
			case 'mysql':
				$procedure_string = "CALL ###name###(###params###);";
				break;
			case 'mssql':
				$procedure_string = "EXEC ###name### ###params###;";
				break;
			case 'oci':
				$procedure_string = "BEGIN ###name### (###params###); END;";
				break;
			default:
				$procedure_string = null;
		}

		if (is_null($procedure_string)) {
			throw new \Exception('Requested driver still not supported');
		}

		return str_replace(['###name###', '###params###'], [$name, $parameters_string], $procedure_string);
	}
}
