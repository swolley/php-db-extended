<?php
namespace Swolley\Database\Interfaces;

interface IRelationalConnectable extends IConnectable
{
	static function validateConnectionParams($params): array;

	static function constructConnectionString(array $params, array $init_Array = []): string;

	function sql(string $query, $params = [], int $fetchMode = PDO::FETCH_ASSOC, $fetchModeParam = 0, array $fetchPropsLateParams = []);
	
	function select(string $table, array $params = [], array $where = [], int $fetchMode = DBFactory::FETCH_ASSOC, $fetchModeParam = 0, array $fetchPropsLateParams = []);

	function insert(string $table, $params, bool $ignore = false);
	
	function update(string $table, $params, string $where): bool;
	
	function delete(string $table, string $where, array $params): bool;
	
	function procedure(string $name, array $inParams = [], array $outParams = [], int $fetchMode = PDO::FETCH_ASSOC, $fetchModeParam = 0, array $fetchPropsLateParams = []);

	static function bindParams(array &$params, &$st = null);

	/**
	 * bind out params by reference with custom parameters depending by driver
	 * @param	mixed	$params			parameters to be binded
	 * @param	mixed	$st				statement. Mongo has no statement
	 * @param 	mixed	$outResult		reference to variable that will contain out values
	 * @param	int		$maxLength		max $outResultRef length
	 */
	static function bindOutParams(&$params, &$st, &$outResult, int $maxLength = 40000);
}
