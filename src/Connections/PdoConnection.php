<?php

namespace Swolley\YardBird\Connections;

use Swolley\YardBird\Interfaces\IRelationalConnectable;
use Swolley\YardBird\Utils\Utils;
use Swolley\YardBird\Utils\QueryBuilder;
use Swolley\YardBird\Exceptions\ConnectionException;
use Swolley\YardBird\Exceptions\QueryException;
use Swolley\YardBird\Exceptions\BadMethodCallException;
use Swolley\YardBird\Exceptions\UnexpectedValueException;
use Swolley\YardBird\Result;
use Swolley\YardBird\Interfaces\TraitDatabase;

class PdoConnection extends \PDO implements IRelationalConnectable
{
	use TraitDatabase;

	/**
	 * @param	array	$params	connection parameters
	 * @param	bool	$debugMode	debug mode
	 */
	public function __construct(array $params, bool $debugMode = false)
	{
		$parsed_params = self::validateConnectionParams($params);
		$this->setInfo($params, $debugMode);

		try {
			parent::__construct($parsed_params['driver'] === 'oci' ? self::getOciString($parsed_params) : self::getDefaultString($parsed_params), $parsed_params['user'], $parsed_params['password']);
			if (error_reporting() === E_ALL) {
				parent::setAttribute(self::ATTR_ERRMODE, self::ERRMODE_EXCEPTION);
			}
		} catch (\PDOException $e) {
			throw new ConnectionException($e->getMessage(), $e->getCode(), $e);
		}
	}

	public static function validateConnectionParams(array $params): array
	{
		if (!in_array($params['driver'], self::getAvailableDrivers())) {
			throw new UnexpectedValueException("No {$params['driver']} driver available");
		} elseif (!isset($params['host'], $params['user'], $params['password'], $params['dbName']) || empty($params['dbName']) || empty($params['host']) || empty($params['user']) || empty($params['password'])) {
			throw new UnexpectedValueException("host, user, password are required");
		} elseif ($params['driver'] === 'oci' && ((!isset($params['sid']) || empty($params['sid']))	&& (!isset($params['serviceName']) || empty($params['serviceName'])))) {
			throw new UnexpectedValueException("sid or serviceName must be specified");
		}

		//defaults
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
		$params['charset'] = $params['charset'] ?? 'UTF8';
		return $params;
	}

	public function sql(string $query, $params = []): Result
	{
		$query = Utils::trimQueryString($query);
		$params = (array) $params;
		//if postgres && has a different meaning than OR
		if ($this->getAttribute(self::ATTR_DRIVER_NAME) !== 'oci') {
			$query = QueryBuilder::operatorsToStandardSyntax($query);
		}

		try {
			$stmt = $this->prepare($query);
			if (!self::bindParams($params, $stmt)) {
				throw new UnexpectedValueException('Cannot bind parameters');
			} elseif (!$stmt->execute()) {
				$error = $stmt->errorInfo();
				$this->rollback();
				throw new QueryException("{$error[0]}: {$error[2]}" . ($this->_debugMode ? PHP_EOL . $stmt->debugDumpParams() : ''), $error[0]);
			}

			return new Result($stmt, strtolower(explode(' ', $query)[0]));
		} catch (\PDOException $e) {
			$this->rollback();
			throw new QueryException($e->getMessage(), $e->getCode(), $e);
		}
	}

	public function select(string $table, array $fields = [], array $where = [], array $join = [], array $orderBy = [], $limit = null): Result
	{
		try {
			$builder = new QueryBuilder;
			$stmt = $this->prepare('SELECT ' . $builder->fieldsToSql($fields) . " FROM `$table` " . $builder->joinsToSql($join) . ' ' . $builder->whereToSql($where) . ' ' . $builder->orderByToSql($orderBy) . ' ' . $builder->limitToSql($limit));
			if (!empty($where) && !self::bindParams($where, $stmt)) {
				throw new UnexpectedValueException('Cannot bind parameters');
			} elseif (!$stmt->execute()) {
				$error = $stmt->errorInfo();
				$this->rollback();
				throw new QueryException("{$error[0]}: {$error[2]}" . ($this->_debugMode ? PHP_EOL . $stmt->debugDumpParams() : ''), $error[0]);
			}

			return new Result($stmt, 'select');
		} catch (\PDOException $e) {
			$this->rollback();
			throw new QueryException($e->getMessage(), $e->getCode(), $e);
		}
	}

