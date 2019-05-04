<?php
namespace Swolley\Database;

use
	\MongoDB\Client as MongoDB,
	\MongoDB\BSON as BSON,
	\MongoDB\Driver\Command as MongoCmd,
	\MongoDB\BSON\Javascript as MongoJs,
	\MongoDB\Driver\Exception as MongoException,
	\MongoLog;
use MongoDB\Exception\BadMethodCallException;
use MongoDB\Exception\UnexpectedValueException;

class MongoExtended extends MongoDB
{
	private $dbName;

	/**
	 * @param	array	$params	connection parameters
	 */
	public function __construct(array $params)
	{
		$params = self::validateParams($params);
		parent::__construct(self::constructConnectionString($params));
		$this->dbName = $params['dbName'];
	}

	public static function validateParams($params): array
	{
		//string $host, int $port, string $user, string $pass, string $dbName
		if (!isset($params['host'], $params['user'], $params['password'], $params['dbName'])) {
			throw new BadMethodCallException("host, user, password, dbName are required");
		} elseif (empty($params['host']) || empty($params['user']) || empty($params['password']) || empty($params['dbName'])) {
			throw new UnexpectedValueException("host, user, password, dbName can't be empty");
		}

		return $params;
	}

	public static function constructConnectionString(array $params, array $init_arr = []): string
	{
		return "mongodb://{$params['user']}:{$params['password']}@{$params['host']}:{$params['port']}/{$params['dbName']}";
	}

	///////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
	public function query(string $query, array $params = [], int $fetchMode = PDO::FETCH_ASSOC, $fetchModeParam = 0, array $fetchPropsLateParams = [])
	{
		$query = (new QueryBuilder)($query, $params);
		switch($query['type']) {
			case 'select':
				return $this->select($query['table'], $query['params'], $query['options'], $fetchMode, $fetchModeParam, $fetchPropsLateParams);
				break;
			case 'insert':
				return $this->insert($query['table'], $query['params'], $query['ignore']);
				break;
			case 'update':
				return $this->update($query['table'], $query['params'], $query['where']);
				break;
			case 'delete':	
				return $this->delete($query['table'], $query['params']);
				break;
		}
	}

	//function select(string $query, array $params = [], int $fetchMode = PDO::FETCH_ASSOC, $fetchModeParam = 0, array $fetchPropsLateParams = []);
	public function select(string $collection, array $search, array $options = [], int $fetchMode = DBFactory::FETCH_ASSOC, $fetchModeParam = 0, array $fetchPropsLateParams = [])
	{
		try {
			foreach ($search as &$param) {
				$param = filter_var($param);
			}

			//FIXME does options need to be filtered???
			/*foreach ($options as $key => &$param) {
				$param = filter_var($param);
			}*/

			$response = $this->{$this->dbName}->{$collection}->find($search, $options);
			switch ($fetchMode) {
				case DBFactory::FETCH_ASSOC:
					$response->setTypeMap(['root' => 'array', 'document' => 'array', 'array' => 'array']);
					break;
				case DBFactory::FETCH_OBJ:
					$response->setTypeMap(['root' => 'object', 'document' => 'object', 'array' => 'array']);
					break;
					//case DBFactory::FETCH_CLASS:
					//	$response->setTypeMap([ 'root' => 'object', 'document' => $fetchModeParam, 'array' => 'array' ]);
					//	break;
				default:
					throw new MongoException\CommandException('Can fetch only Object or Associative Array');
			}

			return $response->toArray();
		} catch (MongoException $e) {
			return error_reporting() === E_ALL ? $e->getMessage() : 'Error while querying db';
		} catch (\Exception $e) {
			return error_reporting() === E_ALL ? $e->getMessage() : 'Internal server error';
		}
	}

