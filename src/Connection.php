<?php
declare(strict_types=1);

namespace Swolley\YardBird;

use Swolley\YardBird\Interfaces\IConnectable;
use Swolley\YardBird\Drivers\MongoExtended;
use Swolley\YardBird\Drivers\OCIExtended;
use Swolley\YardBird\Drivers\PDOExtended;
use Swolley\YardBird\Drivers\MySqliExtended;
//use Swolley\YardBird\Exceptions\QueryException;
use Swolley\YardBird\Exceptions\BadMethodCallException;
use Swolley\YardBird\Exceptions\UnexpectedValueException;

final class Connection
{
	const FETCH_ASSOC = 2;
	const FETCH_OBJ = 5;
	const FETCH_COLUMN = 7;
	const FETCH_CLASS = 8;
	const FETCH_PROPS_LATE = 1048576;

	/**
	 * @param	array	$connectionParameters	connection parameters
	 * @param	boolean	$debugMode				debug mode
	 * @return	IConnectable	driver superclass
	 */
	public function __invoke(array $connectionParameters, bool $debugMode = false): IConnectable
	{
		if (!isset($connectionParameters, $connectionParameters['driver']) || empty($connectionParameters)) throw new BadMethodCallException("Connection parameters are required");

		switch (self::checkExtension($connectionParameters['driver'])) {
			case 'mongodb':
				return new MongoExtended($connectionParameters, $debugMode);
			case 'oci8':
				return new OCIExtended($connectionParameters, $debugMode);
			case 'pdo':
				return new PDOExtended($connectionParameters, $debugMode);
			case 'mysqli':
				return new MySqliExtended($connectionParameters, $debugMode);
		}
		
		throw new \Exception('Extension not supported with current php configuration', 500);
	}

	/**
	 * @param	string		$driver	requested type of connection
	 * @return	string|null			driver or null if no driver compatible
	 */
	private static function checkExtension(string $driver): ?string
	{
		switch ($driver) {
			case 'mongo':
			case 'mongodb':
				return extension_loaded('mongodb') ? 'mongodb' : null;
			case 'oci':
				if(extension_loaded('pdo')) return 'pdo';	//correctly inside if no pdo => tries oci8
			case 'oci8':
				return extension_loaded('oci8') ? 'oci8' : null;
			case 'cubrid':
			case 'dblib':
			case 'firebird':
			case 'ibm':
			case 'informix':
			case 'odbc':
			case 'pgsql':
			case 'sqlite':
			case 'sqlsrv':
			case '4d':
			case 'mysql':
				return extension_loaded('pdo') ? 'pdo' : ($driver === 'mysql' && extension_loaded('mysqli') ? 'mysqli' : null);
			case 'mysqli': 
				return extension_loaded('mysqli') ? 'mysqli' : null;
		}

		throw new BadMethodCallException("No driver found for '$driver'");
	}
}
