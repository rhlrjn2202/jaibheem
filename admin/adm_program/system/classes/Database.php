<?php
/**
 ***********************************************************************************************
 * @copyright 2004-2018 The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 ***********************************************************************************************
 */

/**
 * Handle the connection to the database, send all sql statements and handle the returned rows.
 *
 * This class creates a connection to the database and provides several methods
 * to communicate with the database. There are methods to send sql statements
 * and to handle the response of the database. This class also supports transactions.
 * Just call Database#startTransaction and finish it with Database#endTransaction. If
 * you call this multiple times only 1 transaction will be open and it will be closed
 * after the last endTransaction was send.
 *
 * **Code example:**
 * ```
 * // To open a connection you can use the settings of the config.php of Admidio.
 * // create object and open connection to database
 * try
 * {
 *     $gDb = new Database(DB_ENGINE, DB_HOST, DB_PORT, DB_NAME, DB_USERNAME, DB_PASSWORD);
 * }
 * catch (AdmException $e)
 * {
 *     $e->showText();
 * }
 * ```
 *
 * **Code example:**
 * ```
 * // Now you can use the new object **$gDb** to send a query to the database
 * // send sql to database and assign the returned \PDOStatement
 * $organizationsStatement = $gDb->queryPrepared('SELECT org_shortname, org_longname FROM ' . TBL_ORGANIZATIONS);
 *
 * // now fetch all rows of the returned \PDOStatement within one array
 * $organizationsList = $organizationsStatement->fetchAll();
 *
 * // Array with the results:
 * //    $organizationsList = array(
 * //         [0] => array(
 * //             [org_shortname] => 'DEMO'
 * //             [org_longname]  => 'Demo-Organization'
 * //             )
 * //         [1] => array(
 * //             [org_shortname] => 'TEST'
 * //             [org_longname]  => 'Test-Organization'
 * //             )
 *
 * // you can also go step by step through the returned \PDOStatement
 * while ($organizationNames = $organizationsStatement->fetch())
 * {
 *     echo $organizationNames['shortname'].' '.$organizationNames['longname'];
 * }
 * ```
 */
class Database
{
    const PDO_ENGINE_MYSQL = 'mysql';
    const PDO_ENGINE_PGSQL = 'pgsql';

    /**
     * @var string The database engine ("mysql", "pgsql")
     */
    protected $engine;
    /**
     * @var string The host of the database server ("localhost", "127.0.0.1")
     */
    protected $host;
    /**
     * @var int|null The port of the database server. Set to "null" to use default. (Default: mysql=3306 , pgsql=5432)
     */
    protected $port;
    /**
     * @var string The name of the database
     */
    protected $dbName;
    /**
     * @var string|null The username to access the database
     */
    protected $username;
    /**
     * @var string|null The password to access the database
     */
    protected $password;
    /**
     * @var array Driver specific connection options
     */
    protected $options;

    /**
     * @var string The Data-Source-Name for the database connection
     */
    protected $dsn;
    /**
     * @var \PDO The PDO object that handles the communication with the database.
     */
    protected $pdo;
    /**
     * @var \PDOStatement The PDOStatement object which is needed to handle the return of a query.
     */
    protected $pdoStatement;
    /**
     * @var int The transaction marker. If this is > 0 than a transaction is open.
     */
    protected $transactions = 0;
    /**
     * @var array<string,array<string,array<string,mixed>>> Array with arrays of every table with their structure
     */
    protected $dbStructure = array();
    /**
     * @var string The minimum required version of this database that is necessary to run Admidio.
     */
    protected $minRequiredVersion = '';
    /**
     * @var string The name of the database e.g. 'MySQL'
     */
    protected $databaseName = '';

    /**
     * Simple way to create a database object with the default Admidio globals
     * @throws AdmException
     * @return self Returns a Database instance
     */
    public static function createDatabaseInstance()
    {
        global $gLogger;

        if ($gLogger instanceof \Psr\Log\LoggerInterface) // fix for non-object error in PHP 5.3
        {
            $gLogger->debug(
                'DATABASE: Create DB-Instance with default params!',
                array(
                    'engine' => DB_ENGINE, 'host' => DB_HOST, 'port' => DB_PORT,
                    'name' => DB_NAME, 'username' => DB_USERNAME, 'password' => DB_PASSWORD
                )
            );
        }

        return new self(DB_ENGINE, DB_HOST, DB_PORT, DB_NAME, DB_USERNAME, DB_PASSWORD);
    }