	/**
	 * execute insert query
	 * @param   string  $collection     collection name
	 * @param   array|object   $params	assoc array with placeholder's name and relative values
	 * @param   boolean $ignore         performes an 'insert ignore' query
	 * @return  mixed                   new row id or error message
	 */
	public function insert(string $collection, $params, bool $ignore = false)
	{
		$paramsType = gettype($params);
		if ($paramsType !== 'array' && $paramsType !== 'object') {
			throw new \UnexpectedValueException('$params can be only array or object');
		}

		if ($paramsType === 'object') {
			$params = (array)$params;
		}

		try {
			foreach ($params as &$param) {
				$param = filter_var($param);
			}

			return $this->{$this->dbName}->{$collection}
				->insertOne($params, ['ordered' => !$ignore])
				->getInsertedId()['oid'];
		} catch (MongoException $e) {
			return error_reporting() === E_ALL ? $e->getMessage() : 'Error while querying db';
		} catch (\Exception $e) {
			return error_reporting() === E_ALL ? $e->getMessage() : 'Internal server error';
		}
	}

	/**
	 * execute update query. Where is required, no massive update permitted
	 * @param   string  $collection     collection name
	 * @param   array|object   $params         assoc array with placeholder's name and relative values
	 * @param   array   $where          where condition. no placeholders permitted
	 * @return  mixed                   correct query execution confirm as boolean or error message
	 */
	//function update(string $table, $params, string $where);
	public function update(string $collection, $params, array $where)
	{
		$paramsType = gettype($params);
		if ($paramsType !== 'array' && $paramsType !== 'object') {
			throw new \UnexpectedValueException('$params can be only array or object');
		}

		if ($paramsType === 'object') {
			$params = (array)$params;
		}

		try {
			foreach ($params as &$param) {
				$param = filter_var($param);
			}

			foreach ($where as &$param) {
				$param = filter_var($param);
			}

			return $this->{$this->dbName}->{$collection}
				->updateMany($where, ['$set' => $params], ['upsert' => FALSE])
				->getModifiedCount();
		} catch (MongoException $e) {
			return error_reporting() === E_ALL ? $e->getMessage() : 'Error while querying db';
		} catch (\Exception $e) {
			return error_reporting() === E_ALL ? $e->getMessage() : 'Internal server error';
		}
	}

	/**
	 * execute delete query. Where is required, no massive delete permitted
	 * @param   string  $collection     collection name
	 * @param   array   $where          where condition with placeholders
	 * @return  mixed                   correct query execution confirm as boolean or error message
	 */
	//function delete(string $table, string $where, array $params);
	public function delete(string $collection, array $where)
	{
		try {
			foreach ($where as &$param) {
				$param = filter_var($param);
			}

			$result = $this->{$this->dbName}->{$collection}
				->deleteMany($where);

			return $result->getDeletedCount() ? TRUE : FALSE;
		} catch (MongoException $e) {
			return error_reporting() === E_ALL ? $e->getMessage() : 'Error while querying db';
		} catch (\Exception $e) {
			return error_reporting() === E_ALL ? $e->getMessage() : 'Internal server error';
		}
	}

	/**
	 * execute stored procedure
	 * @param   string  $name           stored procedure name
	 * @param   array   $params         (optional) assoc array with paramter's names and relative values
	 * @return  mixed                   stored procedure result or error message
	 */
	public function procedure(string $name, array $params = [])
	{
		//TODO to be tested
		try {
			foreach ($params as &$param) {
				$param = filter_var($param);
			}

			$jscode = new MongoJs('return db.eval("return ' . $name . '(' . implode(array_values($params)) . ');');
			$command = new MongoCmd(['eval' => $jscode]);
			return $this->executeCommand($this->dbName, $command);
		} catch (MongoException $e) {
			return error_reporting() === E_ALL ? $e->getMessage() : 'Error while querying db';
		} catch (\Exception $e) {
			return error_reporting() === E_ALL ? $e->getMessage() : 'Internal server error';
		}
	}
}
