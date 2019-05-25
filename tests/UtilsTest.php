<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Swolley\Database\Utils\Utils;
use Swolley\Database\Exceptions\QueryException;
use Swolley\Database\Exceptions\BadMethodCallException;
use Swolley\Database\Exceptions\UnexpectedValueException;

final class UtilsTest extends TestCase
{
	///////////////////////////////// UNIT ////////////////////////////////////////////////
	public function test_castToArray_should_return_exception_if_param_is_not_array_or_object(): void
  	{
		$this->expectException(UnexpectedValueException::class);
		$this->castToArray('invalid');
	}

	public function test_castToArray_should_return_array_if_object_or_array_passed(): void
  	{
		$obj = new StdClass();
		$obj->field = 'test';
		$this->assertEquals(['field' => 'test'], Utils::castToArray($obj));

		$arr = ['field' => 'test'];
		$this->assertEquals($arr, Utils::castToArray($arr));
	}

	public function test_castToObject_should_return_exception_if_param_is_not_array_or_object(): void
  	{
		$this->expectException(UnexpectedValueException::class);
		$this->castToObject('invalid');
	}

	public function test_castToObject_should_return_object_if_array_passed(): void
  	{
		$arr = ['field' => 'test'];
		$expected = new StdClass();
		$expected->field = 'test';

		$this->assertEquals($expected, $this->castToObject($arr));

		$obj = new StdClass();
		$obj->field = 'test';
		$this->assertEquals($obj, $this->castToObject($obj));
	}

	public function test_trimQueryString_should_return_replaced_string(): void
	{
		$this->assertEquals('string withouth cr', Utils::trimQueryString("string\nwithouth\ncr"));
	}
}