    /**
     * The constructor will check if a valid engine was set and try to connect to the database.
     * If the engine is invalid or the connection not possible an exception will be thrown.
     * @param string $engine   The database type that is supported from Admidio. **mysql** and **pgsql** are valid values.
     * @param string $host     The hostname or server where the database is running. e.g. localhost or 127.0.0.1
     * @param int    $port     If you don't use the default port of the database then set your port here.
     * @param string $dbName   Name of the database you want to connect.
     * @param string $username Username to connect to database
     * @param string $password Password to connect to database
     * @param array  $options
     * @throws AdmException
     */
    public function __construct($engine, $host, $port = null, $dbName, $username = null, $password = null, array $options = array())
    {
        global $gLogger;

        // for compatibility to old versions accept the string postgresql
        if ($engine === 'postgresql')
        {
            $gLogger->warning('DEPRECATED: Deprecated database engine type used!', array('engine' => $engine));

            $engine = self::PDO_ENGINE_PGSQL;
        }

        $this->engine   = $engine;
        $this->host     = $host;
        $this->port     = $port;
        $this->dbName   = $dbName;
        $this->username = $username;
        $this->password = $password;
        $this->options  = $options;

        $this->connect();

        if ($gLogger instanceof \Psr\Log\LoggerInterface) // fix for non-object error in PHP 5.3
        {
            $gLogger->debug('DATABASE: connected!');
        }
    }

    /**
     * We need the sleep function at this place because otherwise the system will serialize a PDO-Statement
     * which will lead to an exception.
     * @return array<int,string>
     */
    public function __sleep()
    {
        global $gLogger;

        if ($gLogger instanceof \Psr\Log\LoggerInterface) // fix for non-object error in PHP 5.3
        {
            $gLogger->debug('DATABASE: sleep/serialize!');
        }

        return array('engine', 'host', 'port', 'dbName', 'username', 'password', 'options');
    }

    /**
     * @throws AdmException
     */
    protected function connect()
    {
        global $gLogger;

        try
        {
            $this->setDSNString();

            // needed to avoid leaking username, password, ... if a PDOException is thrown
            $this->pdo = new \PDO($this->dsn, $this->username, $this->password, $this->options);

            $this->setConnectionOptions();
        }
        catch (\PDOException $exception)
        {
            $logContext = array(
                'engine'   => $this->engine,
                'host'     => $this->host,
                'port'     => $this->port,
                'dbName'   => $this->dbName,
                'username' => $this->username,
                'password' => '******',
                'options'  => $this->options
            );
            $gLogger->alert('DATABASE: Could not connect to Database! EXCEPTION MSG: ' . $exception->getMessage(), $logContext);

            throw new AdmException($exception->getMessage()); // TODO: change exception class
        }
    }

    /**
     * The method will commit an open transaction to the database. If the
     * transaction counter is greater 1 than only the counter will be
     * decreased and no commit will performed.
     * @return bool Returns **true** if the commit was successful otherwise **false**
     * @see Database#startTransaction
     * @see Database#rollback
     */
    public function endTransaction()
    {
        global $gLogger;

        // if there is no open transaction then do nothing and return
        if ($this->transactions === 0)
        {
            return true;
        }

        // If there was a previously opened transaction we do not commit yet...
        // but count back the number of inner transactions
        if ($this->transactions > 1)
        {
            --$this->transactions;

            return true;
        }

        // if debug mode then log all sql statements
        $gLogger->info('SQL: COMMIT');

        $result = $this->pdo->commit();

        if (!$result)
        {
            $this->showError();
            // => EXIT
        }

        $this->transactions = 0;

        return $result;
    }

    /**
     * Escapes special characters within the input string.
     * Note: This method will add a high comma at the beginning and the end of the $string.
     * @param string $string The string to be quoted.
     * @return string Returns a quoted string that is theoretically safe to pass into an SQL statement.
     * @see <a href="https://secure.php.net/manual/en/pdo.quote.php">PDO::quote</a>
     */
    public function escapeString($string)
    {
        return $this->pdo->quote($string);
    }

    /**
     * Returns an array with all available PDO database drivers of the server.
     * @deprecated 3.1.0:4.0.0 Switched to native PDO method.
     * @return array<int,string> Returns an array with all available PDO database drivers of the server.
     * @see <a href="https://secure.php.net/manual/en/pdo.getavailabledrivers.php">PDO::getAvailableDrivers</a>
     */
    public static function getAvailableDBs()
    {
        global $gLogger;

        $gLogger->warning('DEPRECATED: "$database->getAvailableDBs()" is deprecated, use "\PDO::getAvailableDrivers()" instead!');

        return \PDO::getAvailableDrivers();
    }

