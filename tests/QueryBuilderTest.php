<?php
declare (strict_types = 1);

use PHPUnit\Framework\TestCase;
use Swolley\YardBird\Utils\QueryBuilder;
use Swolley\YardBird\Exceptions\QueryException;
use Swolley\YardBird\Exceptions\BadMethodCallException;
use Swolley\YardBird\Exceptions\UnexpectedValueException;

/**
 * @covers Swolley\YardBird\Utils\QueryBuilder
 */
final class QueryBuilderTest extends TestCase
{
	///////////////////////////////// UNIT ////////////////////////////////////////////////
	/**
	 * @covers Swolley\YardBird\Utils\QueryBuilder::sqlToMongo
	 * @group unit
	 */
	public function test_sqlToMongo_should_return_exception_if_not_recognized_a_valid_query(): void
	{
		$this->expectException(UnexpectedValueException::class);
		(new QueryBuilder)->sqlToMongo('invalid query');
	}

	/**
	 * @covers Swolley\YardBird\Utils\QueryBuilder::sqlToMongo
	 * @group unit
	 */
	public function test_sqlToMongo_should_return_object_if_parameters_are_correct(): void
	{
		$queryBuilder = new QueryBuilder();
		$query = $queryBuilder->sqlToMongo('SELECT id FROM table WHERE `id`=1');
		$this->assertTrue(is_object($query));
		$this->assertEquals('select', $query->type);
		$this->assertEquals('table', $query->table);
		$this->assertEquals(['id' => ['$eq' => 1]], $query->filter);
		$this->assertEquals(['projection' => ['id' => 1, '_id' => 0]], $query->options);

		$query = $queryBuilder->sqlToMongo('INSERT INTO table(`a`, b) VALUES(:a, :b)', ['a' => 1, 'b' => 2]);
		$this->assertTrue(is_object($query));
		$this->assertEquals('insert', $query->type);
		$this->assertEquals('table', $query->table);
		$this->assertEquals(['a' => 1, 'b' => 2], $query->params);
		$this->assertTrue(empty($query->options));

		$query = $queryBuilder->sqlToMongo('UPDATE table SET a=:a, `b`=:b WHERE id=1', ['a' => 1, 'b' => 2]);
		$this->assertTrue(is_object($query));
		$this->assertEquals('update', $query->type);
		$this->assertEquals('table', $query->table);
		$this->assertEquals(['a' => 1, 'b' => 2], $query->params);
		$this->assertEquals(['id' => ['$eq' => 1]], $query->where);
		$this->assertTrue(empty($query->options));

		$query = $queryBuilder->sqlToMongo('DELETE FROM `table` WHERE `id`=1');
		$this->assertTrue(is_object($query));
		$this->assertEquals('delete', $query->type);
		$this->assertEquals('table', $query->table);
		$this->assertEquals(['id' => ['$eq' => 1]], $query->params);
		$this->assertTrue(empty($query->options));

		$query = $queryBuilder->sqlToMongo('CALL procedure(1, 2)');
		$this->assertTrue(is_object($query));
		$this->assertEquals('procedure', $query->type);
		$this->assertEquals('procedure', $query->name);
		$this->assertEquals(['param1' => 1, 'param2' => 2], $query->params);
		$this->assertTrue(empty($query->options));
	}

	/**
	 * @covers Swolley\YardBird\Utils\QueryBuilder::sqlInsertToMongo
	 * @group unit
	 */
	public function test_sqlInsertToMongo_should_throw_exception_if_any_syntax_error(): void
	{
		$queryBuilder = new QueryBuilder();
		$this->expectException(UnexpectedValueException::class);
		$queryBuilder->sqlToMongo('INSERT table');

		$this->expectException(UnexpectedValueException::class);
		$queryBuilder->sqlToMongo('INSERT INTO table');
	}

	/**
	 * @covers Swolley\YardBird\Utils\QueryBuilder::sqlInsertToMongo
	 * @group unit
	 */
	public function test_sqlInsertToMongo_should_throw_exception_if_columns_count_differs_from_values(): void
	{
		$this->expectException(\Exception::class);
		(new QueryBuilder)->sqlInsertToMongo('INSERT INTO table (`column1`, `missingColumn`) VALUES(:column)');
	}

	/**
	 * @covers Swolley\YardBird\Utils\QueryBuilder::sqlInsertToMongo
	 * @group unit
	 */
	public function test_sqlInsertToMongo_shold_return_object_if_parameters_are_correct(): void
	{
		$query = (new QueryBuilder)->sqlInsertToMongo('INSERT IGNORE INTO table (`column1`) VALUES(:column)');
		$response = (object)[
			'type' => 'insert',
			'table' => 'table',
			'params' => ['column1' => ':column'],
			'ignore' => true
		];
		$this->assertEquals($response, $query);
	}