	public function insert(string $table, $params, bool $ignore = false): Result
	{
		$params = (array) $params;
		try {
			$keys_list = array_keys($params);
			$keys = '`' . implode('`, `', $keys_list) . '`';
			$values = ':' . implode(', :', $keys_list);
			$driver = $this->getAttribute(self::ATTR_DRIVER_NAME);
			$stmt = null;
			switch ($driver) {
				case 'mysql':
					$stmt = $this->prepare('INSERT ' . ($ignore ? 'IGNORE ' : '') . "INTO `$table` ($keys) VALUES ($values)");
					break;
				case 'oci':
					$stmt = $this->prepare("BEGIN INSERT INTO `$table` ($keys) VALUES ($values)" . ($ignore ? ' EXCEPTION WHEN dup_val_on_index THEN null' : '') . '; END;');
					break;
			}

			if ($stmt === null) {
				throw new \Exception('Requested driver still not supported');
			} elseif (!self::bindParams($params, $stmt)) {
				throw new UnexpectedValueException('Cannot bind parameters');
			} elseif (!$stmt->execute()) {
				$error = $stmt->errorInfo();
				$this->rollback();
				throw new QueryException("{$error[0]}: {$error[2]}" . ($this->_debugMode ? PHP_EOL . $stmt->debugDumpParams() : ''), $error[0]);
			}

			return new Result($stmt, 'insert', $this->inTransaction() ? $this->lastInsertId() : null);
		} catch (\PDOException $e) {
			$this->rollback();
			throw new QueryException($e->getMessage(), $e->getCode(), $e);
		}
	}

	public function update(string $table, $params, $where = null): Result
	{
		$builder = new QueryBuilder;
		$params = (array) $params;
		if ($where !== null && !is_string($where)) {
			throw new UnexpectedValueException('$where param must be of type string');
		} elseif ($where !== null && $this->getAttribute(self::ATTR_DRIVER_NAME) !== 'oci') {
			//in postgres && has a different meaning than OR
			$where = $builder->operatorsToStandardSyntax($where);
		}

		//TODO how to bind where clause?

		try {
			$values = $builder->valuesListToSql($params);
			$stmt = $this->prepare("UPDATE `$table` SET $values" . ($where !== null ? " WHERE $where" : ''));
			if (!self::bindParams($params, $stmt)) {
				throw new UnexpectedValueException('Cannot bind parameters');
			} elseif (!$stmt->execute()) {
				$error = $stmt->errorInfo();
				$this->rollback();
				throw new QueryException("{$error[0]}: {$error[2]}" . ($this->_debugMode ? PHP_EOL . $stmt->debugDumpParams() : ''), $error[0]);
			}

			return new Result($stmt, 'update');
		} catch (\PDOException $e) {
			$this->rollback();
			throw new QueryException($e->getMessage(), $e->getCode(), $e);
		}
	}

	public function delete(string $table, $where = null, array $params = null): Result
	{
		if ($where !== null && !is_string($where)) {
			throw new UnexpectedValueException('$where param must be of type string');
		} elseif ($where !== null && $this->getAttribute(self::ATTR_DRIVER_NAME) !== 'oci') {
			//in postgres && has a different meaning than OR
			$where = QueryBuilder::operatorsToStandardSyntax($where);
		}

		try {
			$stmt = $this->prepare("DELETE FROM `$table`" . ($where !== null ? " WHERE $where" : ''));
			if ($params !== null && !self::bindParams($params, $stmt)) {
				throw new UnexpectedValueException('Cannot bind parameters');
			} elseif (!$stmt->execute()) {
				$error = $stmt->errorInfo();
				$this->rollback();
				throw new QueryException("{$error[0]}: {$error[2]}" . ($this->_debugMode ? PHP_EOL . $stmt->debugDumpParams() : ''), $error[0]);
			}

			return new Result($stmt, 'delete');
		} catch (\PDOException $e) {
			$this->rollback();
			throw new QueryException($e->getMessage(), $e->getCode(), $e);
		}
	}