    /**
     * This method will create an backtrace of the current position in the script. If several
     * scripts were called than each script with their position will be listed in the backtrace.
     * @return string Returns a string with the backtrace of all called scripts.
     */
    protected function getBacktrace()
    {
        $output = '<div style="font-family: monospace;">';
        $backtrace = debug_backtrace();

        foreach ($backtrace as $number => $trace)
        {
            // We skip the first one, because it only shows this file/function
            if ($number === 0)
            {
                continue;
            }

            // Strip the current directory from path
            if (empty($trace['file']))
            {
                $trace['file'] = '';
            }
            else
            {
                $trace['file'] = str_replace(array(ADMIDIO_PATH, '\\'), array('', '/'), $trace['file']);
                $trace['file'] = substr($trace['file'], 1);
            }
            $args = array();

            // If include/require/include_once is not called, do not show arguments - they may contain sensible information
            if (!in_array($trace['function'], array('include', 'require', 'include_once'), true))
            {
                unset($trace['args']);
            }
            else
            {
                // Path...
                if (!empty($trace['args'][0]))
                {
                    $argument = noHTML($trace['args'][0]);
                    $argument = str_replace(array(ADMIDIO_PATH, '\\'), array('', '/'), $argument);
                    $argument = substr($argument, 1);
                    $args[] = '\'' . $argument . '\'';
                }
            }

            $trace['class'] = array_key_exists('class', $trace) ? $trace['class'] : '';
            $trace['type']  = array_key_exists('type',  $trace) ? $trace['type'] : '';

            $output .= '<br />';
            $output .= '<strong>FILE:</strong> ' . noHTML($trace['file']) . '<br />';
            $output .= '<strong>LINE:</strong> ' . ((!empty($trace['line'])) ? $trace['line'] : '') . '<br />';

            $output .= '<strong>CALL:</strong> ' . noHTML($trace['class'] . $trace['type'] . $trace['function']) .
                       '(' . (count($args) ? implode(', ', $args) : '') . ')<br />';
        }
        $output .= '</div>';

        return $output;
    }

    /**
     * Get the name of the database that is running Admidio.
     * @return string Returns a string with the name of the database e.g. 'MySQL' or 'PostgreSQL'
     */
    public function getName()
    {
        if ($this->databaseName === '')
        {
            $this->databaseName = $this->getPropertyFromDatabaseConfig('name');
        }

        return $this->databaseName;
    }

    /**
     * Get the minimum required version of the database that is necessary to run Admidio.
     * @return string Returns a string with the minimum required database version e.g. '5.0.1'
     */
    public function getMinimumRequiredVersion()
    {
        if ($this->minRequiredVersion === '')
        {
            $this->minRequiredVersion = $this->getPropertyFromDatabaseConfig('minversion');
        }

        return $this->minRequiredVersion;
    }

    /**
     * @param string $property Property name of the in use database config
     * @return string Returns the value of the chosen property
     */
    protected function getPropertyFromDatabaseConfig($property)
    {
        $xmlDatabases = new \SimpleXMLElement(ADMIDIO_PATH . '/adm_program/system/databases.xml', 0, true);
        $node = $xmlDatabases->xpath('/databases/database[@id="' . $this->engine . '"]/' . $property);

        return (string) $node[0];
    }

    /**
     * Method get all columns and their properties from the database table.
     * @param string $table Name of the database table for which the columns-properties should be shown.
     * @return array<string,array<string,mixed>> Returns an array with column-names.
     */
    public function getTableColumnsProperties($table)
    {
        if (!array_key_exists($table, $this->dbStructure))
        {
            $this->loadTableColumnsProperties($table);
        }

        return $this->dbStructure[$table];
    }

    /**
     * Method get all columns-names from the database table.
     * @param string $table Name of the database table for which the columns should be shown.
     * @return array<int,string> Returns an array with each column and their properties.
     */
    public function getTableColumns($table)
    {
        if (!array_key_exists($table, $this->dbStructure))
        {
            $this->loadTableColumnsProperties($table);
        }

        return array_keys($this->dbStructure[$table]);
    }

    /**
     * Get the version of the connected database.
     * @return string Returns a string with the database version e.g. '5.5.8'
     */
    public function getVersion()
    {
        $versionStatement = $this->queryPrepared('SELECT version()');
        $version = $versionStatement->fetchColumn();

        if ($this->engine === self::PDO_ENGINE_PGSQL)
        {
            // the string (PostgreSQL 9.0.4, compiled by Visual C++ build 1500, 64-bit) must be separated
            $versionArray  = explode(',', $version);
            $versionArray2 = explode(' ', $versionArray[0]);
            return $versionArray2[1];
        }

        return $version;
    }