	/**
	 * @covers Swolley\YardBird\Utils\QueryBuilder::sqlDeleteToMongo
	 * @group unit
	 */
	public function test_sqlDeleteToMongo_should_throw_exception_if_any_syntax_error(): void
	{
		$this->expectException(UnexpectedValueException::class);
		(new QueryBuilder)->sqlDeleteToMongo('DELETE FROM table WHERE');
	}

	/**
	 * @covers Swolley\YardBird\Utils\QueryBuilder::sqlDeleteToMongo
	 * @group unit
	 */
	public function test_sqlDeleteToMongo_shold_return_object_if_parameters_are_correct(): void
	{
		$query = (new QueryBuilder)->sqlDeleteToMongo('DELETE FROM `table` WHERE (id<1 AND c<>2)');
		$response = (object)[
			'type' => 'delete',
			'table' => 'table',
			'params' => ['$and' => ['id' => ['$lt' => 1], 'c' => ['$ne' => 2]]]
		];
		$this->assertEquals($response, $query);
	}

	/**
	 * @covers Swolley\YardBird\Utils\QueryBuilder::sqlUpdateToMongo
	 * @group unit
	 */
	public function test_sqlUpdateToMongo_should_throw_exception_if_any_syntax_error(): void
	{
		$this->expectException(UnexpectedValueException::class);
		(new QueryBuilder)->sqlUpdateToMongo("UPDATE WHERE `column` != 'value'");
	}

	/**
	 * @covers Swolley\YardBird\Utils\QueryBuilder::sqlUpdateToMongo
	 * @group unit
	 */
	public function test_sqlUpdateToMongo_shold_return_object_if_parameters_are_correct(): void
	{
		$query = (new QueryBuilder)->sqlUpdateToMongo("UPDATE `table` SET `id`=1 WHERE `id`<=10");
		$response = (object)[
			'type' => 'update',
			'table' => 'table',
			'params' => ['id' => 1],
			'where' => ['id' => ['$lte' => 10]]
		];
		$this->assertEquals($response, $query);

		$query = (new QueryBuilder)->sqlUpdateToMongo("UPDATE `table` SET `id`=1 WHERE `id`>=1");
		$response = (object)[
			'type' => 'update',
			'table' => 'table',
			'params' => ['id' => 1],
			'where' => ['id' => ['$gte' => 1]]
		];
		$this->assertEquals($response, $query);
	}

	/**
	 * @covers Swolley\YardBird\Utils\QueryBuilder::sqlSelectToMongo
	 * @group unit
	 */
	public function test_sqlSelectToMongo_should_throw_exception_if_any_syntax_error(): void
	{
		$this->expectException(UnexpectedValueException::class);
		(new QueryBuilder)->sqlSelectToMongo("SELECT INTO `table` WHERE `column` > 'value'");
	}

	/**
	 * @covers Swolley\YardBird\Utils\QueryBuilder::sqlSelectToMongo
	 * @group unit
	 */
	public function test_sqlSelectToMongo_shold_return_object_if_parameters_are_correct(): void
	{
		$query = (new QueryBuilder)->sqlSelectToMongo("SELECT * FROM `table` LEFT JOIN `table2` ON `table2`.id = `table`.id WHERE field LIKE '%anything' ORDER BY `column` LIMIT 1");
		$response = (object)[
			'type' => 'select',
			'table' => 'table',
			'filter' => [
				'field' => new \MongoDB\BSON\Regex('^anything', 'i')
			],
			'options' => [
				'projection' => []
			],
			'aggregate' => [
				[
					'$lookup' => [
						'from' => 'table2',
						'localField' => 'id',
						'foreignField' => 'id',
						'as' => 'table2'
					]
				]
			],
			'limit' => 1,
			'orderBy' => [ 'column' => 1 ]
		];
		$this->assertEquals($response, $query);

		$query = (new QueryBuilder)->sqlSelectToMongo("SELECT DISTINCT * FROM `table`");
		$response = (object)[
			'type' => 'command',
			'table' => 'table',
			'filter' => [],
			'options' => [
				'distinct' => 'table',
				'projection' => []
			],
			'aggregate' => [],
			'limit' => null,
			'orderBy' => []
		];
		$this->assertEquals($response, $query);
	}

	/**
	 * @covers Swolley\YardBird\Utils\QueryBuilder::sqlProcedureToMongo
	 * @group unit
	 */
	public function test_sqlProcedureToMongo_should_throw_exception_if_any_syntax_error(): void
	{
		$this->expectException(BadMethodCallException::class);
		(new QueryBuilder)->sqlProcedureToMongo("CALL procedure_name (:value1, :value2)");
	}