	public function procedure(string $name, array $inParams = [], array $outParams = [])
	{
		try {
			$procedure_in_params = rtrim(array_reduce($inParams, function ($sum, $key) {
				return $sum .= ":$key, ";
			}, ''), ', ');
			$procedure_out_params = rtrim(array_reduce($outParams, function ($sum, $value) {
				return $sum .= ":$value, ";
			}, ''), ', ');

			$parameters_string = $procedure_in_params . (strlen($procedure_in_params) > 0 && strlen($procedure_out_params) > 0 ? ', ' : '') . $procedure_out_params;
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
			}

			if ($procedure_string === null) throw new \Exception('Requested driver still not supported');
			$stmt = $this->prepare(str_replace(['###name###', '###params###'], [$name, $parameters_string], $procedure_string));
			if (!self::bindParams($inParams, $stmt)) throw new UnexpectedValueException('Cannot bind parameters');

			$outResult = [];
			self::bindOutParams($outParams, $stmt, $outResult);
			if (!$stmt->execute()) {
				$error = $stmt->errorInfo();
				$this->rollback();
				throw new QueryException("{$error[0]}: {$error[2]}" . ($this->_debugMode ? PHP_EOL . $stmt->debugDumpParams() : ''), $error[0]);
			}

			return count($outParams) > 0 ? $outResult : new Result($stmt, 'procedure');
		} catch (\PDOException $e) {
			$this->rollBack();
			throw new QueryException($e->getMessage(), $e->getCode(), $e);
		}
	}

	public function showTables(): array
	{
		$driver = $this->getAttribute(self::ATTR_DRIVER_NAME);
		$query = null;
		switch ($driver) {
			case 'mysql':
				$query = 'SHOW TABLES';
				break;
			case 'oci':
				$query = "SELECT * FROM tab WHERE  TNAME NOT LIKE 'BIN$%'";
				break;
			case 'mssql':
				$query = 'SELECT Distinct TABLE_NAME FROM information_schema.TABLES';
				break;
			case 'pgsql':
				$query = "SELECT * FROM pg_catalog.pg_tables WHERE table_schema = 'public'";
				break;
		}

		if ($query === null) throw new \Exception('Requested driver still not supported');

		return array_map(function ($table) use ($driver) {
			switch ($driver) {
				case 'mysql':
					return array_values($table)[0];
				case 'oci':
					return $table['TNAME'];
				default:
					throw new \Exception('Requested driver still not supported');
			}
		}, $this->sql($query)->fetch());
	}

	public function showColumns($tables)
	{
		if (is_string($tables)) {
			$tables = [$tables];
		} elseif (!is_array($tables)) {
			throw new UnexpectedValueException('Table name must be string or array of strings');
		}

		$driver = $this->getAttribute(self::ATTR_DRIVER_NAME);
		$query = null;
		switch ($driver) {
			case 'mysql':
				$query = "SHOW COLUMNS FROM ###name###";
				break;
			case 'oci':
				$query = "SELECT * FROM user_tab_cols WHERE table_name = '###name###'";
				break;
		}

		if ($query === null) throw new \Exception('Requested driver still not supported');

		$columns = [];
		foreach ($tables as $table) {
			$cur = $this->sql(str_replace('###name###', $table, $query))->fetch();
			$columns[$table] = [];
			foreach ($cur as $column) {
				$column_name = null;
				$column_data = null;

				switch ($driver) {
					case 'mysql':
						$column_name = $column['Field'];
						$column_data = [
							'type' => strtolower($column['Type']),
							'nullable' => $column['Null'] === 'YES',
							'default' => $column['Default']
						];
						break;
					case 'oci':
						$column_name = $column['COLUMN_NAME'];
						$column_data = [
							'type' => strtolower($column['DATA_TYPE']),
							'nullable' => $column['NULLABLE'] === 'Y',
							'default' => $column['DATA_DEFAULT']
						];
				}

				if (!isset($column_name, $column_data)) throw new \Exception('Requested driver still not supported');
				$columns[$table][$column_name] = $column_data;
			}
		}

		return $columns;
	}

	public static function bindParams(array &$params, &$stmt = null): bool
	{
		foreach ($params as $key => $value) {
			$varType = $value === null ? self::PARAM_NULL : (is_bool($value) ? self::PARAM_BOOL : (is_int($value) ? self::PARAM_INT : self::PARAM_STR));
			if (!$stmt->bindValue(":$key", $value, $varType)) {
				return false;
			}
		}

		return true;
	}

	public static function bindOutParams(&$params, &$stmt, &$outResult, int $maxLength = 40000): void
	{
		if (is_array($params) && is_array($outResult)) {
			foreach ($params as $value) {
				$outResult[$value] = null;
				$stmt->bindParam(":$value", $outResult[$value], self::PARAM_STR | self::PARAM_INPUT_OUTPUT, $maxLength);
			}
		} elseif (is_string($params)) {
			$outResult = null;
			$stmt->bindParam(":$value", $outResult[$value], self::PARAM_STR | self::PARAM_INPUT_OUTPUT, $maxLength);
		} else {
			throw new BadMethodCallException('$params and $outResult must have same type');
		}
	}

	public function transaction(): bool
	{
		return !$this->inTransaction() ? parent::beginTransaction() : false;
	}

	public function commit(): bool
	{
		return $this->inTransaction() ? parent::commit() : false;
	}

	public function rollback(): bool
	{
		return $this->inTransaction() ? $this->rollBack() : false;
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
	 * @throws	BadMethodCallException	if missing parameters
	 */
	private static function getOciString(array $params): string
	{
		$connect_data_name = $params['sid'] ? 'sid' : ($params['serviceName'] ? 'serviceName' : null);
		if ($connect_data_name === null) throw new BadMethodCallException("Missing paramters");
		$connect_data_value = $params[$connect_data_name];
		$tns = preg_replace("/\n\r|\n|\r|\n\r|\t|\s/", '', "(DESCRIPTION = (ADDRESS_LIST = (ADDRESS = (PROTOCOL = TCP)(HOST = {$params['host']})(PORT = {$params['port']}))) (CONNECT_DATA = (" . strtoupper(preg_replace('/(?<!^)[A-Z]/', '_$0', $connect_data_name)) . ' = ' . $connect_data_value	. ")))");
		return "oci:dbname={$tns};charset={$params['charset']}";
	}
}