    /**
     * Method gets all columns and their properties from the database table.
     *
     * The array has the following format:
     * array (
     *     'columnName1' => array (
     *         'serial'   => true,
     *         'null'     => false,
     *         'key'      => false,
     *         'unsigned' => true,
     *         'default'  => 10
     *         'type'     => 'integer'
     *     ),
     *     'columnName2' => array (...),
     *     ...
     * )
     *
     * @param string $table Name of the database table for which the columns-properties should be loaded.
     *
     * TODO: Links for improvements
     *       https://secure.php.net/manual/en/pdostatement.getcolumnmeta.php
     *       https://www.postgresql.org/docs/9.5/static/infoschema-columns.html
     *       https://wiki.postgresql.org/wiki/Retrieve_primary_key_columns
     *       https://dev.mysql.com/doc/refman/5.7/en/columns-table.html
     */
    private function loadTableColumnsProperties($table)
    {
        $tableColumnsProperties = array();

        if ($this->engine === self::PDO_ENGINE_MYSQL)
        {
            $sql = 'SHOW COLUMNS FROM ' . $table;
            $columnsStatement = $this->query($sql); // TODO add more params
            $columnsList      = $columnsStatement->fetchAll();

            foreach ($columnsList as $properties)
            {
                $props = array(
                    'serial'   => $properties['Extra'] === 'auto_increment',
                    'null'     => $properties['Null'] === 'YES',
                    'key'      => $properties['Key'] === 'PRI' || $properties['Key'] === 'MUL',
                    'default'  => $properties['Default'],
                    'unsigned' => admStrContains($properties['Type'], 'unsigned')
                );

                if (admStrContains($properties['Type'], 'tinyint(1)'))
                {
                    $props['type'] = 'boolean';
                }
                elseif (admStrContains($properties['Type'], 'smallint'))
                {
                    $props['type'] = 'smallint';
                }
                elseif (admStrContains($properties['Type'], 'int'))
                {
                    $props['type'] = 'integer';
                }
                else
                {
                    $props['type'] = $properties['Type'];
                }

                $tableColumnsProperties[$properties['Field']] = $props;
            }
        }
        elseif ($this->engine === self::PDO_ENGINE_PGSQL)
        {
            $sql = 'SELECT column_name, column_default, is_nullable, data_type
                      FROM information_schema.columns
                     WHERE table_name = ?';
            $columnsStatement = $this->queryPrepared($sql, array($table));
            $columnsList = $columnsStatement->fetchAll();

            foreach ($columnsList as $properties)
            {
                $props = array(
                    'serial'   => admStrContains($properties['column_default'], 'nextval'),
                    'null'     => $properties['is_nullable'] === 'YES',
                    'key'      => null,
                    'default'  => $properties['column_default'],
                    'unsigned' => null
                );

                if (admStrContains($properties['data_type'], 'timestamp'))
                {
                    $props['type'] = 'timestamp';
                }
                elseif (admStrContains($properties['data_type'], 'time'))
                {
                    $props['type'] = 'time';
                }
                else
                {
                    $props['type'] = $properties['data_type'];
                }

                $tableColumnsProperties[$properties['column_name']] = $props;
            }
        }

        // safe array with table structure in class array
        $this->dbStructure[$table] = $tableColumnsProperties;
    }