	/**
	 * @covers Swolley\YardBird\Utils\QueryBuilder::sqlProcedureToMongo
	 * @group unit
	 */
	public function test_sqlProcedureToMongo_shold_return_object_if_parameters_are_correct(): void
	{
		$query = (new QueryBuilder)->sqlProcedureToMongo("CALL procedure_name ()", []);
		$response = (object)[
			'type' => 'procedure',
			'name' => 'procedure_name',
			'params' => []
		];
		$this->assertEquals($response, $query);
	}

	/**
	 * @covers Swolley\YardBird\Utils\QueryBuilder::parseOperators
	 * @group unit
	 */
	public function test_parseOperators_should_throw_exception_if_no_valid_operator_found(): void
	{
		$this->expectException(UnexpectedValueException::class);
		$query = ["a!!b"];
		$params = [];
		$nested_level = $idx = 0;

		(new QueryBuilder)->parseOperators($query, $params, $idx, $nested_level);
	}

	/**
	 * @covers Swolley\YardBird\Utils\QueryBuilder::parseOperators
	 * @group unit
	 */
	public function test_parseOperators_should_return_array_if_parameters_are_correct(): void
	{
		$query = ["a=1", "b>'value'", 'id=:id'];
		$params = ['id' => 1];
		$nested_level = $idx = 0;
		$parsed = (new QueryBuilder)->parseOperators($query, $params, $idx, $nested_level);
		$this->assertTrue(is_array($parsed));
	}

	/**
	 * @covers Swolley\YardBird\Utils\QueryBuilder::groupLogicalOperators
	 * @group unit
	 */
	public function test_groupLogicalOperators_should_return_grouped_params_by_operators(): void
	{
		$query = [
			['a' => ['$eq' => 1]],
			'AND',
			['b' => ['$gt' => 'value']]
		];

		$parsed = (new QueryBuilder)->groupLogicalOperators($query);
		$response = [
			'$and' => [
				'a' => ['$eq' => 1],
				'b' => ['$gt' => 'value']
			]
		];
		$this->assertEquals($response, $parsed);
	}

	/**
	 * @covers Swolley\YardBird\Utils\QueryBuilder::castValue
	 * @group unit
	 */
	public function test_castValue_should_return_converted_value(): void
	{
		$queryBuilder = new QueryBuilder();
		$casted = $queryBuilder->castValue("'string'");
		$response = "string";
		$this->assertEquals($response, $casted);

		$casted = $queryBuilder->castValue(0);
		$response = 0;
		$this->assertEquals($response, $casted);
	}

	/**
	 * @covers Swolley\YardBird\Utils\QueryBuilder::colonsToQuestionMarksPlaceholders
	 * @group unit
	 */
	public function test_colonsToQuestionMarksPlaceholders_should_throw_exception_if_both_colon_and_questionmark_placeholders_found(): void
	{
		$this->expectException(UnexpectedValueException::class);
		$query = ':value, ?';
		$params = ['value' => 1];
		(new QueryBuilder)->colonsToQuestionMarksPlaceholders($query, $params);
	}

	/**
	 * @covers Swolley\YardBird\Utils\QueryBuilder::colonsToQuestionMarksPlaceholders
	 * @group unit
	 */
	public function test_colonsToQuestionMarksPlaceholders_should_throw_exception_if_not_same_number_of_placeholders_and_params(): void
	{
		$this->expectException(BadMethodCallException::class);
		$query = ':value1, :value2';
		$params = ['value1' => 1];
		(new QueryBuilder)->colonsToQuestionMarksPlaceholders($query, $params);
	}

	/**
	 * @covers Swolley\YardBird\Utils\QueryBuilder::colonsToQuestionMarksPlaceholders
	 * @group unit
	 */
	public function test_colonsToQuestionMarksPlaceholders_should_throw_exception_if_no_corresponding_params_and_placeholders(): void
	{
		$this->expectException(BadMethodCallException::class);
		$query = ':value1, :value2';
		$params = ['value3' => 1];
		(new QueryBuilder)->colonsToQuestionMarksPlaceholders($query, $params);
	}

	/**
	 * @covers Swolley\YardBird\Utils\QueryBuilder::operatorsToStandardSyntax
	 * @group unit
	 */
	public function test_operatorsToStandardSyntax_should_return_replaced_string(): void
	{
		$query = 'SELECT * FROM table WHERE value1<=1&&value2>=2 || value3=3 AND value4!=4';
		$expected = 'SELECT * FROM table WHERE value1<=1 AND value2>=2 OR value3=3 AND value4<>4';
		$this->assertEquals($expected, (new QueryBuilder)->operatorsToStandardSyntax($query));
	}
}
