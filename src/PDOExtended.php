<?php
namespace Swolley\Database;

use \PDO;

final class PDOExtended extends PDO implements IConnectable
{
	use TraitUtils;

	/**
	 * @param	array	$params	connection parameters
	 */
	public function __construct(array $params)
	{
		$params = self::validateParams($params);
		parent::__construct(self::constructConnectionString($params), $params['user'], $params['password']);

		if (error_reporting() === E_ALL) {
			parent::setAttribute(self::ATTR_ERRMODE, self::ERRMODE_EXCEPTION);
		}
	}

	public static function validateParams($params): array
	{
		if (!in_array($params['driver'], self::getAvailableDrivers())) {
			throw new \UnexpectedValueException("No {$params['driver']} driver available");
		}

		if (!isset($params['host'], $params['user'], $params['password'])) {
			throw new \BadMethodCallException("host, user, password are required");
		}elseif (empty($params['host']) || empty($params['user']) || empty($params['password'])) {
			throw new \UnexpectedValueException("host, user, password can't be empty");
		}

		//default ports
		if (!isset($params['port'])) {
			switch ($params['driver']) {
				case 'mysql':
					$params['port'] = 3306;
					break;
				case 'pgsql':
					$params['port'] = 5432;
				case 'mssql';
					$params['port'] = 1433;
				case 'oci':
					$params['port'] = 1521;
			}
		}

		//default charset
		if (!isset($params['charset'])) {
			$params['charset'] = 'UTF8';
		}

		/////////////////////////////////////////////////////////////
		if($params['driver'] !== 'oci' && !isset($params['dbName'])) {
			throw new \BadMethodCallException("dbName is required");
		} elseif ($params['driver'] !== 'oci' && empty($params['dbName'])) {
			throw new \UnexpectedValueException("dbName can't be empty");
		}
		
		if($params['driver'] === 'oci' && (!isset($params['sid']) || empty($params['sid']))	&& (!isset($params['serviceName']) || empty($params['serviceName']))) {
			throw new \BadMethodCallException("sid or serviceName must be specified");
		}
		
		return $params;
	}

	public static function constructConnectionString(array $params, array $init_arr = []): string
	{
		return $params['driver'] === 'oci' 
			? self::getOciString($params)
			: self:: getDefaultString($params);
	}

	/**
	 * @param	array	$params	connection parameters
	 * @return	string	connection string for main drivers
	 */
	private static function getDefaultString(array $params): string
	{
		return "{$params['driver']}:host={$params['host']};port={$params['port']};dbname={$params['dbName']};charset={$params['charset']}";
	}

	/**
	 * @param	array	$params	connection parameters
	 * @return	string	connection string with tns for oci driver
	 * @throws	\BadMethodCallException	if missing parameters
	 */
	private static function getOciString(array $params): string
	{
		$connect_data_name = $params['sid'] ? 'sid' : ($params['serviceName'] ? 'serviceName' : null);
		
		if(is_null($connect_data_name)) {
			throw new \BadMethodCallException("Missing paramters");
		}

		$connect_data_value = $params[$connect_data_name];

		$tns = preg_replace("/\n|\r|\n\r|\t/", '', "
			(DESCRIPTION = 
				(ADDRESS_LIST = 
					(ADDRESS = (PROTOCOL = TCP)(HOST = {$params['host']})(PORT = {$params['port']}))
				)
				(CONNECT_DATA = 
					(" . strtoupper(preg_replace('/(?<!^)[A-Z]/', '_$0', $connect_data_name)) . ' = ' . $connect_data_value	. ")
				)
			)"
		);
		
		return "oci:dbname={$tns};charset={$params['charset']}";	
	}

	public function query(string $query, $params = [], int $fetchMode = self::FETCH_ASSOC, $fetchModeParam = 0, array $fetchPropsLateParams = [])
	{
		$params = self::castParamsToArray($params);

		try {
			ksort($params);
			$st = $this->prepare($query);
			self::bindParams($params, $st);
			$st->execute();
			if (($fetchMode === self::FETCH_COLUMN && is_int($fetchModeParam)) || ($fetchMode & self::FETCH_CLASS && is_string($fetchModeParam))) {
				return $fetchMode & self::FETCH_PROPS_LATE
					? $st->fetchAll($fetchMode, $fetchModeParam, $fetchPropsLateParams)
					: $st->fetchAll($fetchMode, $fetchModeParam);
			} else {
				return $st->fetchAll($fetchMode);
			}
		} catch (\PDOException $e) {
			return error_reporting() === E_ALL ? $e->getMessage() : 'Error while querying db';
		} catch (\Exception $e) {
			return error_reporting() === E_ALL ? $e->getMessage() : 'Internal server error';
		}
	}