    /**
     * Returns the ID of the unique id column of the last INSERT operation.
     * This method replace the old method Database#insert_id.
     * @return int Return ID value of the last INSERT operation.
     * @see Database#insert_id
     */
    public function lastInsertId()
    {
        if ($this->engine === self::PDO_ENGINE_PGSQL)
        {
            $lastValStatement = $this->queryPrepared('SELECT lastval()');

            return (int) $lastValStatement->fetchColumn();
        }

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param string $sql
     * @return string
     */
    private function preparePgSqlQuery($sql)
    {
        $sqlCompare = strtolower($sql);

        // prepare the sql statement to be compatible with PostgreSQL
        if (admStrContains($sqlCompare, 'create table'))
        {
            // on a create-table-statement if necessary cut existing MySQL table options
            $sql = substr($sql, 0, strrpos($sql, ')') + 1);
        }
        if (admStrContains($sqlCompare, 'create table') || admStrContains($sqlCompare, 'alter table'))
        {
            $replaces = array(
                // PostgreSQL doesn't know unsigned
                'unsigned' => '',
                // PostgreSQL interprets a boolean as string so transform it to a smallint
                'boolean'  => 'smallint',
                // A blob is in PostgreSQL a bytea datatype
                'blob'     => 'bytea'
            );
            $sql = admStrMultiReplace($sql, $replaces);

            // Auto_Increment must be replaced with Serial
            $posAutoIncrement = strpos($sql, 'AUTO_INCREMENT');
            if ($posAutoIncrement > 0)
            {
                $posInteger = strripos(substr($sql, 0, $posAutoIncrement), 'integer');
                $sql = substr($sql, 0, $posInteger) . ' serial ' . substr($sql, $posAutoIncrement + 14);
            }
        }

        return $sql;
    }

    /**
     * Replace the table prefixes in SQL statements
     * @param string $sql
     * @return string
     */
    public static function prepareSqlTablePrefix($sql)
    {
        return str_replace('%PREFIX%', TABLE_PREFIX, $sql);
    }

    /**
     * Prepares SQL statements in a log-able format
     * @param string $sql
     * @return string
     */
    private static function prepareSqlForLog($sql)
    {
        $sql = preg_replace('/\/\*.+\*\//sU', '', $sql); // Removes /* ... */ (multi-line) comments
        $sql = preg_replace('/--.+$/m', '', $sql); // Removes -- (single-line) comments
        $sql = preg_replace('/\s+/', ' ', $sql); // Changes all types of sequent white spaces to one space

        return trim($sql);
    }

    /**
     * Send a sql statement to the database that will be executed. If debug mode is set
     * then this statement will be written to the error log. If it's a **SELECT** statement
     * then also the number of rows will be logged. If an error occurred the script will
     * be terminated and the error with a backtrace will be send to the browser.
     * @param string $sql        A string with the sql statement that should be executed in database.
     * @param bool   $showError  Default will be **true** and if an error the script will be terminated and
     *                           occurred the error with a backtrace will be send to the browser. If set to
     *                           **false** no error will be shown and the script will be continued.
     * @return \PDOStatement|false For **SELECT** statements an object of <a href="https://secure.php.net/manual/en/class.pdostatement.php">\PDOStatement</a> will be returned.
     *                             This should be used to fetch the returned rows. If an error occurred then **false** will be returned.
     */
    public function query($sql, $showError = true)
    {
        global $gLogger;

        if ($this->engine === self::PDO_ENGINE_PGSQL)
        {
            $sql = $this->preparePgSqlQuery($sql);
        }

        // if debug mode then log all sql statements
        $gLogger->info('SQL: ' . self::prepareSqlForLog($sql));

        $startTime = microtime(true);

        try
        {
            $this->pdoStatement = $this->pdo->query($sql);

            if ($this->pdoStatement !== false && admStrStartsWith(strtoupper($sql), 'SELECT'))
            {
                $gLogger->debug('SQL: Found rows: ' . $this->pdoStatement->rowCount());
            }

            $gLogger->debug('SQL: Execution time ' . getExecutionTime($startTime));
        }
        // only throws if "PDO::ATTR_ERRMODE" is set to "PDO::ERRMODE_EXCEPTION"
        catch (\PDOException $exception)
        {
            $gLogger->debug('SQL: Execution time ' . getExecutionTime($startTime));

            if ($showError)
            {
                $gLogger->critical('PDOException: ' . $exception->getMessage());
                $this->showError();
                // => EXIT
            }

            $gLogger->warning('PDOException: ' . $exception->getMessage());

            return false;
        }

        return $this->pdoStatement;
    }

    /**
     * Send a sql statement to the database that will be executed. If debug mode is set
     * then this statement will be written to the error log. If it's a **SELECT** statement
     * then also the number of rows will be logged. If an error occurred the script will
     * be terminated and the error with a backtrace will be send to the browser.
     * @param string           $sql        A string with the sql statement that should be executed in database.
     * @param array<int,mixed> $params     An array of parameters to bind to the prepared statement.
     * @param bool             $showError  Default will be **true** and if an error the script will be terminated and
     *                                     occurred the error with a backtrace will be send to the browser. If set to
     *                                     **false** no error will be shown and the script will be continued.
     * @return \PDOStatement|false For **SELECT** statements an object of <a href="https://secure.php.net/manual/en/class.pdostatement.php">\PDOStatement</a> will be returned.
     *                             This should be used to fetch the returned rows. If an error occurred then **false** will be returned.
     */
    public function queryPrepared($sql, array $params = array(), $showError = true)
    {
        global $gLogger;

        if ($this->engine === self::PDO_ENGINE_PGSQL)
        {
            $sql = $this->preparePgSqlQuery($sql);
        }

        // if debug mode then log all sql statements
        $gLogger->info('SQL: ' . self::prepareSqlForLog($sql), $params);

        $startTime = microtime(true);

        try
        {
            $this->pdoStatement = $this->pdo->prepare($sql);

            if ($this->pdoStatement !== false)
            {
                $this->pdoStatement->execute($params);

                if (admStrStartsWith(strtoupper($sql), 'SELECT'))
                {
                    $gLogger->info('SQL: Found rows: ' . $this->pdoStatement->rowCount());
                }
            }

            $gLogger->debug('SQL: Execution time ' . getExecutionTime($startTime));
        }
        // only throws if "PDO::ATTR_ERRMODE" is set to "PDO::ERRMODE_EXCEPTION"
        catch (\PDOException $exception)
        {
            $gLogger->debug('SQL: Execution time ' . getExecutionTime($startTime));

            if ($showError)
            {
                $gLogger->critical('PDOException: ' . $exception->getMessage());
                $this->showError();
                // => EXIT
            }

            $gLogger->warning('PDOException: ' . $exception->getMessage());

            return false;
        }

        return $this->pdoStatement;
    }

    /**
     * If there is a open transaction than this method sends a rollback
     * to the database and will set the transaction counter to zero.
     * @return bool
     * @see Database#startTransaction
     * @see Database#endTransaction
     */
    public function rollback()
    {
        global $gLogger;

        if ($this->transactions === 0)
        {
            return false;
        }

        // if debug mode then log all sql statements
        $gLogger->info('SQL: ROLLBACK');

        $result = $this->pdo->rollBack();

        if (!$result)
        {
            $this->showError();
            // => EXIT
        }

        $this->transactions = 0;

        return true;
    }

    /**
     * Create a valid DSN string for the engine that was set through the constructor.
     * If no valid engine is set than an exception is thrown.
     * @throws \PDOException
     */
    private function setDSNString()
    {
        global $gLogger;

        $availableDrivers = \PDO::getAvailableDrivers();

        if (count($availableDrivers) === 0)
        {
            throw new \PDOException('PDO does not support any drivers'); // TODO: change exception class
        }
        if (!in_array($this->engine, $availableDrivers, true))
        {
            throw new \PDOException('The requested PDO driver ' . $this->engine . ' is not supported'); // TODO: change exception class
        }

        switch ($this->engine)
        {
            case self::PDO_ENGINE_MYSQL:
                $port = '';
                if ($this->port !== null)
                {
                    $port = ';port=' . $this->port;
                }
                // TODO: change to "charset=utf8mb4" if we change charset in DB to "utf8mb4"
                $this->dsn = 'mysql:host=' . $this->host . $port . ';dbname=' . $this->dbName . ';charset=utf8';
                break;

            case self::PDO_ENGINE_PGSQL:
                $port = '';
                if ($this->port !== null)
                {
                    $port = ';port=' . $this->port;
                }
                $this->dsn = 'pgsql:host=' . $this->host . $port . ';dbname=' . $this->dbName;
                break;

            default:
                throw new \PDOException('Engine is not supported by Admidio'); // TODO: change exception class
        }

        $gLogger->debug('DATABASE: DSN-String: "' . $this->dsn . '"!');
    }

    /**
     * Set connection specific options like UTF8 connection.
     * These options should always be set if Admidio connect to a database.
     */
    private function setConnectionOptions()
    {
        global $gDebug;

        if ($gDebug)
        {
            $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        }
        else
        {
            $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_SILENT);
        }

        $this->pdo->setAttribute(\PDO::ATTR_STRINGIFY_FETCHES, false);
        $this->pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC); // maybe change in future to \PDO::FETCH_OBJ
        $this->pdo->setAttribute(\PDO::ATTR_CASE, \PDO::CASE_NATURAL);

