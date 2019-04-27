<?php
namespace Swolley\Database;

interface IConnectable
{
	/**
	 * @param	array	$params	connection parameters
	 * @return  array	parsed and validated parameters
	 * @throws	BadMethodCallException	if missing parameters
	 * @throws	UnexpectedValueException if no requested driver available
	 */
	static function validateParams($params): array;

	/**
	 * @param	array	$params	connection parameters
	 * @return	string	connection string with tns for oci driver
	 * @throws	BadMethodCallException	if missing parameters
	 */
	static function constructConnectionString(array $params, array $init_Array = []): string;

	/**
     * execute generic query
     * @param   string  	$query          	query text with placeholders
     * @param   array   	$params         	assoc array with placeholder's name and relative values
	 * @param   int     	$fetchMode     		(optional) PDO fetch mode. default = associative array
     * @param	int|string	$fetchModeParam		(optional) fetch mode param (ex. integer for FETCH_COLUMN, strin for FETCH_CLASS)
	 * @param	int|string	$fetchModePropsLateParams		(optional) fetch mode param to class contructor
     * @return  mixed							response array or error message
     */
	function query(string $query, array $params = [], int $fetchMode = PDO::FETCH_ASSOC, $fetchModeParam = 0, array $fetchPropsLateParams = []);
	
	/**
     * execute insert query
     * @param   string  $table          table name
     * @param   array   $params         assoc array with placeholder's name and relative values
     * @param   boolean $ignore         performes an 'insert ignore' query
     * @return  mixed                   new row id or error message
     */
	function insert(string $table, array $params, bool $ignore = false);
	
	/**
     * execute update query. Where is required, no massive update permitted
     * @param   string  $table          table name
     * @param   array   $params         assoc array with placeholder's name and relative values
     * @param   string  $where          where condition. no placeholders permitted
     * @return  mixed                   correct query execution confirm as boolean or error message
     */
	function update(string $table, array $params, string $where): bool;
	
	/**
     * execute delete query. Where is required, no massive delete permitted
     * @param   string  $table          table name
     * @param   string  $where          where condition with placeholders
     * @param   array   $params         assoc array with placeholder's name and relative values for where condition
     * @return  mixed                   correct query execution confirm as boolean or error message
     */
	function delete(string $table, string $where, array $params): bool;
	
	/**
     * execute procedure call.
     * @param   string  $table          procedure name
     * @param   array  	$inParams       array of input parameters
     * @param   array  	$outParams      array of output parameters
	 * @param   int     	$fetchMode     		(optional) PDO fetch mode. default = associative array
     * @param	int|string	$fetchModeParam		(optional) fetch mode param (ex. integer for FETCH_COLUMN, strin for FETCH_CLASS)
     * @param	int|string	$fetchModePropsLateParams		(optional) fetch mode param to class contructor
     * @return  mixed							response array or error message
     */
	public function procedure(string $name, array $inParams = [], array $outParams = [], int $fetchMode = PDO::FETCH_ASSOC, $fetchModeParam = 0, array $fetchPropsLateParams = []);
}