	public function insert(string $table, $params, bool $ignore = false)
	{
		$params = self::castParamsToArray($params);

		try {
			ksort($params);
			$keys = implode(',', array_keys($params));
			$values = ':' . implode(', :', array_keys($params));

			$this->beginTransaction();

			$driver = $this->getAttribute(self::ATTR_DRIVER_NAME);
			$st = null;
			switch($driver) {
				case 'mysql':
					$st = $this->prepare('INSERT ' . ($ignore ? 'IGNORE ' : '') . "INTO {$table} ({$keys}) VALUES ({$values})");
					break;
				case 'oci':
					$st = $this->prepare("BEGIN INSERT INTO {$table} ({$keys}) VALUES ({$values}); EXCEPTION WHEN dup_val_on_index THEN null; END;");
					break;
				default:
					$st = null;
			}

			if(is_null($st)) {
				throw new \Exception('Requested driver still not supported');
			}
			
			self::bindParams($params, $st);
			$st->execute();
			$inserted_id = $this->lastInsertId();
			$this->commit();

			return $inserted_id;
		} catch (\PDOException $e) {
			return error_reporting() === E_ALL ? $e->getMessage() : 'Error while querying db';
		} catch (\Exception $e) {
			return error_reporting() === E_ALL ? $e->getMessage() : 'Internal server error';
		}
	}

	public function update(string $table, $params, string $where): bool
	{
		$params = self::castParamsToArray($params);

		try {
			ksort($params);
			$values = '';
			foreach ($params as $key => $value) {
				$values .= "`$key`=:$key";
			}
			$field_details = rtrim($values, ', ');

			$st = $this->prepare("UPDATE {$table} SET {$values} WHERE {$where}");
			self::bindParams($params, $st);
			return $st->execute();
		} catch (\PDOException $e) {
			return error_reporting() === E_ALL ? $e->getMessage() : 'Error while querying db';
		} catch (\Exception $e) {
			return error_reporting() === E_ALL ? $e->getMessage() : 'Internal server error';
		}
	}

	public function delete(string $table, string $where, array $params): bool
	{
		try {
			ksort($params);
			$st = $this->prepare("DELETE FROM {$table} WHERE {$where}");
			self::bindParams($params, $st);
			return $st->execute();
		} catch (\PDOException $e) {
			return error_reporting() === E_ALL ? $e->getMessage() : 'Error while querying db';
		} catch (\Exception $e) {
			return error_reporting() === E_ALL ? $e->getMessage() : 'Internal server error';
		}
	}

	public function procedure(string $name, array $inParams = [], array $outParams = [], int $fetchMode = self::FETCH_ASSOC, $fetchModeParam = 0, array $fetchPropsLateParams = [])
	{
		try {
			//input params
			$procedure_in_params = '';
			foreach ($inParams as $key => $value) {
				$procedure_in_params .= ":$key,";
			}
			$procedure_in_params = rtrim($procedure_in_params, ', ');

			//output params
			$procedure_out_params = '';
			foreach ($outParams as $value) {
				$procedure_out_params .= ":$value,";
			}
			$procedure_out_params = rtrim($procedure_out_params, ', ');

			$st = $this->prepare($this->constructProcedureString($name, $procedure_in_params, $procedure_out_params));
			self::bindParams($inParams, $st);
			$outResult = self::bindOutParams($outParams, $st);
			$st->execute();

			if (count($outParams) > 0) {
				return $outResult;
			} elseif (($fetchMode === self::FETCH_COLUMN && is_int($fetchModeParam)) || ($fetchMode & self::FETCH_CLASS && is_string($fetchModeParam))) {
				return $fetchMode & self::FETCH_PROPS_LATE
					? $st->fetchAll($fetchMode, $fetchModeParam, $fetchPropsLateParams)
					: $st->fetchAll($fetchMode, $fetchModeParam);
			} else {
				return $st->fetchAll($fetchMode);
			}
		} catch (\PDOException $e) {
			return error_reporting() === E_ALL ? $e->getMessage() : 'Error while querying db';
		} catch (\Exception $e) {
			return error_reporting() === E_ALL ? $e->getMessage() : 'Internal server error';
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
		switch($this->getAttribute(self::ATTR_DRIVER_NAME)) {
			case 'pgsql':
			case 'mysql':
				$procedure_string = "CALL ###name###(###params###);";
				break;
			case 'mssql':
				$procedure_string = "EXEC ###name### ###params###;";
				break;
			case 'oci':
				$procedure_string = "BEGIN ###name### (###params###);";
				break;
			default:
				$procedure_string = null;
		}

		if(is_null($procedure_string)) {
			throw new \Exception('Requested driver still not supported');
		}

		return str_replace(['###name###', '###params###'], [$name, $parameters_string], $procedure_string);
	}

	protected static function bindParams(array &$params, &$st = null)
	{
		foreach ($params as $key => $value) {
			$st->bindValue(":$key", $value);
		}
	}

	protected static function bindOutParams(array &$params, &$st = null): array
	{
		$outResult = [];
		foreach ($params as $value) {
			$outResult[$value] = null;
			$st->bindParam(":$value", $outResult[$value], self::PARAM_STR | self::PARAM_INPUT_OUTPUT, 4000);
		}
		return $outResult;
	}
}