        switch ($this->engine)
        {
            case self::PDO_ENGINE_MYSQL:
                // MySQL charset UTF-8 is set in DSN-string
                // set ANSI mode, that SQL could be more compatible with other DBs
                $this->queryPrepared('SET SQL_MODE = \'ANSI\'');
                // if the server has limited the joins, it can be canceled with this statement
                $this->queryPrepared('SET SQL_BIG_SELECTS = 1');
                break;
            case self::PDO_ENGINE_PGSQL:
                $this->queryPrepared('SET NAMES \'UTF8\'');
                break;
        }
    }

    /**
     * Display the error code and error message to the user if a database error occurred.
     * The error must be read by the child method. This method will call a backtrace so
     * you see the script and specific line in which the error occurred.
     * @return void Will exit the script and returns a html output with the error information.
     */
    public function showError()
    {
        global $gLogger, $gSettingsManager, $gL10n;

        $backtrace = $this->getBacktrace();

        // Rollback on open transaction
        if ($this->transactions > 0)
        {
            $this->pdo->rollBack();
        }

        // transform the database error to html
        $errorCode = $this->pdo->errorCode();
        $errorInfo = $this->pdo->errorInfo();

        $gLogger->critical($errorCode.': '.$errorInfo[1].' | '.$errorInfo[2]);

        $htmlOutput = '
            <div style="font-family: monospace;">
                 <p><strong>S Q L - E R R O R</strong></p>
                 <p><strong>CODE:</strong> ' . $errorCode . '</p>
                 ' . $errorInfo[1] . '<br /><br />
                 ' . $errorInfo[2] . '<br /><br />
                 <strong>B A C K T R A C E</strong><br />
                 ' . $backtrace . '
             </div>';

        // display database error to user
        if (isset($gSettingsManager) && defined('THEME_ADMIDIO_PATH') && !headers_sent())
        {
            // create html page object
            $page = new HtmlPage($gL10n->get('SYS_DATABASE_ERROR'));
            $page->addHtml($htmlOutput);
            $page->show();
        }
        else
        {
            echo $htmlOutput;
        }

        exit();
    }

    /**
     * Start a transaction if no open transaction exists. If you call this multiple times
     * only 1 transaction will be open and it will be closed after the last endTransaction was send.
     * @return bool
     * @see Database#endTransaction
     * @see Database#rollback
     */
    public function startTransaction()
    {
        global $gLogger;

        // If we are within a transaction we will not open another one,
        // but enclose the current one to not loose data (preventing auto commit)
        if ($this->transactions > 0)
        {
            ++$this->transactions;
            return true;
        }

        // if debug mode then log all sql statements
        $gLogger->info('SQL: START TRANSACTION');

        $result = $this->pdo->beginTransaction();

        if (!$result)
        {
            $this->showError();
            // => EXIT
        }

        $this->transactions = 1;

        return $result;
    }

    /**
     * Reads an prepares a SQL file to SQL statements
     * @param string $sqlFilePath The path to the SQL file
     * @throws \UnexpectedValueException Throws if the file does not exist or is not readable
     * @throws \RuntimeException         Throws if the read process fails
     * @return array<int,string> Returns an array with all prepared SQL statements
     */
    public static function getSqlStatementsFromSqlFile($sqlFilePath)
    {
        $sqlFileContent = FileSystemUtils::readFile($sqlFilePath);

        $sqlArray = explode(';', $sqlFileContent);

        $sqlStatements = array();
        foreach ($sqlArray as $sql)
        {
            $sql = self::prepareSqlTablePrefix(trim($sql));
            if ($sql !== '')
            {
                $sqlStatements[] = $sql;
            }
        }

        return $sqlStatements;
    }


    /**
     * Fetch a result row as an associative array, a numeric array, or both.
     * @deprecated 3.1.0:4.0.0 Switched to native PDO method.
     *             Please use the PHP class <a href="https://secure.php.net/manual/en/class.pdostatement.php">\PDOStatement</a>
     *             and the method <a href="https://secure.php.net/manual/en/pdostatement.fetch.php">fetch</a> instead.
     * @param \PDOStatement $pdoStatement An object of the class \PDOStatement. This should be set if multiple
     *                                    rows where selected and other sql statements are also send to the database.
     * @param int           $fetchType    Set the result type. Can contain **\PDO::FECTH_ASSOC** for an associative array,
     *                                    **\PDO::FETCH_NUM** for a numeric array or **\PDO::FETCH_BOTH** (Default).
     * @return mixed|null Returns an array that corresponds to the fetched row and moves the internal data pointer ahead.
     * @see <a href="https://secure.php.net/manual/en/pdostatement.fetch.php">\PDOStatement::fetch</a>
     */
    public function fetch_array(\PDOStatement $pdoStatement = null, $fetchType = \PDO::FETCH_BOTH)
    {
        global $gLogger;

        $gLogger->warning('DEPRECATED: "$database->fetch_array()" is deprecated, use "$this->pdoStatement->fetch()" instead!');

        // if pdo statement is committed then fetch this object
        if ($pdoStatement instanceof \PDOStatement)
        {
            return $pdoStatement->fetch($fetchType);
        }
        // if no pdo statement was committed then take the one from the last query
        if ($this->pdoStatement instanceof \PDOStatement)
        {
            return $this->pdoStatement->fetch($fetchType);
        }

        return null;
    }

    /**
     * Fetch a result row as an object.
     * @deprecated 3.1.0:4.0.0 Switched to native PDO method.
     *             Please use methods Database#fetchAll or Database#fetch instead.
     *             Please use the PHP class <a href="https://secure.php.net/manual/en/class.pdostatement.php">\PDOStatement</a>
     *             and the method <a href="https://secure.php.net/manual/en/pdostatement.fetchobject.php">fetchObject</a> instead.
     * @param \PDOStatement $pdoStatement An object of the class \PDOStatement. This should be set if multiple
     *                                    rows where selected and other sql statements are also send to the database.
     * @return mixed|null Returns an object that corresponds to the fetched row and moves the internal data pointer ahead.
     * @see <a href="https://secure.php.net/manual/en/pdostatement.fetchobject.php">\PDOStatement::fetchObject</a>
     */
    public function fetch_object(\PDOStatement $pdoStatement = null)
    {
        global $gLogger;

        $gLogger->warning('DEPRECATED: "$database->fetch_object()" is deprecated, use "$this->pdoStatement->fetchObject()" instead!');

        // if pdo statement is committed then fetch this object
        if ($pdoStatement instanceof \PDOStatement)
        {
            return $pdoStatement->fetchObject();
        }
        // if no pdo statement was committed then take the one from the last query
        if ($this->pdoStatement instanceof \PDOStatement)
        {
            return $this->pdoStatement->fetchObject();
        }

        return null;
    }

    /**
     * Returns the ID of the unique id column of the last INSERT operation.
     * @deprecated 3.1.0:4.0.0 Renamed method to camelCase style.
     *             Please use methods Database#lastInsertId instead.
     * @return int Return ID value of the last INSERT operation.
     * @see Database#lastInsertId
     */
    public function insert_id()
    {
        global $gLogger;

        $gLogger->warning('DEPRECATED: "$database->insert_id()" is deprecated, use "$database->lastInsertId()" instead!');

        return $this->lastInsertId();
    }

    /**
     * Returns the number of rows of the last executed statement.
     * @deprecated 3.1.0:4.0.0 Switched to native PDO method.
     *             Please use the PHP class <a href="https://secure.php.net/manual/en/class.pdostatement.php">\PDOStatement</a>
     *             and the method <a href="https://secure.php.net/manual/en/pdostatement.rowcount.php">rowCount</a> instead.
     * @param \PDOStatement $pdoStatement An object of the class \PDOStatement. This should be set if multiple
     *                                    rows where selected and other sql statements are also send to the database.
     * @return int|null Return the number of rows of the result of the sql statement.
     * @see <a href="https://secure.php.net/manual/en/pdostatement.rowcount.php">\PDOStatement::rowCount</a>
     */
    public function num_rows(\PDOStatement $pdoStatement = null)
    {
        global $gLogger;

        $gLogger->warning('DEPRECATED: "$database->num_rows()" is deprecated, use "$this->pdoStatement->rowCount()" instead!');

        // if pdo statement is committed then fetch this object
        if ($pdoStatement instanceof \PDOStatement)
        {
            return $pdoStatement->rowCount();
        }
        // if no pdo statement was committed then take the one from the last query
        if ($this->pdoStatement instanceof \PDOStatement)
        {
            return $this->pdoStatement->rowCount();
        }

        return null;
    }

    /**
     * Method gets all columns and their properties from the database table.
     * @deprecated 3.2.0:4.0.0 Switch to new methods (getTableColumnsProperties(), getTableColumns()).
     * @param string $table                Name of the database table for which the columns should be shown.
     * @param bool   $showColumnProperties If this is set to **false** only the column names were returned.
     * @return array<string,array<string,mixed>>|array<int,string> Returns an array with each column and their properties if $showColumnProperties is set to **true**.
     */
    public function showColumns($table, $showColumnProperties = true)
    {
        global $gLogger;

        $gLogger->warning('DEPRECATED: "$database->showColumns()" is deprecated, use "$database->getTableColumnsProperties()" or "$database->getTableColumns()" instead!');

        if ($showColumnProperties)
        {
            // returns all columns with their properties of the table
            return $this->getTableColumnsProperties($table);
        }

        // returns only the column names of the table.
        return $this->getTableColumns($table);
    }
}
